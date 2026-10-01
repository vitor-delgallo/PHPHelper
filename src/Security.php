<?php

namespace VD\PHPHelper;

class Security {
    /**
     * Plaintext bytes per data block used by encryptFile until setFileEncryptBlocksBytes() says
     * otherwise.
     *
     * @var int
     */
    private const DEFAULT_FILE_ENCRYPT_BLOCK_BYTES = 3200000;

    /**
     * Default for the largest length-encoded block of an encrypted file, measured as it sits on
     * disk (base64). See setFileMaxEncodedBlockBytes().
     *
     * @var int
     */
    private const DEFAULT_FILE_MAX_ENCODED_BLOCK_BYTES = 268435456; // 256 MiB

    /**
     * Plaintext bytes read per data block by encryptFile. 0 = not set (the default applies).
     *
     * @var int
     */
    private static int $fileEncryptBlocksBytes = 0;

    /**
     * Largest length-encoded block (bytes, base64) of an encrypted file. 0 = not set (the default
     * applies).
     *
     * @var int
     */
    private static int $fileMaxEncodedBlockBytes = 0;

    /**
     * Minimum master-key length (in bytes) for the AES-256 code paths. HKDF cannot add
     * entropy, so a 32-byte master is required to reach real 256-bit strength.
     *
     * @var int
     */
    private const MIN_KEY_BYTES = 32;

    /**
     * Version tag for the encryptDataDB/decryptDataDB envelope "v2:{key id}:{base64}". Emitted as
     * a prefix AND bound into the GCM AAD, so a value cannot be reinterpreted under another
     * version. v1 (no key id) is no longer read.
     *
     * @var string
     */
    private const DB_ENVELOPE_VERSION = 'v2';

    /**
     * Version tag for the encryptLocal/decryptLocal envelope "l1:{key id}:{base64}". Covered by
     * the HMAC together with the key id.
     *
     * @var string
     */
    private const LOCAL_ENVELOPE_VERSION = 'l1';

    /**
     * Raw bytes behind a key id; it is written hex-encoded (16 characters).
     *
     * @var int
     */
    private const KEY_ID_BYTES = 8;

    /**
     * File format written by encryptFile(): a key per file, counter nonces, key id in the header.
     *
     * @var string
     */
    private const FILE_VERSION = 'v3';

    /**
     * Legacy file format: one key for every file under a master key and salt, random nonces.
     * decryptFile() still reads it; nothing writes it any more.
     *
     * @var string
     */
    private const FILE_V2_VERSION = 'v2';

    /**
     * Cipher of both file formats; also written as the file's first header block.
     *
     * @var string
     */
    private const FILE_CIPHER = 'aes-256-gcm';

    /**
     * Random bytes behind the per-file id. It is written hex-encoded, i.e. as twice as many
     * characters.
     *
     * @var int
     */
    private const FILE_ID_BYTES = 16;

    /**
     * AES-GCM nonce and tag sizes.
     *
     * @var int
     */
    private const GCM_IV_BYTES = 12;
    private const GCM_TAG_BYTES = 16;

    /**
     * Generates cryptographically secure random bytes, failing CLOSED.
     *
     * Uses random_bytes(), which throws when the platform CSPRNG is unavailable. There is
     * deliberately NO openssl_random_pseudo_bytes() fallback: that path can return
     * non-strong bytes, and a weak/repeated GCM nonce is catastrophic (nonce reuse leaks the
     * GHASH authentication key and enables forgery). Aborting beats encrypting with
     * unverified randomness.
     *
     * @param int $length Number of bytes to generate
     *
     * @throws \Exception When no cryptographically strong RNG is available
     * @return string
     */
    private static function secureRandomBytes(int $length): string {
        try {
            return random_bytes($length);
        } catch (\Throwable $e) {
            throw new \Exception("No cryptographically strong RNG available.", 0, $e);
        }
    }

    /**
     * Refuses a master key shorter than MIN_KEY_BYTES.
     *
     * @param string $key Master key
     *
     * @throws \InvalidArgumentException
     * @return void
     */
    private static function assertKeyLength(#[\SensitiveParameter] string $key): void {
        if (strlen($key) < self::MIN_KEY_BYTES) {
            throw new \InvalidArgumentException("Invalid encryption key. The key must be at least " . self::MIN_KEY_BYTES . " bytes long.");
        }
    }

    /**
     * A plain key is a keyring of one; a Keyring is used as given.
     *
     * @param string|Keyring $key
     *
     * @throws \InvalidArgumentException For a key shorter than 32 bytes
     * @return Keyring
     */
    private static function keyring(#[\SensitiveParameter] string|Keyring $key): Keyring {
        return ($key instanceof Keyring ? $key : new Keyring($key));
    }

    /**
     * Splits a "{version}:{key id}:{payload}" envelope and resolves its key in the keyring.
     *
     * @param string $envelope Envelope to parse
     * @param string $version Expected version tag
     * @param Keyring $keys Keys to resolve the key id against
     *
     * @throws \Exception On a malformed envelope, an unsupported version or an unknown key id
     * @return array{0: string, 1: string, 2: string} [key id, raw master key, payload]
     */
    private static function openEnvelope(string $envelope, string $version, Keyring $keys): array {
        $parts = explode(":", $envelope, 3);
        if (count($parts) < 2) {
            throw new \Exception("Malformed envelope: missing version prefix.");
        }
        if ($parts[0] !== $version) {
            throw new \Exception("Unsupported envelope version '" . substr($parts[0], 0, 8) . "' (expected '{$version}').");
        }
        if (count($parts) !== 3 || !preg_match('/\A[0-9a-f]{' . (2 * self::KEY_ID_BYTES) . '}\z/', $parts[1])) {
            throw new \Exception("Malformed envelope: missing or invalid key id.");
        }

        $key = $keys->get($parts[1]);
        if ($key === null) {
            throw new \Exception(
                "No key with id {$parts[1]} in the keyring: the value was encrypted under a key that was not passed. "
                . "After a key rotation, pass a Keyring that still holds the old key as a previous key."
            );
        }

        return [$parts[1], $key, $parts[2]];
    }

    /**
     * Derives a key of the specified length from the given key & salt using HKDF with SHA-256.
     * This function is used to generate encryption keys of the required length from a base key.
     *
     * @param string $key Base key to derive from
     * @param int $length Desired length of the derived key in bytes
     * @param string|null $salt Optional salt value to add randomness to the derived key
     * @param string $info Domain label: keys derived for different purposes never coincide
     *
     * @throws \Exception
     * @return string
     */
    private static function deriveKey(#[\SensitiveParameter] string $key, int $length, ?string $salt = "", string $info = 'derived-key'): string {
        self::assertKeyLength($key);

        return hash_hkdf(
            'sha256',
            $key,
            $length,
            $info,
            ($salt ?? "")
        );
    }

    /**
     * Converts a value to the string an encrypt/hash method operates on.
     *
     * The old `(string) $value` turned EVERY array into the literal "Array" (with only a warning):
     * encryptDataDB/encryptLocal stored "Array" for real data — silent, unrecoverable corruption —
     * and generateSearchHash gave every array the same blind index. A non-Stringable object raised
     * an \Error past the documented \Exception. Both are refused now.
     *
     * @param mixed $value Scalar or Stringable. true/false become "1"/"0".
     * @param string $method Calling method, for the message
     *
     * @throws \InvalidArgumentException For an array, a non-Stringable object or a resource
     * @return string
     */
    private static function scalarToString(mixed $value, string $method): string {
        if (is_string($value)) {
            return $value;
        }
        if (is_bool($value)) {
            return ($value ? "1" : "0");
        }
        if (is_int($value) || is_float($value) || $value instanceof \Stringable) {
            return (string) $value;
        }

        throw new \InvalidArgumentException(
            "{$method}() accepts a string, int, float, bool, Stringable or null; " . get_debug_type($value) . " given."
        );
    }

    /**
     * Size of $rawBytes once base64-encoded, as a length-encoded block carries it: 4*ceil(n/3).
     *
     * @param int $rawBytes Raw payload size
     *
     * @return int|float A float only for a size too large for a PHP int (block sizes near PHP_INT_MAX)
     */
    private static function encodedBlockBytes(int $rawBytes): int|float {
        // intdiv() first: ($rawBytes + 2) would overflow for a size near PHP_INT_MAX.
        return intdiv($rawBytes, 3) * 4 + ($rawBytes % 3 === 0 ? 0 : 4);
    }

    /**
     * The smallest usable max-encoded-block limit: the encoded size of the largest FIXED-size block
     * every file contains — the hex file id, 44 bytes, in both formats. Below it not even an empty
     * file could be written or read. The other fixed blocks are smaller: cipher name (16), version
     * (4), v3 key id (24), v2 IV (16) and tag (24), and the smallest v3 block, one byte of
     * ciphertext plus its tag (24). The v3 end marker holds the block count plus a tag, so a 44-byte
     * limit still fits the end marker of any file of fewer than 10^17 blocks.
     *
     * @return int
     */
    private static function fileMinEncodedBlockBytes(): int {
        return (int) max(
            self::encodedBlockBytes(strlen(self::FILE_CIPHER)),
            self::encodedBlockBytes(strlen(self::FILE_VERSION)),
            self::encodedBlockBytes(2 * self::KEY_ID_BYTES),
            self::encodedBlockBytes(2 * self::FILE_ID_BYTES),
            self::encodedBlockBytes(1 + self::GCM_TAG_BYTES),
            self::encodedBlockBytes(self::GCM_IV_BYTES),
            self::encodedBlockBytes(self::GCM_TAG_BYTES)
        );
    }

    /**
     * Refuses to encrypt when a block could be written that decryptFile would refuse to read.
     *
     * The limit used to be enforced on READ only: a large enough block size (or a huge salt)
     * produced a file that the encrypt call reported as a success and that could never be
     * decrypted. The check is made against the CONFIGURED block size, not against the size of the
     * file at hand, so a bad configuration fails on every call instead of only on the first file
     * that happens to be large.
     *
     * A v3 data block is the ciphertext AND its 16-byte tag, so its encoded size is
     * 4*ceil((blockBytes + 16) / 3), and the largest block size a limit L allows is
     * 3*floor(L/4) - 16 — 201.326.576 bytes under the default limit.
     *
     * @param int $blockBytes Plaintext bytes per data block
     * @param string $salt Salt as it will be written to the header
     *
     * @throws \Exception
     * @return void
     */
    private static function assertFileBlocksFitLimit(int $blockBytes, string $salt): void {
        $limit = self::getFileMaxEncodedBlockBytes();
        $largestBlock = intdiv($limit, 4) * 3 - self::GCM_TAG_BYTES;

        if ($blockBytes > $largestBlock) {
            // $blockBytes + 16 may exceed PHP_INT_MAX: computed in float, it is only for the message.
            $encodedBlock = ($blockBytes > PHP_INT_MAX - self::GCM_TAG_BYTES)
                ? ((float) $blockBytes + self::GCM_TAG_BYTES) / 3 * 4
                : self::encodedBlockBytes($blockBytes + self::GCM_TAG_BYTES);

            throw new \Exception(sprintf(
                "Refusing to encrypt: a %d-byte plaintext block (setFileEncryptBlocksBytes) is written, with its %d-byte tag, as a "
                . "%.0f-byte base64 block, above the max-encoded-block limit of %d bytes (setFileMaxEncodedBlockBytes); decryptFile "
                . "would refuse it and the file could never be decrypted. Lower the block size to at most %d bytes, or raise the "
                . "limit to at least %.0f bytes — and decrypt under a limit at least that large.",
                $blockBytes,
                self::GCM_TAG_BYTES,
                $encodedBlock,
                $limit,
                $largestBlock,
                $encodedBlock
            ));
        }

        $encodedSalt = self::encodedBlockBytes(strlen($salt));
        if ($encodedSalt > $limit) {
            throw new \Exception(sprintf(
                "Refusing to encrypt: a %d-byte salt is written as a %.0f-byte header block, above the max-encoded-block limit of "
                . "%d bytes (setFileMaxEncodedBlockBytes); decryptFile would refuse it. Use a shorter salt, or raise the limit "
                . "to at least %.0f bytes — and decrypt under a limit at least that large.",
                strlen($salt),
                $encodedSalt,
                $limit,
                $encodedSalt
            ));
        }
    }

    /**
     * Reads one length-encoded block, "{length}-{base64}", and returns its DECODED payload.
     *
     * Strict on purpose. The header blocks are authenticated only indirectly, and every declared
     * length precedes an allocation, so the container accepts exactly one byte sequence per file:
     *  - the length is canonical decimal: digits only, no sign, no leading zero, never 0 (fread()
     *    with 0 is a ValueError), within getFileMaxEncodedBlockBytes() and within what is left of
     *    the file — checked BEFORE the read, which allocates the declared length up front, so a
     *    hostile length cannot force a huge allocation ahead of authentication;
     *  - the payload is the CANONICAL base64 of what it decodes to. PHP's strict base64_decode()
     *    still skips whitespace and ignores non-zero padding bits, so a modified file could decode
     *    to identical bytes and decrypt "successfully".
     *
     * Every failure THROWS, naming $what. A declared length above the limit gets a message naming
     * the setter, because the ordinary cause is a file written by a process with a raised limit.
     *
     * @param resource $fp File pointer opened for reading
     * @param string $what Name of the block, for the error message
     *
     * @throws \Exception
     * @return string|null The decoded payload (never ""), or NULL at a clean end of file — nothing
     *                     at all left to read. Callers decide whether that EOF is legitimate.
     */
    private static function readLengthEncodedBlock($fp, string $what): ?string {
        $limit = self::getFileMaxEncodedBlockBytes();
        $limitString = (string) $limit;

        $digits = "";
        while (true) {
            // @: a read error (e.g. a byte-range lock on Windows) ends in an exception or a
            // "truncated" failure below — a raw notice would only leak the path.
            $char = @fgetc($fp);
            if ($char === false) {
                if ($digits === "") {
                    return null;
                }
                throw new \Exception("Error on reading {$what}: the file ends inside a block length (truncated).");
            }
            if ($char === "-") {
                break;
            }
            if (strspn($char, "0123456789") !== 1 || ($digits === "" && $char === "0")) {
                throw new \Exception("Error on reading {$what}: malformed block length.");
            }

            $digits .= $char;
            if (strlen($digits) > strlen($limitString)) {
                throw self::blockOverLimitException($what, "at least " . $digits, $limit);
            }
        }

        if ($digits === "") {
            throw new \Exception("Error on reading {$what}: malformed block length.");
        }
        // Same number of digits and no leading zeros: comparing the strings compares the numbers,
        // with no int overflow for a length near PHP_INT_MAX.
        if (strlen($digits) === strlen($limitString) && strcmp($digits, $limitString) > 0) {
            throw self::blockOverLimitException($what, $digits, $limit);
        }

        $length = (int) $digits;

        // fread() allocates the full $length up front, so a 20-byte file declaring a block just
        // under the limit would still cost a limit-sized allocation. Compare with what is left.
        $stat = fstat($fp);
        $position = ftell($fp);
        if ($stat !== false && $position !== false && $length > $stat['size'] - $position) {
            throw new \Exception("Error on reading {$what}: the file ends inside a block (truncated).");
        }

        $data = @fread($fp, $length);
        if ($data === false || strlen($data) !== $length) {
            throw new \Exception("Error on reading {$what}: the file ends inside a block (truncated).");
        }

        $decoded = base64_decode($data, true);
        if ($decoded === false || $decoded === "" || base64_encode($decoded) !== $data) {
            throw new \Exception("Error on reading {$what}: the block is not canonical base64.");
        }

        return $decoded;
    }

    /**
     * readLengthEncodedBlock() for a block that MUST be present: an end of file there is a
     * truncation, never a clean end.
     *
     * @param resource $fp File pointer opened for reading
     * @param string $what Name of the block, for the error message
     *
     * @throws \Exception
     * @return string
     */
    private static function readRequiredLengthEncodedBlock($fp, string $what): string {
        $block = self::readLengthEncodedBlock($fp, $what);
        if ($block === null) {
            throw new \Exception("Error on reading {$what}: the file ends before it (truncated).");
        }

        return $block;
    }

    /**
     * The error for a block whose declared length exceeds the max-encoded-block limit.
     *
     * @param string $what Name of the block
     * @param string $declared Declared length, as read
     * @param int $limit Limit in effect
     *
     * @return \Exception
     */
    private static function blockOverLimitException(string $what, string $declared, int $limit): \Exception {
        return new \Exception(
            "Error on reading {$what}: the block declares {$declared} encoded bytes, above the max-encoded-block limit of "
            . "{$limit} bytes in effect in this process. If the file is trusted and was encrypted by a process with a larger "
            . "block size, raise the limit with Security::setFileMaxEncodedBlockBytes() to at least the declared size before "
            . "decrypting; otherwise the file is corrupt or hostile."
        );
    }

