<?php

namespace VD\PHPHelper;

class System {
    /**
     * Returns the list of memory units in order from smallest to largest.
     *
     * @return array<string> List of memory units
     */
    public static function getMemoryUnitOrder(): array {
        return [
            'b',
            'kb',
            'mb',
            'gb',
            'tb',
            'pb',
            'eb',
            'zb',
            'yb',
        ];
    }

    /**
     * Converts a byte size into a human-readable memory unit (e.g., KB, MB, GB).
     *
     * Binary (1024-based) units, rounded to 2 decimals. The unit is chosen AFTER rounding, so a
     * value just under a boundary is promoted rather than shown as "1024": 1048575 bytes is "1 MB",
     * not "1024 KB" (which the log()-based version produced, along with a float-precision risk at
     * exact powers of 1024).
     *
     * @param int $bytes The size in bytes
     * @return string Human-readable memory size; "0 B" for zero and for negative input
     *
     * @see http://php.net/manual/en/function.memory-get-usage.php
     */
    public static function convertBytesToReadable(int $bytes): string {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = self::getMemoryUnitOrder();
        $last = count($units) - 1;

        $i = 0;
        $value = (float) $bytes;
        while ($value >= 1024 && $i < $last) {
            $value /= 1024;
            $i++;
        }

        $value = round($value, 2);
        if ($value >= 1024 && $i < $last) {
            $value = round($value / 1024, 2);
            $i++;
        }

        return $value . ' ' . Str::strToUpper($units[$i]);
    }

    /**
     * Resolves a memory unit string to its power-of-1024 index in getMemoryUnitOrder().
     *
     * Accepts the multi-letter form ("kb") and the single-letter shorthand ("k") that PHP itself
     * uses for memory_limit / post_max_size / upload_max_filesize. Nothing else is accepted: the
     * IEC forms ("kib") and any unknown unit ("x") are rejected rather than guessed at.
     *
     * @param string $unit Unit to resolve. Must already be lowercase and free of surrounding
     *                     whitespace. An empty string means "plain byte count" and resolves to 0.
     * @return int|false The exponent for pow(1024, $index), or false if the unit is not recognised.
     */
    private static function resolveMemoryUnitIndex(string $unit): int|false {
        if ($unit === '') {
            return 0;
        }

        $units = self::getMemoryUnitOrder();

        $index = array_search($unit, $units, true);
        if ($index !== false) {
            return $index;
        }

        // Single-letter shorthand: "m" is "mb". The first letters of getMemoryUnitOrder() are unique.
        if (strlen($unit) === 1) {
            foreach ($units as $i => $candidate) {
                if ($candidate[0] === $unit) {
                    return $i;
                }
            }
        }

        return false;
    }

