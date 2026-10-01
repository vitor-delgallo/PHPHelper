<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Keyring;
use VD\PHPHelper\Security;

/**
 * encryptFile / decryptFile end to end: exact round trips around every block boundary, the
 * configurable max-encoded-block limit on both sides, streaming memory, and an adversarial decrypt
 * battery — for the v3 format encryptFile writes (a key per file, counter nonces, key id in the
 * header), checked against an independent implementation of it — and the refusal of the earlier
 * v2 format, which is no longer read.
 *
 * Every test runs in its own directory, so "nothing was left behind" is checked as "the directory
 * holds exactly the files the test created" — which also catches a stray staging (.part) file.
 */
final class SecurityFileEncryptionTest extends TestCase
{
    /** 32-byte master key (the minimum Security accepts). */
    private const KEY = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';

    /** A different 32-byte master key. */
    private const OTHER_KEY = 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';

    private const DEFAULT_BLOCK_BYTES = 3200000;
    private const DEFAULT_MAX_ENCODED_BLOCK_BYTES = 268435456;

    /** Security::keyId(KEY): the v3 header names the key by it. */
    private const KEY_ID = '0735d1be7e009715';

    /** v3 header blocks: cipher, version, key id, salt, file id. Data block i is block 5 + i. */
    private const HEADER_BLOCKS = 5;

    /**
     * A v3 file written by encryptFile (key KEY, salt 'v3-salt', 16-byte blocks). Files at rest must
     * stay decryptable across library versions: a change to the key derivation, the nonces or the
     * AAD would still round-trip against itself, and strand every file already written.
     */
    private const V3_PAYLOAD_B64 = 'VjMgdmVjdG9yOiAwMTIzNDU2Nzg5LUFCQwD/DQoaIGVuZCDinJM=';
    private const V3_FILE_B64 = 'MTYtWVdWekxUSTFOaTFuWTIwPTQtZGpNPTI0LU1EY3pOV1F4WW1VM1pUQXdPVGN4TlE9PTEyLWRqTXRjMkZzZEE9PTQ0LU9XRmhaR1psTXpjNU5HSmtOREppTVdWak1ERTJNR0kxWm1JMk1UUmpPVEE9NDQtbEl3T1VjM1FUQ3JOci82clJERXk4bmMrd2VxWVg5cEFaSXFLajhrMDlGQT00NC1uYXRmdGF0aDRaT0EwcVZDcmJaQWNuTlJMaUdQU1N5YXQ0RWNCQ21xS1RJPTMyLU1vKzhuNzV0MWV5SG4xcHY1SEFYSGY0d1BER2o0UT09MjQtNUw0cTZTMThnWTNBQU4wUDl5dkhzZ289';

    /**
     * A LEGACY v2 file, written by the former encryptFileV2 before the reader/writer rewrite (block
     * size 16, key 'L' x 32, salt 'legacy-salt'). v3 is the only format read: decryptFile must
     * refuse it, loudly and without leaving anything behind.
     */
    private const LEGACY_KEY = 'LLLLLLLLLLLLLLLLLLLLLLLLLLLLLLLL';
    private const LEGACY_FILE_B64 = 'MTYtWVdWekxUSTFOaTFuWTIwPTQtZGpJPTE2LWJHVm5ZV041TFhOaGJIUT00NC1ZekJtWlRVeU16YzJNREU1WkRFd1lqSXdOR1ZpTWpNNVkySTFORFE0TjJVPTE2LWw0eXViRjQ5eXFlZHRPK0cyNC1JZzhzZloyWmZCbzJMS3JJeFBHNjl3PT0yNC1MeW1aMHIxaGlqQkJLbWN4TUNXVjJnPT0xNi1BVzRKNm9WZU4vQzJQaHdyMjQtbkhpNENZaDhrTnhnMDZBNUk1R2hsQT09MjQtN0phcTZuU1Y4alo3TVZMS0pEQkNIZz09MTYtdGdXMkhTNHM4MGowalhnazI0LTNQaWc5RmRsSmZkaWsyV0wzSWxscnc9PTEyLWZyZWFnTVgybWtZTzE2LVd1N1lweTFIWUVZVGViVVkyNC1UR1Ayclk4c2ZEODFOT1gzLzVyQnFBPT00LVFRPT0=';

    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpht_sfe_' . bin2hex(random_bytes(8));
        mkdir($this->dir);

