<?php

namespace VD\PHPHelper;

use InvalidArgumentException;

/**
 * Counts hits per bucket in fixed windows and tells when a bucket goes over its limit: how often a
 * form, a login or an endpoint may be used, with nothing but a directory — no database, no cache
 * server.
 *
 * ```php
 * $limiter = new RateLimiter(__DIR__ . '/storage/ratelimit', getenv('APP_KEY'));
 * $ip = RateLimiter::ipBucket(HTTP::getClientIp(getenv('TRUSTED_PROXIES')) ?? '0.0.0.0');
 * if ($limiter->hit("contact:ip:{$ip}", 6, 3600) || $limiter->hit('contact:global', 45, 86400)) {
 *     // answer 429
 * }
 * ```
 *
 * - A bucket is any name. Its window starts at its first hit and starts over at the first hit
 *   after it ended (a fixed window, not a sliding one).
 * - Each bucket is a small JSON file whose NAME is an HMAC of the bucket, under a subkey derived
 *   from $key: a plain hash of "contact:ip:203.0.113.9" is reversed by trying the whole IPv4 space
 *   in minutes, which would turn the directory into a list of who used the site. Without a key the
 *   name is an unkeyed hash, and that is all it is.
 * - It FAILS OPEN: when the directory cannot be created, or a counter cannot be opened, locked or
 *   written, the hit is allowed. A full disk must not take a form down; it only stops counting.
 * - Counters nobody touched for $maxAgeSeconds are deleted, at most once every $sweepEverySeconds
 *   (a marker file keeps the time of the last sweep). Sweeping on every hit would make a flood from
 *   many addresses — one file each — pay for listing the whole directory on every request, the
 *   refused ones included. Only the limiter's own files (64 hex characters + ".json") are deleted.
 * - Two processes counting the same bucket take turns through flock(). On a file system without
 *   working locks (some network mounts) two simultaneous hits may count as one.
 *
 * For limits on a client address, count IPv6 by its /64 (ipBucket()), and consider a wider tier
 * per network (ipNetwork()), asked with isOver() before the narrow ones are counted.
 */
final class RateLimiter {
    private const SWEEP_MARKER = 'sweep.marker';
    private const KEY_LABEL = 'vd-phphelper/rate-limiter';

    private string $directory;
    private string $hashKey;

    /**
     * @param string $directory Where the counters live; created (0775) on the first hit when
     *                          missing. Give the limiter a directory of its own.
     * @param string|Keyring|null $key A secret of the site, of any length (a Keyring gives its
     *                          current key). File names are keyed with a subkey derived from it
     *                          (HKDF-SHA256, a label of their own), never with the secret itself, so
     *                          the limiter shares no key with your encryption. Rotating the secret
     *                          starts every counter over. NULL or '': unkeyed names.
     * @param int $maxAgeSeconds Counters untouched this long are swept. It must be at least the
     *                          longest window you use; hit() and isOver() refuse a longer window.
     * @param int $sweepEverySeconds The least time between two sweeps.
     * @throws InvalidArgumentException On an empty $directory, or a $maxAgeSeconds or
     *                          $sweepEverySeconds below 1.
     */
    public function __construct(
        string $directory,
        #[\SensitiveParameter] string|Keyring|null $key = null,
        private readonly int $maxAgeSeconds = 86400,
        private readonly int $sweepEverySeconds = 600
    ) {
        if (trim($directory) === '') {
            throw new InvalidArgumentException('$directory must not be empty.');
        }
        if ($maxAgeSeconds < 1 || $sweepEverySeconds < 1) {
            throw new InvalidArgumentException('$maxAgeSeconds and $sweepEverySeconds must be at least 1.');
        }

        $trimmed = rtrim($directory, '/\\');
        $this->directory = $trimmed !== '' ? $trimmed : $directory;

        $secret = $key instanceof Keyring ? $key->currentKey() : (string) $key;
        $this->hashKey = $secret !== '' ? hash_hkdf('sha256', $secret, 32, self::KEY_LABEL) : '';
    }

    /** Shows the directory only: the subkey stays out of var_dump() and print_r(). */
    public function __debugInfo(): array {
        return ['directory' => $this->directory, 'keyed' => $this->hashKey !== ''];
    }