    /**
     * Converts a string representing a memory size (e.g. "2GB", "512M", "1.5gb", "1024") into bytes.
     *
     * Accepted format, case-insensitive: an optional run of whitespace, a non-negative decimal
     * number, optional whitespace, an optional unit, optional whitespace. Units are binary
     * (1024-based), so "1kb" is 1024 bytes, and are either the multi-letter forms returned by
     * getMemoryUnitOrder() ("b", "kb", "mb", "gb", "tb", "pb", "eb", "zb", "yb") or their
     * single-letter shorthand ("b", "k", "m", "g", "t", "p", "e", "z", "y") — the form PHP itself
     * uses for memory_limit, post_max_size and upload_max_filesize. Without a unit the number is
     * taken as a plain byte count. A fractional byte result is floored ("1.5b" -> 1).
     *
     * A whole number is converted with EXACT integer arithmetic: going through a float silently
     * rounded anything above 2^53, so "9007199254740993" came back as ...992.
     *
     * @param string|null $input The memory size string. Required; null is a conversion failure.
     * @return int|false The size in bytes, or false if the conversion failed. Conversion FAILS for:
     *                   null, "", a string with no number ("gb"), a negative value ("-1", including
     *                   PHP's "memory_limit = -1"), an unknown unit ("5x", "5kib"), any other
     *                   unparseable input, and any result at or above PHP_INT_MAX ("9yb"). It never
     *                   throws and never returns a partially-parsed number: check `=== false` and
     *                   the value is trustworthy.
     */
    public static function convertMemoryToBytes(?string $input): int|false {
        if ($input === null) {
            return false;
        }

        if (!preg_match('/^\s*([0-9]+(?:\.[0-9]+)?)\s*([a-z]*)\s*\z/i', $input, $matches)) {
            return false;
        }

        $index = self::resolveMemoryUnitIndex(Str::strToLower($matches[2]));
        if ($index === false) {
            return false;
        }

        if (!str_contains($matches[1], '.')) {
            $digits = ltrim($matches[1], '0');
            if ($digits === '') {
                return 0;
            }

            $number = filter_var($digits, FILTER_VALIDATE_INT);
            $multiplier = 1024 ** $index; // a float from "zb" up: already beyond PHP_INT_MAX
            if ($number === false || !is_int($multiplier) || $number > intdiv(PHP_INT_MAX, $multiplier)) {
                return false;
            }

            // Cannot overflow after the check above; PHP_INT_MAX itself fails like the float path.
            $bytes = $number * $multiplier;
            return $bytes >= PHP_INT_MAX ? false : $bytes;
        }

        $bytes = ((float) $matches[1]) * pow(1024, $index);

        // (int) on a float at/above 2**63 is undefined behaviour, so refuse instead of returning garbage.
        if ($bytes >= (float) PHP_INT_MAX) {
            return false;
        }

        return (int) floor($bytes);
    }

    /**
     * Returns whether the Windows memory probe (wmic) applies to the given PHP_OS_FAMILY.
     *
     * The old test was stripos(PHP_OS, 'win'), which also matches "Darwin" and "CYGWIN_NT-…": on
     * macOS it ran `wmic … 2>NUL` through /bin/sh, where "2>NUL" CREATES A FILE NAMED "NUL" in the
     * current working directory on every call.
     *
     * @param string $osFamily A PHP_OS_FAMILY value
     * @return bool
     */
    private static function usesWindowsMemoryProbe(string $osFamily): bool {
        return $osFamily === 'Windows';
    }