        // Start from the defaults whatever an earlier test (or test class) left behind.
        Security::setFileEncryptBlocksBytes(null);
        Security::setFileMaxEncodedBlockBytes(null);
    }

    protected function tearDown(): void
    {
        try {
            // Both are PROCESS-GLOBAL: leaking a tiny block size or limit into another class is a hazard.
            Security::setFileEncryptBlocksBytes(null);
            Security::setFileMaxEncodedBlockBytes(null);
        } finally {
            self::removeTree($this->dir);
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @chmod($path, 0666); // a read-only file cannot be unlinked on Windows
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (scandir($path) as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($path);
    }

    private function path(string $name): string
    {
        return $this->dir . DIRECTORY_SEPARATOR . $name;
    }

    private function write(string $name, string $bytes): string
    {
        $path = $this->path($name);
        file_put_contents($path, $bytes);

        return $path;
    }

    /** Every entry of the test directory, hidden ones included, sorted. */
    private function dirEntries(): array
    {
        $entries = array_values(array_diff(scandir($this->dir), ['.', '..']));
        sort($entries);

        return $entries;
    }

    /** Deterministic pseudo-random bytes (so a failure is reproducible). */
    private static function bytes(int $length, string $seed = 'seed'): string
    {
        $out = '';
        for ($i = 0; strlen($out) < $length; $i++) {
            $out .= hash('sha512', $seed . '|' . $i, true);
        }

        return substr($out, 0, $length);
    }

    /**
     * Encrypts and decrypts $payload with the current settings and asserts the round trip is
     * byte-exact, the source untouched and the directory free of leftovers.
     *
     * @return array{0:string,1:string,2:string} [source, encrypted, decrypted]
     */
    private function assertRoundTrip(string $payload, ?string $salt = null, string $name = 'rt'): array
    {
        $src = $this->write("{$name}.src", $payload);
        $enc = Security::encryptFile($src, self::KEY, $this->path("{$name}.enc"), $salt);
        $dec = Security::decryptFile($enc, self::KEY, $this->path("{$name}.dec"));
        clearstatcache();

        $this->assertSame(realpath($this->path("{$name}.enc")), $enc);
        $this->assertSame(realpath($this->path("{$name}.dec")), $dec);
        $this->assertSame(hash_file('sha256', $src), hash_file('sha256', $dec), 'sha256 of source and decrypted file differ');
        $this->assertSame(strlen($payload), filesize($dec));
        // assertTrue(===): assertSame() would dump megabytes of binary into a failure message.
        $this->assertTrue($payload === file_get_contents($dec), 'decrypted bytes differ from the source');
        $this->assertTrue($payload === file_get_contents($src), 'the source was modified');

        $entries = $this->dirEntries();
        foreach ($entries as $entry) {
            $this->assertStringEndsNotWith('.part', $entry, 'a staging file was left behind');
        }

        return [$src, $enc, $dec];
    }

    // ---------------------------------------------------------------------------------------
    // An independent implementation of the documented V2 format — the oracle for the tests below
    // ---------------------------------------------------------------------------------------

    /**
     * Splits a V2 file into the decoded payloads of its "{len}-{base64}" blocks, asserting the
     * container is canonical along the way.
     *
     * @return string[]
     */
    private static function decodeBlocks(string $raw): array
    {
        $blocks = [];
        $i = 0;
        while ($i < strlen($raw)) {
            if (!preg_match('/\G([1-9][0-9]*)-/', $raw, $m, 0, $i)) {
                throw new \RuntimeException("malformed length at offset {$i}");
            }
            $len = (int) $m[1];
            $data = substr($raw, $i + strlen($m[0]), $len);
            $decoded = base64_decode($data, true);
            if (strlen($data) !== $len || $decoded === false || base64_encode($decoded) !== $data) {
                throw new \RuntimeException("bad block at offset {$i}");
            }
            $blocks[] = $decoded;
            $i += strlen($m[0]) + $len;
        }

        return $blocks;
    }

    /** Inverse of decodeBlocks(). */
    private static function encodeBlocks(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $encoded = base64_encode($block);
            $out .= strlen($encoded) . '-' . $encoded;
        }

        return $out;
    }

    /** [start, end) byte span of each block of a V2 file. */
    private static function blockSpans(string $raw): array
    {
        $spans = [];
        $i = 0;
        while ($i < strlen($raw) && preg_match('/\G([1-9][0-9]*)-/', $raw, $m, 0, $i)) {
            $end = $i + strlen($m[0]) + (int) $m[1];
            $spans[] = [$i, $end];
            $i = $end;
        }

        return $spans;
    }

    /** Number of DATA blocks in a v3 file (header and end marker excluded). */
    private static function dataBlockCount(string $raw): int
    {
        return count(self::decodeBlocks($raw)) - self::HEADER_BLOCKS - 1;
    }

    /** The documented nonce of block $i: the block counter, 12 bytes big-endian. */
    private static function nonce(int $i): string
    {
        return str_repeat("\0", 4) . pack('J', $i);
    }

    /** The documented key id: HKDF-SHA256(master key, info "key-id"), 8 bytes, hex. */
    private static function referenceKeyId(string $masterKey): string
    {
        return bin2hex(hash_hkdf('sha256', $masterKey, 8, 'key-id'));
    }

    /** The documented per-file key: HKDF-SHA256(master key, salt, info "file-v3|{file id}"). */
    private static function referenceFileKey(string $masterKey, string $salt, string $fileId): string
    {
        return hash_hkdf('sha256', $masterKey, 32, "file-v3|{$fileId}", $salt);
    }

    /** Decrypts a v3 file using nothing but the documented format — independent of Security. */
    private static function referenceDecrypt(string $raw, string $masterKey): string
    {
        $blocks = self::decodeBlocks($raw);
        [$cipher, $version, $keyId, $salt, $fileId] = array_slice($blocks, 0, self::HEADER_BLOCKS);
        if ($cipher !== 'aes-256-gcm' || $version !== 'v3' || $keyId !== self::referenceKeyId($masterKey)) {
            throw new \RuntimeException('not a v3 file under this key');
        }

        $key = self::referenceFileKey($masterKey, $salt, $fileId);
        $body = array_slice($blocks, self::HEADER_BLOCKS);
        $count = count($body) - 1;
        $out = '';
        foreach ($body as $i => $block) {
            $aad = $i < $count ? "{$fileId}|v3|D|{$i}" : "{$fileId}|v3|F|{$count}";
            $pt = openssl_decrypt(substr($block, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($i), substr($block, -16), $aad);
            if ($pt === false) {
                throw new \RuntimeException("block {$i} failed authentication");
            }
            if ($i < $count) {
                $out .= $pt;
            } elseif ($pt !== (string) $count) {
                throw new \RuntimeException('end marker count mismatch');
            }
        }

        return $out;
    }

    /** Builds a v3 file by hand from the documented format — independent of Security. */
    private static function referenceEncrypt(string $plaintext, string $masterKey, string $salt, int $blockBytes): string
    {
        $fileId = bin2hex(random_bytes(16));
        $key = self::referenceFileKey($masterKey, $salt, $fileId);
        $blocks = ['aes-256-gcm', 'v3', self::referenceKeyId($masterKey), $salt, $fileId];
        $chunks = ($plaintext === '' ? [] : str_split($plaintext, $blockBytes));
        foreach ($chunks as $i => $chunk) {
            $ct = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($i), $tag, "{$fileId}|v3|D|{$i}", 16);
            $blocks[] = $ct . $tag;
        }
        $count = count($chunks);
        $ct = openssl_encrypt((string) $count, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($count), $tag, "{$fileId}|v3|F|{$count}", 16);
        $blocks[] = $ct . $tag;

        return self::encodeBlocks($blocks);
    }

    /**
     * Builds a LEGACY v2 file by hand: one key per master key and salt (info "file-v2"), a random IV
     * per block, and [iv][tag][ciphertext] triples — what the former encryptFileV2 wrote.
     */
    private static function referenceEncryptV2(string $plaintext, string $masterKey, string $salt, int $blockBytes): string
    {
        $fileId = bin2hex(random_bytes(16));
        $key = hash_hkdf('sha256', $masterKey, 32, 'file-v2', $salt);
        $blocks = ['aes-256-gcm', 'v2', $salt, $fileId];
        $chunks = ($plaintext === '' ? [] : str_split($plaintext, $blockBytes));
        foreach ($chunks as $i => $chunk) {
            $iv = random_bytes(12);
            $ct = openssl_encrypt($chunk, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "{$fileId}|v2|D|{$i}", 16);
            array_push($blocks, $iv, $tag, $ct);
        }
        $count = count($chunks);
        $iv = random_bytes(12);
        $ct = openssl_encrypt((string) $count, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, "{$fileId}|v2|F|{$count}", 16);
        array_push($blocks, $iv, $tag, $ct);

        return self::encodeBlocks($blocks);
    }

    /**
     * Asserts decryptFile refuses $bytes and leaves nothing behind: no destination, no staging
     * file, no other change to the directory.
     */
    private function assertDecryptRejects(string $bytes, string $label, ?string $messagePart = null): \Exception
    {
        $crafted = $this->write('crafted.enc', $bytes);
        $destination = $this->path('crafted.dec');
        $before = $this->dirEntries();

        try {
            Security::decryptFile($crafted, self::KEY, $destination);
        } catch (\Exception $e) {
            if ($messagePart !== null) {
                $this->assertStringContainsString($messagePart, $e->getMessage(), $label);
            }
            clearstatcache();
            $this->assertFileDoesNotExist($destination, "{$label}: a destination was left behind");
            $this->assertSame($before, $this->dirEntries(), "{$label}: the directory changed");

            return $e;
        }

        $this->fail("decryptFile accepted a modified file: {$label}");
    }

    /** A small multi-block ciphertext for the tamper tests: 16-byte blocks, 7 data blocks. */
    private function smallEncryptedFile(string $name = 'small', ?string $payload = null, ?string $salt = null): string
    {
        Security::setFileEncryptBlocksBytes(16);
        $src = $this->write("{$name}.src", $payload ?? self::bytes(100, $name));
        Security::encryptFile($src, self::KEY, $this->path("{$name}.enc"), $salt);

        return file_get_contents($this->path("{$name}.enc"));
    }

    // ---------------------------------------------------------------------------------------
    // get/setFileMaxEncodedBlockBytes
    // ---------------------------------------------------------------------------------------

    public function testMaxEncodedBlockBytesDefaultsTo256MiB(): void
    {
        $this->assertSame(self::DEFAULT_MAX_ENCODED_BLOCK_BYTES, Security::getFileMaxEncodedBlockBytes());
    }

    public function testSetMaxEncodedBlockBytesStoresAValueAndNullReallyResets(): void
    {
        Security::setFileMaxEncodedBlockBytes(1024);
        $this->assertSame(1024, Security::getFileMaxEncodedBlockBytes());

        Security::setFileMaxEncodedBlockBytes(PHP_INT_MAX);
        $this->assertSame(PHP_INT_MAX, Security::getFileMaxEncodedBlockBytes());

        Security::setFileMaxEncodedBlockBytes(null);
        $this->assertSame(self::DEFAULT_MAX_ENCODED_BLOCK_BYTES, Security::getFileMaxEncodedBlockBytes());
    }

    /**
     * A limit of 1 (or anything below 44) could not hold the 32-character file id that EVERY
     * file contains, so no file — not even an empty one — could ever be written or read under it.
     * It is refused in the setter, loudly, instead of failing every later call.
     */
    #[DataProvider('limitBelowTheFormatMinimumProvider')]
    public function testSetMaxEncodedBlockBytesRejectsALimitNoFileCouldFit(int $limit): void
    {
        Security::setFileMaxEncodedBlockBytes(4096);

        try {
            Security::setFileMaxEncodedBlockBytes($limit);
            $this->fail("A limit of {$limit} must be refused.");
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('>= 44', $e->getMessage());
        }

        $this->assertSame(4096, Security::getFileMaxEncodedBlockBytes(), 'A refused value must not replace the one in effect.');
    }

    public static function limitBelowTheFormatMinimumProvider(): array
    {
        return [
            'one (the literal example)' => [1],
            'zero'                      => [0],
            'negative'                  => [-1],
            'PHP_INT_MIN'               => [PHP_INT_MIN],
            'one below the minimum'     => [43],
        ];
    }

    /** The minimum is genuinely usable: a 17-byte block plus its 16-byte tag encodes to exactly 44 bytes. */
    public function testTheMinimumLimitIsUsableAtItsExactBoundary(): void
    {
        Security::setFileMaxEncodedBlockBytes(44);
        Security::setFileEncryptBlocksBytes(17);

        [, $enc] = $this->assertRoundTrip(self::bytes(100));
        $this->assertSame(6, self::dataBlockCount(file_get_contents($enc)));

        Security::setFileEncryptBlocksBytes(18); // 34 bytes with the tag: 48 encoded
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('max-encoded-block limit');
        Security::encryptFile($this->path('rt.src'), self::KEY, $this->path('over.enc'));
    }

    public function testSetFileEncryptBlocksBytesRejectsNonPositiveSizesAsInvalidArgument(): void
    {
        foreach ([0, -1, PHP_INT_MIN] as $size) {
            try {
                Security::setFileEncryptBlocksBytes($size);
                $this->fail("A block size of {$size} must be refused.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('>= 1 byte', $e->getMessage());
            }
        }
        $this->assertSame(self::DEFAULT_BLOCK_BYTES, Security::getFileEncryptBlocksBytes());
    }

    // ---------------------------------------------------------------------------------------
    // Round trips around every block boundary
    // ---------------------------------------------------------------------------------------

    /** File sizes around a fixed 1000-byte block: exact multiples, and one byte either side. */
    #[DataProvider('fileSizeAroundBlockProvider')]
    public function testRoundTripForFileSizesAroundTheBlockSize(int $size): void
    {
        Security::setFileEncryptBlocksBytes(1000);

        [, $enc] = $this->assertRoundTrip(self::bytes($size, "size{$size}"));

        // An exact multiple must not grow an empty trailing data block.
        $this->assertSame((int) ceil($size / 1000), self::dataBlockCount(file_get_contents($enc)));
    }

    public static function fileSizeAroundBlockProvider(): array
    {
        $sizes = [0, 1, 2, 3, 999, 1000, 1001, 1999, 2000, 2001, 2999, 3000, 3001, 10000];

        return array_combine(array_map(static fn ($s) => "{$s} bytes", $sizes), array_map(static fn ($s) => [$s], $sizes));
    }

    /** The mirror image: a fixed 1000-byte file, block sizes around it. */
    #[DataProvider('blockSizeAroundFileProvider')]
    public function testRoundTripForBlockSizesAroundTheFileSize(int $blockBytes, int $expectedBlocks): void
    {
        Security::setFileEncryptBlocksBytes($blockBytes);

        [, $enc] = $this->assertRoundTrip(self::bytes(1000, 'fixed'));

        $this->assertSame($expectedBlocks, self::dataBlockCount(file_get_contents($enc)));
    }

    public static function blockSizeAroundFileProvider(): array
    {
        return [
            'block = file - 1' => [999, 2],
            'block = file'     => [1000, 1],
            'block = file + 1' => [1001, 1],
            'block = file / 2' => [500, 2],
            'block = file/2-1' => [499, 3],
            'block = file/2+1' => [501, 2],
            'block = file / 4' => [250, 4],
            'block ≫ file'     => [1000000, 1],
        ];
    }

    /** Around the DEFAULT block size, which is what real callers use. */
    #[DataProvider('fileSizeAroundDefaultBlockProvider')]
    public function testRoundTripAroundTheDefaultBlockSize(int $size, int $expectedBlocks): void
    {
        [, $enc] = $this->assertRoundTrip(self::bytes($size, "default{$size}"));

        $this->assertSame($expectedBlocks, self::dataBlockCount(file_get_contents($enc)));
    }

    public static function fileSizeAroundDefaultBlockProvider(): array
    {
        $b = self::DEFAULT_BLOCK_BYTES;

        return [
            'default - 1'     => [$b - 1, 1],
            'default'         => [$b, 1],
            'default + 1'     => [$b + 1, 2],
            '2 x default'     => [2 * $b, 2],
            '2 x default + 1' => [2 * $b + 1, 3],
        ];
    }

    /** Block size 1: every byte is its own authenticated block. */
    public function testBlockSizeOneEncryptsEveryByteAsItsOwnBlock(): void
    {
        Security::setFileEncryptBlocksBytes(1);
        $payload = implode('', array_map('chr', range(0, 255)));

        [, $enc] = $this->assertRoundTrip($payload);

        $raw = file_get_contents($enc);
        $this->assertSame(256, self::dataBlockCount($raw));
        $this->assertSame($payload, self::referenceDecrypt($raw, self::KEY));
    }

    // ---------------------------------------------------------------------------------------
    // Block size 1 under the DEFAULT max-encoded-block limit: the file is inspected block by
    // block, not just round-tripped, so a writer that merged, dropped, reordered or re-used a
    // nonce across 1-byte blocks cannot hide behind a decrypt that happens to agree with it.
    // ---------------------------------------------------------------------------------------

    /**
     * Opens a v3 file written with 1-byte blocks and asserts its exact layout: one block per
     * plaintext byte, in order, each holding exactly that byte plus its tag, authenticated under the
     * file's own key, its position-bound AAD and the nonce equal to its position — and under no
     * other nonce — followed by an end marker counting them.
     */
    private function assertOneBytePerBlockLayout(string $raw, string $payload): void
    {
        $size = strlen($payload);
        $blocks = self::decodeBlocks($raw);
        $this->assertCount(self::HEADER_BLOCKS + $size + 1, $blocks, 'header + one block per byte + end marker');

        [$cipher, $version, $keyId, $salt, $fileId] = $blocks;
        $this->assertSame(['aes-256-gcm', 'v3', self::KEY_ID], [$cipher, $version, $keyId]);
        $key = self::referenceFileKey(self::KEY, $salt, $fileId);

        foreach (array_slice($blocks, self::HEADER_BLOCKS, $size) as $i => $block) {
            $this->assertSame(17, strlen($block), "data block {$i}: one byte of ciphertext plus the 16-byte tag");
            [$ciphertext, $tag] = [substr($block, 0, 1), substr($block, 1)];
            $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($i), $tag, "{$fileId}|v3|D|{$i}");
            $this->assertSame($payload[$i], $plaintext, "data block {$i} must hold exactly byte {$i} of the source");
            $this->assertFalse(
                openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($i + 1), $tag, "{$fileId}|v3|D|{$i}"),
                "data block {$i} must authenticate under nonce {$i} only"
            );
        }

        $marker = $blocks[self::HEADER_BLOCKS + $size];
        $count = openssl_decrypt(substr($marker, 0, -16), 'aes-256-gcm', $key, OPENSSL_RAW_DATA, self::nonce($size), substr($marker, -16), "{$fileId}|v3|F|{$size}");
        $this->assertSame((string) $size, $count, 'the end marker must count every byte');
    }

    /**
     * Block size 1 with the limit left at its default. Size 1 is the "file exactly equal to the
     * block" case; the rest are files longer than the block, so every byte becomes its own block.
     */
    #[DataProvider('blockSizeOneUnderTheDefaultLimitProvider')]
    public function testBlockSizeOneUnderTheDefaultLimitWritesOneBlockPerByteInOrder(int $size): void
    {
        $this->assertSame(self::DEFAULT_MAX_ENCODED_BLOCK_BYTES, Security::getFileMaxEncodedBlockBytes(), 'precondition: the limit is at its default');
        Security::setFileEncryptBlocksBytes(1);
        $this->assertSame(1, Security::getFileEncryptBlocksBytes());

        $payload = self::bytes($size, "block1-{$size}");
        [, $enc] = $this->assertRoundTrip($payload);

        $raw = file_get_contents($enc);
        $this->assertSame($size, self::dataBlockCount($raw));
        $this->assertOneBytePerBlockLayout($raw, $payload);
    }

    public static function blockSizeOneUnderTheDefaultLimitProvider(): array
    {
        $sizes = [1, 2, 3, 10, 100, 1000, 4096];

        return array_combine(
            array_map(static fn ($s) => $s === 1 ? 'file = block (1 byte)' : "file = {$s} x block", $sizes),
            array_map(static fn ($s) => [$s], $sizes)
        );
    }

    /**
     * Text, not random bytes: with 1-byte blocks every multibyte UTF-8 character is split across
     * two to four blocks, so the characters only come back if the blocks are reassembled exactly.
     */
    public function testBlockSizeOneReassemblesMultibyteTextSplitAcrossBlocks(): void
    {
        Security::setFileEncryptBlocksBytes(1);
        $payload = "Criptografia de arquivos: ação, coração, pão — € 1.234,56 😀\r\nLinha 2\n";

        [, $enc, $dec] = $this->assertRoundTrip($payload);

        $this->assertSame($payload, file_get_contents($dec));
        $this->assertTrue(mb_check_encoding(file_get_contents($dec), 'UTF-8'));
        $this->assertOneBytePerBlockLayout(file_get_contents($enc), $payload);
    }

    /** A larger file at block size 1 (tens of thousands of blocks) still round-trips exactly. */
    public function testBlockSizeOneRoundTripsAFileOfTensOfThousandsOfBlocks(): void
    {
        Security::setFileEncryptBlocksBytes(1);
        $payload = self::bytes(50000, 'block1-large');

        [, $enc] = $this->assertRoundTrip($payload);

        $raw = file_get_contents($enc);
        $this->assertSame(50000, self::dataBlockCount($raw));
        $this->assertSame($payload, self::referenceDecrypt($raw, self::KEY));
    }

    /**
     * With 1-byte blocks, swapping two blocks is the most tempting edit there is (it reorders two
     * characters). Every block is bound to its position, so it must be rejected, not decrypted.
     */
    public function testBlockSizeOneRejectsTwoSwappedBlocks(): void
    {
        Security::setFileEncryptBlocksBytes(1);
        $src = $this->write('swap.src', 'AB');
        $raw = file_get_contents(Security::encryptFile($src, self::KEY, $this->path('swap.enc')));

        $blocks = self::decodeBlocks($raw);
        $swapped = $blocks;
        [$swapped[5], $swapped[6]] = [$blocks[6], $blocks[5]];

        $this->assertDecryptRejects(self::encodeBlocks($swapped), 'blocks 0 and 1 swapped');
    }

    public function testRoundTripOfEveryByteValue(): void
    {
        $this->assertRoundTrip(str_repeat(implode('', array_map('chr', range(0, 255))), 64));
    }

    /**
     * Plaintext made of the container's own alphabet — digits and '-' — split at awkward block
     * edges, and an encrypted file used as plaintext. Nothing in the plaintext may be parsed.
     */
    #[DataProvider('containerLookalikeProvider')]
    public function testRoundTripOfContentThatLooksLikeTheContainerFormat(string $payload, int $blockBytes): void
    {
        Security::setFileEncryptBlocksBytes($blockBytes);

        $this->assertRoundTrip($payload);
    }

    public static function containerLookalikeProvider(): array
    {
        $digits = str_repeat('16-0-4-QQ==-12345678901234567890-', 40);

        return [
            'digits and dashes, 7-byte blocks'  => [$digits, 7],
            'digits and dashes, 3-byte blocks'  => [$digits, 3],
            'only dashes'                       => [str_repeat('-', 1000), 10],
            'only zeros'                        => [str_repeat('0', 1000), 10],
            'a leading "0-" block'              => ['0-' . $digits, 16],
        ];
    }

    public function testRoundTripOfAnEncryptedFileAsPlaintext(): void
    {
        $inner = $this->smallEncryptedFile('inner');
        Security::setFileEncryptBlocksBytes(10);

        $this->assertRoundTrip($inner, null, 'outer');
    }

    /** Text-mode translation (CRLF, \x1A as EOF on Windows) must never apply: binary in, binary out. */
    public function testRoundTripPreservesBytesThatTextModeWouldTranslate(): void
    {
        Security::setFileEncryptBlocksBytes(5);

        $this->assertRoundTrip("a\r\nb\nc\rd\x1Ae\x00f\r\n\r\n\x1A\x1A\x00\x00\xFF\xFE\xEF\xBB\xBF end\r");
    }

    public function testEmptyFileEncryptsToHeaderPlusEndMarkerAndDecryptsToAnEmptyFile(): void
    {
        [, $enc, $dec] = $this->assertRoundTrip('');

        $raw = file_get_contents($enc);
        $this->assertCount(self::HEADER_BLOCKS + 1, self::decodeBlocks($raw), 'header + end marker only');
        $this->assertSame(0, self::dataBlockCount($raw));
        $this->assertFileExists($dec);
        $this->assertSame(0, filesize($dec));
    }

    public function testManySmallBlocksRoundTrip(): void
    {
        Security::setFileEncryptBlocksBytes(4096);

        [, $enc] = $this->assertRoundTrip(self::bytes(1024 * 1024, 'many'));

        $this->assertSame(256, self::dataBlockCount(file_get_contents($enc)));
    }

    /** The salt is stored in the header (decryptFile takes none); null and "" are stored as "?". */
    #[DataProvider('saltProvider')]
    public function testRoundTripWithSaltsAndTheSaltIsStoredInTheHeader(?string $salt, string $stored): void
    {
        Security::setFileEncryptBlocksBytes(64);

        [, $enc] = $this->assertRoundTrip(self::bytes(300, 'salted'), $salt);

        $this->assertSame($stored, self::decodeBlocks(file_get_contents($enc))[3]);
    }

    public static function saltProvider(): array
    {
        $binary = "a\x00-1-\xFF\x00";

        return [
            'null'            => [null, '?'],
            'empty'           => ['', '?'],
            'question mark'   => ['?', '?'],
            'zero'            => ['0', '0'],
            'dashes & digits' => ['12-34-', '12-34-'],
            'binary with NUL' => [$binary, $binary],
            'unicode'         => ['sal ✓ 塩', 'sal ✓ 塩'],
            '1000 bytes'      => [str_repeat('s', 1000), str_repeat('s', 1000)],
        ];
    }

    public function testFilesWrittenByTheIndependentFormatReferenceDecrypt(): void
    {
        foreach ([[0, 16], [1, 16], [16, 16], [17, 16], [1000, 7]] as [$size, $block]) {
            $payload = self::bytes($size, "ref{$size}");
            $crafted = $this->write("ref{$size}.enc", self::referenceEncrypt($payload, self::KEY, 'ref-salt', $block));

            $dec = Security::decryptFile($crafted, self::KEY, $this->path("ref{$size}.dec"));

            $this->assertTrue($payload === file_get_contents($dec), "size {$size}");
        }
    }

    /**
     * Only v3 is read: a genuine v2 file — built by the independent v2 encoder under the right key —
     * is refused on its version, before any key is tried or anything is created.
     */
    public function testV2FilesAreRefusedEvenUnderTheRightKey(): void
    {
        foreach ([[0, 16], [1, 16], [1000, 7]] as [$size, $block]) {
            $crafted = $this->write("v2-{$size}.enc", self::referenceEncryptV2(self::bytes($size, "v2-{$size}"), self::KEY, 'v2-salt', $block));
            $before = $this->dirEntries();

            try {
                Security::decryptFile($crafted, new Keyring(self::OTHER_KEY, self::KEY), $this->path("v2-{$size}.dec"));
                $this->fail("size {$size}: a v2 file must be refused.");
            } catch (\Exception $e) {
                $this->assertStringContainsString('only file format v3 is supported', $e->getMessage());
            }
            $this->assertSame($before, $this->dirEntries(), "size {$size}: something was created");
        }
    }

    public function testFilesWrittenByEncryptFileFollowTheDocumentedFormat(): void
    {
        Security::setFileEncryptBlocksBytes(10);
        $payload = self::bytes(95, 'format');
        [, $enc] = $this->assertRoundTrip($payload, 'fmt-salt');

        $raw = file_get_contents($enc);
        $blocks = self::decodeBlocks($raw);

        $this->assertSame('aes-256-gcm', $blocks[0]);
        $this->assertSame('v3', $blocks[1]);
        $this->assertSame(self::KEY_ID, $blocks[2]);
        $this->assertSame('fmt-salt', $blocks[3]);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{32}$/', $blocks[4]);
        $this->assertSame(10, self::dataBlockCount($raw));
        $this->assertSame($payload, self::referenceDecrypt($raw, self::KEY));
    }

    public function testAFixedV3FileStillDecrypts(): void
    {
        $raw = base64_decode(self::V3_FILE_B64);
        $vector = $this->write('v3.enc', $raw);

        $dec = Security::decryptFile($vector, self::KEY, $this->path('v3.dec'));

        $this->assertSame(base64_decode(self::V3_PAYLOAD_B64), file_get_contents($dec));
        $this->assertSame(base64_decode(self::V3_PAYLOAD_B64), self::referenceDecrypt($raw, self::KEY));
    }

    /**
     * WHY v3: every file has its own key, so the same content under the same master key and salt
     * shares no ciphertext — not across files, and not between equal blocks of one file.
     */
    public function testIdenticalInputsUnderTheSameKeyShareNoCiphertext(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        $payload = str_repeat('A', 160); // ten identical 16-byte blocks

        $a = self::decodeBlocks(file_get_contents(Security::encryptFile($this->write('a.src', $payload), self::KEY, $this->path('a.enc'), 'same')));
        $b = self::decodeBlocks(file_get_contents(Security::encryptFile($this->write('b.src', $payload), self::KEY, $this->path('b.enc'), 'same')));

        $this->assertNotSame($a[4], $b[4], 'each file draws its own file id');
        $bodyA = array_slice($a, self::HEADER_BLOCKS);
        $bodyB = array_slice($b, self::HEADER_BLOCKS);
        $this->assertSame([], array_values(array_intersect($bodyA, $bodyB)), 'no block may appear in both files');
        $this->assertCount(count($bodyA), array_unique($bodyA), 'equal plaintext blocks must not repeat within a file');
    }

    /** v3 stores no nonce: at 1-byte blocks a file is about half the size the v2 format needed. */
    public function testAV3FileIsSmallerThanTheV2FileOfTheSameContent(): void
    {
        Security::setFileEncryptBlocksBytes(1);
        $payload = self::bytes(1000, 'size');
        [, $enc] = $this->assertRoundTrip($payload);

        $v2 = strlen(self::referenceEncryptV2($payload, self::KEY, '?', 1));
        $this->assertLessThan($v2 * 0.6, filesize($enc), 'v3 ' . filesize($enc) . " bytes vs v2 {$v2} bytes");
    }

    /** The header names the key: a keyring goes straight to it, and a missing key is named. */
    public function testTheHeaderNamesTheKeyAndAKeyringUsesIt(): void
    {
        $src = $this->write('k.src', 'written under the newer key');
        $enc = Security::encryptFile($src, new Keyring(self::OTHER_KEY, self::KEY), $this->path('k.enc'));
        $this->assertSame(self::referenceKeyId(self::OTHER_KEY), self::decodeBlocks(file_get_contents($enc))[2]);

        $dec = Security::decryptFile($enc, new Keyring(self::KEY, self::OTHER_KEY), $this->path('k.dec'));
        $this->assertSame('written under the newer key', file_get_contents($dec));

        $before = $this->dirEntries();
        try {
            Security::decryptFile($enc, self::KEY, $this->path('k2.dec'));
            $this->fail('A file under a key the keyring does not hold must be refused.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('No key with id ' . self::referenceKeyId(self::OTHER_KEY), $e->getMessage());
        }
        $this->assertSame($before, $this->dirEntries());
    }

    /** For a rotation: which key wrote a file, read from the header alone. */
    public function testFileKeyIdReadsTheKeyIdFromTheHeader(): void
    {
        $src = $this->write('id.src', 'x');
        $underKey = Security::encryptFile($src, self::KEY, $this->path('k.enc'));
        $underOther = Security::encryptFile($src, new Keyring(self::OTHER_KEY, self::KEY), $this->path('o.enc'));
        $legacy = $this->write('legacy-id.enc', base64_decode(self::LEGACY_FILE_B64));

        $this->assertSame(self::KEY_ID, Security::fileKeyId($underKey));
        $this->assertSame(self::referenceKeyId(self::OTHER_KEY), Security::fileKeyId($underOther));
        foreach (['a v2 file' => [$legacy, 'only file format v3 is supported'],
                  'not an encrypted file' => [$this->write('junk', self::encodeBlocks(['not a cipher'])), 'Cipher type does not match']] as $label => [$path, $message]) {
            try {
                Security::fileKeyId($path);
                $this->fail("{$label} must be refused.");
            } catch (\Exception $e) {
                $this->assertStringContainsString($message, $e->getMessage(), $label);
            }
        }
    }

    /** The v2 file the former encryptFileV2 wrote is refused under its own key, leaving nothing behind. */
    public function testTheLegacyV2FileIsRefused(): void
    {
        $this->assertSame('v2', self::decodeBlocks(base64_decode(self::LEGACY_FILE_B64))[1], 'premise: a v2 file');
        $legacy = $this->write('legacy.enc', base64_decode(self::LEGACY_FILE_B64));
        $destination = $this->write('legacy.dec', 'PREVIOUS');

        try {
            Security::decryptFile($legacy, self::LEGACY_KEY, $destination);
            $this->fail('A v2 file must be refused.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('only file format v3 is supported', $e->getMessage());
        }

        $this->assertSame('PREVIOUS', file_get_contents($destination));
        $this->assertSame(['legacy.dec', 'legacy.enc'], $this->dirEntries());
    }

    // ---------------------------------------------------------------------------------------
    // Streaming: memory stays bounded by the block size, not the file size
    // ---------------------------------------------------------------------------------------

    /**
     * @return array{0:int,1:int} [encrypt peak, decrypt peak] in bytes above the baseline
     */
    private function measureRoundTripPeaks(int $sizeMiB, string $name): array
    {
        $src = $this->path("{$name}.src");
        $fp = fopen($src, 'wb');
        for ($i = 0; $i < $sizeMiB; $i++) {
            fwrite($fp, random_bytes(1024 * 1024));
        }
        fclose($fp);

        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $enc = Security::encryptFile($src, self::KEY, $this->path("{$name}.enc"));
        $encryptPeak = memory_get_peak_usage() - $baseline;

        gc_collect_cycles();
        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $dec = Security::decryptFile($enc, self::KEY, $this->path("{$name}.dec"));
        $decryptPeak = memory_get_peak_usage() - $baseline;

        $this->assertSame(hash_file('sha256', $src), hash_file('sha256', $dec));
        $this->assertSame($sizeMiB * 1024 * 1024, filesize($dec));

        return [$encryptPeak, $decryptPeak];
    }

    public function testAMultiBlockFileIsStreamedNotLoaded(): void
    {
        Security::setFileEncryptBlocksBytes(1024 * 1024);

        [$encryptPeak, $decryptPeak] = $this->measureRoundTripPeaks(24, 'stream');

        // 24 MiB through 1 MiB blocks: a few copies of ONE block, nowhere near the file.
        $this->assertLessThan(8 * 1024 * 1024, $encryptPeak, "encrypt peak {$encryptPeak} bytes");
        $this->assertLessThan(8 * 1024 * 1024, $decryptPeak, "decrypt peak {$decryptPeak} bytes");
    }

    /** A genuinely large file through the DEFAULT block size (~21 blocks). */
    #[Group('slow')]
    public function testALargeFileRoundTripsWithBoundedMemoryAtTheDefaultBlockSize(): void
    {
        [$encryptPeak, $decryptPeak] = $this->measureRoundTripPeaks(64, 'large');

        $this->assertLessThan(32 * 1024 * 1024, $encryptPeak, "encrypt peak {$encryptPeak} bytes for a 64 MiB file");
        $this->assertLessThan(32 * 1024 * 1024, $decryptPeak, "decrypt peak {$decryptPeak} bytes for a 64 MiB file");
    }

    // ---------------------------------------------------------------------------------------
    // The max-encoded-block limit, enforced on BOTH sides
    // ---------------------------------------------------------------------------------------

    /** A 284-byte block plus its 16-byte tag is 300 bytes, which encode to exactly 400: a limit of exactly 400 must work. */
    public function testAnEncodedBlockExactlyAtTheLimitRoundTrips(): void
    {
        Security::setFileEncryptBlocksBytes(284);
        Security::setFileMaxEncodedBlockBytes(400);

        foreach ([284, 568, 852, 853] as $size) {
            [, $enc] = $this->assertRoundTrip(self::bytes($size, "limit{$size}"), null, "limit{$size}");

            $raw = file_get_contents($enc);
            $declared = array_map(
                static fn (array $span): int => (int) substr($raw, $span[0], strpos($raw, '-', $span[0]) - $span[0]),
                self::blockSpans($raw)
            );
            $this->assertSame(400, max($declared), "size {$size}: the largest block must sit exactly at the limit");
        }
    }

    /**
     * THE BUG: the limit was enforced on read only, so encrypting with a block that encodes above
     * it "succeeded" and wrote a file nothing could decrypt. It must fail at ENCRYPT time, before
     * the destination (or its directory) exists.
     */
    public function testALimitOneByteBelowTheEncodedBlockFailsAtEncryptTimeLeavingNothing(): void
    {
        Security::setFileEncryptBlocksBytes(284);
        Security::setFileMaxEncodedBlockBytes(399);
        $src = $this->write('src', self::bytes(300));
        $destination = $this->path('not-created' . DIRECTORY_SEPARATOR . 'out.enc');

        try {
            Security::encryptFile($src, self::KEY, $destination);
            $this->fail('An over-limit block size must be refused at encrypt time.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('setFileEncryptBlocksBytes', $e->getMessage());
            $this->assertStringContainsString('setFileMaxEncodedBlockBytes', $e->getMessage());
            $this->assertStringContainsString('400-byte base64 block', $e->getMessage());
            $this->assertStringContainsString('at most 281 bytes', $e->getMessage());
        }

        $this->assertSame(['src'], $this->dirEntries(), 'Not even the destination directory may be created.');
    }

    public function testAnOverLimitRefusalLeavesAPreExistingDestinationUntouched(): void
    {
        Security::setFileEncryptBlocksBytes(300);
        Security::setFileMaxEncodedBlockBytes(399);
        $src = $this->write('src', self::bytes(10));
        $destination = $this->write('precious.enc', 'PRECIOUS');

        try {
            Security::encryptFile($src, self::KEY, $destination);
            $this->fail('Expected the over-limit refusal.');
        } catch (\Exception) {
        }

        $this->assertSame('PRECIOUS', file_get_contents($destination));
        $this->assertSame(['precious.enc', 'src'], $this->dirEntries());
    }

    /**
     * The exact threshold under the DEFAULT limit: 3*floor(268435456/4) - 16 = 201.326.576 bytes
     * (the block is written with its 16-byte tag). One byte more and the file could never be
     * decrypted. Refused before a single byte is read, so this costs no 200 MB allocation.
     */
    public function testTheDefaultLimitRefusesABlockSizeOneByteAboveTheDecryptableThreshold(): void
    {
        Security::setFileEncryptBlocksBytes(201326577);
        $src = $this->write('src', 'tiny');

        try {
            Security::encryptFile($src, self::KEY, $this->path('out.enc'));
            $this->fail('A block size above 201326576 bytes must be refused under the default limit.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('at most 201326576 bytes', $e->getMessage());
            $this->assertStringContainsString('268435460-byte base64 block', $e->getMessage());
        }

        $this->assertSame(['src'], $this->dirEntries());
    }

    /**
     * ...and exactly AT the threshold it is accepted. Reads are capped at what is left of the
     * file (fread() allocates its full length up front), so a 192 MiB block size costs nothing on
     * a small file — it used to allocate the whole block for every file.
     */
    public function testTheDefaultLimitAcceptsTheLargestDecryptableBlockSizeWithoutAllocatingIt(): void
    {
        Security::setFileEncryptBlocksBytes(201326576);

        memory_reset_peak_usage();
        $baseline = memory_get_usage();
        $this->assertRoundTrip('tiny payload');

        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $baseline);
    }

    public function testAnAbsurdBlockSizeIsRefusedWithoutIntegerOverflow(): void
    {
        Security::setFileEncryptBlocksBytes(PHP_INT_MAX);
        Security::setFileMaxEncodedBlockBytes(PHP_INT_MAX);
        $src = $this->write('src', 'tiny');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('max-encoded-block limit');
        Security::encryptFile($src, self::KEY, $this->path('out.enc'));
    }

    /** The salt is a header block, so it is bound by the same limit. */
    public function testAnOversizedSaltIsRefusedAtEncryptTime(): void
    {
        Security::setFileEncryptBlocksBytes(30);
        Security::setFileMaxEncodedBlockBytes(400);

        $this->assertRoundTrip(self::bytes(100), str_repeat('s', 300)); // 400 encoded: fits exactly
        $before = $this->dirEntries();

        try {
            Security::encryptFile($this->path('rt.src'), self::KEY, $this->path('salt.enc'), str_repeat('s', 301));
            $this->fail('A salt whose header block exceeds the limit must be refused.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('301-byte salt', $e->getMessage());
            $this->assertStringContainsString('setFileMaxEncodedBlockBytes', $e->getMessage());
        }

        $this->assertSame($before, $this->dirEntries());
    }

    /**
     * CROSS-PROCESS: a file encrypted under a raised limit, decrypted where the limit is lower.
     * The error must say which limit to raise and to what, and nothing may be written — in either
     * mode; then raising the limit makes the same file decrypt.
     */
    public function testAFileWrittenUnderARaisedLimitNeedsThatLimitToDecrypt(): void
    {
        Security::setFileEncryptBlocksBytes(284);
        Security::setFileMaxEncodedBlockBytes(400);
        $payload = self::bytes(1000, 'xproc');
        $src = $this->write('src', $payload);
        $enc = Security::encryptFile($src, self::KEY, $this->path('file.enc'));

        // "Another process", running with a smaller limit.
        Security::setFileEncryptBlocksBytes(null);
        Security::setFileMaxEncodedBlockBytes(399);

        $replaced = $this->write('replaced.dec', 'OLD CONTENT');
        $appended = $this->write('appended.dec', 'PART ONE;');
        foreach ([[$replaced, 'w', 'OLD CONTENT'], [$appended, 'a', 'PART ONE;']] as [$destination, $mode, $expected]) {
            try {
                Security::decryptFile($enc, self::KEY, $destination, null, $mode);
                $this->fail("Mode {$mode}: a block above the current limit must be refused.");
            } catch (\Exception $e) {
                $this->assertStringContainsString('declares 400 encoded bytes', $e->getMessage());
                $this->assertStringContainsString('limit of 399 bytes', $e->getMessage());
                $this->assertStringContainsString('setFileMaxEncodedBlockBytes', $e->getMessage());
            }
            $this->assertSame($expected, file_get_contents($destination), "Mode {$mode}: the destination changed.");
        }
        $this->assertSame(['appended.dec', 'file.enc', 'replaced.dec', 'src'], $this->dirEntries());

        Security::setFileMaxEncodedBlockBytes(400);
        Security::decryptFile($enc, self::KEY, $replaced);
        Security::decryptFile($enc, self::KEY, $appended, null, 'a');

        $this->assertSame($payload, file_get_contents($replaced));
        $this->assertSame('PART ONE;' . $payload, file_get_contents($appended));
    }

    /** A hostile length is refused BEFORE it is read: no giant allocation ahead of authentication. */
    #[DataProvider('hostileLengthProvider')]
    public function testAHostileDeclaredLengthIsRefusedBeforeAnyAllocation(string $prefix): void
    {
        memory_reset_peak_usage();
        $baseline = memory_get_usage();

        $this->assertDecryptRejects($prefix . 'QUJD', $prefix, 'above the max-encoded-block limit');

        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $baseline);
    }

    public static function hostileLengthProvider(): array
    {
        return [
            'limit + 1'             => ['268435457-'],
            '12 digits'             => ['999999999999-'],
            'beyond PHP_INT_MAX'    => ['99999999999999999999999999-'],
        ];
    }

    /**
     * A length WITHIN the limit but longer than the file: fread() would allocate the whole
     * declared size (here 256 MiB) before discovering the file is 14 bytes long.
     */
    public function testALengthWithinTheLimitButBeyondTheFileCostsNoAllocation(): void
    {
        memory_reset_peak_usage();
        $baseline = memory_get_usage();

        $this->assertDecryptRejects('268435456-QUJD', 'exactly the limit', 'truncated');

        $this->assertLessThan(1024 * 1024, memory_get_peak_usage() - $baseline);
    }

    // ---------------------------------------------------------------------------------------
    // Adversarial decrypt
    // ---------------------------------------------------------------------------------------

    public function testWrongKeyIsRejected(): void
    {
        $raw = $this->smallEncryptedFile();
        $crafted = $this->write('wrongkey.enc', $raw);
        $before = $this->dirEntries();

        try {
            Security::decryptFile($crafted, self::OTHER_KEY, $this->path('wrongkey.dec'));
            $this->fail('A wrong key must be rejected.');
        } catch (\Exception $e) {
            // The header names the key it needs; a different key has a different id.
            $this->assertStringContainsString('No key with id ' . self::KEY_ID, $e->getMessage());
        }

        $this->assertSame($before, $this->dirEntries());
    }

    /** Pointing the header at another key of the keyring cannot select it: every block then fails. */
    public function testRewritingTheKeyIdInTheHeaderIsRejected(): void
    {
        $blocks = self::decodeBlocks($this->smallEncryptedFile());
        $blocks[2] = self::referenceKeyId(self::OTHER_KEY);
        $crafted = $this->write('kid.enc', self::encodeBlocks($blocks));
        $before = $this->dirEntries();

        try {
            Security::decryptFile($crafted, new Keyring(self::KEY, self::OTHER_KEY), $this->path('kid.dec'));
            $this->fail('A rewritten key id must be rejected.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('failed authentication', $e->getMessage());
        }

        $this->assertSame($before, $this->dirEntries());
    }

    /** A short key is refused before anything is resolved — the destination directory included. */
    public function testAShortKeyIsRefusedBeforeTheDestinationDirectoryIsCreated(): void
    {
        $enc = $this->write('file.enc', $this->smallEncryptedFile());
        $before = $this->dirEntries();

        foreach (['encrypt' => fn () => Security::encryptFile($enc, str_repeat('k', 31), $this->path('new' . DIRECTORY_SEPARATOR . 'x')),
                  'decrypt' => fn () => Security::decryptFile($enc, str_repeat('k', 31), $this->path('new' . DIRECTORY_SEPARATOR . 'x'))] as $label => $call) {
            try {
                $call();
                $this->fail("{$label}: a 31-byte key must be refused.");
            } catch (\Exception $e) {
                $this->assertStringContainsString('at least 32 bytes', $e->getMessage(), $label);
            }
            $this->assertSame($before, $this->dirEntries(), $label);
        }
    }

    /**
     * EVERY single-bit flip anywhere in the file must be rejected. Before the canonical-base64
     * check, flipping one of the unused low bits of a base64 block's last character (the tag's,
     * for instance) decoded to identical bytes and the modified file decrypted "successfully";
     * '+16-' and '016-' length spellings were accepted too.
     */
    #[Group('slow')]
    public function testEverySingleBitFlipAnywhereInTheFileIsRejected(): void
    {
        $this->assertEveryBitFlipIsRejected(range(0, 7));
    }

    /** The fast subset of the sweep above: bit 0 turns digits into digits and letters into letters. */
    public function testFlippingTheLowBitOfEveryByteIsRejected(): void
    {
        $this->assertEveryBitFlipIsRejected([0]);
    }

    /** @param int[] $bits */
    private function assertEveryBitFlipIsRejected(array $bits): void
    {
        $raw = $this->smallEncryptedFile('flip', self::bytes(40, 'flip'));

        for ($offset = 0; $offset < strlen($raw); $offset++) {
            foreach ($bits as $bit) {
                $mutated = $raw;
                $mutated[$offset] = chr(ord($raw[$offset]) ^ (1 << $bit));
                $this->assertDecryptRejects($mutated, "offset {$offset}, bit {$bit}");
            }
        }
    }

    /**
     * The same, but SEMANTIC: each decoded block gets a bit flipped and is re-encoded canonically,
     * so the container is perfectly well-formed and only the cryptography can notice.
     */
    public function testATamperedBlockIsRejectedWhereverItIs(): void
    {
        $blocks = self::decodeBlocks($this->smallEncryptedFile('semantic'));
        $names = ['cipher', 'version', 'key id', 'salt', 'file id'];
        $last = count($blocks) - 1;

        foreach ($blocks as $k => $block) {
            foreach ([0, strlen($block) - 1] as $position) {
                $mutated = $blocks;
                $mutated[$k][$position] = chr(ord($block[$position]) ^ 0x01);
                $name = $names[$k] ?? ($k === $last ? 'end marker' : 'data block ' . ($k - self::HEADER_BLOCKS));
                $this->assertDecryptRejects(self::encodeBlocks($mutated), "{$name}, byte {$position}");
            }
        }
    }

    public function testTruncationAtEveryByteOffsetIsRejected(): void
    {
        $raw = $this->smallEncryptedFile('trunc', self::bytes(40, 'trunc'));

        for ($length = 0; $length < strlen($raw); $length++) {
            $this->assertDecryptRejects(substr($raw, 0, $length), "truncated to {$length} bytes");
        }
    }

    public function testDroppingWholeTrailingBlocksIsReportedAsTruncation(): void
    {
        $blocks = self::decodeBlocks($this->smallEncryptedFile());

        $this->assertDecryptRejects(self::encodeBlocks(array_slice($blocks, 0, -1)), 'end marker dropped', 'truncated');
        $this->assertDecryptRejects(self::encodeBlocks(array_slice($blocks, 0, -2)), 'last data block + marker dropped', 'truncated');
        $this->assertDecryptRejects(self::encodeBlocks(array_slice($blocks, 0, self::HEADER_BLOCKS)), 'header only', 'truncated');
        $this->assertDecryptRejects(self::encodeBlocks(array_slice($blocks, 0, self::HEADER_BLOCKS - 1)), 'no file id', 'truncated');
    }

    /**
     * Structural attacks on whole blocks: each must fail authentication.
     *
     * @return array<string, array{0: string}>
     */
    public static function blockAttackProvider(): array
    {
        return [
            'swap first two data blocks'    => ['swap01'],
            'swap last data block & marker' => ['swapLastMarker'],
            'duplicate a data block'        => ['duplicate'],
            'drop a middle data block'      => ['dropMiddle'],
            'drop the first data block'     => ['dropFirst'],
            'marker moved to the front'     => ['markerFirst'],
        ];
    }

    #[DataProvider('blockAttackProvider')]
    public function testReorderingDuplicatingOrDroppingBlocksIsRejected(string $attack): void
    {
        $blocks = self::decodeBlocks($this->smallEncryptedFile());
        $header = array_slice($blocks, 0, self::HEADER_BLOCKS);
        $body = array_slice($blocks, self::HEADER_BLOCKS);
        $last = count($body) - 1; // the end marker

        switch ($attack) {
            case 'swap01':
                [$body[0], $body[1]] = [$body[1], $body[0]];
                break;
            case 'swapLastMarker':
                [$body[$last - 1], $body[$last]] = [$body[$last], $body[$last - 1]];
                break;
            case 'duplicate':
                array_splice($body, 2, 0, [$body[2]]);
                break;
            case 'dropMiddle':
                array_splice($body, 3, 1);
                break;
            case 'dropFirst':
                array_shift($body);
                break;
            case 'markerFirst':
                array_unshift($body, array_pop($body));
                break;
        }

        $this->assertDecryptRejects(self::encodeBlocks(array_merge($header, $body)), $attack);
    }

    /** Same key, same salt, same position — only the per-file id (hence the file key) tells the files apart. */
    public function testBlocksSplicedFromAnotherFileUnderTheSameKeyAreRejected(): void
    {
        $a = self::decodeBlocks($this->smallEncryptedFile('a', self::bytes(100, 'same')));
        $b = self::decodeBlocks($this->smallEncryptedFile('b', self::bytes(100, 'same')));
        $this->assertSame(count($a), count($b));
        $last = count($a) - 1;

        $dataFromB = $a;
        $dataFromB[self::HEADER_BLOCKS + 1] = $b[self::HEADER_BLOCKS + 1];
        $this->assertDecryptRejects(self::encodeBlocks($dataFromB), 'data block 1 from file B');

        $markerFromB = $a;
        $markerFromB[$last] = $b[$last];
        $this->assertDecryptRejects(self::encodeBlocks($markerFromB), 'end marker from file B (same block count)');

        $headerFromB = array_merge(array_slice($b, 0, self::HEADER_BLOCKS), array_slice($a, self::HEADER_BLOCKS));
        $this->assertDecryptRejects(self::encodeBlocks($headerFromB), 'header (file id) from file B');
    }

    #[DataProvider('appendedGarbageProvider')]
    public function testAnythingAppendedAfterTheEndMarkerIsRejected(string $suffix): void
    {
        $raw = $this->smallEncryptedFile();
        $blocks = self::decodeBlocks($raw);
        $suffix = match ($suffix) {
            'MARKER' => self::encodeBlocks(array_slice($blocks, -1)),
            'BLOCK'  => self::encodeBlocks([$blocks[self::HEADER_BLOCKS]]),
            default  => $suffix,
        };

        $this->assertDecryptRejects($raw . $suffix, 'suffix ' . bin2hex($suffix), 'Trailing data after end-of-file marker');
    }

    public static function appendedGarbageProvider(): array
    {
        return [
            'one letter'             => ['x'],
            'a zero'                 => ['0'],
            'a dash'                 => ['-'],
            'a newline'              => ["\n"],
            'a NUL'                  => ["\0"],
            'a valid block'          => ['4-QUJD'],
            'the end marker again'   => ['MARKER'],
            'a data block again'     => ['BLOCK'],
        ];
    }

    /**
     * One file, one byte sequence: non-canonical spellings of the same content are rejected, even
     * though every one of them decodes to the original bytes.
     */
    #[DataProvider('nonCanonicalEncodingProvider')]
    public function testNonCanonicalContainerEncodingsAreRejected(string $variant): void
    {
        $raw = $this->smallEncryptedFile();
        $spans = self::blockSpans($raw);
        [$start, $end] = $spans[2]; // the key id: 16 bytes, "==" padded, 4 unused bits
        $block = substr($raw, $start, $end - $start);
        [$len, $payload] = explode('-', $block, 2);
        $len = (int) $len;

        // 16 bytes = 128 bits in 22 characters (132 bits): the last character carries 4 bits that
        // a canonical encoder always leaves 0. Set one of them — in the base64 VALUE, not the ASCII.
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $lastChar = $alphabet[strpos($alphabet, $payload[21]) | 0x01];

        $replacement = match ($variant) {
            'plus sign'    => '+' . $block,
            'leading zero' => '0' . $block,
            'space'        => ($len + 1) . '-' . substr($payload, 0, 4) . ' ' . substr($payload, 4),
            'newline'      => ($len + 1) . '-' . substr($payload, 0, 4) . "\n" . substr($payload, 4),
            'no padding'   => ($len - 2) . '-' . rtrim($payload, '='),
            'padding bits' => $len . '-' . substr($payload, 0, 21) . $lastChar . '==',
        };
        // Premise: every variant still decodes (non-strictly) to the very same tag bytes.
        $decodedPayload = base64_decode(explode('-', ltrim($replacement, '+0'), 2)[1]);
        $this->assertSame(base64_decode($payload), $decodedPayload, "premise for {$variant}");

        $mutated = substr($raw, 0, $start) . $replacement . substr($raw, $end);
        $this->assertDecryptRejects($mutated, $variant);
    }

    public static function nonCanonicalEncodingProvider(): array
    {
        return array_combine(
            ['plus sign', 'leading zero', 'space', 'newline', 'no padding', 'padding bits'],
            [['plus sign'], ['leading zero'], ['space'], ['newline'], ['no padding'], ['padding bits']]
        );
    }

    #[DataProvider('notAnEncryptedFileProvider')]
    public function testSomethingThatIsNotAnEncryptedFileIsRejected(string $bytes, string $message): void
    {
        $this->assertDecryptRejects($bytes, bin2hex(substr($bytes, 0, 16)), $message);
    }

    public static function notAnEncryptedFileProvider(): array
    {
        $fileId = str_repeat('a', 32);

        return [
            'empty file'                   => ['', 'truncated'],
            'plain text'                   => ['hello world', 'malformed block length'],
            'zero-length block'            => ['0-', 'malformed block length'],
            'other cipher'                 => [self::encodeBlocks(['aes-128-gcm', 'v3', self::KEY_ID, '?', $fileId]), 'Cipher type does not match'],
            'unknown version'              => [self::encodeBlocks(['aes-256-gcm', 'v9', self::KEY_ID, '?', $fileId]), 'Cipher version does not match'],
            'v3: malformed key id'         => [self::encodeBlocks(['aes-256-gcm', 'v3', 'not-a-key-id', '?', $fileId]), 'malformed key id'],
            'v3: unknown key id'           => [self::encodeBlocks(['aes-256-gcm', 'v3', str_repeat('0', 16), '?', $fileId]), 'No key with id 0000000000000000'],
            'v3: malformed file id'        => [self::encodeBlocks(['aes-256-gcm', 'v3', self::KEY_ID, '?', 'short']), 'malformed file id'],
            'v3: block shorter than a tag' => [self::encodeBlocks(['aes-256-gcm', 'v3', self::KEY_ID, '?', $fileId, str_repeat('t', 16)]), 'shorter than ciphertext plus tag'],
            'v3: header only'              => [self::encodeBlocks(['aes-256-gcm', 'v3', self::KEY_ID, '?', $fileId]), 'truncated'],
            'v2 (no longer read)'          => [self::encodeBlocks(['aes-256-gcm', 'v2', '?', $fileId]), 'only file format v3 is supported'],
            'v1 (no longer read)'          => [self::encodeBlocks(['aes-256-gcm', 'v1', '?', $fileId]), 'only file format v3 is supported'],
            'length never ends'            => [str_repeat('1', 5), 'truncated'],
        ];
    }

    /**
     * NEW CONTRACT: a failed decrypt leaves a pre-existing destination EXACTLY as it was. The old
     * code wrote plaintext into it as it went and then deleted it on failure — destroying the
     * caller's file for a bad input — and a crash midway left truncated plaintext behind under the
     * destination's name.
     */
    public function testAFailedDecryptLeavesAPreExistingDestinationExactlyAsItWas(): void
    {
        $blocks = self::decodeBlocks($this->smallEncryptedFile());
        $last = count($blocks) - 1;
        $blocks[$last - 1][0] = chr(ord($blocks[$last - 1][0]) ^ 1); // the LAST data block: 6 decrypt first
        $crafted = $this->write('crafted.enc', self::encodeBlocks($blocks));

        foreach (['w', 'a'] as $mode) {
            $destination = $this->write("existing-{$mode}.dec", "PREVIOUS CONTENT ({$mode})");
            try {
                Security::decryptFile($crafted, self::KEY, $destination, null, $mode);
                $this->fail('A tampered block must be rejected.');
            } catch (\Exception) {
            }
            $this->assertSame("PREVIOUS CONTENT ({$mode})", file_get_contents($destination), "mode {$mode}");
        }

        $this->assertSame(['crafted.enc', 'existing-a.dec', 'existing-w.dec', 'small.enc', 'small.src'], $this->dirEntries());
    }

    public function testAppendModeReassemblesPartsAndAFailedPartChangesNothing(): void
    {
        $partA = $this->write('a.src', self::bytes(50, 'A'));
        $partB = $this->write('b.src', self::bytes(70, 'B'));
        Security::setFileEncryptBlocksBytes(16);
        $encA = Security::encryptFile($partA, self::KEY, $this->path('a.enc'));
        $encB = Security::encryptFile($partB, self::KEY, $this->path('b.enc'));
        $bad = $this->write('bad.enc', substr(file_get_contents($encB), 0, -10));

        $joined = $this->path('joined');
        Security::decryptFile($encA, self::KEY, $joined, null, 'a'); // creates it
        try {
            Security::decryptFile($bad, self::KEY, $joined, null, 'a');
            $this->fail('A truncated part must be rejected.');
        } catch (\Exception) {
        }
        $this->assertSame(self::bytes(50, 'A'), file_get_contents($joined), 'The failed part must not change the destination.');

        Security::decryptFile($encB, self::KEY, $joined, null, 'ab');
        $this->assertSame(self::bytes(50, 'A') . self::bytes(70, 'B'), file_get_contents($joined));
    }

    // ---------------------------------------------------------------------------------------
    // Paths, sources and destinations
    // ---------------------------------------------------------------------------------------

    #[DataProvider('equivalentSpellingProvider')]
    public function testDecryptRefusesEquivalentSpellingsOfTheSourceAsDestination(string $mutation): void
    {
        $enc = $this->write('same.enc', $this->smallEncryptedFile());
        $before = file_get_contents($enc);

        if ($mutation === 'case' && DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Path case only aliases the same file on Windows.');
        }
        $destination = match ($mutation) {
            'identical'      => $enc,
            'dot-slash'      => $this->dir . DIRECTORY_SEPARATOR . '.' . DIRECTORY_SEPARATOR . 'same.enc',
            'trailing-slash' => $enc . DIRECTORY_SEPARATOR,
            'dot-dot'        => $this->dir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . basename($this->dir) . DIRECTORY_SEPARATOR . 'same.enc',
            'case'           => $this->dir . DIRECTORY_SEPARATOR . 'SAME.ENC',
            'hard link'      => $this->path('hardlink.enc'),
        };
        if ($mutation === 'hard link' && !@link($enc, $destination)) {
            $this->markTestSkipped('Hard links are not available here.');
        }

        foreach (['w', 'a'] as $mode) {
            try {
                Security::decryptFile($enc, self::KEY, $destination, null, $mode);
                $this->fail("{$mutation}/{$mode}: decrypting onto the source must be refused.");
            } catch (\Exception $e) {
                $this->assertStringContainsString('must not be the same file', $e->getMessage());
            }
            $this->assertSame($before, file_get_contents($enc), "{$mutation}/{$mode}: the ciphertext changed");
        }
    }

    public static function equivalentSpellingProvider(): array
    {
        $cases = ['identical', 'dot-slash', 'trailing-slash', 'dot-dot', 'case', 'hard link'];

        return array_combine($cases, array_map(static fn ($c) => [$c], $cases));
    }

    public function testAMissingSourceIsRefusedAndTouchesNothing(): void
    {
        $destination = $this->write('precious', 'PRECIOUS');
        $missing = $this->path('does-not-exist');

        foreach (['encrypt' => fn () => Security::encryptFile($missing, self::KEY, $destination),
                  'decrypt' => fn () => Security::decryptFile($missing, self::KEY, $destination),
                  'empty path' => fn () => Security::encryptFile('', self::KEY, $destination)] as $label => $call) {
            try {
                $call();
                $this->fail("{$label}: a missing source must be refused.");
            } catch (\Exception $e) {
                $this->assertStringContainsString('File not found', $e->getMessage(), $label);
            }
            $this->assertSame('PRECIOUS', file_get_contents($destination), $label);
        }
        $this->assertSame(['precious'], $this->dirEntries());
    }

    /**
     * realpath() resolves directories too. On POSIX a directory read as a file came back empty, so
     * encrypting one "succeeded" with a ciphertext of nothing; on Windows fopen() warned.
     */
    public function testADirectoryIsRefusedAsASource(): void
    {
        mkdir($this->path('folder'));

        foreach (['encrypt' => fn () => Security::encryptFile($this->path('folder'), self::KEY, $this->path('out')),
                  'decrypt' => fn () => Security::decryptFile($this->path('folder'), self::KEY, $this->path('out'))] as $label => $call) {
            try {
                $call();
                $this->fail("{$label}: a directory must not be accepted as the source.");
            } catch (\Exception $e) {
                $this->assertStringContainsString('not a regular file', $e->getMessage(), $label);
            }
        }
        $this->assertSame(['folder'], $this->dirEntries());
    }

    /** empty() rejected a destination file literally named "0". */
    public function testFilesNamedZeroWorkAsSourceAndDestination(): void
    {
        mkdir($this->path('in'));
        mkdir($this->path('out'));
        $src = $this->write('in' . DIRECTORY_SEPARATOR . '0', 'zero-named payload');

        $enc = Security::encryptFile($src, self::KEY, $this->path('out' . DIRECTORY_SEPARATOR . '0'));
        $this->assertSame(realpath($this->path('out' . DIRECTORY_SEPARATOR . '0')), $enc);

        $dec = Security::decryptFile($enc, self::KEY, $this->path('0'));
        $this->assertSame('zero-named payload', file_get_contents($dec));
    }

    /**
     * A blank path must throw — never resolve to the current directory — and a NUL byte must end in
     * an \Exception: realpath() raises a ValueError for it, which slipped past every documented
     * `catch (\Exception)`. Run from inside the test directory so anything created in the cwd shows.
     */
    #[DataProvider('unusablePathProvider')]
    public function testBlankAndNulPathsAreRefusedWithAnException(string $side, string $path): void
    {
        $src = $this->write('src', 'x');
        $enc = Security::encryptFile($src, self::KEY, $this->path('src.enc'));
        $before = $this->dirEntries();

        $cwd = getcwd();
        chdir($this->dir);
        try {
            foreach (['encrypt', 'decrypt'] as $direction) {
                $input = ($direction === 'encrypt' ? $src : $enc);
                try {
                    if ($direction === 'encrypt') {
                        $side === 'source'
                            ? Security::encryptFile($path, self::KEY, $this->path('out'))
                            : Security::encryptFile($input, self::KEY, $path);
                    } else {
                        $side === 'source'
                            ? Security::decryptFile($path, self::KEY, $this->path('out'))
                            : Security::decryptFile($input, self::KEY, $path);
                    }
                    $this->fail("{$direction}: the {$side} path " . json_encode($path) . ' must be refused.');
                } catch (\Exception $e) {
                    $this->assertStringContainsString(
                        $side === 'source' ? 'File not found' : 'Invalid destination path',
                        $e->getMessage(),
                        $direction
                    );
                }
                $this->assertSame($before, $this->dirEntries(), "{$direction}: something was created");
            }
        } finally {
            chdir($cwd);
        }
    }

    public static function unusablePathProvider(): array
    {
        return [
            'empty source'          => ['source', ''],
            'blank source'          => ['source', '   '],
            'NUL in source'         => ['source', "src\0.txt"],
            'empty destination'     => ['destination', ''],
            'blank destination'     => ['destination', "  \t "],
            'NUL in destination'    => ['destination', "out\0.enc"],
        ];
    }

    public function testAnExistingDirectoryIsRefusedAsTheDestination(): void
    {
        $src = $this->write('src', 'x');
        mkdir($this->path('folder'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Invalid destination path');
        Security::encryptFile($src, self::KEY, $this->path('folder'));
    }

    /**
     * A malformed mode used to fall back SILENTLY to the 0755 default — wider than asked for. It
     * is refused now, before anything (even the destination directory) is created.
     */
    #[DataProvider('malformedModeProvider')]
    public function testAMalformedPermissionModeIsRefusedBeforeAnythingIsTouched(string $mode): void
    {
        $src = $this->write('src', 'x');
        $enc = Security::encryptFile($src, self::KEY, $this->path('ok.enc'));
        $before = $this->dirEntries();
        $destination = $this->path('sub' . DIRECTORY_SEPARATOR . 'out');

        foreach (['encrypt' => fn () => Security::encryptFile($src, self::KEY, $destination, null, $mode),
                  'decrypt' => fn () => Security::decryptFile($enc, self::KEY, $destination, $mode)] as $label => $call) {
            try {
                $call();
                $this->fail("{$label}: mode '{$mode}' must be refused.");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid permission mode', $e->getMessage(), $label);
            }
            $this->assertSame($before, $this->dirEntries(), $label);
        }
    }

    public static function malformedModeProvider(): array
    {
        return [
            'empty'        => [''],
            'not octal'    => ['0999'],
            'letters'      => ['rw-r--r--'],
            'too large'    => ['17777'],
            'too long'     => ['0000644'],
            'sign'         => ['-644'],
            'spaces'       => [' 644'],
        ];
    }

    /**
     * The mode used to be chmod()ed onto the destination BEFORE fopen() created it, so a NEW
     * destination never got it: a decrypted secret asked for as '0600' landed world-readable.
     * Windows models only the read-only bit, which is enough to prove the mode is applied.
     */
    public function testAPermissionModeIsAppliedToANewDestination(): void
    {
        $src = $this->write('src', 'SECRET');
        $windows = (DIRECTORY_SEPARATOR === '\\');
        $readOnly = ($windows ? '0444' : '0400');
        $enc = Security::encryptFile($src, self::KEY, $this->path('new.enc'), null, $readOnly);
        $dec = Security::decryptFile($enc, self::KEY, $this->path('new.dec'), $readOnly);
        clearstatcache();
        $this->assertSame('SECRET', file_get_contents($dec));

        if ($windows) {
            $this->assertFalse(is_writable($enc), 'encryptFile did not apply the mode to a new file');
            $this->assertFalse(is_writable($dec), 'decryptFile did not apply the mode to a new file');

            return;
        }

        // Exact bits rather than is_writable(): root may write a 0400 file.
        $this->assertSame(0400, fileperms($enc) & 0777, 'encryptFile did not apply the mode to a new file');
        $this->assertSame(0400, fileperms($dec) & 0777, 'decryptFile did not apply the mode to a new file');
        chmod($enc, 0600);
        Security::decryptFile($enc, self::KEY, $this->path('secret.dec'), '0600');
        clearstatcache();
        $this->assertSame(0600, fileperms($this->path('secret.dec')) & 0777);
    }

    /** Root bypasses permission bits on POSIX, so a "read-only" premise cannot hold there. */
    private function skipWhenPermissionsAreBypassed(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            $this->markTestSkipped('Running as root: a read-only file is still writable.');
        }
    }

    /**
     * The FILE mode used to be passed on as the DIRECTORY mode for a missing parent: '0600' made a
     * directory with no execute bit, so nothing could be created inside it on POSIX.
     */
    public function testAMissingDirectoryIsCreatedUsableEvenWithAFileOnlyMode(): void
    {
        $src = $this->write('src', 'payload');
        $destination = $this->path('made' . DIRECTORY_SEPARATOR . 'deeper' . DIRECTORY_SEPARATOR . 'out.enc');

        $enc = Security::encryptFile($src, self::KEY, $destination, null, '0600');
        $dec = Security::decryptFile($enc, self::KEY, $this->path('made2' . DIRECTORY_SEPARATOR . 'out.dec'), '0600');

        $this->assertSame('payload', file_get_contents($dec));
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->assertNotSame(0, fileperms(dirname($destination)) & 0100, 'The directory must be traversable by its owner.');
        }
    }

    /** A read-only destination is not overwritten unless a mode says so (it used to be chmod'ed to 0755). */
    public function testAReadOnlyDestinationIsOnlyReplacedWhenAModeIsGiven(): void
    {
        $this->skipWhenPermissionsAreBypassed();

        $src = $this->write('src', 'NEW');
        $destination = $this->write('ro.enc', 'READ-ONLY CONTENT');
        chmod($destination, 0444);
        clearstatcache();

        try {
            Security::encryptFile($src, self::KEY, $destination);
            $this->fail('A read-only destination must not be replaced without a mode.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('read-only', $e->getMessage());
        }
        $this->assertSame('READ-ONLY CONTENT', file_get_contents($destination));

        Security::encryptFile($src, self::KEY, $destination, null, '0644');
        $this->assertSame('NEW', file_get_contents(Security::decryptFile($destination, self::KEY, $this->path('ro.dec'))));
    }

    /**
     * The staging file holds plaintext while decryptFile writes it. Permissions are checked at
     * open time, so it must be owner-only from the moment it exists — not narrowed afterwards.
     */
    public function testTheStagingFileIsCreatedOwnerOnly(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows models only the read-only bit; exact modes are asserted on POSIX.');
        }

        $previousUmask = umask(0022);
        try {
            [$path, $fp] = (new \ReflectionMethod(Security::class, 'openStagingFile'))->invoke(null, $this->path('target'));
            fclose($fp);
            clearstatcache();

            $this->assertSame(0600, fileperms($path) & 0777);
            $this->assertSame(0022, umask(), 'The process umask must be restored.');
        } finally {
            umask($previousUmask);
        }
    }

    /** With no mode, a replaced destination keeps its permission bits: a 0600 file must not come back 0644. */
    public function testReplacingADestinationKeepsItsPermissionBitsWhenNoModeIsGiven(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows models only the read-only bit; exact modes are asserted on POSIX.');
        }

        $src = $this->write('src', 'SECRET');
        $enc = Security::encryptFile($src, self::KEY, $this->path('file.enc'));
        $destination = $this->write('private.dec', 'old');
        chmod($destination, 0600);

        Security::decryptFile($enc, self::KEY, $destination);
        clearstatcache();

        $this->assertSame(0600, fileperms($destination) & 0777);
        $this->assertSame('SECRET', file_get_contents($destination));
    }

    // ---------------------------------------------------------------------------------------
    // Read failures mid-stream (Windows byte-range locks make them reproducible)
    // ---------------------------------------------------------------------------------------

    /**
     * The source becomes unreadable after encryptFile has started. The old code had already
     * truncated the destination by then; now a pre-existing destination survives and no staging
     * file is left. A byte-range lock (mandatory on Windows) makes every read fail.
     */
    public function testEncryptFailsLoudlyOnAnUnreadableSourceAndKeepsTheDestination(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('flock() is advisory on POSIX: it cannot make a read fail.');
        }

        $src = $this->write('locked.src', self::bytes(10000));
        $destination = $this->write('precious.enc', 'PRECIOUS');
        $lock = fopen($src, 'rb');
        $this->assertTrue(flock($lock, LOCK_EX), 'Premise: the source can be locked.');

        try {
            Security::encryptFile($src, self::KEY, $destination);
            $this->fail('An unreadable source must fail the encryption.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Error on reading plaintext', $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame('PRECIOUS', file_get_contents($destination));
        $this->assertSame(['locked.src', 'precious.enc'], $this->dirEntries());
    }

    public function testDecryptFailsLoudlyOnAnUnreadableSourceAndKeepsTheDestination(): void
    {
        if (DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('flock() is advisory on POSIX: it cannot make a read fail.');
        }

        $enc = $this->write('locked.enc', $this->smallEncryptedFile());
        $destination = $this->write('precious.dec', 'PRECIOUS');
        $before = $this->dirEntries();
        $lock = fopen($enc, 'rb');
        $this->assertTrue(flock($lock, LOCK_EX), 'Premise: the source can be locked.');

        try {
            Security::decryptFile($enc, self::KEY, $destination);
            $this->fail('An unreadable source must fail the decryption.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Error on reading', $e->getMessage());
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        $this->assertSame('PRECIOUS', file_get_contents($destination));
        $this->assertSame($before, $this->dirEntries());
    }

    // ---------------------------------------------------------------------------------------
    // Short writes (disk full / quota) are failures, not successes
    // ---------------------------------------------------------------------------------------

    /**
     * fwrite() reports a full disk as a SHORT COUNT, not false. A user-space stream that accepts
     * only a few bytes reproduces that exactly, and every byte either direction writes goes
     * through these two helpers.
     */
    public function testAShortWriteIsAnErrorInBothWriteHelpers(): void
    {
        $protocol = 'phphtshort' . bin2hex(random_bytes(4));
        stream_wrapper_register($protocol, SecurityFileEncryptionShortWriteStream::class);

        try {
            $writeAll = new \ReflectionMethod(Security::class, 'writeAll');
            $writeBlock = new \ReflectionMethod(Security::class, 'writeLengthEncodedBlock');

            SecurityFileEncryptionShortWriteStream::$capacity = 5;
            $fp = fopen("{$protocol}://x", 'wb');
            try {
                $writeAll->invoke(null, $fp, 'abcdefghij', 'plaintext');
                $this->fail('A 5-of-10-byte write must be an error.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('only 5 of 10 bytes', $e->getMessage());
            }
            fclose($fp);

            SecurityFileEncryptionShortWriteStream::$capacity = 7;
            $fp = fopen("{$protocol}://x", 'wb');
            try {
                $writeBlock->invoke(null, $fp, 'hello world', 'ciphertext');
                $this->fail('A block cut short must be an error.');
            } catch (\Exception $e) {
                $this->assertStringContainsString('Error on writing ciphertext', $e->getMessage());
            }
            fclose($fp);

            // And a stream with room writes the exact "{len}-{base64}" envelope.
            SecurityFileEncryptionShortWriteStream::$capacity = 1000;
            SecurityFileEncryptionShortWriteStream::$written = '';
            $fp = fopen("{$protocol}://x", 'wb');
            $writeBlock->invoke(null, $fp, 'hello world', 'ciphertext');
            fclose($fp);
            $this->assertSame('16-aGVsbG8gd29ybGQ=', SecurityFileEncryptionShortWriteStream::$written);
        } finally {
            stream_wrapper_unregister($protocol);
        }
    }
}

/** A stream that accepts at most $capacity bytes, then reports 0 — i.e. a full disk. */
final class SecurityFileEncryptionShortWriteStream
{
    /** @var resource|null */
    public $context;

    public static int $capacity = 0;
    public static string $written = '';

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$written = '';

        return true;
    }

    public function stream_write(string $data): int
    {
        $room = self::$capacity - strlen(self::$written);
        if ($room <= 0) {
            return 0;
        }
        $chunk = substr($data, 0, $room);
        self::$written .= $chunk;

        return strlen($chunk);
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }
}