    /**
     * Writes $raw as one length-encoded block: "{length}-{base64}".
     *
     * @param resource $fp File pointer opened for writing
     * @param string $raw Raw content to be encoded and written; never "" (it would read back as the
     *                    rejected "0-")
     * @param string $what Name of the block, for the error message
     *
     * @throws \Exception When the block did not reach the stream in full
     * @return void
     */
    private static function writeLengthEncodedBlock($fp, string $raw, string $what): void {
        $encoded = base64_encode($raw);

        // Two writes rather than one concatenation: no third copy of a multi-megabyte block.
        self::writeAll($fp, strlen($encoded) . "-", $what);
        self::writeAll($fp, $encoded, $what);
    }

    /**
     * Writes $data in full or throws.
     *
     * A PARTIAL write is a failure. fwrite() returns a SHORT count (or 0) rather than false on a
     * full disk or an exhausted quota; accepting it truncates the output while the caller reports
     * success. Every stream written here is a local file this class opened, so a short count is
     * never a benign "try again later".
     *
     * @param resource $fp File pointer opened for writing
     * @param string $data Bytes to write
     * @param string $what Name of what is written, for the error message
     *
     * @throws \Exception
     * @return void
     */
    private static function writeAll($fp, string $data, string $what): void {
        $length = strlen($data);
        $written = @fwrite($fp, $data);
        if ($written === false || $written !== $length) {
            throw new \Exception(
                "Error on writing {$what}: only " . (int) $written . " of {$length} bytes reached the file (disk full or quota exceeded?)."
            );
        }
    }

    /**
     * The GCM AAD of one file block: binds it to its file, the format version, its kind ("D" data,
     * "F" end marker) and its position, so reorder, duplication, cross-file splice and header
     * tamper all fail authentication. Shared by both directions so they cannot drift apart.
     *
     * @param string $version Format version, "v3" or "v2"
     * @param string $fileId Per-file id, as written in the header
     * @param string $kind "D" or "F"
     * @param int $index Position of a data block; the block count for the end marker
     *
     * @return string
     */
    private static function fileAad(string $version, string $fileId, string $kind, int $index): string {
        return $fileId . "|" . $version . "|" . $kind . "|" . $index;
    }

    /**
     * The nonce of block $index of a v3 file: the block counter as a 12-byte big-endian integer.
     * Data blocks are 0..n-1 and the end marker of an n-block file is n, so no nonce repeats within
     * a file; and every file has its own key, so no (key, nonce) pair is ever used twice.
     *
     * @param int $index Block position (the block count for the end marker)
     *
     * @return string 12 bytes
     */
    private static function fileV3Nonce(int $index): string {
        return "\0\0\0\0" . pack('J', $index);
    }

    /**
     * Encrypts one v3 block and writes it as a single length-encoded block: ciphertext || tag.
     *
     * @param resource $fp File pointer opened for writing
     * @param string $key The file's own key
     * @param string $fileId Per-file id
     * @param string $kind "D" (data) or "F" (end marker)
     * @param int $index Position of a data block; the block count for the end marker
     * @param string $plaintext Non-empty plaintext
     *
     * @throws \Exception
     * @return void
     */
    private static function writeFileV3Block($fp, #[\SensitiveParameter] string $key, string $fileId, string $kind, int $index, string $plaintext): void {
        $tag = "";
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::FILE_CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            self::fileV3Nonce($index),
            $tag,
            self::fileAad(self::FILE_VERSION, $fileId, $kind, $index),
            self::GCM_TAG_BYTES
        );

        $isData = ($kind === "D");
        if ($ciphertext === false || strlen($ciphertext) !== strlen($plaintext)) {
            throw new \Exception($isData ? "Error on creating cipher of plaintext" : "Error on creating end marker");
        }
        if (strlen($tag) !== self::GCM_TAG_BYTES) {
            throw new \Exception($isData ? "Error on validating tag length" : "Error on validating end marker tag length");
        }