    /**
     * Extracts total and available physical memory from /proc/meminfo content.
     *
     * "Available" is MemAvailable when the kernel reports it (Linux 3.14+) and MemFree otherwise.
     * MemFree excludes the page cache, which Linux fills with otherwise-idle RAM, so on a
     * long-running server it reads as a small fraction of what can actually be allocated — and
     * getMemoryUsage() caps its freeBytes by this figure.
     *
     * @param string $content Raw /proc/meminfo content
     * @return array{totalBytes: int|null, freeBytes: int|null} NULL for a figure that is absent or
     *                                                           unparseable.
     */
    private static function parseMeminfo(string $content): array {
        $values = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) as $line) {
            $parts = explode(':', $line);
            if (count($parts) !== 2) continue;

            $key = Str::removeExcessSpaces(Str::strToLower($parts[0]), false);
            if ($key !== 'memtotal' && $key !== 'memfree' && $key !== 'memavailable') {
                continue;
            }

            // An unparseable line leaves the field null, which makes the caller return null —
            // never a false that would poison the arithmetic.
            $bytes = self::convertMemoryToBytes(Str::removeExcessSpaces(Str::strToLower($parts[1]), false));
            if ($bytes !== false) {
                $values[$key] = $bytes;
            }
        }

        return [
            'totalBytes' => $values['memtotal'] ?? null,
            'freeBytes' => $values['memavailable'] ?? $values['memfree'] ?? null,
        ];
    }

    /**
     * Extracts the numbers from `wmic ComputerSystem get TotalPhysicalMemory` and
     * `wmic OS get FreePhysicalMemory` output.
     *
     * @param array<string> $outputTotal Lines of the first command (bytes)
     * @param array<string> $outputFree Lines of the second command (KiB)
     * @return array{totalBytes: int|null, freeBytes: int|null}
     */
    private static function parseWmicOutput(array $outputTotal, array $outputFree): array {
        $result = ['totalBytes' => null, 'freeBytes' => null];

        foreach ($outputTotal as $line) {
            $line = trim((string) $line);
            if (preg_match('/^[0-9]+\z/', $line)) {
                $result['totalBytes'] = self::convertMemoryToBytes($line) ?: null;
                break;
            }
        }

        foreach ($outputFree as $line) {
            $line = trim((string) $line);
            if (preg_match('/^[0-9]+\z/', $line)) {
                $bytes = self::convertMemoryToBytes($line . 'k');
                $result['freeBytes'] = $bytes === false ? null : $bytes;
                break;
            }
        }

        return $result;
    }

    /**
     * Builds the getServerMemoryUsage() array from raw figures, or NULL if they are unusable.
     *
     * @param int|null $totalBytes Physical total
     * @param int|null $freeBytes Physical free/available
     * @return array|null See getServerMemoryUsage()
     */
    private static function buildServerMemoryUsage(?int $totalBytes, ?int $freeBytes): ?array {
        if ($totalBytes === null || $freeBytes === null || $totalBytes <= 0 || $freeBytes < 0) {
            return null;
        }

        // Never report more free than total (a racy or odd probe), which would make usage negative.
        $freeBytes = min($freeBytes, $totalBytes);
        $usageBytes = $totalBytes - $freeBytes;

        return [
            'totalBytes' => $totalBytes,
            'freeBytes' => $freeBytes,
            'usageBytes' => $usageBytes,
            'total' => self::convertBytesToReadable($totalBytes),
            'usage' => self::convertBytesToReadable($usageBytes),
            'free' => self::convertBytesToReadable($freeBytes),
            'freePercent' => Number::roundDecimal(($freeBytes * 100 / $totalBytes), 2, 'floor'),
        ];
    }

    /**
     * Retrieves PHYSICAL memory usage of the whole machine from the operating system.
     * This is the box, not the PHP process: see getMemoryUsage() for the current process.
     *
     * Best-effort probe. On Windows (PHP_OS_FAMILY — macOS is NOT Windows, although "Darwin"
     * contains "win") it shells out to `wmic` with fixed arguments; everywhere else it reads
     * /proc/meminfo. Any diagnostic is suppressed, and it returns null — never a partial array
     * and never a warning — when the numbers cannot be obtained. Returning null is ROUTINE, not
     * exceptional: `wmic` is deprecated and absent from Windows 11 24H2 / Server 2025 onwards,
     * macOS and the BSDs have no /proc/meminfo, it is unreadable under many hardened setups and
     * open_basedir, and exec() is disabled on most shared hosting. Callers MUST handle null.
     *
     * In a container, /proc/meminfo describes the HOST, not the container's cgroup limit.
     *
     * @return array{
     *     totalBytes: int,
     *     freeBytes: int,
     *     usageBytes: int,
     *     total: string,
     *     usage: string,
     *     free: string,
     *     freePercent: float
     * }|null Memory data, or null if the OS did not give usable numbers. freeBytes is the memory
     *        the OS reports as AVAILABLE for new allocations (Linux: MemAvailable, falling back to
     *        MemFree on kernels that lack it; Windows: FreePhysicalMemory), never more than
     *        totalBytes. freePercent is freeBytes / totalBytes * 100, floored to 2 decimals — the
     *        free share, not the used one. usageBytes is totalBytes - freeBytes.
     *
     * @see https://www.php.net/manual/en/function.memory-get-usage.php
     */
    public static function getServerMemoryUsage(): ?array {
        $figures = ['totalBytes' => null, 'freeBytes' => null];

        if (self::usesWindowsMemoryProbe(PHP_OS_FAMILY)) {
            // function_exists() is not paranoia: exec() is in disable_functions on most shared
            // hosting, and calling a disabled function raises an \Error that @ does NOT suppress.
            $outputTotal = [];
            $outputFree = [];

            if (function_exists('exec')) {
                // 2>NUL: without wmic, cmd would write "'wmic' is not recognized" to stderr on
                // every call, polluting caller logs. The commands are constants — nothing is
                // interpolated, so there is nothing to escape.
                @exec("wmic ComputerSystem get TotalPhysicalMemory 2>NUL", $outputTotal);
                @exec("wmic OS get FreePhysicalMemory 2>NUL", $outputFree);
            }

            $figures = self::parseWmicOutput($outputTotal, $outputFree);
        } elseif (@is_readable('/proc/meminfo')) {
            $content = @file_get_contents('/proc/meminfo');
            if ($content !== false) {
                $figures = self::parseMeminfo($content);
            }
        }

        return self::buildServerMemoryUsage($figures['totalBytes'], $figures['freeBytes']);
    }

    /**
     * Returns detailed information about the memory available to the current PHP process.
     *
     * "Total" is PHP's own memory_limit, which is the ceiling that actually matters to a script.
     * It is read with ini_parse_quantity(), PHP's own parser for that setting, so every form PHP
     * accepts is understood ("512M", "0x20000000", "1G"...); a hex limit used to be unparseable
     * here and was reported as NO limit. When memory_limit is negative ("-1", i.e. no PHP limit)
     * or unusable, the machine's physical total from getServerMemoryUsage() is used instead. Free
     * memory is the remaining headroom under that total, capped by the physical memory the OS
     * still has available when the OS can be asked — you cannot allocate what the box does not
     * have.
     *
     * Always returns the full array; it never throws and never returns null, even when the OS
     * probe fails (see getServerMemoryUsage(), where that is routine).
     *
     * @return array{
     *     usageBytes: int,
     *     usage: string,
     *     totalBytes: int,
     *     total: string,
     *     freeBytes: int,
     *     free: string,
     *     freePercent: float
     * } usageBytes is memory_get_usage(true) — real memory allocated from the OS by this process.
     *   freePercent is the share of totalBytes still FREE (freeBytes / totalBytes * 100), floored
     *   to 2 decimals — NOT the used share: it falls towards 0 as memory runs out.
     *   UNKNOWN TOTAL: if memory_limit is unlimited AND the OS probe returns null, the total is
     *   unknowable here; totalBytes and freeBytes are then 0 and freePercent is 0.0. Test
     *   `totalBytes > 0` before trusting freePercent — a 0.0 there means "unknown", not
     *   "exhausted". usageBytes/usage are always real.
     *
     * @see http://php.net/manual/en/function.memory-get-usage.php
     */
    public static function getMemoryUsage(): array {
        $serverMemory = self::getServerMemoryUsage();

        $usageBytes = memory_get_usage(true);

        $memoryLimit = trim((string) ini_get('memory_limit'));
        $limitBytes = $memoryLimit === '' ? -1 : @ini_parse_quantity($memoryLimit);

        if ($limitBytes > 0) {
            $totalBytes = $limitBytes;

            $freeBytes = $limitBytes - $usageBytes;
            if ($serverMemory !== null) {
                // Never claim more free memory than the machine physically has left.
                $freeBytes = min($freeBytes, $serverMemory['freeBytes']);
            }

            $freeBytes = max(0, $freeBytes);
        } else {
            // 0 = unknown: no PHP limit and no OS numbers. Documented in @return.
            $totalBytes = $serverMemory['totalBytes'] ?? 0;
            $freeBytes = $serverMemory['freeBytes'] ?? 0;
        }

        // Assembled in the documented @return order: the key order is what json_encode() emits.
        return [
            'usageBytes' => $usageBytes,
            'usage' => self::convertBytesToReadable($usageBytes),
            'totalBytes' => $totalBytes,
            'total' => self::convertBytesToReadable($totalBytes),
            'freeBytes' => $freeBytes,
            'free' => self::convertBytesToReadable($freeBytes),
            'freePercent' => $totalBytes > 0
                ? Number::roundDecimal(($freeBytes * 100 / $totalBytes), 2, 'floor')
                : 0.0,
        ];
    }

    /**
     * Compares two memory size strings and returns true if memory A is strictly greater than B.
     *
     * Both operands go through convertMemoryToBytes() and are compared as byte counts, so the
     * units do not have to match and neither does their notation: "1GB" vs "2048MB" compares
     * 1073741824 against 2147483648 and is correctly false.
     *
     * Unparseable input never throws; it resolves to the fail-closed answer on each side, so a
     * typo in a configured limit tightens a guard instead of opening it.
     *
     * @param string|null $memoryA Memory value to compare (e.g. "512MB", "512M", "1.5gb", "1024").
     *                             If null/""/unparseable, this returns FALSE: an unknown A is
     *                             never treated as greater than B.
     * @param string|null $memoryB Memory value to compare (e.g. "256MB"). If null/""/unparseable
     *                             while A is valid, this returns TRUE: an absent or unreadable B
     *                             is treated as no ceiling at all, so A is taken to exceed it.
     * @return bool True if A > B, false if A <= B or A is unusable. Equal values return false.
     *              Never throws.
     */
    public static function isMemoryGreaterThan(?string $memoryA, ?string $memoryB): bool
    {
        $bytesA = self::convertMemoryToBytes($memoryA);
        if ($bytesA === false) {
            return false;
        }

        $bytesB = self::convertMemoryToBytes($memoryB);
        if ($bytesB === false) {
            return true;
        }

        return $bytesA > $bytesB;
    }

    /**
     * Seeds PHP's legacy rand() generator, optionally from a string, so a sequence is reproducible.
     *
     * SECURITY: this seeds the generator behind rand()/mt_rand()/shuffle()/str_shuffle(), which is
     * NOT cryptographically secure and is fully predictable to anyone who learns or guesses the
     * seed. Never use it for tokens, passwords, keys, OTPs, IDs or anything else that must be
     * unguessable — use random_int() / random_bytes(), which cannot be seeded, for those.
     *
     * @param string|null $seed Optional seed. NULL (the default) derives a non-reproducible seed
     *                          from the current microtime plus rand(). Any other value is honoured
     *                          exactly, INCLUDING "" and "0": a canonical integer string — optional
     *                          '-', digits only, within the int range — is used as that integer
     *                          ("42" and "042" seed srand(42)); ANY other string is hashed with
     *                          crc32(), which is what makes a string seed reproducible
     *                          ("order-7" seeds srand(crc32("order-7"))). Only integer strings are
     *                          taken numerically: is_numeric() + (int) used to collapse "4.2" and
     *                          "4.7" (both 4), "1e3" and "1000", and every out-of-range number
     *                          onto the same seed. Note "" and "0" both seed srand(0), since
     *                          crc32("") is 0.
     * @return void
     *
     * @see https://www.php.net/manual/en/function.srand.php
     */
    public static function makeSeed(?string $seed = null): void {
        if ($seed === null) {
            list($microseconds, $seconds) = explode(' ', microtime());

            srand((int) (
                ( ((int) $seconds) + ((int) (((float) $microseconds) * 1000000)) ) +
                rand(0, 99999)
            ));
            return;
        }

        if (preg_match('/^(-?)0*([0-9]+)\z/', $seed, $matches) === 1) {
            $integer = filter_var($matches[1] . $matches[2], FILTER_VALIDATE_INT);
            if ($integer !== false) {
                srand($integer);
                return;
            }
        }

        srand(crc32($seed));
    }

    /**
     * Controls named timers: starts/restarts one, reads elapsed time, or discards them.
     *
     * Timers live in a static array for the lifetime of the PHP process, keyed by name. Each entry
     * holds the wall-clock start ('started_at', a 'Y-m-d H:i:s.u' string) and a monotonic start
     * ('started_ns', from hrtime()); elapsed time is measured from the monotonic one, so a clock
     * adjustment cannot corrupt it. The store is process-wide and shared by every caller — a name
     * is a global key, so prefix names you do not want another component to reset.
     *
     * NEVER returns a string, despite the elapsed values being time-based: every result is an
     * array or null (the ": ?array" signature makes a string return impossible).
     *
     * @param string|null $timerName Timer name. Required for "init" and "reset". For "get" it is
     *                               optional: null/"" reads EVERY timer. For "clear" it is
     *                               optional too, and null/"" discards EVERY timer. "0" is an
     *                               ordinary name (empty() used to treat it as "no name").
     * @param string $type Case-insensitive. One of:
     *                     - "init"/"reset" (default "init"): (re)starts $timerName at now.
     *                     - "get": reads elapsed time without stopping the timer.
     *                     - "clear": discards $timerName, or all timers when no name is given.
     *                     Any other value, or "", is a no-op that returns null.
     *
     * @return array{timer: string, started_at: string}|array{timer: string, started_at: string, now: string, elapsed_ms: float, elapsed_seconds: float}|list<array{timer: string, started_at: string, now: string, elapsed_ms: float, elapsed_seconds: float}>|null
     *         Depends on $type:
     *         - "init"/"reset": ['timer' => name, 'started_at' => 'Y-m-d H:i:s.u'].
     *         - "get" with a name: that array plus 'now', 'elapsed_ms' and 'elapsed_seconds'
     *           (floats, rounded to 3 and 6 decimals) — or NULL if the timer was never started
     *           or has been cleared.
     *         - "get" without a name: a list of those arrays, one per live timer; [] when none.
     *         - "clear": always null (the discard still happens).
     *         - Missing name on "init"/"reset", empty/unknown $type: null, and nothing happens.
     */
    public static function timer(?string $timerName, string $type = "init"): ?array {
        if ($type === '') {
            return null;
        }

        $type = Str::strToLower($type);
        $hasName = $timerName !== null && $timerName !== '';
        if (!$hasName && $type !== "get" && $type !== "clear") {
            return null;
        }

        static $timers = [];

        switch ($type) {
            case "clear":
                if ($hasName) {
                    // unset(), not = []: an empty entry would survive isset() in "get" and then
                    // read a missing 'started_ns'.
                    unset($timers[$timerName]);
                } else {
                    $timers = [];
                }
                break;

            case "init":
            case "reset":
                $nowDate = new \DateTimeImmutable('now');

                $timers[$timerName] = [
                    'started_at' => $nowDate->format('Y-m-d H:i:s.u'),
                    'started_ns' => hrtime(true),
                ];

                return [
                    'timer' => $timerName,
                    'started_at' => $timers[$timerName]['started_at'],
                ];

            case "get":
                $nowDate = new \DateTimeImmutable('now');
                $nowNs = hrtime(true);

                if (!$hasName) {
                    $result = [];

                    foreach ($timers as $name => $timer) {
                        $elapsedNs = $nowNs - $timer['started_ns'];
                        $elapsedMs = $elapsedNs / 1_000_000;

                        $result[] = [
                            // (string): a numeric name such as "0" is stored under an INT key.
                            'timer' => (string) $name,
                            'started_at' => $timer['started_at'],
                            'now' => $nowDate->format('Y-m-d H:i:s.u'),
                            'elapsed_ms' => round($elapsedMs, 3),
                            'elapsed_seconds' => round($elapsedMs / 1000, 6),
                        ];
                    }

                    return $result;
                }

                if (!isset($timers[$timerName])) {
                    return null;
                }

                $elapsedNs = $nowNs - $timers[$timerName]['started_ns'];
                $elapsedMs = $elapsedNs / 1_000_000;

                return [
                    'timer' => $timerName,
                    'started_at' => $timers[$timerName]['started_at'],
                    'now' => $nowDate->format('Y-m-d H:i:s.u'),
                    'elapsed_ms' => round($elapsedMs, 3),
                    'elapsed_seconds' => round($elapsedMs / 1000, 6),
                ];
        }

        return null;
    }
}