    /**
     * Counts one hit of $bucket and tells whether the bucket is now OVER its limit: TRUE from the
     * ($limit + 1)th hit of a window on. Refused hits count too, so a client that keeps trying stays
     * over until the window ends.
     *
     * @param string $bucket Any non-empty name, e.g. "contact:ip:203.0.113.9" or "contact:global".
     * @param int $limit Hits allowed per window, 0 or more (0: every hit is over).
     * @param int $windowSeconds The window: 1 or more, and at most the $maxAgeSeconds the limiter
     *                           was built with.
     * @param int|null $now Unix time of the hit for the window arithmetic; NULL is now. The sweep
     *                      always goes by the real clock, as the file times do.
     * @return bool TRUE when over the limit. FALSE when within it — or when the hit could not be
     *              counted (it fails open).
     * @throws InvalidArgumentException On an empty $bucket, a negative $limit, or a window outside
     *                                  1..$maxAgeSeconds.
     */
    public function hit(string $bucket, int $limit, int $windowSeconds, ?int $now = null): bool {
        $this->checkArguments($bucket, $limit, $windowSeconds);
        $now ??= time();
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0775, true) && !is_dir($this->directory)) {
            return false;
        }

        $handle = @fopen($this->path($bucket), 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $counter = self::decode((string) stream_get_contents($handle));
            if ($counter === null || $counter['start'] + $windowSeconds <= $now) {
                $counter = ['start' => $now, 'count' => 0];
            }
            $counter['count']++;

            $written = ftruncate($handle, 0) && rewind($handle) && fwrite($handle, (string) json_encode($counter)) !== false;
            fflush($handle);
            flock($handle, LOCK_UN);
            if (!$written) {
                return false;
            }
        } finally {
            fclose($handle);
        }

        if ($this->sweepDue()) {
            $this->sweep();
        }

        return $counter['count'] > $limit;
    }

    /**
     * Whether $bucket has already had its $limit hits in the window that is running, i.e. whether
     * the next hit() would be over. Only reads: nothing is counted and no file is created. It is how
     * a wide tier (a whole network) is asked before the narrow ones (each address) are counted, so a
     * network that is already over cannot make the limiter write a new file for every address it
     * sends from. A counter that is missing or cannot be read is not over; with $limit 0, every
     * bucket is.
     *
     * @param int|null $now Unix time to judge the window by; NULL is now.
     * @throws InvalidArgumentException As hit().
     */
    public function isOver(string $bucket, int $limit, int $windowSeconds, ?int $now = null): bool {
        $this->checkArguments($bucket, $limit, $windowSeconds);
        if ($limit === 0) {
            return true;
        }

        $path = $this->path($bucket);
        if (!is_file($path)) {
            return false;
        }
        $handle = @fopen($path, 'r');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_SH)) {
                return false;
            }
            $counter = self::decode((string) stream_get_contents($handle));
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return $counter !== null
            && $counter['start'] + $windowSeconds > ($now ?? time())
            && $counter['count'] >= $limit;
    }

    /**
     * An address as a per-address limit should count it: IPv4 as it is, IPv6 by its /64
     * ("2001:db8:1:2::/64"). One subscriber line is handed a whole /64 — 2^64 addresses — so
     * counting IPv6 address by address would never limit anything. An IPv4 address mapped into
     * IPv6 ("::ffff:203.0.113.9") counts as the IPv4 address. Anything that is not an address is
     * returned as given.
     */
    public static function ipBucket(string $ip): string {
        $packed = @inet_pton(trim($ip));
        if ($packed === false || strlen($packed) !== 16) {
            return $ip;
        }
        if (substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            return (string) inet_ntop(substr($packed, 12));
        }

        return inet_ntop(substr($packed, 0, 8) . str_repeat("\0", 8)) . '/64';
    }

    /**
     * The network an address belongs to, for a wider tier of a limit: the /24 of an IPv4 address
     * ("203.0.113.0/24") and the /48 of an IPv6 one ("2001:db8:1::/48"). A /48 is what a provider
     * hands one customer — 65,536 of the /64s ipBucket() counts one by one —, and a /24 is the
     * smallest IPv4 block routed on its own. An IPv4 address mapped into IPv6 counts as IPv4.
     * Anything that is not an address is returned as given.
     */
    public static function ipNetwork(string $ip): string {
        $packed = @inet_pton(trim($ip));
        if ($packed === false) {
            return $ip;
        }
        if (strlen($packed) === 16 && substr($packed, 0, 12) === str_repeat("\0", 10) . "\xff\xff") {
            $packed = substr($packed, 12);
        }
        if (strlen($packed) === 4) {
            return inet_ntop(substr($packed, 0, 3) . "\0") . '/24';
        }

        return inet_ntop(substr($packed, 0, 6) . str_repeat("\0", 10)) . '/48';
    }

    private function checkArguments(string $bucket, int $limit, int $windowSeconds): void {
        if ($bucket === '') {
            throw new InvalidArgumentException('$bucket must not be empty.');
        }
        if ($limit < 0) {
            throw new InvalidArgumentException('$limit must be 0 or more.');
        }
        if ($windowSeconds < 1 || $windowSeconds > $this->maxAgeSeconds) {
            // A window longer than the sweep age would have its counter deleted while it runs,
            // and the limit would quietly start over.
            throw new InvalidArgumentException("\$windowSeconds must be between 1 and the limiter's \$maxAgeSeconds ({$this->maxAgeSeconds}).");
        }
    }

    /** The counter file of a bucket: an HMAC of it, so no bucket name is written to disk. */
    private function path(string $bucket): string {
        return $this->directory . DIRECTORY_SEPARATOR . hash_hmac('sha256', 'rate-limit|' . $bucket, $this->hashKey) . '.json';
    }

    /**
     * A counter as stored, or NULL for an empty, corrupt or foreign file (which then starts over).
     *
     * @return array{start: int, count: int}|null
     */
    private static function decode(string $json): ?array {
        $data = json_decode($json, true);
        if (!is_array($data) || !is_int($data['start'] ?? null) || !is_int($data['count'] ?? null) || $data['count'] < 0) {
            return null;
        }

        return ['start' => $data['start'], 'count' => $data['count']];
    }

    /**
     * Whether a sweep is due, and if so books it by touching the marker: the counters therefore
     * outlive $maxAgeSeconds by $sweepEverySeconds at most.
     */
    private function sweepDue(): bool {
        $marker = $this->directory . DIRECTORY_SEPARATOR . self::SWEEP_MARKER;
        clearstatcache(true, $marker);
        $last = @filemtime($marker);
        if ($last !== false && $last + $this->sweepEverySeconds > time()) {
            return false;
        }

        return @touch($marker);
    }

    /** Deletes the limiter's own files not modified for $maxAgeSeconds. */
    private function sweep(): void {
        $cutoff = time() - $this->maxAgeSeconds;
        foreach (@scandir($this->directory) ?: [] as $name) {
            if (preg_match('/^[0-9a-f]{64}\.json$/D', $name) !== 1) {
                continue;
            }
            $file = $this->directory . DIRECTORY_SEPARATOR . $name;
            clearstatcache(true, $file);
            $modified = @filemtime($file);
            if ($modified !== false && $modified < $cutoff) {
                @unlink($file);
            }
        }
    }
}