        self::writeLengthEncodedBlock($fp, $ciphertext . $tag, $isData ? "ciphertext" : "end marker");
    }

    /**
     * Resolves and validates the real source file path.
     *
     * @param string $source Source file path (or the tmp_name of an upload)
     *
     * @throws \Exception When it does not exist or is not a regular file
     * @return string Returns the validated real source file path
     */
    private static function getRealSource(string $source) : string {
        // realpath("") is the current DIRECTORY, not "no file"; and a NUL byte makes realpath()
        // raise a ValueError — an \Error past every documented `catch (\Exception)`.
        $ret = ($source === "" || str_contains($source, "\0") ? false : realpath($source));
        if ($ret === false && $source !== "" && !str_contains($source, "\0") && is_uploaded_file($source)) {
            // A genuine PHP upload whose realpath() failed: keep the tmp path itself.
            $ret = $source;
        }

        if ($ret === false || $ret === "") {
            throw new \Exception("File not found: the source path does not exist.");
        }

        // realpath() resolves a DIRECTORY too. Read as a file it came back empty on POSIX, so
        // the file encryption wrote a valid ciphertext of nothing and reported success.
        if (!is_file($ret)) {
            throw new \Exception("The source is not a regular file.");
        }

        return $ret;
    }

    /**
     * Resolves and validates the destination file path for writing.
     * It extracts the file name and directory, creates a missing target directory (with
     * File::getDefaultMode() — never with the caller's FILE mode: a '0600' directory has no execute
     * bit and nothing could be created inside it), and normalizes the final path.
     *
     * @param string $destination Destination file path
     *
     * @throws \Exception
     * @return string Returns the validated full destination file path
     */
    private static function getRealDestination(string $destination) : string {
        // Checked here too, not only trusted to File::getPathInfo(): a NUL byte reaching a
        // filesystem function is a ValueError, and a blank path must never resolve to the cwd.
        if (trim($destination) === "" || str_contains($destination, "\0")) {
            throw new \Exception("Invalid destination path provided for writing!");
        }

        $destPath = File::getPathInfo($destination, keepFileNotExists: true, createPath: true);

        // Strict checks: empty() also rejected a destination file literally named "0".
        if (($destPath['path'] ?? "") === "" || ($destPath['file'] ?? "") === "") {
            throw new \Exception("Invalid destination path provided for writing!");
        }

        if (($destPath['dir'] ?? "") === "" || !is_dir($destPath['dir'])) {
            throw new \Exception("Error on creating destination path!");
        }

        return $destPath['path'];
    }

    /**
     * Rejects a file operation whose destination IS the source file.
     *
     * The destination is only replaced once the output is complete, so an in-place call would no
     * longer shred the input mid-read — but it would REPLACE the only copy of the input with the
     * output. For encryptFile that is irreversible the moment the key is mistyped or lost, and
     * for decryptFile in append mode it is meaningless. It is refused, loudly, before anything
     * is opened; write elsewhere and rename afterwards.
     *
     * Identity is decided on RESOLVED paths and on file identity, never on the raw strings:
     * './x' vs 'x', a trailing separator, '..' segments, a symlink to the source and — on Windows
     * — an 8.3 short name or a different case all name the SAME file while comparing unequal as
     * strings.
     *
     * Two checks, because neither alone is sufficient:
     *  1. Device+inode equality. This is the PRIMARY check and the only one that catches a HARD
     *     LINK or a junction: those are second, equally real names for one file, so realpath()
     *     correctly reports two different paths and no path comparison can see through them.
     *     Skipped when the platform reports no inode (ino 0) rather than guessing — an
     *     unavailable inode must not make every ordinary call fail.
     *  2. Canonical path equality (realpath), as the FALLBACK for a platform with no inodes.
     *     realpath() output is canonical but not guaranteed fully expanded (it can leave an 8.3
     *     short name in the prefix), so two of its results can differ for one file: check 1 leads.
     *
     * Case is deliberately NOT folded, on any platform: a directory can opt into case sensitivity
     * (fsutil; WSL does it by default), where 'x.txt' and 'X.TXT' are two different files. On a
     * case-insensitive volume the two spellings are one file, so realpath() returns the same
     * on-disk name for both AND they share an inode.
     *
     * @param string $source Resolved source path, as returned by getRealSource()
     * @param string $destination Resolved destination path, as returned by getRealDestination()
     *
     * @throws \Exception If the destination resolves to the same file as the source
     * @return void
     */
    private static function assertDestinationIsNotSource(string $source, string $destination): void {
        $message = "The destination must not be the same file as the source. The output replaces the "
            . "destination, so an in-place call would replace the only copy of the input; write to a "
            . "different path.";

        clearstatcache(true, $source);
        clearstatcache(true, $destination);

        // realpath() returns false for a path that does not exist — the normal case for a
        // destination. Fall back to the given path then: getRealSource()/getRealDestination() have
        // already resolved everything that CAN be resolved.
        $sourceReal = realpath($source);
        $destinationReal = realpath($destination);

        $paths = [];
        foreach ([($sourceReal === false ? $source : $sourceReal), ($destinationReal === false ? $destination : $destinationReal)] as $path) {
            // Unify the separators ONLY on Windows, where '/' and '\' are interchangeable. On
            // POSIX a backslash is an ordinary, legal character in a file name.
            if (DIRECTORY_SEPARATOR === "\\") {
                $path = str_replace("/", DIRECTORY_SEPARATOR, $path);
            }

            // Drop trailing separators ("x/" is the same file as "x") without eating a root.
            while (strlen($path) > 1 && str_ends_with($path, DIRECTORY_SEPARATOR)) {
                $path = substr($path, 0, -1);
            }

            $paths[] = $path;
        }

        if ($paths[0] === $paths[1]) {
            throw new \Exception($message);
        }

        // Distinct canonical paths can still be one file (hard link, junction). That needs both
        // sides to exist: an unresolvable destination does not exist, hence is not the source.
        if ($sourceReal === false || $destinationReal === false) {
            return;
        }

        $sourceStat = @stat($sourceReal);
        $destinationStat = @stat($destinationReal);
        if ($sourceStat === false || $destinationStat === false) {
            return;
        }

        // An inode of 0 means "not reported by this platform/filesystem", NOT "inode zero".
        if (empty($sourceStat['ino']) || empty($destinationStat['ino'])) {
            return;
        }

        if ($sourceStat['ino'] === $destinationStat['ino'] && $sourceStat['dev'] === $destinationStat['dev']) {
            throw new \Exception($message);
        }
    }

    /**
     * Parses the caller's octal permission mode BEFORE anything is touched.
     *
     * File::getPermissionMode() silently turns a malformed mode into the 0755 default — wider than
     * what a caller asking for '0600' on a decrypted secret wanted — so a malformed mode is refused
     * here instead, like File::writeFile() does.
     *
     * @param string|null $permissionMode Octal string such as "0600"; NULL = not given
     *
     * @throws \InvalidArgumentException When given but not an octal mode <= 07777
     * @return int|null
     */
    private static function parseFileMode(?string $permissionMode): ?int {
        if ($permissionMode === null) {
            return null;
        }

        $length = strlen($permissionMode);
        if ($length < 1 || $length > 5 || strspn($permissionMode, "01234567") !== $length || octdec($permissionMode) > 07777) {
            throw new \InvalidArgumentException("Invalid permission mode '{$permissionMode}': expected an octal string such as '0600'.");
        }

        return (int) octdec($permissionMode);
    }

    /**
     * Refuses to replace a READ-ONLY destination unless the caller passed a $permissionMode.
     *
     * The old code chmod()ed every existing destination to the 0755 default before writing, so a
     * read-only file was silently made writable (and executable) and overwritten, on every
     * platform. A read-only bit is taken to mean "do not overwrite" now; an explicit mode is the
     * caller saying otherwise.
     *
     * @param string $destination Resolved destination path
     * @param int|null $mode Parsed permission mode
     *
     * @throws \Exception
     * @return void
     */
    private static function assertDestinationReplaceable(string $destination, ?int $mode): void {
        clearstatcache(true, $destination);
        if ($mode === null && is_file($destination) && !is_writable($destination)) {
            throw new \Exception(
                "The destination exists and is read-only; it is only replaced when a \$permissionMode is given."
            );
        }
    }

    /**
     * Creates the staging file the file functions write into, instead of writing the destination
     * directly: a NEW, exclusively created sibling of $destination (so the final rename() stays on
     * one filesystem and is atomic, and 'x' never opens a file or symlink someone else planted).
     *
     * It is created OWNER-ONLY (umask 077) and keeps that mode while it is written. Permissions are
     * checked when a file is opened, not on each read, so a staging file created 0644 and
     * narrowed afterwards could be opened by another user in between — who would then read every
     * byte of plaintext written later. applyStagingMode() sets the final mode once it is complete.
     *
     * @param string $destination Resolved destination path
     *
     * @throws \Exception
     * @return array{0: string, 1: resource} [staging path, handle opened for writing]
     */
    private static function openStagingFile(string $destination): array {
        $directory = dirname($destination);
        $path = "";
        $fp = false;
        $oldUmask = umask(0077);
        try {
            for ($attempt = 0; $attempt < 3 && $fp === false; $attempt++) {
                $path = $directory . DIRECTORY_SEPARATOR . ".phphelper-" . bin2hex(self::secureRandomBytes(8)) . ".part";
                $fp = @fopen($path, 'xb');
                if ($fp === false && !file_exists($path)) {
                    break;
                }
            }
        } finally {
            umask($oldUmask);
        }

        if ($fp === false) {
            throw new \Exception("Error while writing to the destination file: cannot create a staging file in its directory.");
        }

        return [$path, $fp];
    }

    /**
     * Gives a COMPLETE staging file its final mode, right before it replaces the destination:
     * $mode when given; otherwise the permission bits of the destination it replaces (a 0600 file
     * must not come back 0644); otherwise what a plain new file gets (0666 & ~umask).
     *
     * @param string $stagingPath Finished, closed staging file
     * @param string $destination Resolved destination path
     * @param int|null $mode Parsed permission mode
     *
     * @throws \Exception When an explicit $mode cannot be applied
     * @return void
     */
    private static function applyStagingMode(string $stagingPath, string $destination, ?int $mode): void {
        $explicitMode = ($mode !== null);
        if (!$explicitMode) {
            clearstatcache(true, $destination);
            $existing = (is_file($destination) ? @fileperms($destination) : false);
            $mode = ($existing === false ? (0666 & ~umask()) : ($existing & 07777));
        }

        // Only a mode the CALLER asked for is fatal to miss; the fallbacks are best effort (some
        // filesystems do not model permission bits at all).
        if (!@chmod($stagingPath, $mode) && $explicitMode) {
            throw new \Exception("Error while writing to the destination file: cannot apply permission mode " . sprintf('%04o', $mode) . ".");
        }
    }

    /**
     * Flushes and closes a finished staging file.
     *
     * @param resource $fp Staging file handle; closed on return, whatever happens
     *
     * @throws \Exception
     * @return void
     */
    private static function closeStagingFile($fp): void {
        $flushed = @fflush($fp);
        // Best effort: without it a power loss right after the rename() can leave an empty file on
        // some filesystems, but not every filesystem supports it, so failing here is not fatal.
        @fsync($fp);
        $closed = @fclose($fp);

        if (!$flushed || !$closed) {
            throw new \Exception("Error while writing to the destination file: it could not be flushed to disk.");
        }
    }

    /**
     * Moves a finished staging file over $destination with one rename(): a reader, a crash or a
     * killed process sees either the previous destination or the complete new one — never a
     * partial file and never unauthenticated plaintext.
     *
     * @param string $stagingPath Finished staging file
     * @param string $destination Resolved destination path
     * @param int|null $mode Parsed permission mode
     *
     * @throws \Exception When the destination cannot be replaced; the staging file is left for the
     *                    caller to remove
     * @return void
     */
    private static function commitStagingFile(string $stagingPath, string $destination, ?int $mode): void {
        clearstatcache(true, $destination);
        if ($mode !== null && is_file($destination) && !is_writable($destination)) {
            // Windows cannot rename over a read-only file. Its replacement carries $mode anyway, so
            // granting $mode to the file about to be replaced changes nothing that survives.
            @chmod($destination, $mode);
        }

        for ($attempt = 1; ; $attempt++) {
            if (@rename($stagingPath, $destination)) {
                return;
            }
            // Windows only: a scanner or indexer can hold a freshly closed file for a moment.
            if (DIRECTORY_SEPARATOR !== "\\" || $attempt >= 5) {
                break;
            }
            usleep(20000 * $attempt);
        }

        throw new \Exception(
            "Error while writing to the destination file: the finished output could not be moved into place "
            . "(read-only, open in another process, or not writable?)."
        );
    }

    /**
     * decryptFile's append mode: copies an AUTHENTICATED staging file onto the end of
     * $destination (or moves it into place when $destination does not exist yet).
     *
     * The copy is the only step that writes into the caller's existing file, and it only starts
     * once every block has been verified. If it comes up short, the destination is truncated back
     * to the length it had, so parts appended by earlier calls survive.
     *
     * @param string $stagingPath Finished staging file
     * @param string $destination Resolved destination path
     * @param int|null $mode Parsed permission mode
     *
     * @throws \Exception
     * @return void
     */
    private static function appendStagingFile(string $stagingPath, string $destination, ?int $mode): void {
        clearstatcache(true, $destination);
        if (!is_file($destination)) {
            self::commitStagingFile($stagingPath, $destination, $mode);
            return;
        }

        if ($mode !== null) {
            @chmod($destination, $mode);
        }

        $fpDestination = @fopen($destination, 'ab');
        $fpStaging = @fopen($stagingPath, 'rb');
        if ($fpDestination === false || $fpStaging === false) {
            if ($fpDestination !== false) {
                @fclose($fpDestination);
            }
            if ($fpStaging !== false) {
                @fclose($fpStaging);
            }
            throw new \Exception("Error while writing to the destination file.");
        }

        $entryLength = (int) (fstat($fpDestination)['size'] ?? 0);
        $expected = (int) (fstat($fpStaging)['size'] ?? -1);
        $copied = @stream_copy_to_stream($fpStaging, $fpDestination);
        $complete = ($copied === $expected && @fflush($fpDestination));
        if ($complete) {
            @fsync($fpDestination);
        } else {
            @ftruncate($fpDestination, $entryLength);
        }
        @fclose($fpStaging);
        @fclose($fpDestination);

        if (!$complete) {
            throw new \Exception(
                "Error on writing plaintext: the decrypted part could not be appended in full; the destination was "
                . "truncated back to its previous length."
            );
        }

        @unlink($stagingPath);
    }

    /**
     * Returns the block size (plaintext bytes) read per iteration by encryptFile.
     *
     * When no value is set (initial state, or after setFileEncryptBlocksBytes(null)), the default
     * of 3.200.000 is installed and returned, so this never returns 0.
     *
     * @return int Always >= 1
     */
    public static function getFileEncryptBlocksBytes(): int
    {
        if (empty(self::$fileEncryptBlocksBytes)) {
            self::setFileEncryptBlocksBytes(self::DEFAULT_FILE_ENCRYPT_BLOCK_BYTES);
        }

        return self::$fileEncryptBlocksBytes;
    }

    /**
     * Sets the block size (plaintext bytes) read per iteration during file encryption.
     *
     * encryptFile holds a few copies of one block in memory at a time (plaintext, ciphertext,
     * base64), so this is also what bounds its memory use. The block size is not recorded in the
     * file: decryptFile needs no setting to match it — except that every block, ciphertext plus its
     * 16-byte tag, must fit getFileMaxEncodedBlockBytes() (4*ceil((size+16)/3) <= limit, i.e. at
     * most 201.326.576 bytes under the default limit). That is checked when encryptFile runs, not
     * here, so the two setters can be called in either order.
     *
     * Any size is safe for the cipher: v3 files number their blocks under a key of their own, so a
     * tiny block size costs space and speed, never nonce safety.
     *
     * This is PROCESS-GLOBAL static state: on a long-lived worker (FPM child, queue worker, Swoole)
     * a value set here survives until it is changed or reset, across requests/jobs.
     *
     * @param int|null $fileEncryptBlocksBytes Block size in bytes; must be >= 1. NULL resets to the
     *                                         default (a real reset, not a no-op).
     *
     * @throws \InvalidArgumentException When a non-null value <= 0 is given. It is rejected LOUDLY
     *                                   and the value in effect is kept.
     * @return void
     */
    public static function setFileEncryptBlocksBytes(?int $fileEncryptBlocksBytes): void
    {
        // NULL means "reset": 0 is the sentinel getFileEncryptBlocksBytes() treats as "unset".
        if ($fileEncryptBlocksBytes === null) {
            self::$fileEncryptBlocksBytes = 0;
            return;
        }

        if ($fileEncryptBlocksBytes <= 0) {
            throw new \InvalidArgumentException("Invalid file encryption block size: must be >= 1 byte, or null to reset to the default.");
        }

        self::$fileEncryptBlocksBytes = $fileEncryptBlocksBytes;
    }

    /**
     * Returns the largest length-encoded block (bytes, base64 as written on disk) an encrypted
     * file may contain — see setFileMaxEncodedBlockBytes().
     *
     * When no value is set (initial state, or after setFileMaxEncodedBlockBytes(null)), the default
     * of 268.435.456 (256 MiB) is installed and returned.
     *
     * @return int Always >= 44
     */
    public static function getFileMaxEncodedBlockBytes(): int
    {
        if (empty(self::$fileMaxEncodedBlockBytes)) {
            self::setFileMaxEncodedBlockBytes(self::DEFAULT_FILE_MAX_ENCODED_BLOCK_BYTES);
        }

        return self::$fileMaxEncodedBlockBytes;
    }

    /**
     * Sets the largest length-encoded block (bytes, base64 as written on disk) an encrypted file
     * may contain. It is enforced on BOTH sides:
     *  - decryptFile refuses a block that declares more, BEFORE allocating anything, so a corrupt
     *    or hostile file cannot force a huge allocation ahead of authentication. It is therefore
     *    also the largest single read decryptFile makes: keep it within memory_limit.
     *  - encryptFile refuses, before touching the destination, a configuration that could write
     *    a larger block: a plaintext block size (setFileEncryptBlocksBytes) whose block — the
     *    ciphertext plus its 16-byte tag, base64-encoded, 4*ceil((n+16)/3) bytes — or a salt whose
     *    base64 form exceeds it. The largest plaintext block a limit L allows is
     *    3*floor(L/4) - 16 bytes — 201.326.576 under the default.
     *
     * CROSS-PROCESS: the limit is not recorded in the file. A file written under a raised limit
     * must be decrypted under a limit at least as large; decryptFile's error names the size it
     * found and this setter.
     *
     * This is PROCESS-GLOBAL static state, like setFileEncryptBlocksBytes().
     *
     * @param int|null $maxEncodedBlockBytes Limit in bytes, >= 44: the encoded size of the largest
     *                                       fixed-size block every file contains (its 32-char
     *                                       file id). Below that no file — not even an empty one —
     *                                       could be written or read, so such a value is rejected
     *                                       here rather than failing on every call. NULL resets to
     *                                       the default.
     *
     * @throws \InvalidArgumentException When a non-null value below the minimum is given. The value
     *                                   in effect is kept.
     * @return void
     */
    public static function setFileMaxEncodedBlockBytes(?int $maxEncodedBlockBytes): void
    {
        if ($maxEncodedBlockBytes === null) {
            self::$fileMaxEncodedBlockBytes = 0;
            return;
        }

        $minimum = self::fileMinEncodedBlockBytes();
        if ($maxEncodedBlockBytes < $minimum) {
            throw new \InvalidArgumentException(
                "Invalid max encoded block size {$maxEncodedBlockBytes}: must be >= {$minimum} bytes (the encoded size of the "
                . "file id block every encrypted file contains), or null to reset to the default."
            );
        }

        self::$fileMaxEncodedBlockBytes = $maxEncodedBlockBytes;
    }

    /**
     * Generates a new random master key, base64-encoded for an environment variable or a secrets
     * file. Decode it with keyFromBase64() (or build a Keyring with Keyring::fromBase64()).
     *
     * This is the way to make a key. A passphrase typed by a person is accepted by every method as
     * long as it is 32 bytes, but HKDF is fast and adds no strength, so a guessable passphrase can
     * be brute-forced offline from a single ciphertext.
     *
     * @throws \Exception When no cryptographically strong RNG is available
     * @return string Base64 of 32 random bytes (44 characters)
     */
    public static function generateKey(): string {
        return base64_encode(self::secureRandomBytes(self::MIN_KEY_BYTES));
    }

    /**
     * Decodes a key made by generateKey(). Surrounding whitespace (a trailing newline from a
     * secrets file) is ignored; anything else must be strict base64 of EXACTLY 32 bytes, so a
     * truncated or mistyped variable fails here instead of silently becoming another key.
     *
     * @param string $encoded Base64-encoded key
     *
     * @throws \InvalidArgumentException When the value is not strict base64 of exactly 32 bytes
     * @return string The raw 32-byte key
     */
    public static function keyFromBase64(#[\SensitiveParameter] string $encoded): string {
        $key = base64_decode(trim($encoded), true);
        if ($key === false || strlen($key) !== self::MIN_KEY_BYTES) {
            throw new \InvalidArgumentException(
                "Invalid key: expected base64 of exactly " . self::MIN_KEY_BYTES . " bytes, as Security::generateKey() produces."
            );
        }

        return $key;
    }

    /**
     * The id of a master key: 16 hex characters derived from the key with HKDF (info "key-id").
     * It is written into every DB and local envelope, and it reveals nothing about the key.
     *
     * @param string $key Raw master key (>= 32 bytes)
     *
     * @throws \InvalidArgumentException For a key shorter than 32 bytes
     * @return string 16 lowercase hex characters
     */
    public static function keyId(#[\SensitiveParameter] string $key): string {
        self::assertKeyLength($key);

        return bin2hex(hash_hkdf('sha256', $key, self::KEY_ID_BYTES, 'key-id'));
    }

    /**
     * Builds the AAD (context) for encryptDataDB/decryptDataDB from where the value lives, in one
     * canonical, unambiguous form — hand-built strings such as "a.b:c" can collide when a name
     * contains the separator, and two code paths formatting the same cell differently can no
     * longer decrypt each other's values.
     *
     * $version is the defense against REPLAY: without it, an attacker who can write to the
     * database (but has no key) can put back an OLDER genuine value of the same cell — an old
     * balance, an old role — and it decrypts. With a version that the application increments on
     * every write, an old value no longer matches the row's current version. See SECURITY.md for
     * how to keep that version and what it does not cover.
     *
     * ```php
     * $aad = Security::dbContext('wallets', 'balance', $row['id'], $row['balance_version']);
     * ```
     *
     * @param string $table Table name (non-empty)
     * @param string $column Column name (non-empty)
     * @param string|int $rowId Primary key of the row (non-empty)
     * @param int|null $version Version of the value (>= 0), or null for a value that is not versioned
     *
     * @throws \InvalidArgumentException For an empty name or id, a negative version, or invalid UTF-8
     * @return string The AAD to pass to encryptDataDB/decryptDataDB (a JSON array)
     */
    public static function dbContext(string $table, string $column, string|int $rowId, ?int $version = null): string {
        $rowId = (string) $rowId;
        if ($table === '' || $column === '' || $rowId === '') {
            throw new \InvalidArgumentException("dbContext(): table, column and row id must be non-empty.");
        }
        if ($version !== null && $version < 0) {
            throw new \InvalidArgumentException("dbContext(): version must be >= 0; {$version} given.");
        }

        $parts = ['db', $table, $column, $rowId];
        if ($version !== null) {
            $parts[] = $version;
        }

        try {
            return json_encode($parts, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException $e) {
            throw new \InvalidArgumentException("dbContext(): names and row id must be valid UTF-8.", 0, $e);
        }
    }

    /**
     * Generates a search hash for a given string using HMAC with a derived key.
     * This function is used to create a consistent hash for search purposes, allowing for secure comparisons without exposing the original data.
     *
     * Deterministic by design (that is what makes it searchable): equal inputs give equal hashes,
     * so it reveals equality between rows. Scalars are normalized first — true/1/"1" hash alike,
     * as do false/0/"0" — and null/"" return "" without hashing. Normalize text yourself (case,
     * spaces, masks) before hashing, or equal values will not match.
     *
     * The hash is computed with the CURRENT key of a Keyring. After a key rotation, rows hashed
     * under an old key only match generateSearchHashes() until they are re-hashed.
     *
     * A blind index is not authenticated: someone who can write to the database can copy another
     * row's hash into their own row. After a lookup, decrypt the value and compare it with what
     * was searched (see SECURITY.md).
     *
     * @param mixed $str The value to hash: a string, int, float, bool, Stringable or null
     * @param string|Keyring $key Master key (>= 32 bytes) or keyring
     * @param string|null $salt (Optional) Salt value to add randomness to the derived search hash key.
     *                          NULL and "" are the same salt.
     *
     * @throws \InvalidArgumentException When the key is shorter than 32 bytes; for an array, a
     *                                   non-Stringable object or a resource. These used to be
     *                                   hashed as the literal "Array" — every array shared one
     *                                   blind index — or to raise an \Error.
     * @return string 64 lowercase hex characters, or "" for null/""
     */
    public static function generateSearchHash(#[\SensitiveParameter] mixed $str, #[\SensitiveParameter] string|Keyring $key, ?string $salt = ""): string {
        return self::searchHashUnder($str, self::keyring($key)->currentKey(), $salt, __FUNCTION__);
    }

    /**
     * The search hash of $str under EVERY key of the keyring, current key first — for lookups
     * while a key rotation is in progress: `WHERE email_hash IN (...)` finds a row whether it has
     * been re-hashed under the new key yet or not.
     *
     * @param mixed $str The value to hash (see generateSearchHash())
     * @param string|Keyring $key Master key or keyring
     * @param string|null $salt Salt, as for generateSearchHash()
     *
     * @throws \InvalidArgumentException As generateSearchHash()
     * @return array<string, string> Key id => hash; [] for null/""
     */
    public static function generateSearchHashes(#[\SensitiveParameter] mixed $str, #[\SensitiveParameter] string|Keyring $key, ?string $salt = ""): array {
        $hashes = [];
        foreach (self::keyring($key)->all() as $id => $masterKey) {
            $hash = self::searchHashUnder($str, $masterKey, $salt, __FUNCTION__);
            if ($hash === "") {
                return [];
            }
            $hashes[$id] = $hash;
        }

        return $hashes;
    }

    /**
     * generateSearchHash() under one given master key.
     *
     * @throws \InvalidArgumentException
     */
    private static function searchHashUnder(#[\SensitiveParameter] mixed $str, #[\SensitiveParameter] string $key, ?string $salt, string $method): string {
        if ($str === null || $str === "") {
            return "";
        }

        $str = self::scalarToString($str, $method);
        if ($str === "") {
            return "";
        }

        // Normalize the salt (null -> "") so a null vs "" caller cannot yield different, unstable
        // blind indexes. The blind index must be perfectly deterministic to match on lookup.
        $keySearch = self::deriveKey($key, 32, $salt, 'search-hash');

        return hash_hmac('sha256', $str, $keySearch);
    }

    /**
     * Encrypts a file with AES-256-GCM into the authenticated, chunked v3 format.
     *
     * Replaces encryptFileV2(), which was removed: the suffix named a file format, and the format
     * this method writes is v3. decryptFile() reads both v3 and the older v2 files.
     *
     * FORMAT v3 (stable). A sequence of length-encoded blocks, "{decimal length}-{base64}":
     *   header:   "aes-256-gcm", "v3", key id (keyId() of the key used), salt, file id (32 hex
     *             chars of CSPRNG output)
     *   per data block i (0-based): ciphertext || tag (16 bytes), as ONE block
     *   end marker: ciphertext of the decimal block count || tag
     * File key = HKDF-SHA256(master key, salt, info "file-v3|{file id}"): every file has its own key.
     * Nonce of block i = i as a 12-byte big-endian integer (the end marker of an n-block file uses
     * n). AAD = "{fileId}|v3|D|{i}" ("...|F|{n}" for the end marker), so a reordered, duplicated,
     * dropped, truncated or spliced block fails authentication.
     *
     * NO NONCE CAN REPEAT. v2 drew a random nonce per block under a key shared by every file with the
     * same master key and salt, which caps the safe volume at about 2^32 blocks per key (4 GiB of
     * data at 1-byte blocks). v3 gives each file its own key and numbers its blocks, so a (key,
     * nonce) pair is never used twice — by construction, at any block size and any volume. Nothing
     * per block is stored but the tag, which also makes the file smaller.
     *
     * STREAMING: the source is read one block at a time (setFileEncryptBlocksBytes, default
     * 3.200.000 bytes), so memory stays bounded by a few copies of one block whatever the file size.
     *
     * LOUD, AND BEFORE ANY SIDE EFFECT: a configuration that could write a block decryptFile would
     * refuse — see setFileMaxEncodedBlockBytes() — is refused before the destination (or its
     * directory) is touched, as are a short key, a malformed $permissionMode, a missing or
     * non-regular source, an in-place call and a read-only destination with no $permissionMode.
     *
     * ATOMIC: the output is written to a staging file next to $destination and renamed over it only
     * once it is complete. On ANY failure the staging file is removed and an existing $destination
     * is left exactly as it was. A crash leaves at most a stray ".phphelper-*.part" file. An
     * existing destination is REPLACED (a new file, not a rewrite): its permission bits are carried
     * over when $permissionMode is null, but ownership, ACLs and hard links to it are not, and a
     * destination that is a symlink is replaced by a regular file rather than written through.
     *
     * IN-PLACE IS REFUSED: $destination must not resolve to the same file as $source (see
     * assertDestinationIsNotSource): replacing the only plaintext copy with its ciphertext is
     * irreversible if the key is mistyped or lost. Encrypt to another path and rename afterwards.
     *
     * @param string $source Path to the file to be encrypted (use tmp_name if from $_FILES)
     * @param string|Keyring $key Master key (>= 32 bytes) or keyring; a keyring encrypts under its
     *                            current key, whose id goes into the header
     * @param string $destination Path where the encrypted file should be saved. MUST NOT be $source.
     *                            A missing directory is created with File::getDefaultMode().
     * @param string|null $salt Optional salt for key derivation. It is stored in the file header —
     *                          decryptFile needs no salt argument — so it separates keys, it is not
     *                          a secret. NULL and "" are stored as "?".
     * @param string|null $permissionMode Octal mode for the destination file (e.g. "0600"). The
     *                                    output is written owner-only and given this mode once it
     *                                    is complete, just before it replaces the destination. NULL:
     *                                    a new file gets the platform default (0666 & ~umask), a
     *                                    replaced one keeps its previous bits.
     *                                    A malformed mode throws \InvalidArgumentException. It is
     *                                    never used for the directory.
     *
     * @return string Returns the resolved path of the encrypted file
     * @throws \Exception If $destination is the same file as $source, on an over-limit block or
     *                    salt, or if encryption or file handling fails. An \Error raised midway is
     *                    rethrown wrapped in an \Exception, after the cleanup.
     */
    public static function encryptFile(string $source, #[\SensitiveParameter] string|Keyring $key, string $destination, ?string $salt = null, ?string $permissionMode = null): string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        $mode = self::parseFileMode($permissionMode);

        // "" would be written as the "0-" block the reader rejects, so "no salt" is stored as "?".
        $salt = (($salt ?? "") === "" ? "?" : $salt);
        $keys = self::keyring($key);

        // Random per-file identity: it selects the file's own key and is bound into every block's
        // AAD. Drawn (and the key derived) before any path is resolved, so a missing RNG or a bad
        // key fails before the destination directory can be created.
        $fileId = bin2hex(self::secureRandomBytes(self::FILE_ID_BYTES));
        $fileKey = self::deriveKey($keys->currentKey(), 32, $salt, "file-v3|" . $fileId);

        $blockBytes = self::getFileEncryptBlocksBytes();
        self::assertFileBlocksFitLimit($blockBytes, $salt);

        $source = self::getRealSource($source);
        $destination = self::getRealDestination($destination);
        self::assertDestinationIsNotSource($source, $destination);
        self::assertDestinationReplaceable($destination, $mode);

        // The source is opened BEFORE anything is created: an unreadable source used to be
        // discovered only after the destination had been truncated.
        $fpIn = @fopen($source, 'rb');
        if ($fpIn === false) {
            throw new \Exception("Error on opening source file stream");
        }

        $stagingPath = null;
        $fpOut = false;
        try {
            [$stagingPath, $fpOut] = self::openStagingFile($destination);

            // The header is not MAC'd on its own: the key id selects the master key, and the salt
            // and file id select the file key, so tampering with any of them fails every block.
            self::writeLengthEncodedBlock($fpOut, self::FILE_CIPHER, "cipher type");
            self::writeLengthEncodedBlock($fpOut, self::FILE_VERSION, "cipher version");
            self::writeLengthEncodedBlock($fpOut, $keys->currentId(), "key id");
            self::writeLengthEncodedBlock($fpOut, $salt, "cipher salt");
            self::writeLengthEncodedBlock($fpOut, $fileId, "file id");

            $stat = fstat($fpIn);
            $sizeAtOpen = ($stat === false ? null : $stat['size']);
            $bytesRead = 0;
            $index = 0;
            while (true) {
                // fread() allocates its full length up front: never ask for more than is left, so
                // a large block size costs nothing on a small file. Past the size seen at open,
                // small reads detect the end (or a file that grew meanwhile).
                $remaining = ($sizeAtOpen === null ? $blockBytes : $sizeAtOpen - $bytesRead);
                $plaintext = @fread($fpIn, ($remaining > 0 ? min($blockBytes, $remaining) : min($blockBytes, 8192)));
                if ($plaintext === false) {
                    throw new \Exception("Error on reading plaintext");
                }
                if ($plaintext === "") {
                    break;
                }
                $bytesRead += strlen($plaintext);

                self::writeFileV3Block($fpOut, $fileKey, $fileId, "D", $index, $plaintext);
                $index++;
            }

            // Some read failures look like a plain end of file. Encrypting what was read so far
            // would produce a VALID ciphertext of a truncated file — silent data loss.
            if ($sizeAtOpen !== null && $bytesRead < $sizeAtOpen) {
                throw new \Exception("Error on reading plaintext: only {$bytesRead} of {$sizeAtOpen} bytes could be read.");
            }

            // Authenticated end marker: the block count under AAD "...|F|<count>", so dropping
            // trailing blocks or the marker itself is detected on decrypt.
            self::writeFileV3Block($fpOut, $fileKey, $fileId, "F", $index, (string) $index);

            $handle = $fpOut;
            $fpOut = false;
            self::closeStagingFile($handle);
            self::applyStagingMode($stagingPath, $destination, $mode);

            self::commitStagingFile($stagingPath, $destination, $mode);
            $stagingPath = null;
        } catch (\Throwable $e) {
            if (is_resource($fpOut)) {
                @fclose($fpOut);
            }
            if ($stagingPath !== null) {
                @unlink($stagingPath);
            }

            // One failure channel, as documented: an \Error is wrapped, keeping it as getPrevious().
            throw ($e instanceof \Exception ? $e : new \Exception($e->getMessage(), 0, $e));
        } finally {
            if (is_resource($fpIn)) {
                @fclose($fpIn);
            }
        }

        // Returns the path of the encrypted file
        return $destination;
    }

    /**
     * Decrypts a file produced by encryptFile (format v3) or by the former encryptFileV2 (format
     * v2), verifying the GCM tag of every block. Replaces decryptFileV2(), which was removed along
     * with encryptFileV2().
     *
     * FAILS LOUD — this function NEVER returns false. Every failure mode (unreadable source,
     * unresolvable destination, a key the keyring does not hold, wrong key, tampered/reordered/
     * spliced block, truncated file with no authenticated end marker, trailing data, a block over
     * getFileMaxEncodedBlockBytes(), an unknown format version, missing OpenSSL) throws \Exception;
     * an \Error raised midway is wrapped in one. Callers MUST try/catch.
     *
     * The \Exception messages are raw internal diagnostics ("Encrypted file is truncated ...").
     * They are for logs — do NOT render them to end users.
     *
     * NO UNAUTHENTICATED PLAINTEXT EVER REACHES $destination. The plaintext is written to a staging
     * file next to it and only moved into place ("w") or appended ("a") once the whole file —
     * end marker included — has been verified. On any failure the staging file is removed and
     * $destination is left exactly as it was, in both modes. The one exception: if appending the
     * VERIFIED plaintext itself fails (disk full), the destination is truncated back to its
     * previous length, so parts appended by earlier calls survive. Append mode therefore needs room
     * for the plaintext twice while it runs.
     *
     * An existing destination is REPLACED in "w" mode (a new file, not a rewrite): its permission
     * bits are carried over when $permissionMode is null, but ownership, ACLs and hard links are
     * not, and a destination that is a symlink is replaced rather than written through.
     *
     * CROSS-PROCESS LIMIT: a file written under a raised setFileMaxEncodedBlockBytes() can only be
     * decrypted under a limit at least as large; the error names the size and the setter.
     *
     * IN-PLACE IS REFUSED. $destination must not resolve to the same file as $source (see
     * assertDestinationIsNotSource). Decrypt to a different path and rename afterwards if needed.
     *
     * @param string $source Path to the file to be decrypted (use tmp_name when from $_FILES)
     * @param string|Keyring $key The master key the file was encrypted under, or a keyring holding
     *                            it. A v3 file names its key in the header; a v2 file does not, so
     *                            each key of the keyring is tried on its first block. The salt is
     *                            read from the file header.
     * @param string $destination Path where the decrypted file should be saved. MUST NOT be $source.
     *                            A missing directory is created with File::getDefaultMode().
     * @param string|null $permissionMode Octal mode for the destination file (e.g. "0600"). The
     *                                    plaintext is written owner-only and given this mode once
     *                                    it is verified, just before it reaches the destination.
     *                                    NULL: a new file gets the platform default (0666 & ~umask),
     *                                    a replaced one keeps its previous bits, and a READ-ONLY
     *                                    existing destination is refused. In append mode an
     *                                    existing destination is chmod()ed to the given mode. A
     *                                    malformed mode throws \InvalidArgumentException.
     * @param string $outReadMode How the destination is written. Accepts exactly "w"/"wb" (replace,
     *                            the default) or "a"/"ab" (append — for reassembling a multi-part
     *                            payload into one destination). Any other value THROWS.
     *
     * @return string Returns the resolved path of the decrypted file
     * @throws \Exception If $destination is the same file as $source, on an unknown $outReadMode,
     *                    or on any source/destination resolution, key, authentication, truncation
     *                    or tamper failure.
     */
    public static function decryptFile(string $source, #[\SensitiveParameter] string|Keyring $key, string $destination, ?string $permissionMode = null, string $outReadMode = "w"): string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        // An unrecognized mode is REJECTED, never silently rewritten: the old code mapped the
        // documented "a" onto "wb" and truncated the destination a caller had asked to append to.
        $appendMode = in_array($outReadMode, array('a', 'ab'), true);
        if (!$appendMode && !in_array($outReadMode, array('w', 'wb'), true)) {
            throw new \Exception("Invalid \$outReadMode '{$outReadMode}': expected 'w'/'wb' (truncate) or 'a'/'ab' (append).");
        }

        // Everything that needs no file is validated before the destination directory can be
        // created by getRealDestination().
        $mode = self::parseFileMode($permissionMode);
        $keys = self::keyring($key);

        $source = self::getRealSource($source);
        $destination = self::getRealDestination($destination);
        self::assertDestinationIsNotSource($source, $destination);
        self::assertDestinationReplaceable($destination, $mode);

        $fpIn = @fopen($source, 'rb');
        if ($fpIn === false) {
            throw new \Exception("Error while reading the source file.");
        }

        $stagingPath = null;
        $fpOut = false;
        try {
            $fCipher = self::readRequiredLengthEncodedBlock($fpIn, "cipher type");
            if ($fCipher !== self::FILE_CIPHER) {
                throw new \Exception("Cipher type does not match with the one used in function");
            }

            // The rest of the header depends on the format; each reader validates it in full and
            // resolves the key before anything is created next to the destination.
            $fVersion = self::readRequiredLengthEncodedBlock($fpIn, "cipher version");
            $decryptBody = match ($fVersion) {
                self::FILE_VERSION => self::fileV3BodyDecryptor($fpIn, $keys),
                self::FILE_V2_VERSION => self::fileV2BodyDecryptor($fpIn, $keys),
                default => throw new \Exception("Cipher version does not match with the one used in function (unsupported file format version)."),
            };

            [$stagingPath, $fpOut] = self::openStagingFile($destination);
            $decryptBody($fpOut);

            // The end marker must be the last block: reject any trailing/spliced data. A read error
            // here (false) cannot prove there is none, so it fails closed as well.
            if (@fread($fpIn, 1) !== "") {
                throw new \Exception("Trailing data after end-of-file marker");
            }

            $handle = $fpOut;
            $fpOut = false;
            self::closeStagingFile($handle);
            self::applyStagingMode($stagingPath, $destination, $mode);

            if ($appendMode) {
                self::appendStagingFile($stagingPath, $destination, $mode);
            } else {
                self::commitStagingFile($stagingPath, $destination, $mode);
            }
            $stagingPath = null;
        } catch (\Throwable $e) {
            if (is_resource($fpOut)) {
                @fclose($fpOut);
            }
            if ($stagingPath !== null) {
                @unlink($stagingPath);
            }

            // One failure channel, as documented: an \Error is wrapped, keeping it as getPrevious().
            throw ($e instanceof \Exception ? $e : new \Exception($e->getMessage(), 0, $e));
        } finally {
            if (is_resource($fpIn)) {
                @fclose($fpIn);
            }
        }

        // Returns the path of the decrypted file
        return $destination;
    }

    /**
     * The id (keyId()) of the key an encrypted file was written under, read from its header without
     * decrypting anything — for a key rotation: re-encrypt the files whose id is not the keyring's
     * current one.
     *
     * The header is not authenticated on its own, so this is an index, not a proof: decryptFile
     * still verifies every block under the key the id names.
     *
     * @param string $path Encrypted file
     *
     * @throws \Exception When the file cannot be read or is not an encrypted file
     * @return string|null The key id of a v3 file; null for a legacy v2 file, which records none
     */
    public static function fileKeyId(string $path): ?string {
        $source = self::getRealSource($path);
        $fp = @fopen($source, 'rb');
        if ($fp === false) {
            throw new \Exception("Error while reading the source file.");
        }

        try {
            if (self::readRequiredLengthEncodedBlock($fp, "cipher type") !== self::FILE_CIPHER) {
                throw new \Exception("Cipher type does not match with the one used in function");
            }

            $version = self::readRequiredLengthEncodedBlock($fp, "cipher version");
            if ($version === self::FILE_V2_VERSION) {
                return null;
            }
            if ($version !== self::FILE_VERSION) {
                throw new \Exception("Cipher version does not match with the one used in function (unsupported file format version).");
            }

            $keyId = self::readRequiredLengthEncodedBlock($fp, "key id");
            if (!preg_match('/\A[0-9a-f]{' . (2 * self::KEY_ID_BYTES) . '}\z/', $keyId)) {
                throw new \Exception("Error on reading key id: malformed key id.");
            }

            return $keyId;
        } finally {
            fclose($fp);
        }
    }

    /**
     * Reads the rest of a v3 header (key id, salt, file id) and returns the function that decrypts
     * the blocks that follow into a stream, verifying each one and the end marker.
     *
     * @param resource $fpIn Source, positioned after the version block
     * @param Keyring $keys Keys to resolve the header's key id against
     *
     * @throws \Exception On a malformed header or a key id the keyring does not hold
     * @return \Closure(resource): void
     */
    private static function fileV3BodyDecryptor($fpIn, Keyring $keys): \Closure {
        $keyId = self::readRequiredLengthEncodedBlock($fpIn, "key id");
        if (!preg_match('/\A[0-9a-f]{' . (2 * self::KEY_ID_BYTES) . '}\z/', $keyId)) {
            throw new \Exception("Error on reading key id: malformed key id.");
        }
        $salt = self::readRequiredLengthEncodedBlock($fpIn, "cipher salt");
        $fileId = self::readRequiredLengthEncodedBlock($fpIn, "file id");
        if (!preg_match('/\A[0-9a-f]{' . (2 * self::FILE_ID_BYTES) . '}\z/', $fileId)) {
            throw new \Exception("Error on reading file id: malformed file id.");
        }

        $masterKey = $keys->get($keyId);
        if ($masterKey === null) {
            throw new \Exception(
                "No key with id {$keyId} in the keyring: the file was encrypted under a key that was not passed. "
                . "After a key rotation, pass a Keyring that still holds the old key as a previous key."
            );
        }
        $fileKey = self::deriveKey($masterKey, 32, $salt, "file-v3|" . $fileId);

        return static function ($fpOut) use ($fpIn, $fileKey, $fileId): void {
            for ($index = 0; ; $index++) {
                $block = self::readLengthEncodedBlock($fpIn, "block {$index}");
                if ($block === null) {
                    throw new \Exception("Encrypted file is truncated (missing authenticated end marker)");
                }
                // Every block holds at least one byte of ciphertext (a data block is never empty,
                // the end marker holds at least one digit) followed by its tag.
                if (strlen($block) <= self::GCM_TAG_BYTES) {
                    throw new \Exception("Error on validating block {$index}: shorter than ciphertext plus tag.");
                }

                $ciphertext = substr($block, 0, -self::GCM_TAG_BYTES);
                $tag = substr($block, -self::GCM_TAG_BYTES);
                $nonce = self::fileV3Nonce($index);
                $block = null;

                // Try to authenticate it as the DATA block at the expected position.
                $plaintext = openssl_decrypt($ciphertext, self::FILE_CIPHER, $fileKey, OPENSSL_RAW_DATA, $nonce, $tag, self::fileAad(self::FILE_VERSION, $fileId, "D", $index));
                if ($plaintext !== false) {
                    self::writeAll($fpOut, $plaintext, "plaintext");
                    continue;
                }

                // Otherwise it must be the authenticated end marker for exactly $index blocks.
                $count = openssl_decrypt($ciphertext, self::FILE_CIPHER, $fileKey, OPENSSL_RAW_DATA, $nonce, $tag, self::fileAad(self::FILE_VERSION, $fileId, "F", $index));
                if ($count === false || $count !== (string) $index) {
                    throw new \Exception(
                        "Error on creating plaintext of a ciphertext: block {$index} failed authentication "
                        . "(the file was tampered with, reordered, spliced or truncated)."
                    );
                }

                return;
            }
        };
    }

    /**
     * Reads the rest of a LEGACY v2 header (salt, file id) and returns the function that decrypts
     * the [iv][tag][ciphertext] triples that follow into a stream. v2 files carry no key id, so the
     * first triple decides which key of the keyring is the file's.
     *
     * @param resource $fpIn Source, positioned after the version block
     * @param Keyring $keys Keys to try
     *
     * @throws \Exception On a malformed header
     * @return \Closure(resource): void
     */
    private static function fileV2BodyDecryptor($fpIn, Keyring $keys): \Closure {
        $salt = self::readRequiredLengthEncodedBlock($fpIn, "cipher salt");
        $fileId = self::readRequiredLengthEncodedBlock($fpIn, "file id");

        // One candidate per key of the keyring; the first block decides which one is right.
        $candidates = [];
        foreach ($keys->all() as $masterKey) {
            $candidates[] = self::deriveKey($masterKey, 32, $salt, 'file-v2');
        }

        return static function ($fpOut) use ($fpIn, $candidates, $fileId): void {
            $key = null;
            for ($index = 0; ; $index++) {
                $iv = self::readLengthEncodedBlock($fpIn, "IV ciphertext");
                if ($iv === null) {
                    throw new \Exception("Encrypted file is truncated (missing authenticated end marker)");
                }
                if (strlen($iv) !== self::GCM_IV_BYTES) {
                    throw new \Exception("Error on validating iv length");
                }

                $tag = self::readRequiredLengthEncodedBlock($fpIn, "tag ciphertext");
                if (strlen($tag) !== self::GCM_TAG_BYTES) {
                    throw new \Exception("Error on validating tag length");
                }

                $ciphertext = self::readRequiredLengthEncodedBlock($fpIn, "ciphertext");

                // The first block is either data block 0 or, for an empty file, the end marker:
                // the key it authenticates under is the file's key.
                if ($key === null) {
                    foreach ($candidates as $candidate) {
                        if (openssl_decrypt($ciphertext, self::FILE_CIPHER, $candidate, OPENSSL_RAW_DATA, $iv, $tag, self::fileAad(self::FILE_V2_VERSION, $fileId, "D", 0)) !== false
                            || openssl_decrypt($ciphertext, self::FILE_CIPHER, $candidate, OPENSSL_RAW_DATA, $iv, $tag, self::fileAad(self::FILE_V2_VERSION, $fileId, "F", 0)) !== false) {
                            $key = $candidate;
                            break;
                        }
                    }
                    if ($key === null) {
                        throw new \Exception(
                            "Error on creating plaintext of a ciphertext: block 0 failed authentication under every key "
                            . "(wrong key, or the file was tampered with, reordered, spliced or truncated)."
                        );
                    }
                }

                // Try to authenticate it as the DATA block at the expected position.
                $plaintext = openssl_decrypt($ciphertext, self::FILE_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::fileAad(self::FILE_V2_VERSION, $fileId, "D", $index));
                if ($plaintext !== false) {
                    self::writeAll($fpOut, $plaintext, "plaintext");
                    continue;
                }

                // Otherwise it must be the authenticated end marker for exactly $index blocks.
                $count = openssl_decrypt($ciphertext, self::FILE_CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, self::fileAad(self::FILE_V2_VERSION, $fileId, "F", $index));
                if ($count === false || $count !== (string) $index) {
                    throw new \Exception(
                        "Error on creating plaintext of a ciphertext: block {$index} failed authentication "
                        . "(wrong key, or the file was tampered with, reordered, spliced or truncated)."
                    );
                }

                return;
            }
        };
    }

    /**
     * Encrypts a value for storage with AES-256-GCM, binding it to a caller-supplied context
     * (AAD) so a ciphertext cannot be relocated to another cell/row and still decrypt.
     *
     * Output is a self-describing envelope: "v2:{key id}:" . base64(iv || tag || ciphertext). The
     * version, the key id (see keyId()) AND the caller's $aad are all fed as GCM Additional
     * Authenticated Data, so a value cannot be reinterpreted under another version, key, table,
     * column, or row. The key id is what lets decryptDataDB() pick the right key of a Keyring after
     * a rotation, and what a migration uses to find the values still under an old key.
     *
     * EMPTY IS A VALUE: "" is encrypted like any other string, so a blank column is never a
     * legitimate ciphertext — decryptDataDB("") throws, and a value someone blanked out is
     * detected. Only null means "no value" (null in, null out).
     *
     * @param mixed $str The value to encrypt: a string, int, float, bool (stored as "1"/"0"),
     *                   Stringable or null. Decryption always returns a string — the original type
     *                   is not recorded.
     * @param string|Keyring $key Master key (>= 32 bytes) or keyring; a keyring encrypts under its
     *                            current key
     * @param string $aad Context to bind — build it with dbContext() (table, column, row id and,
     *                    against replay, a version). REQUIRED; an empty AAD is rejected.
     * @param string|null $salt Optional per-subject salt for key derivation
     *
     * @return string|null The envelope, or null for null
     * @throws \InvalidArgumentException For an array, a non-Stringable object or a resource — an
     *                                   array used to be stored as the literal "Array" — or a key
     *                                   shorter than 32 bytes.
     * @throws \Exception For an empty AAD, or when encryption fails
     */
    public static function encryptDataDB(#[\SensitiveParameter] mixed $str, #[\SensitiveParameter] string|Keyring $key, string $aad, ?string $salt = ""): ?string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        if ($str === null) {
            return null;
        }
        $str = self::scalarToString($str, __FUNCTION__);

        // A missing context defeats the whole point of the AAD binding.
        if ($aad === "") {
            throw new \Exception("A non-empty AAD (value context) is required for encryptDataDB.");
        }

        $keys = self::keyring($key);
        $keyId = $keys->currentId();

        // Domain-separated 256-bit key (distinct from the file/local subsystems).
        $derived = self::deriveKey($keys->currentKey(), 32, $salt, 'db-cell');

        // Fresh CSPRNG nonce; fails closed if no strong RNG is available.
        $iv = self::secureRandomBytes(self::GCM_IV_BYTES);

        $ciphertext = openssl_encrypt(
            $str,
            'aes-256-gcm',
            $derived,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::dbAad($keyId, $aad),
            self::GCM_TAG_BYTES
        );

        if ($ciphertext === false) {
            throw new \Exception("Encryption failed.");
        }
        if (strlen($tag) !== self::GCM_TAG_BYTES) {
            throw new \Exception("Invalid authentication tag length.");
        }

        return self::DB_ENVELOPE_VERSION . ":" . $keyId . ":" . base64_encode($iv . $tag . $ciphertext);
    }

    /**
     * Decrypts a value produced by encryptDataDB, verifying the GCM tag over the SAME context
     * (AAD). A tampered value, a wrong context (relocated ciphertext), an unknown version, a key id
     * the keyring does not hold, a wrong key or a BLANK value THROWS — it never returns something
     * a caller could mistake for success.
     *
     * @param string|null $str Envelope produced by encryptDataDB, or null
     * @param string|Keyring $key The master key, or a keyring holding the key the value was written
     *                            under (its id is read from the envelope)
     * @param string $aad The identical context passed to encryptDataDB
     * @param string|null $salt The identical salt passed to encryptDataDB
     *
     * @return string|null Decrypted text, or null for null
     * @throws \Exception On an empty value, or a decode / version / key id / authentication failure
     */
    public static function decryptDataDB(?string $str, #[\SensitiveParameter] string|Keyring $key, string $aad, ?string $salt = ""): ?string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        if ($str === null) {
            return null;
        }
        if ($str === "") {
            throw new \Exception(
                "Empty value: encryptDataDB never produces \"\" (an empty string is encrypted too), so this value was blanked or never encrypted."
            );
        }

        if ($aad === "") {
            throw new \Exception("A non-empty AAD (value context) is required for decryptDataDB.");
        }

        [$keyId, $masterKey, $payload] = self::openEnvelope($str, self::DB_ENVELOPE_VERSION, self::keyring($key));

        // Native strict decode: encryptDataDB emits plain base64 and nothing else, and the GCM tag
        // authenticates the bytes, so no data-URI or other leniency is wanted here.
        $decoded = base64_decode($payload, true);
        if ($decoded === false) {
            throw new \Exception("Failed to decode the secret message. Invalid base64.");
        }

        // An empty plaintext has an empty ciphertext: IV + tag is the minimum.
        if (strlen($decoded) < self::GCM_IV_BYTES + self::GCM_TAG_BYTES) {
            throw new \Exception("Encrypted payload is too short.");
        }

        $plaintext = openssl_decrypt(
            substr($decoded, self::GCM_IV_BYTES + self::GCM_TAG_BYTES),
            'aes-256-gcm',
            self::deriveKey($masterKey, 32, $salt, 'db-cell'),
            OPENSSL_RAW_DATA,
            substr($decoded, 0, self::GCM_IV_BYTES),
            substr($decoded, self::GCM_IV_BYTES, self::GCM_TAG_BYTES),
            self::dbAad($keyId, $aad)
        );

        // GCM authentication failure (tamper / wrong context / wrong key) => fail LOUD.
        if ($plaintext === false) {
            throw new \Exception("Decryption failed: authentication tag mismatch.");
        }

        return $plaintext;
    }

    /**
     * Decrypts a value and encrypts it again under the CURRENT key and, optionally, a new context.
     * The two jobs it is for:
     *  - a KEY ROTATION: re-encrypt values still written under an old key (their envelope does not
     *    start with "v2:{$keyring->currentId()}:") with a Keyring holding the new and old keys;
     *  - a VERSION BUMP: a value whose AAD includes a version (see dbContext()) must be encrypted
     *    again under the new version when the row is written.
     *
     * @param string|null $str Envelope produced by encryptDataDB, or null
     * @param string|Keyring $key Keyring (or key) that can decrypt it; the result uses its current key
     * @param string $aad Context the value is bound to now
     * @param string|null $newAad Context to bind it to instead (null: keep $aad)
     * @param string|null $salt Salt, as for encryptDataDB
     *
     * @return string|null The new envelope, or null for null
     * @throws \Exception As decryptDataDB()/encryptDataDB()
     */
    public static function reencryptDataDB(?string $str, #[\SensitiveParameter] string|Keyring $key, string $aad, ?string $newAad = null, ?string $salt = ""): ?string {
        $keys = self::keyring($key);

        return self::encryptDataDB(self::decryptDataDB($str, $keys, $aad, $salt), $keys, $newAad ?? $aad, $salt);
    }

    /**
     * GCM AAD of the DB envelope: version, key id and the caller's context. The version and the key
     * id have a fixed format (no "|"), so the concatenation is unambiguous.
     */
    private static function dbAad(string $keyId, string $aad): string {
        return self::DB_ENVELOPE_VERSION . "|" . $keyId . "|" . $aad;
    }

    /**
     * Encrypts a string using AES-256-CTR with authentication (MAC).
     *
     * It derives two keys from the provided master key:
     * - One for encryption (encKey)
     * - One for message authentication (authKey)
     *
     * Output: "l1:{key id}:" . base64(MAC || IV || ciphertext). The HMAC-SHA256 covers the prefix
     * (version and key id) as well as the IV and ciphertext.
     *
     * NOT BOUND TO A CONTEXT: the value decrypts wherever it is pasted under the same key and salt.
     * Use encryptDataDB() with an AAD when a value must not be movable.
     *
     * EMPTY IS A VALUE, as for encryptDataDB(): "" is encrypted, decryptLocal("") throws, and only
     * null means "no value".
     *
     * @param mixed $str The plaintext to encrypt: a string, int, float, bool (as "1"/"0"),
     *                   Stringable or null. Decryption returns a string.
     * @param string|Keyring $key Master key of AT LEAST 32 bytes, or a keyring (its current key is
     *                            used). Shorter keys are REJECTED, including 16..31-byte ones: HKDF
     *                            cannot add entropy, so a sub-32-byte master would never reach real
     *                            256-bit strength.
     * @param string|null $salt Optional salt for key derivation
     *
     * @return string|null The envelope, or null for null
     * @throws \InvalidArgumentException For an array, a non-Stringable object or a resource — an
     *                                   array used to be encrypted as the literal "Array" — or a
     *                                   key shorter than 32 bytes.
     * @throws \Exception If secure random bytes can't be generated or encryption fails
     *
     * @link https://stackoverflow.com/questions/9262109/simplest-two-way-encryption-using-php
     */
    public static function encryptLocal(#[\SensitiveParameter] mixed $str, #[\SensitiveParameter] string|Keyring $key, ?string $salt = ""): ?string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        if ($str === null) {
            return null;
        }
        $str = self::scalarToString($str, __FUNCTION__);

        $keys = self::keyring($key);
        [$encKey, $authKey] = self::localKeys($keys->currentKey(), $salt);

        // Fresh CSPRNG nonce; fails closed if no strong RNG is available.
        $nonce = self::secureRandomBytes(openssl_cipher_iv_length('aes-256-ctr'));

        $encryptedData = openssl_encrypt($str, 'aes-256-ctr', $encKey, OPENSSL_RAW_DATA, $nonce);
        if ($encryptedData === false) {
            throw new \Exception("Encryption failed.");
        }

        $prefix = self::LOCAL_ENVELOPE_VERSION . ":" . $keys->currentId() . ":";
        $payload = $nonce . $encryptedData;
        $mac = hash_hmac("sha256", $prefix . $payload, $authKey, true);

        return $prefix . base64_encode($mac . $payload);
    }

    /**
     * Decrypts a value produced by encryptLocal, verifying its HMAC (constant-time) before
     * decrypting. Every failure THROWS, including a blank value.
     *
     * @param string|null $str The envelope, or null
     * @param string|Keyring $key The master key passed to encryptLocal, or a keyring holding it (its
     *                            id is read from the envelope). Shorter keys are REJECTED.
     * @param string|null $salt Optional salt for key derivation
     *
     * @return string|null Decrypted string, or null for null
     * @throws \Exception On an empty value, a malformed envelope, an unknown key id, invalid Base64
     *                    or a MAC mismatch
     *
     * @link https://stackoverflow.com/questions/9262109/simplest-two-way-encryption-using-php
     */
    public static function decryptLocal(?string $str, #[\SensitiveParameter] string|Keyring $key, ?string $salt = ""): ?string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }

        if ($str === null) {
            return null;
        }
        if ($str === "") {
            throw new \Exception(
                "Empty value: encryptLocal never produces \"\" (an empty string is encrypted too), so this value was blanked or never encrypted."
            );
        }

        [$keyId, $masterKey, $encoded] = self::openEnvelope($str, self::LOCAL_ENVELOPE_VERSION, self::keyring($key));
        [$encKey, $authKey] = self::localKeys($masterKey, $salt);

        // Native strict decode: encryptLocal emits plain base64, and the MAC authenticates the bytes.
        $decoded = base64_decode($encoded, true);
        if ($decoded === false) {
            throw new \Exception("Failed to decode encrypted message. Invalid Base64.");
        }

        $ivLength = openssl_cipher_iv_length('aes-256-ctr');
        $macSize = 32;

        // An empty plaintext has an empty ciphertext: MAC + IV is the minimum.
        if (strlen($decoded) < $macSize + $ivLength) {
            throw new \Exception("Encrypted payload is too short.");
        }

        $mac = substr($decoded, 0, $macSize);
        $payload = substr($decoded, $macSize);

        $prefix = self::LOCAL_ENVELOPE_VERSION . ":" . $keyId . ":";
        if (!hash_equals(hash_hmac("sha256", $prefix . $payload, $authKey, true), $mac)) {
            throw new \Exception("Provided MAC does not match the calculated MAC.");
        }

        $plaintext = openssl_decrypt(substr($payload, $ivLength), 'aes-256-ctr', $encKey, OPENSSL_RAW_DATA, substr($payload, 0, $ivLength));
        if ($plaintext === false) {
            throw new \Exception("Decryption failed.");
        }

        return $plaintext;
    }

    /**
     * Encryption and MAC keys of the local envelope, both derived from the master key and salt.
     *
     * @return array{0: string, 1: string} [encryption key, authentication key]
     */
    private static function localKeys(#[\SensitiveParameter] string $masterKey, ?string $salt): array {
        // The 32-byte floor is enforced once, by deriveKey (see MIN_KEY_BYTES).
        $key = self::deriveKey($masterKey, 32, $salt, 'local');

        return [
            hash_hkdf("sha256", $key, 32, "local-encryption"),
            hash_hkdf("sha256", $key, 32, "local-authentication"),
        ];
    }

    /**
     * Encrypts a value in the aes-bridge GCM format, so it can be decrypted on other platforms.
     *
     * INTEROPERABILITY — READ THIS, the peer must replicate the key step exactly. The value handed
     * to aes-bridge as its PASSPHRASE is not your master key but
     *     HKDF-SHA256(ikm = master key, salt = $salt ("" if null), info = "derived-key", length = 32)
     * as 32 RAW BYTES (binary — not hex, not UTF-8 text). aes-bridge then runs its own
     * PBKDF2-SHA256 (100.000 iterations, random 16-byte salt) on that passphrase. The output is
     * base64(salt[16] || nonce[12] || ciphertext || tag[16]) — aes-bridge's GCM format — so any
     * aes-bridge GCM implementation that accepts a BINARY passphrase can decrypt it once it has
     * derived the same 32 bytes. Passing the master key to aes-bridge directly will NOT work.
     *
     * The format is aes-bridge's, so it carries NO key id: decryptCrossPlatform() tries each key
     * of a keyring in turn (one PBKDF2 run per key tried).
     *
     * Booleans are encoded as the marker strings "{{!BOOL_TRUE!}}" / "{{!BOOL_FALSE!}}" (a
     * non-PHP peer sees those strings), and decryptCrossPlatform turns those exact strings back
     * into booleans — so encrypting the literal string "{{!BOOL_TRUE!}}" decrypts to true.
     * Ints/floats are encrypted as their string form and come back as strings.
     *
     * EMPTY IS A VALUE: "" is encrypted, and only null means "no value" (null in, null out).
     *
     * @param mixed $var Value to be encrypted: string, int, float, bool, Stringable or null.
     * @param string|Keyring $key Master key of AT LEAST 32 bytes, or a keyring (its current key is
     *                            used). Shorter keys are REJECTED (HKDF cannot add entropy).
     * @param string|null $salt Salt for key derivation. NULL and "" are the same salt.
     *
     * @return string|null The base64 value, or null for null
     * @throws \InvalidArgumentException For an array, a non-Stringable object or a resource (these
     *                                   used to escape as a \TypeError from aes-bridge), or a key
     *                                   shorter than 32 bytes
     * @throws \Exception
     *
     * @ref https://github.com/mervick/aes-bridge-php
     */
    public static function encryptCrossPlatform(#[\SensitiveParameter] mixed $var, #[\SensitiveParameter] string|Keyring $key, ?string $salt = ""): ?string {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }
        if (!class_exists(\AesBridge\Gcm::class)) {
            throw new \Exception("Class '\AesBridge\Gcm' not found");
        }

        if ($var === null) {
            return null;
        }

        if ($var === true) {
            $var = "{{!BOOL_TRUE!}}";
        } elseif ($var === false) {
            $var = "{{!BOOL_FALSE!}}";
        } else {
            $var = self::scalarToString($var, __FUNCTION__);
        }

        // The 32-byte floor is enforced once, by deriveKey (see MIN_KEY_BYTES).
        $passphrase = self::deriveKey(self::keyring($key)->currentKey(), 32, $salt);

        return \AesBridge\Gcm::encrypt($var, $passphrase);
    }

    /**
     * Decrypts a value in the aes-bridge GCM format — see encryptCrossPlatform() for the exact key
     * derivation a peer must use.
     *
     * FAILS LOUD. aes-bridge's own Gcm::decrypt() returns openssl_decrypt()'s false through a
     * `string` return type, which PHP coerces to "" — so a WRONG KEY or a TAMPERED value used to
     * come back as "" (plus a warning for a short input), indistinguishable from a real empty
     * value. The format is therefore parsed here, with aes-bridge's own key derivation, and every
     * failure throws — including a blank value, which encryptCrossPlatform never produces.
     *
     * @param mixed $encrypted Base64 value produced by encryptCrossPlatform or any aes-bridge GCM
     *                         implementation, or null.
     * @param string|Keyring $key Master key of AT LEAST 32 bytes — the same one passed to
     *                            encryptCrossPlatform — or a keyring holding it; each of its keys is
     *                            tried, as the format carries no key id. Shorter keys are REJECTED.
     * @param string|null $salt Salt for key derivation
     *
     * @return mixed The decrypted string; true/false for the boolean markers; null for null
     * @throws \InvalidArgumentException When $encrypted is not a string (or null)
     * @throws \Exception On an empty value, invalid base64, a too-short value, or authentication
     *                    failure under every key (wrong key/salt, tampered value)
     *
     * @ref https://github.com/mervick/aes-bridge-php
     */
    public static function decryptCrossPlatform(mixed $encrypted, #[\SensitiveParameter] string|Keyring $key, ?string $salt = ""): mixed {
        if (!extension_loaded('openssl')) {
            throw new \Exception("OpenSSL not loaded");
        }
        if (!class_exists(\AesBridge\Gcm::class)) {
            throw new \Exception("Class '\AesBridge\Gcm' not found");
        }

        if ($encrypted === null) {
            return null;
        }
        if (!is_string($encrypted)) {
            throw new \InvalidArgumentException("decryptCrossPlatform() expects a base64 string; " . get_debug_type($encrypted) . " given.");
        }
        if ($encrypted === "") {
            throw new \Exception(
                "Empty value: encryptCrossPlatform never produces \"\" (an empty string is encrypted too), so this value was blanked or never encrypted."
            );
        }

        // The 32-byte floor is enforced once, by deriveKey (see MIN_KEY_BYTES).
        $passphrases = [];
        foreach (self::keyring($key)->all() as $masterKey) {
            $passphrases[] = self::deriveKey($masterKey, 32, $salt);
        }

        $ret = self::aesBridgeGcmDecrypt($encrypted, $passphrases);
        if ($ret === "{{!BOOL_TRUE!}}") {
            return true;
        }
        if ($ret === "{{!BOOL_FALSE!}}") {
            return false;
        }

        return $ret;
    }

    /**
     * Decrypts aes-bridge's GCM format — base64(salt[16] || nonce[12] || ciphertext || tag[16]) —
     * throwing on every failure instead of returning "".
     *
     * The key comes from aes-bridge's own \AesBridge\derive_key() (defined alongside
     * \AesBridge\Gcm), so its PBKDF2 parameters cannot drift from what Gcm::encrypt() used.
     *
     * @param string $encoded Base64 envelope
     * @param string[] $passphrases Passphrases given to aes-bridge (derived keys), tried in order
     *
     * @throws \Exception
     * @return string
     */
    private static function aesBridgeGcmDecrypt(string $encoded, #[\SensitiveParameter] array $passphrases): string {
        $saltBytes = 16;
        $nonceBytes = 12;
        $tagBytes = 16;

        $data = base64_decode($encoded, true);
        if ($data === false) {
            throw new \Exception("Failed to decode the encrypted value. Invalid base64.");
        }
        if (strlen($data) < $saltBytes + $nonceBytes + $tagBytes) {
            throw new \Exception("Encrypted payload is too short.");
        }
        if (!function_exists('AesBridge\derive_key')) {
            throw new \Exception("Function '\AesBridge\derive_key' not found");
        }

        foreach ($passphrases as $passphrase) {
            $aesKey = \AesBridge\derive_key($passphrase, substr($data, 0, $saltBytes));
            $plaintext = openssl_decrypt(
                substr($data, $saltBytes + $nonceBytes, -$tagBytes),
                'aes-256-gcm',
                $aesKey,
                OPENSSL_RAW_DATA,
                substr($data, $saltBytes, $nonceBytes),
                substr($data, -$tagBytes)
            );
            if ($plaintext !== false) {
                return $plaintext;
            }
        }

        throw new \Exception("Decryption failed: authentication tag mismatch.");
    }

    /**
     * Objects applySecurityFunctionArray() is walking, higher up the recursion stack (its cycle
     * guard). Empty between top-level calls.
     *
     * @var \SplObjectStorage<object, null>|null
     */
    private static ?\SplObjectStorage $applyWalkInProgress = null;

    /**
     * Applies a Security encrypt/decrypt/hash method to every scalar leaf of an array or object.
     *
     * NOT a general callable dispatcher. $fnName names a method OF THIS CLASS and nothing else; it
     * is resolved against an ALLOWLIST, and anything outside it throws \Exception. Your own
     * functions and other classes' methods are not reachable by design.
     *
     * The leaf call is `Security::$fnName($value, $key, $salt)`, so only methods accepting
     * (mixed $value, string $key, ?string $salt) can be dispatched. Exactly these five qualify:
     *   encryptLocal · decryptLocal · encryptCrossPlatform · decryptCrossPlatform · generateSearchHash
     *
     * encryptDataDB / decryptDataDB are REJECTED, not merely discouraged. Their third parameter is
     * $aad, not $salt, so dispatching them here would silently bind $salt as the AAD while their own
     * $salt kept its "" default — every value encrypted with an unsalted key and an AAD nobody
     * intended. It would round-trip (both directions wrong identically), so the defect would stay
     * invisible while the per-cell relocation binding those methods exist to provide was gone. They
     * need a per-cell AAD, which is meaningless for a bulk array walk — call them directly.
     *
     * @param mixed $item Value, array or object to walk. Arrays/objects recurse to every scalar
     *                    leaf; an object is converted to an array — `(array)` semantics, so private
     *                    and protected properties are included under their mangled keys — and
     *                    RETURNED AS AN ARRAY. The leaf methods' own conversions apply: e.g.
     *                    encryptLocal keeps null as null but turns ints into strings, so a round
     *                    trip does not restore types.
     * @param string|Keyring $key Master key or keyring, forwarded as the 2nd argument to $fnName
     * @param string|null $salt Optional salt for key derivation, forwarded as the THIRD argument
     * @param string $fnName Name of one of the five allowlisted methods above. A class prefix of
     *                       "self::", "static::", "class::", "Security::" or the fully-qualified
     *                       class name is optional and stripped; any other class prefix is
     *                       refused. An empty name returns $item unchanged.
     *
     * @throws \Exception When $fnName is not one of the five allowlisted methods, or by $fnName
     *                    itself (invalid key, authentication failure, ...).
     * @throws \InvalidArgumentException When an object contains itself (directly or through other
     *                                   objects): a cycle has no array form, and walking it used to
     *                                   recurse until the process died with an uncatchable fatal.
     * @return mixed The walked structure; arrays/objects come back as arrays, a scalar comes back
     *               as $fnName's return value.
     */
    public static function applySecurityFunctionArray(#[\SensitiveParameter] mixed $item, #[\SensitiveParameter] string|Keyring $key, ?string $salt, string $fnName): mixed {
        if(empty($fnName)) {
            return $item;
        }

        // Strip an optional class prefix naming THIS class down to the bare method name. Anything
        // else before "::" is some other class, and is refused by the allowlist below.
        $separator = strrpos($fnName, "::");
        if ($separator !== false) {
            $class = strtolower(ltrim(substr($fnName, 0, $separator), "\\"));
            if (in_array($class, ["self", "static", "class", "security", strtolower(self::class)], true)) {
                $fnName = substr($fnName, $separator + 2);
            }
        }

        // Allowlist: ONLY methods whose 3rd parameter really is $salt. This is what makes the
        // documented contract enforceable instead of advisory — notably it turns a bulk
        // encryptDataDB/decryptDataDB call (whose 3rd parameter is $aad) into a loud failure
        // rather than a silent, symmetric AAD misbinding.
        $allowed = [
            'encryptLocal',
            'decryptLocal',
            'encryptCrossPlatform',
            'decryptCrossPlatform',
            'generateSearchHash',
        ];
        $method = null;
        foreach ($allowed AS $candidate) {
            if (strcasecmp($fnName, $candidate) === 0) {
                $method = $candidate;
                break;
            }
        }
        if ($method === null) {
            throw new \Exception(
                "Unsupported function '{$fnName}' for applySecurityFunctionArray. Allowed: " .
                implode(", ", $allowed) . ". (encryptDataDB/decryptDataDB take an \$aad as their " .
                "third argument, not a \$salt, and must be called directly with a per-cell AAD.)"
            );
        }

        // Bind to THIS class explicitly. A bare "Security::method" string resolves against the
        // GLOBAL namespace and never finds VD\PHPHelper\Security, which made every call fail.
        $callable = [self::class, $method];

        if (is_object($item)) {
            self::$applyWalkInProgress ??= new \SplObjectStorage();
            if (self::$applyWalkInProgress->contains($item)) {
                throw new \InvalidArgumentException(
                    "applySecurityFunctionArray cannot walk a cyclic object graph: " . $item::class . " contains itself."
                );
            }

            // Held while the object's own array form is walked; released on every exit, so a
            // refusal cannot leave an instance marked for the caller's next call.
            self::$applyWalkInProgress->attach($item);
            try {
                return self::applySecurityFunctionArray((array) $item, $key, $salt, $method);
            } finally {
                self::$applyWalkInProgress->detach($item);
            }
        }
        if (!is_array($item)) {
            return call_user_func($callable, $item, $key, $salt);
        }

        $keyItem = null;
        $valueItem = null;
        foreach ($item AS $keyItem => $valueItem) {
            if (!is_array($valueItem) && !is_object($valueItem)) {
                $item[$keyItem] = call_user_func($callable, $valueItem, $key, $salt);
            } else {
                $item[$keyItem] = self::applySecurityFunctionArray($valueItem, $key, $salt, $fnName);
            }
        }
        $keyItem = null;
        $valueItem = null;

        return $item;
    }

    /**
     * Elements this sanitizer is willing to EMIT, mapped to the attributes allowed on each
     * (on top of XSS_GLOBAL_ATTRIBUTES). Anything absent here is never emitted.
     *
     * Deliberately excluded, and NOT to be added without re-reading xssCleanRecursive's soundness
     * argument — adding any of these breaks it:
     *  - Raw-text / escapable-raw-text elements (script, style, textarea, title, xmp, noembed,
     *    noframes, plaintext): their content is NOT parsed as markup, so escaped text inside them
     *    does not stay inert when the browser re-parses our output.
     *  - Foreign content (svg, math): switches the parser into XML-ish rules mid-document, which is
     *    the engine behind most mutation-XSS (mXSS) vectors.
     *  - id / name-bearing form controls: DOM clobbering.
     *
     * @var array<string, string[]>
     */
    private const XSS_ALLOWED_ELEMENTS = [
        'a' => ['href'], 'abbr' => [], 'b' => [], 'blockquote' => ['cite'], 'br' => [],
        'caption' => [], 'cite' => [], 'code' => [], 'col' => ['span'], 'colgroup' => ['span'],
        'dd' => [], 'del' => ['cite', 'datetime'], 'div' => [], 'dl' => [], 'dt' => [], 'em' => [],
        'figcaption' => [], 'figure' => [], 'h1' => [], 'h2' => [], 'h3' => [], 'h4' => [],
        'h5' => [], 'h6' => [], 'hr' => [], 'i' => [], 'img' => ['src', 'alt', 'width', 'height'],
        'ins' => ['cite', 'datetime'], 'kbd' => [], 'li' => ['value'], 'mark' => [],
        'ol' => ['start', 'reversed'], 'p' => [], 'pre' => [], 'q' => ['cite'], 's' => [],
        'samp' => [], 'small' => [], 'span' => [], 'strong' => [], 'sub' => [], 'sup' => [],
        'table' => [], 'tbody' => [], 'td' => ['colspan', 'rowspan'], 'tfoot' => [],
        'th' => ['colspan', 'rowspan', 'scope'], 'thead' => [], 'tr' => [], 'u' => [], 'ul' => [],
        'var' => [], 'wbr' => [],
    ];

    /**
     * Attributes allowed on every allow-listed element.
     *
     * "style" is deliberately absent: CSS is its own injection context (expression(),
     * url(javascript:), -moz-binding). "id"/"name" are absent to avoid DOM clobbering.
     *
     * @var string[]
     */
    private const XSS_GLOBAL_ATTRIBUTES = ['title', 'lang', 'dir', 'class'];

    /**
     * Allow-listed elements that are void (self-closing, never given a closing tag).
     *
     * @var string[]
     */
    private const XSS_VOID_ELEMENTS = ['br', 'hr', 'img', 'col', 'wbr'];

    /**
     * libxml's XML_ERR_NO_MEMORY error code (PHP defines no constant for error codes).
     *
     * @var int
     */
    private const LIBXML_ERR_NO_MEMORY = 2;

    /**
     * Allow-listed attributes whose value is a URL and therefore needs scheme validation.
     *
     * @var string[]
     */
    private const XSS_URL_ATTRIBUTES = ['href', 'src', 'cite'];

    /**
     * URL schemes permitted in an XSS_URL_ATTRIBUTES value. A scheme-less (relative) URL is also
     * allowed; anything else — javascript:, vbscript:, data:, blob:, file: — is dropped.
     *
     * @var string[]
     */
    private const XSS_ALLOWED_URL_SCHEMES = ['http', 'https', 'mailto', 'tel', 'ftp', 'ftps'];

    /**
     * Elements whose ENTIRE SUBTREE is discarded rather than unwrapped.
     *
     * Soundness does not depend on this list (an unwrapped subtree emits only escaped text and
     * allow-listed elements, which is already inert). It exists so that dropping <script> does not
     * leave its source code behind as visible page text.
     *
     * @var string[]
     */
    private const XSS_DROP_SUBTREE_ELEMENTS = [
        'script', 'style', 'svg', 'math', 'template', 'noscript', 'noembed', 'noframes', 'iframe',
        'object', 'embed', 'applet', 'frame', 'frameset', 'link', 'meta', 'base', 'title', 'head',
        'xml', 'form', 'input', 'button', 'select', 'textarea', 'option', 'optgroup', 'canvas',
        'audio', 'video', 'source', 'track', 'param', 'marquee', 'xmp', 'plaintext', 'listing',
        'portal', 'keygen', 'menuitem', 'bgsound', 'layer', 'ilayer',
    ];

    /**
     * Escapes a string so it is inert in an HTML text or quoted-attribute context.
     *
     * @param string $text Raw text
     * @return string
     */
    private static function xssEscape(string $text): string {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Validates a URL attribute value against XSS_ALLOWED_URL_SCHEMES.
     *
     * The scheme is matched against a PROBE copy with entities decoded and every control character
     * and every kind of whitespace removed, because browsers ignore those when resolving a scheme:
     * "jav&#x09;ascript:" decodes to "jav\tascript:" and still executes. Testing the raw value
     * would miss it.
     *
     * @param string $value Decoded attribute value
     * @return string|null The ORIGINAL value when the scheme is allowed (or the URL is relative);
     *                     null when the attribute must be dropped.
     */
    private static function xssSafeUrl(string $value): ?string {
        // Entities are decoded (to a fixed point) in the PROBE only. libxml's HTML4 parser leaves
        // HTML5-only references such as "&colon;" and "&Tab;" undecoded, so "javascript&colon;..."
        // reaches us literally. Emitted escaped it is inert to a browser — but one stray
        // html_entity_decode() downstream would turn it into a live javascript: URL.
        $probe = $value;
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($probe, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $probe) {
                break;
            }
            $probe = $decoded;
        }

        $probe = preg_replace('/[\p{C}\p{Z}\s]+/u', '', $probe);

        // preg_replace returns null on a malformed-UTF-8 subject: fail CLOSED.
        if ($probe === null || $probe === "") {
            return null;
        }

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $matches)) {
            if (!in_array(strtolower($matches[1]), self::XSS_ALLOWED_URL_SCHEMES, true)) {
                return null;
            }
        } elseif (str_starts_with($probe, ":")) {
            return null;
        }

        return $value;
    }

    /**
     * Recursively rebuilds one parsed DOM node as sanitized HTML.
     *
     * This is the whole allow-list: it can only ever emit (a) text escaped by xssEscape(), or
     * (b) an element named in XSS_ALLOWED_ELEMENTS carrying only allow-listed, escaped attributes.
     * Comments, CDATA, processing instructions and doctypes are dropped — a comment is an mXSS
     * vector and carries no display value here.
     *
     * @param \DOMNode $node Node to rebuild
     * @return string Sanitized HTML
     */
    private static function xssSanitizeNode(\DOMNode $node): string {
        if ($node instanceof \DOMText || $node instanceof \DOMCdataSection) {
            return self::xssEscape($node->nodeValue ?? "");
        }
        if (!($node instanceof \DOMElement)) {
            // Comment, processing instruction, doctype, ...
            return "";
        }

        $tag = strtolower($node->nodeName);
        if (in_array($tag, self::XSS_DROP_SUBTREE_ELEMENTS, true)) {
            return "";
        }

        $children = "";
        foreach ($node->childNodes as $child) {
            $children .= self::xssSanitizeNode($child);
        }

        // Not allow-listed but not dangerous either: drop the tag, keep the sanitized content.
        if (!array_key_exists($tag, self::XSS_ALLOWED_ELEMENTS)) {
            return $children;
        }

        $allowedAttributes = array_merge(self::XSS_GLOBAL_ATTRIBUTES, self::XSS_ALLOWED_ELEMENTS[$tag]);

        $html = "<" . $tag;
        foreach ($node->attributes as $attribute) {
            $name = strtolower($attribute->nodeName);
            if (!in_array($name, $allowedAttributes, true)) {
                // Every event handler (on*) lands here, whatever separator introduced it.
                continue;
            }

            $value = $attribute->nodeValue ?? "";
            if (in_array($name, self::XSS_URL_ATTRIBUTES, true)) {
                $value = self::xssSafeUrl($value);
                if ($value === null) {
                    continue;
                }
            }

            // $name is a literal from the allow-list, so only the value can carry hostile bytes.
            $html .= " " . $name . '="' . self::xssEscape($value) . '"';
        }

        if (in_array($tag, self::XSS_VOID_ELEMENTS, true)) {
            return $html . " />";
        }

        return $html . ">" . $children . "</" . $tag . ">";
    }

    /**
     * Sanitizes one HTML string by parsing it and rebuilding it against the allow-list.
     *
     * @param string $data Untrusted HTML
     * @return string Sanitized HTML, safe for an HTML body context
     */
    private static function xssSanitizeHtml(string $data): string {
        // Fail CLOSED when we cannot parse: escape everything instead. This destroys markup but can
        // never execute. ext-dom is only a "suggest" of this package, so its absence must be safe.
        if (!class_exists(\DOMDocument::class) || !mb_check_encoding($data, 'UTF-8')) {
            return self::xssEscape($data);
        }

        // libxml works on C strings: a NUL inside an attribute value cut the rest of the DOCUMENT
        // off — '<a href="x\0y">text</a>' came back as '<a href="x"></a>'. U+FFFD is what an HTML5
        // parser substitutes for NUL in most contexts anyway.
        $data = str_replace("\0", "\u{FFFD}", $data);

        $document = new \DOMDocument();

        // Hostile input is expected to be malformed; libxml must not emit warnings for it. Restore
        // the caller's error mode rather than clobbering it.
        $previousErrorMode = libxml_use_internal_errors(true);

        // Drain the buffer FIRST. libxml's error queue is process-wide: if the caller already had
        // internal errors enabled and left a fatal queued from their own unrelated parse, the
        // inspection below would attribute it to OUR parse and escape every valid input from here
        // on. Only errors raised by the loadHTML() on the next line may be judged. (The clear that
        // already ran after the parse discarded the caller's queue regardless, so this drains
        // nothing the old code preserved.)
        libxml_clear_errors();

        $loaded = $document->loadHTML(
            '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8"></head>'
            . '<body>' . $data . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        // A FATAL libxml error does NOT make loadHTML() return false. "Excessive depth in document:
        // 256" is the one that matters: the tree is TRUNCATED at depth 255 and everything deeper is
        // discarded, yet $loaded is true — so the guard below never fired and the sanitizer silently
        // dropped content. Inspect the errors BEFORE clearing them, and fail closed on a fatal.
        //
        // Only level >= LIBXML_ERR_FATAL counts, plus one ERROR-level code: XML_ERR_NO_MEMORY (2),
        // which is how libxml reports "xmlSAX2Characters: huge text node" — it then keeps only the
        // first 10.000.000 bytes of that text, again with $loaded true. Other LIBXML_ERR_ERROR
        // (level 2) errors are the normal response to hostile-but-parseable markup
        // (`<svg/onload=1>`, mismatched tags, a raw `&`); failing closed on those would escape
        // almost every real input and destroy the allow-list.
        $fatalParseError = false;
        foreach (libxml_get_errors() as $parseError) {
            if ($parseError->level >= LIBXML_ERR_FATAL || $parseError->code === self::LIBXML_ERR_NO_MEMORY) {
                $fatalParseError = true;
                break;
            }
        }

        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        if (!$loaded || $fatalParseError || $document->documentElement === null) {
            return self::xssEscape($data);
        }

        // Rebuilt from the ROOT, not from the first <body>: after a literal "</body></html>" libxml
        // nests the rest of the input in a second <html>/<body>, which reading only the first body
        // silently discarded. <html>/<body> are not allow-listed, so they unwrap; <head> (our own
        // wrapper's) is a dropped subtree.
        return self::xssSanitizeNode($document->documentElement);
    }

    /**
     * The objects currently being walked, higher up the recursion stack. Purely the cycle guard's
     * bookkeeping: every walk detaches its own object on the way out (finally), so between
     * top-level calls this is empty.
     *
     * @var \SplObjectStorage<object, null>|null
     */
    private static ?\SplObjectStorage $objectWalkInProgress = null;

    /**
     * Walks a PLAIN object and rewrites its PUBLIC PROPERTIES in place via $sanitize.
     *
     * Shared by xssCleanRecursive() and filterValue() so the two walks cannot drift apart — they
     * did drift, and the drift was the bug.
     *
     * THE CONTRACT IS DELIBERATELY NARROW, AND THE NARROWING IS THE FIX. Three earlier attempts
     * tried to walk arbitrary container objects in place, and each one shipped a different
     * fail-open:
     *  - `$object->$k = ...` — `foreach ($object as $k => $v)` dispatches to the ITERATOR on any
     *    Traversable, so this wrote a PHANTOM DYNAMIC property while the real storage (an
     *    ArrayObject's) kept the live payload; the caller got a "sanitized" copy reachable only
     *    through a property nothing reads.
     *  - `$object->offsetSet($key, ...)` with ITERATION keys — correct only while getIterator()
     *    happens to yield storage offsets. On a container that re-keys (array_values(), a sorted
     *    or paginated view) it writes to INVENTED offsets: the live payload SURVIVES at the real
     *    key and the container silently GROWS an entry, on the success path, with no error.
     *
     * There is no general, correct way to address a foreign container's storage from out here.
     * So this no longer tries. It walks exactly what it can address correctly — public properties,
     * via get_object_vars(), which no custom iterator can steer — and THROWS on everything else.
     * Refusing loudly is the whole point: silently returning an unwalked container IS the
     * fail-open, only quieter.
     *
     * @param object $object   Plain object to walk, mutated in place
     * @param callable $sanitize fn(mixed $value): mixed applied to every public property
     * @return void
     * @throws \InvalidArgumentException When $object is not a plain object whose public properties
     *                                   are its data — see assertWalkablePlainObject() for the
     *                                   refused shapes and what to do instead.
     */
    private static function walkObjectInPlace(object $object, callable $sanitize): void {
        // An enum case is a process-wide singleton whose name/value are compile-time constants
        // written in SOURCE — they are never caller data, so there is genuinely nothing to
        // sanitize and returning it untouched is the CORRECT result, not a slot skipped. (It is
        // also the only thing possible: writing to them is an \Error.) This is the one shape this
        // walk handles completely by doing nothing, which is why it returns instead of throwing.
        if ($object instanceof \UnitEnum) {
            return;
        }

        self::assertWalkablePlainObject($object);

        // Cycle guard. A back-reference ($a->self = $a, or $a->b->a) used to recurse until memory
        // was exhausted and die with an UNCATCHABLE fatal. An object already being walked higher
        // up the stack is skipped: it is the same instance, it is already being sanitized by that
        // frame, and re-entering it could never terminate.
        self::$objectWalkInProgress ??= new \SplObjectStorage();
        if (self::$objectWalkInProgress->contains($object)) {
            return;
        }

        self::$objectWalkInProgress->attach($object);

        try {
            // get_object_vars() is called from this scope, so private/protected properties are
            // invisible here — which IS the documented contract — and, unlike foreach, it cannot
            // be steered by a custom iterator.
            foreach (get_object_vars($object) as $key => $value) {
                $object->$key = $sanitize($value);
            }
        } finally {
            // Detach on every exit, throw included: otherwise a refusal deep in the graph would
            // leave this instance marked forever and the caller's NEXT call would skip it
            // silently unsanitized — a fail-open manufactured by the cycle guard itself.
            self::$objectWalkInProgress->detach($object);
        }
    }

    /**
     * Enforces walkObjectInPlace's narrow contract: an object is walked only when its public
     * properties ARE its data. Anything else is REFUSED, loudly, rather than handed back looking
     * sanitized.
     *
     * REFUSED, and why each one cannot be done correctly from out here:
     *  - Traversable and/or ArrayAccess — ArrayObject, ArrayIterator, SplObjectStorage, WeakMap,
     *    SplFixedArray, any custom collection. Their payload lives in storage reached through
     *    offsets (or through nothing but a getter), and an iteration key is NOT a storage address:
     *    SplObjectStorage iterates integer positions while its offsets are objects, WeakMap
     *    iterates object keys, and any re-keying view (sorted, paginated, array_values()) hands out
     *    keys that address nothing. Writing by iteration key is a TypeError at best and an invented
     *    entry beside the surviving payload at worst.
     *  - a public readonly property. It cannot be rewritten in place from outside its declaring
     *    scope — the assignment is an \Error — and it is on the object's PUBLIC data surface, so
     *    skipping it would return the object with attacker-controlled data still live in it. What
     *    this walk can SEE it must handle; private/protected state is a different case, outside
     *    the contract by construction and documented as not walked.
     *
     * @param object $object
     * @return void
     * @throws \InvalidArgumentException For the shapes above. Returns silently for a plain object,
     *                                   including one that carries private/protected state or an
     *                                   uninitialized (and therefore invisible) readonly property.
     */
    private static function assertWalkablePlainObject(object $object): void {
        if ($object instanceof \Traversable || $object instanceof \ArrayAccess) {
            throw new \InvalidArgumentException(self::unwalkableObjectMessage(
                $object,
                'its payload lives in container storage, and this walk cannot address that storage '
                . '— iteration keys are not storage offsets, so writing back through them either '
                . 'raises a TypeError or invents an entry while the real value survives',
                'Extract the data where the storage IS addressable — $value->getArrayCopy(), or '
                . 'your container\'s own getter — sanitize that ARRAY, and write it back through '
                . 'the container\'s own API.'
            ));
        }

        foreach (get_object_vars($object) as $key => $_) {
            if (self::isReadOnlyProperty($object, $key)) {
                throw new \InvalidArgumentException(self::unwalkableObjectMessage(
                    $object,
                    sprintf(
                        'its public readonly property $%s cannot be rewritten in place from outside '
                        . 'its declaring scope, and skipping it would hand the object back with '
                        . 'that property still unsanitized',
                        $key
                    ),
                    sprintf(
                        'Sanitize the value BEFORE constructing %s — a readonly property is a '
                        . 'promise that its value was settled at construction, so that is the only '
                        . 'place it can be settled correctly. If the data genuinely arrives '
                        . 'untrusted and must be cleaned afterwards, $%s should not be readonly.',
                        $object::class,
                        $key
                    )
                ));
            }
        }
    }

    /**
     * The refusal message. It must leave the caller with a working alternative, not just a "no" —
     * a refusal the caller cannot act on just moves the problem.
     *
     * @param object $object The object being refused
     * @param string $reason Why this walk cannot handle it correctly
     * @param string $remedy What the caller should do instead
     * @return string
     */
    private static function unwalkableObjectMessage(object $object, string $reason, string $remedy): string {
        return sprintf(
            'Security cannot sanitize %s in place: %s. This walk handles strings, arrays '
            . '(recursively), and the public properties of plain objects only — it refuses what it '
            . 'cannot address rather than hand back an object that merely looks sanitized. %s',
            $object::class,
            $reason,
            $remedy
        );
    }

    /**
     * True when $name is a declared readonly property of $object (so assigning to it would raise
     * an \Error). A dynamic property is never readonly.
     *
     * @param object $object
     * @param string $name
     * @return bool
     */
    private static function isReadOnlyProperty(object $object, string $name): bool {
        if (!property_exists($object, $name)) {
            return false;
        }

        try {
            return (new \ReflectionProperty($object, $name))->isReadOnly();
        } catch (\ReflectionException) {
            return false;
        }
    }

    /**
     * Sanitizes untrusted HTML down to a safe subset, recursively over arrays and objects.
     *
     * This is an ALLOW-LIST sanitizer. The string is PARSED (DOMDocument) and then REBUILT from the
     * parse tree: the output can only contain elements named in XSS_ALLOWED_ELEMENTS, carrying only
     * allow-listed attributes, with every text and attribute value escaped. Everything else — every
     * event handler, every unknown attribute, every javascript:/vbscript:/data: URI, <script>,
     * <svg>, <math>, comments — is dropped. Nothing is pattern-matched, so there is no separator,
     * entity or casing trick to "evade": a novel bypass shape would have to make the PARSER hand us
     * an allow-listed element with an allow-listed attribute, which is exactly the safe subset.
     *
     * This REPLACES a blacklist that had live bypasses (`<svg/onload=alert(1)>` and
     * `<img/onerror=alert(1) src=x>` passed through it unchanged, because its on*-attribute rule
     * required whitespace before "on" and "/" is also a valid attribute separator). Both are now
     * neutralised, along with the wider bypass battery pinned in SecurityTest.
     *
     * Why the output stays inert once the browser re-parses it (the mXSS argument): the allow-list
     * contains no raw-text element (script/style/textarea/title/xmp/...) and no foreign-content
     * element (svg/math). Those are the only constructs whose descendants get parsed under
     * different rules, so our serialized output re-parses to the tree we sanitized. This matters
     * because DOMDocument uses libxml's HTML4 parser, which does NOT implement the HTML5 parsing
     * algorithm — refusing to emit those elements is what makes the divergence unexploitable.
     *
     * HONEST LIMITS — still true, read them:
     *  - Output is safe for an HTML BODY (element content) context ONLY. It is NOT pre-escaped for
     *    an attribute, <script>, <style> or URL context. Interpolating it into any of those is
     *    still an injection.
     *  - It does NOT replace context-correct output encoding + a Content-Security-Policy. Use it to
     *    accept rich text you intend to RENDER as HTML; for input you will render as plain text,
     *    escape at output instead — that is simpler and strictly safer.
     *  - It REWRITES rather than preserves: markup is normalized (tags lowercased, attributes
     *    reordered/quoted, unknown tags unwrapped, `&` escaped). Do not use it on a value you need
     *    back byte-for-byte, and sanitize on OUTPUT (or store both forms) rather than destroying
     *    the original on input.
     *  - If ext-dom is unavailable, the input is not valid UTF-8, or libxml reports a FATAL parse
     *    error (notably "Excessive depth in document: 256" — HTML nested deeper than 255 elements)
     *    or a text node over 10.000.000 bytes ("huge text node"), it falls back to escaping the
     *    whole string (htmlspecialchars, ENT_QUOTES|ENT_SUBSTITUTE): still safe, but it destroys
     *    legitimate markup. This is deliberate: loadHTML() returns TRUE in both cases while
     *    SILENTLY TRUNCATING the tree or the text, so trusting it would discard content without a
     *    word. Escaping keeps the content visible and inert.
     *  - NUL bytes become U+FFFD (libxml would otherwise cut the input off at the first one).
     *  - ARRAY KEYS ARE NOT WALKED — only values are. A key is a structural identifier, and
     *    rewriting it could collide two entries into one and silently drop data, so keys are left
     *    byte-for-byte intact and MAY STILL CONTAIN LIVE MARKUP (the keys of $_POST are
     *    attacker-controlled). If you render keys, escape them at output.
     *  - OBJECTS: ONLY a PLAIN object's PUBLIC PROPERTIES are walked. This does NOT walk container
     *    storage of any kind, and does not pretend to. Two shapes are REFUSED with an
     *    \InvalidArgumentException, rather than returned unwalked — a container that comes back
     *    looking sanitized while still holding the live payload is the bug, not the safe default:
     *      * Traversable or ArrayAccess (ArrayObject, ArrayIterator, SplObjectStorage, WeakMap,
     *        SplFixedArray, any custom collection). Extract the data yourself
     *        (e.g. $ao->getArrayCopy()), sanitize the ARRAY, write it back via the container's API.
     *      * a public READONLY property. It cannot be rewritten from outside its declaring scope,
     *        and it CAN hold attacker data (`new Dto(bio: $_POST['bio'])`), so skipping it would be
     *        a silent fail-open on the object's own public surface. Sanitize before construction —
     *        readonly means the value was settled there — or do not make that property readonly.
     *        NOTE: this refuses the object even when the readonly value needed no change (a
     *        readonly int id). That is deliberate: a predictable refusal beats a rule the caller
     *        has to evaluate per value.
     *  - PRIVATE/PROTECTED state is never walked and is left UNSANITIZED — it is not reachable from
     *    here, and unlike a public readonly property it is not part of the object's public data
     *    surface, so it is outside this contract rather than a slot silently skipped inside it.
     *    Sanitize it before construction, or sanitize on output.
     *  - An object graph is walked only ONCE per instance per cycle: a back-reference ($a->b->a) is
     *    detected and not re-entered, so a cyclic graph terminates instead of exhausting memory.
     *  - If it THROWS partway through an object graph, the objects already visited KEEP their
     *    sanitized values — the walk is in-place and has no rollback. Discard the object on throw;
     *    do not use it half-walked.
     *
     * @param mixed $input The value to sanitize. Strings are sanitized; arrays are copied
     *                     (copy-on-write) and walked recursively; a PLAIN object is walked
     *                     recursively and rewritten IN PLACE, and the same instance is returned.
     *                     An enum case is returned untouched — its cases are compile-time
     *                     constants, not caller data, so there is nothing in one to sanitize.
     *                     Non-string scalars are returned unchanged.
     * @return mixed The sanitized value
     * @throws \InvalidArgumentException When $input contains an object this walk cannot sanitize
     *                                   correctly (Traversable, ArrayAccess, or a public readonly
     *                                   property) — see HONEST LIMITS. The message names the
     *                                   alternative.
     */
    public static function xssCleanRecursive(mixed $input): mixed {
        if ($input === null || $input === "" || is_bool($input) || filter_var($input, FILTER_VALIDATE_INT) || filter_var($input, FILTER_VALIDATE_FLOAT)) {
            return $input;
        }

        if (is_array($input)) {
            // Keys are deliberately NOT sanitized — see HONEST LIMITS.
            foreach ($input as $key => $value) {
                $input[$key] = self::xssCleanRecursive($value);
            }
        } elseif (is_object($input)) {
            self::walkObjectInPlace($input, static fn (mixed $value): mixed => self::xssCleanRecursive($value));
        }

        if (is_string($input)) {
            return self::xssSanitizeHtml($input);
        }

        return $input;
    }

    /**
     * Retrieves a value from an array/object/scalar, applying the selected transformations.
     *
     * Arrays and PLAIN objects are walked recursively and the filters are applied to every scalar
     * leaf; the container type is preserved (an object stays an object, its public properties
     * rewritten in place). A scalar is filtered directly.
     *
     * MUTATION ASYMMETRY: an array $source is copied (PHP copy-on-write), so the caller's array is
     * untouched and only the return value is filtered. An OBJECT $source is a shared handle, so it
     * is rewritten IN PLACE — the caller's object is modified and the same instance is returned.
     * Clone before calling if you need the original intact.
     *
     * WHAT THE OBJECT WALK VISITS: the PUBLIC PROPERTIES of a plain object, and nothing else. It
     * does NOT walk container storage. An object that is Traversable or ArrayAccess (ArrayObject,
     * ArrayIterator, SplObjectStorage, WeakMap, any custom collection), or that has a public
     * readonly property, is REFUSED with an \InvalidArgumentException naming what to do instead —
     * it is not returned unfiltered, because a container that comes back looking filtered while
     * still holding the raw value is exactly the bug this narrow contract exists to kill. Filter
     * such a container yourself: extract its data, filter the ARRAY, write it back. An enum case is
     * returned untouched (nothing in one is caller data). PRIVATE/PROTECTED state is never walked
     * and survives UNFILTERED. Array KEYS are never filtered — only values are.
     *
     * @param mixed $source The input array, object, or scalar
     * @param string|null $key If set, extracts a value from $source by key. ARRAYS ONLY: if $source
     *                         is an object (or not an array at all), or the key is absent or null,
     *                         $ifNull is returned. Pass null to filter $source itself.
     * @param mixed $ifNull Default value to return if $source is null, or the key is missing/null
     * @param bool $decodeStr Whether to decode the string using a custom decoder
     * @param bool $xssClean Runs xssCleanRecursive(), an ALLOW-LIST sanitizer that reduces the value
     *                       to a safe HTML subset. Read that method's docblock before enabling this:
     *                       its output is safe for an HTML BODY context only (not an attribute,
     *                       <script>, <style> or URL context), and it REWRITES markup rather than
     *                       preserving it byte-for-byte. Enable it only for rich text you intend to
     *                       RENDER as HTML; for values you render as plain text, escape at output.
     * @param bool $stripTags Whether to strip HTML tags
     * @param bool $htmlEntities Whether to apply htmlentities()
     * @param bool $addSlashes Whether to apply addslashes()
     * @param bool $escapeDB Whether to escape for DB queries using custom method
     * @param bool $trim Whether to apply trim()
     * @param bool $formatDecimal Formats the value with Formatter::formatNumber()'s defaults ("."
     *                            in and out; any other character is discarded first, so "1,5"
     *                            becomes "15" — see that method). The result is a string.
     * @param bool $asInteger Keeps only the ASCII digits (Str::onlyNumbers): the SIGN and the
     *                        decimal separator are dropped too ("-12.5" becomes "125"). The result
     *                        is a digit string, or the int 0 when no digit was left.
     * @param bool $asBoolean !Validator::isCompletelyEmpty(): false for null, "", whitespace-only
     *                        strings, any numeric zero ("0", "0.0", " 0 ") and placeholders such
     *                        as "null", "false", "no", "undefined"; true otherwise.
     * @param bool $base64Encode Whether to base64-encode the result
     * @param bool $base64Decode Whether to base64-decode the result
     * @param bool $base64UrlEncode Whether to base64-URL-encode the result
     * @param bool $base64UrlDecode Whether to base64-URL-decode the result
     * @param bool $urlEncode Whether to URL-encode the result
     * @param bool $urlDecode Whether to URL-decode the result
     * @param bool $jsonEncode Whether to JSON-encode the result
     * @param bool $jsonDecode Whether to JSON-decode the result
     *
     * @return mixed The filtered value
     * @throws \InvalidArgumentException When the walked value contains an object this walk cannot
     *                                   filter correctly (Traversable, ArrayAccess, or a public
     *                                   readonly property) — see WHAT THE OBJECT WALK VISITS. The
     *                                   walk is in-place with no rollback, so an object graph that
     *                                   throws partway keeps the filters already applied: discard
     *                                   it rather than using it half-filtered.
     */
    public static function filterValue(
        mixed $source,
        ?string $key = null,
        mixed $ifNull = null,
        bool $decodeStr = false,
        bool $xssClean = false,
        bool $stripTags = false,
        bool $htmlEntities = false,
        bool $addSlashes = false,
        bool $escapeDB = false,
        bool $trim = false,
        bool $formatDecimal = false,
        bool $asInteger = false,
        bool $asBoolean = false,
        bool $base64Encode = false,
        bool $base64Decode = false,
        bool $base64UrlEncode = false,
        bool $base64UrlDecode = false,
        bool $urlEncode = false,
        bool $urlDecode = false,
        bool $jsonEncode = false,
        bool $jsonDecode = false
    ): mixed {
        if (
            $source === null ||
            ($key !== null && (!is_array($source) || !array_key_exists($key, $source) || $source[$key] === null))
        ) {
            return $ifNull;
        }

        $value = ($key !== null) ? $source[$key] : $source;

        $applyFilters = function ($val) use (
            $decodeStr, $xssClean, $stripTags, $htmlEntities, $addSlashes, $escapeDB, $trim,
            $formatDecimal, $asInteger, $asBoolean,
            $base64Encode, $base64Decode, $base64UrlEncode, $base64UrlDecode,
            $urlEncode, $urlDecode,
            $jsonEncode, $jsonDecode
        ) {
            if ($decodeStr) {
                $val = Str::decodeText($val);
            }
            if ($xssClean) {
                $val = self::xssCleanRecursive($val);
            }
            if ($stripTags) {
                $val = strip_tags($val);
            }
            if ($htmlEntities) {
                $val = htmlentities($val);
            }
            if ($addSlashes) {
                $val = addslashes($val);
            }
            if ($escapeDB) {
                $val = SQL::escapeString($val);
            }
            if ($trim) {
                $val = trim($val);
            }

            if ($formatDecimal) {
                $val = Formatter::formatNumber($val);
                if ($val === "") $val = 0;
            } elseif ($asInteger) {
                $val = Str::onlyNumbers($val);
                if ($val === "") $val = 0;
            } elseif ($asBoolean) {
                $val = !Validator::isCompletelyEmpty($val);
            }

            if ($base64Encode) {
                $val = base64_encode($val);
            } elseif ($base64Decode) {
                $val = Parser::base64Decode($val);
            } elseif ($base64UrlEncode) {
                $val = Parser::base64UrlEncode($val);
            } elseif ($base64UrlDecode) {
                $val = Parser::base64UrlDecode($val);
            }

            if ($urlEncode) {
                $val = urlencode($val);
            } elseif ($urlDecode) {
                $val = urldecode($val);
            }

            if ($jsonEncode) {
                // json_encode()'s 2nd parameter is $flags, not json_decode()'s $assoc: the `true`
                // written here always meant flags = 1 = JSON_HEX_TAG. Spelled out, same output.
                $val = json_encode($val, JSON_HEX_TAG);
            } elseif ($jsonDecode) {
                $val = json_decode(Str::decodeText($val), true);
            }

            return $val;
        };

        $recurse = function (mixed $v) use (
            $ifNull,
            $decodeStr, $xssClean, $stripTags, $htmlEntities, $addSlashes, $escapeDB, $trim,
            $formatDecimal, $asInteger, $asBoolean,
            $base64Encode, $base64Decode, $base64UrlEncode, $base64UrlDecode,
            $urlEncode, $urlDecode,
            $jsonEncode, $jsonDecode
        ): mixed {
            return self::filterValue(
                $v, null, $ifNull,
                $decodeStr, $xssClean, $stripTags, $htmlEntities, $addSlashes, $escapeDB, $trim,
                $formatDecimal, $asInteger, $asBoolean,
                $base64Encode, $base64Decode, $base64UrlEncode, $base64UrlDecode,
                $urlEncode, $urlDecode,
                $jsonEncode, $jsonDecode
            );
        };

        if (is_array($value)) {
            // Keys are not walked, matching xssCleanRecursive.
            foreach ($value as $k => $v) {
                $value[$k] = $recurse($v);
            }
        } elseif (is_object($value)) {
            // Route every write through the SAME walk xssCleanRecursive uses, so the two cannot
            // drift apart and offer different contracts for the same object. That walk is narrow
            // on purpose and REFUSES what it cannot address correctly — see walkObjectInPlace().
            self::walkObjectInPlace($value, $recurse);
        } else {
            $value = $applyFilters($value);
        }

        return $value;
    }

    /**
     * Hashes a password with Argon2id, for storage.
     *
     * Rejects an empty password LOUDLY. It previously returned "" — which is not a hash — for any
     * empty()-y password, so a caller following the "@return string Encrypted password" contract
     * persisted "" into the password column and permanently locked the account out (verifyPassword
     * against "" is always false). That also swallowed the literal password "0", which empty()
     * reports as empty. Both are now impossible: this either returns a real hash or throws.
     *
     * The returned hash is self-describing (algorithm, cost and salt are embedded), so no salt or
     * algorithm needs to be stored alongside it. Store it as-is; it is never "" and never null.
     *
     * @param string|null $password Password to hash. Must be a non-empty string. NULL and "" are
     *                              rejected; "0" is a perfectly valid password and IS hashed.
     *
     * @throws \Exception When $password is null or "". An empty password is a validation failure
     *                    the caller must handle — it is never silently turned into a stored value.
     *                    Also when this PHP build has no Argon2 support.
     * @return string Argon2id hash, always non-empty
     */
    public static function encryptPassword(#[\SensitiveParameter] ?string $password): string {
        // Strict test, matching this file's null/"" idiom elsewhere: empty() would also swallow the
        // legitimate password "0".
        if ($password === null || $password === "") {
            throw new \Exception("Cannot hash an empty password.");
        }

        // PASSWORD_ARGON2ID only exists on builds with Argon2; referencing it elsewhere is an \Error.
        if (!defined('PASSWORD_ARGON2ID')) {
            throw new \Exception("Argon2id is not available in this PHP build.");
        }

        return password_hash($password, PASSWORD_ARGON2ID);
    }

    /**
     * Verifies a password against a hash produced by encryptPassword, in constant time.
     *
     * @param string $password Password to be verified. NOT nullable: passing null raises a
     *                         \TypeError. Null-coalesce at the call site (`$input ?? ''`) rather
     *                         than forwarding a missing form field straight in — a null password is
     *                         a caller bug, not a failed login.
     * @param string $hash Hash produced by encryptPassword. REQUIRED and NOT nullable: a NULL
     *                     column (SSO-only, invited, or not-yet-activated user) raises a
     *                     \TypeError, so check for it before calling. A non-hash value such as ""
     *                     simply returns false.
     *
     * @throws \TypeError When $password or $hash is null. NOTE: \TypeError is an \Error, NOT an
     *                    \Exception — `catch (\Exception $e)` will NOT stop it.
     * @return bool True only if $password matches $hash. False for a wrong password AND for any
     *              malformed/empty $hash — a false is never proof the hash was valid.
     */
    public static function verifyPassword(#[\SensitiveParameter] string $password, string $hash): bool {
        return password_verify($password, $hash);
    }
}
