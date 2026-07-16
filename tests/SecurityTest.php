<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Security;

/**
 * Contract & crypto-conformance tests for VD\PHPHelper\Security.
 *
 * Supersedes the former standalone tests/SecurityCryptoTest.php (hand-rolled ok()/throws(), no
 * PHPUnit dependency, executed as loose script output by the test runner). Every invariant it
 * covered is preserved here: AAD context binding, version binding, tamper rejection, blind-index
 * determinism, key-length enforcement, and chunked-file integrity (round-trip, truncation,
 * reorder, splice, tamper).
 *
 * Tests named *_pinsFinding* / documented as such fail without the corresponding docblock-vs-code
 * fix; they exist to stop the contract from rotting back.
 */
final class SecurityTest extends TestCase
{
    /** Row-scoped AAD contexts, shaped like the real "{table}.{column}:{row_id}" usage. */
    private const AAD  = 'product_formula.name_encrypted:1900-0000-7000-8000-000000000001';
    private const AAD2 = 'product_formula.name_encrypted:1900-0000-7000-8000-000000000002';

    /** @var string[] Temp files created by a test, removed in tearDown. */
    private array $tempPaths = [];

    /** 32-byte master key (the minimum this class enforces). */
    private static function masterKey(): string
    {
        return str_repeat('k', 32);
    }

    /** A different 32-byte master key. */
    private static function otherKey(): string
    {
        return str_repeat('z', 32);
    }

    /** Returns a unique path inside the system temp dir, registered for cleanup. */
    private function tempPath(string $prefix): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'phpht_' . $prefix . '_' . bin2hex(random_bytes(8)) . '.bin';
        $this->tempPaths[] = $path;

        return $path;
    }

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->tempPaths = [];

        // setFileEncryptBlocksBytes is PROCESS-GLOBAL static state; leaking a tiny block size into
        // another test class would be a cross-test hazard.
        Security::setFileEncryptBlocksBytes(null);
    }

    /**
     * Splits the V2 file format into its raw "{len}-{base64}" blocks so a test can manipulate the
     * ciphertext structurally. Layout: [cipher][version][salt][fileId] then [iv][tag][ct] triples,
     * then the authenticated end-marker triple.
     *
     * @return string[] The base64 payload of each block, in file order
     */
    private static function parseBlocks(string $blob): array
    {
        $blocks = [];
        $i = 0;
        $n = strlen($blob);
        while ($i < $n) {
            $j = strpos($blob, '-', $i);
            if ($j === false) {
                break;
            }
            $len = (int) substr($blob, $i, $j - $i);
            $blocks[] = substr($blob, $j + 1, $len);
            $i = $j + 1 + $len;
        }

        return $blocks;
    }

    /** Inverse of parseBlocks(). */
    private static function rebuildBlocks(array $blocks): string
    {
        $out = '';
        foreach ($blocks as $block) {
            $out .= strlen($block) . '-' . $block;
        }

        return $out;
    }

    /**
     * Encrypts $payload to a fresh temp file.
     *
     * @return array{0:string,1:string} [sourcePath, encryptedPath]
     */
    private function makeEncryptedFile(string $payload, ?string $key = null): array
    {
        $src = $this->tempPath('src');
        $enc = $this->tempPath('enc');
        file_put_contents($src, $payload);
        Security::encryptFileV2($src, $key ?? self::masterKey(), $enc);

        return [$src, $enc];
    }

    // ---------------------------------------------------------------------------------------
    // encryptDataDB / decryptDataDB — the DB-cell envelope
    // ---------------------------------------------------------------------------------------

    public function testEncryptDataDbEmitsVersionedEnvelopeAndRoundTrips(): void
    {
        $plain = 'Formula #1: NaOH 4%';
        $envelope = Security::encryptDataDB($plain, self::masterKey(), self::AAD);

        $this->assertStringStartsWith('v1:', $envelope);
        $this->assertSame($plain, Security::decryptDataDB($envelope, self::masterKey(), self::AAD));
    }

    /** The whole point of the AAD: a ciphertext must not survive relocation to another row. */
    public function testDecryptDataDbRejectsCiphertextRelocatedToAnotherAad(): void
    {
        $envelope = Security::encryptDataDB('secret', self::masterKey(), self::AAD);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('authentication tag mismatch');
        Security::decryptDataDB($envelope, self::masterKey(), self::AAD2);
    }

    public function testDecryptDataDbRejectsWrongKey(): void
    {
        $envelope = Security::encryptDataDB('secret', self::masterKey(), self::AAD);

        $this->expectException(\Exception::class);
        Security::decryptDataDB($envelope, self::otherKey(), self::AAD);
    }

    public function testDecryptDataDbRejectsWrongSalt(): void
    {
        $envelope = Security::encryptDataDB('secret', self::masterKey(), self::AAD, 'salt-a');

        $this->expectException(\Exception::class);
        Security::decryptDataDB($envelope, self::masterKey(), self::AAD, 'salt-b');
    }

    public function testDecryptDataDbRejectsTamperedCiphertext(): void
    {
        $envelope = Security::encryptDataDB('secret', self::masterKey(), self::AAD);

        $raw = base64_decode(substr($envelope, 3));
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);

        $this->expectException(\Exception::class);
        Security::decryptDataDB('v1:' . base64_encode($raw), self::masterKey(), self::AAD);
    }

    public function testDecryptDataDbRejectsUnknownEnvelopeVersion(): void
    {
        $envelope = Security::encryptDataDB('secret', self::masterKey(), self::AAD);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported envelope version');
        Security::decryptDataDB('v9:' . substr($envelope, 3), self::masterKey(), self::AAD);
    }

    public function testDecryptDataDbRejectsMissingVersionPrefix(): void
    {
        $envelope = Security::encryptDataDB('secret', self::masterKey(), self::AAD);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('missing version prefix');
        Security::decryptDataDB(substr($envelope, 3), self::masterKey(), self::AAD);
    }

    public function testDecryptDataDbRejectsTooShortPayload(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('too short');
        Security::decryptDataDB('v1:' . base64_encode('abc'), self::masterKey(), self::AAD);
    }

    public function testEncryptDataDbRejectsEmptyAad(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('non-empty AAD');
        Security::encryptDataDB('x', self::masterKey(), '');
    }

    public function testDecryptDataDbRejectsEmptyAad(): void
    {
        $envelope = Security::encryptDataDB('x', self::masterKey(), self::AAD);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('non-empty AAD');
        Security::decryptDataDB($envelope, self::masterKey(), '');
    }

    public function testEmptyValuesRoundTripToEmptyStringWithoutEncrypting(): void
    {
        $this->assertSame('', Security::encryptDataDB('', self::masterKey(), self::AAD));
        $this->assertSame('', Security::encryptDataDB(null, self::masterKey(), self::AAD));
        $this->assertSame('', Security::decryptDataDB('', self::masterKey(), self::AAD));
        $this->assertSame('', Security::decryptDataDB(null, self::masterKey(), self::AAD));
    }

    /** A fresh GCM nonce per call: identical plaintexts must not produce identical envelopes. */
    public function testEncryptDataDbUsesFreshIvPerCall(): void
    {
        $a = Security::encryptDataDB('same', self::masterKey(), self::AAD);
        $b = Security::encryptDataDB('same', self::masterKey(), self::AAD);

        $this->assertNotSame($a, $b);
        $this->assertSame('same', Security::decryptDataDB($a, self::masterKey(), self::AAD));
        $this->assertSame('same', Security::decryptDataDB($b, self::masterKey(), self::AAD));
    }

    public function testEncryptDataDbNormalizesBoolsToInts(): void
    {
        $envelope = Security::encryptDataDB(true, self::masterKey(), self::AAD);
        $this->assertSame('1', Security::decryptDataDB($envelope, self::masterKey(), self::AAD));

        // false is NOT empty-string, so it encrypts to "0" rather than short-circuiting to "".
        $envelope = Security::encryptDataDB(false, self::masterKey(), self::AAD);
        $this->assertSame('0', Security::decryptDataDB($envelope, self::masterKey(), self::AAD));
    }

    // ---------------------------------------------------------------------------------------
    // Key-length enforcement (HKDF cannot add entropy: a short master must be refused)
    // ---------------------------------------------------------------------------------------

    public function testEncryptDataDbRejectsKeyShorterThan32Bytes(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('at least 32 bytes');
        Security::encryptDataDB('x', str_repeat('k', 16), self::AAD);
    }

    public function testEncryptDataDbRejectsKeyOneByteShort(): void
    {
        $this->expectException(\Exception::class);
        Security::encryptDataDB('x', str_repeat('k', 31), self::AAD);
    }

    public function testGenerateSearchHashRejectsKeyOneByteShort(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('at least 32 bytes');
        Security::generateSearchHash('x', str_repeat('k', 31));
    }

    public function testEncryptFileV2RejectsShortKey(): void
    {
        $src = $this->tempPath('src');
        file_put_contents($src, 'payload');

        $this->expectException(\Exception::class);
        Security::encryptFileV2($src, str_repeat('k', 31), $this->tempPath('enc'));
    }

    // ---------------------------------------------------------------------------------------
    // generateSearchHash — the blind index
    // ---------------------------------------------------------------------------------------

    public function testGenerateSearchHashIsSixtyFourHexChars(): void
    {
        $hash = Security::generateSearchHash('alice@example.com', self::masterKey());

        $this->assertSame(64, strlen($hash));
        $this->assertTrue(ctype_xdigit($hash));
    }

    /** A blind index is useless unless it is perfectly deterministic — that is how lookups match. */
    public function testGenerateSearchHashIsDeterministicForEqualInput(): void
    {
        $this->assertSame(
            Security::generateSearchHash('alice@example.com', self::masterKey()),
            Security::generateSearchHash('alice@example.com', self::masterKey())
        );
    }

    public function testGenerateSearchHashDiffersByInputKeyAndSalt(): void
    {
        $alice = Security::generateSearchHash('alice@example.com', self::masterKey());

        $this->assertNotSame($alice, Security::generateSearchHash('bob@example.com', self::masterKey()));
        $this->assertNotSame($alice, Security::generateSearchHash('alice@example.com', self::otherKey()));
        $this->assertNotSame($alice, Security::generateSearchHash('alice@example.com', self::masterKey(), 'tenant-1'));
    }

    /** null and "" must normalize identically, or a caller's index silently splits in two. */
    public function testGenerateSearchHashTreatsNullSaltAsEmptySalt(): void
    {
        $this->assertSame(
            Security::generateSearchHash('x', self::masterKey(), null),
            Security::generateSearchHash('x', self::masterKey(), '')
        );
    }

    public function testGenerateSearchHashReturnsEmptyStringForNullOrEmptyInput(): void
    {
        $this->assertSame('', Security::generateSearchHash(null, self::masterKey()));
        $this->assertSame('', Security::generateSearchHash('', self::masterKey()));
    }

    public function testGenerateSearchHashNormalizesBoolsToInts(): void
    {
        $this->assertSame(
            Security::generateSearchHash(1, self::masterKey()),
            Security::generateSearchHash(true, self::masterKey())
        );
    }

    // ---------------------------------------------------------------------------------------
    // encryptLocal / decryptLocal — AES-256-CTR + HMAC-SHA256
    // ---------------------------------------------------------------------------------------

    public function testEncryptLocalRoundTrips(): void
    {
        $encrypted = Security::encryptLocal('local secret', self::masterKey());

        $this->assertNotSame('local secret', $encrypted);
        $this->assertSame('local secret', Security::decryptLocal($encrypted, self::masterKey()));
    }

    /** Encrypt-then-MAC: a flipped byte must be caught by the HMAC, before any decryption. */
    public function testDecryptLocalRejectsTamperedPayload(): void
    {
        $encrypted = Security::encryptLocal('local secret', self::masterKey());
        $raw = base64_decode($encrypted);
        $raw[strlen($raw) - 1] = chr(ord($raw[strlen($raw) - 1]) ^ 0x01);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('MAC does not match');
        Security::decryptLocal(base64_encode($raw), self::masterKey());
    }

    public function testDecryptLocalRejectsWrongKey(): void
    {
        $encrypted = Security::encryptLocal('local secret', self::masterKey());

        $this->expectException(\Exception::class);
        Security::decryptLocal($encrypted, self::otherKey());
    }

    public function testDecryptLocalRejectsTooShortPayload(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('too short');
        Security::decryptLocal(base64_encode('short'), self::masterKey());
    }

    public function testEncryptLocalRejectsKeyShorterThan16Bytes(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('at least 16 bytes');
        Security::encryptLocal('x', str_repeat('k', 15));
    }

    /**
     * encryptLocal advertises a 16-byte minimum but delegates to deriveKey, which enforces 32.
     * A 16..31-byte key is therefore still refused — pinned so the stricter floor cannot silently
     * regress into the weaker advertised one.
     */
    public function testEncryptLocalStillRefusesKeysBelowTheThirtyTwoByteDerivationFloor(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('at least 32 bytes');
        Security::encryptLocal('x', str_repeat('k', 16));
    }

    public function testEncryptLocalReturnsEmptyStringForNullOrEmpty(): void
    {
        $this->assertSame('', Security::encryptLocal(null, self::masterKey()));
        $this->assertSame('', Security::encryptLocal('', self::masterKey()));
        $this->assertSame('', Security::decryptLocal(null, self::masterKey()));
        $this->assertSame('', Security::decryptLocal('', self::masterKey()));
    }

    public function testEncryptLocalUsesFreshNoncePerCall(): void
    {
        $this->assertNotSame(
            Security::encryptLocal('same', self::masterKey()),
            Security::encryptLocal('same', self::masterKey())
        );
    }

    public function testEncryptLocalSaltSeparatesKeyspaces(): void
    {
        $encrypted = Security::encryptLocal('secret', self::masterKey(), 'tenant-1');

        $this->assertSame('secret', Security::decryptLocal($encrypted, self::masterKey(), 'tenant-1'));

        $this->expectException(\Exception::class);
        Security::decryptLocal($encrypted, self::masterKey(), 'tenant-2');
    }

    // ---------------------------------------------------------------------------------------
    // encryptCrossPlatform / decryptCrossPlatform
    // ---------------------------------------------------------------------------------------

    public function testEncryptCrossPlatformRoundTrips(): void
    {
        $encrypted = Security::encryptCrossPlatform('hi', self::masterKey());

        $this->assertIsString($encrypted);
        $this->assertNotSame('hi', $encrypted);
        $this->assertSame('hi', Security::decryptCrossPlatform($encrypted, self::masterKey()));
    }

    public function testEncryptCrossPlatformPreservesBooleansThroughMarkers(): void
    {
        $this->assertTrue(Security::decryptCrossPlatform(
            Security::encryptCrossPlatform(true, self::masterKey()),
            self::masterKey()
        ));
        $this->assertFalse(Security::decryptCrossPlatform(
            Security::encryptCrossPlatform(false, self::masterKey()),
            self::masterKey()
        ));
    }

    public function testEncryptCrossPlatformPassesThroughNullAndEmptyUnchanged(): void
    {
        $this->assertNull(Security::encryptCrossPlatform(null, self::masterKey()));
        $this->assertSame('', Security::encryptCrossPlatform('', self::masterKey()));
        $this->assertNull(Security::decryptCrossPlatform(null, self::masterKey()));
        $this->assertSame('', Security::decryptCrossPlatform('', self::masterKey()));
    }

    public function testEncryptCrossPlatformRejectsShortKey(): void
    {
        $this->expectException(\Exception::class);
        Security::encryptCrossPlatform('x', str_repeat('k', 15));
    }

    // ---------------------------------------------------------------------------------------
    // Passwords (Argon2id)
    // ---------------------------------------------------------------------------------------

    public function testEncryptPasswordProducesVerifiableArgon2idHash(): void
    {
        $hash = Security::encryptPassword('s3nha-forte');

        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(Security::verifyPassword('s3nha-forte', $hash));
    }

    public function testVerifyPasswordRejectsWrongPassword(): void
    {
        $hash = Security::encryptPassword('s3nha-forte');

        $this->assertFalse(Security::verifyPassword('errada', $hash));
    }

    /** Argon2id embeds a random salt: the same password must never yield the same hash. */
    public function testEncryptPasswordProducesDistinctHashesForTheSamePassword(): void
    {
        $a = Security::encryptPassword('s3nha-forte');
        $b = Security::encryptPassword('s3nha-forte');

        $this->assertNotSame($a, $b);
        $this->assertTrue(Security::verifyPassword('s3nha-forte', $a));
        $this->assertTrue(Security::verifyPassword('s3nha-forte', $b));
    }

    /**
     * Pins the empty()-vs-strict fix: empty() reports the literal password "0" as empty, so the old
     * code returned "" instead of a hash and permanently locked out anyone whose password was "0".
     * Fails without the fix.
     */
    public function testEncryptPasswordHashesTheLiteralZeroPassword(): void
    {
        $hash = Security::encryptPassword('0');

        $this->assertNotSame('', $hash);
        $this->assertStringStartsWith('$argon2id$', $hash);
        $this->assertTrue(Security::verifyPassword('0', $hash));
        $this->assertFalse(Security::verifyPassword('1', $hash));
    }

    /**
     * Pins the fail-loud fix: an empty password must never be silently turned into "", which a
     * caller would then persist as if it were a hash. Fails without the fix (used to return "").
     */
    public function testEncryptPasswordThrowsOnEmptyStringInsteadOfReturningNonHash(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot hash an empty password');
        Security::encryptPassword('');
    }

    public function testEncryptPasswordThrowsOnNullInsteadOfReturningNonHash(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot hash an empty password');
        Security::encryptPassword(null);
    }

    /** Fail-closed: a non-hash in the column must never authenticate anybody. */
    public function testVerifyPasswordReturnsFalseForNonHashValues(): void
    {
        $this->assertFalse(Security::verifyPassword('anything', ''));
        $this->assertFalse(Security::verifyPassword('', ''));
        $this->assertFalse(Security::verifyPassword('anything', 'not-a-hash'));
    }

    /**
     * Pins the documented \TypeError: verifyPassword is deliberately non-nullable, so a NULL
     * password column (SSO-only / not-yet-activated user) fails loudly rather than being coerced.
     * The docblock now says so; this proves it, and proves catch(\Exception) does NOT stop it.
     */
    public function testVerifyPasswordRaisesTypeErrorOnNullHash(): void
    {
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line — deliberately violating the signature to pin the contract. */
        Security::verifyPassword('pw', null);
    }

    public function testVerifyPasswordRaisesTypeErrorOnNullPassword(): void
    {
        $hash = Security::encryptPassword('pw');

        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line — deliberately violating the signature to pin the contract. */
        Security::verifyPassword(null, $hash);
    }

    // ---------------------------------------------------------------------------------------
    // setFileEncryptBlocksBytes / getFileEncryptBlocksBytes
    // ---------------------------------------------------------------------------------------

    public function testGetFileEncryptBlocksBytesInstallsDefaultWhenUnset(): void
    {
        Security::setFileEncryptBlocksBytes(null);

        $this->assertSame(3200000, Security::getFileEncryptBlocksBytes());
    }

    public function testSetFileEncryptBlocksBytesStoresAPositiveValue(): void
    {
        Security::setFileEncryptBlocksBytes(4096);

        $this->assertSame(4096, Security::getFileEncryptBlocksBytes());
    }

    /**
     * Pins the reset fix. The doc always promised "pass null and the default is used on next get",
     * but null used to hit an empty() guard and return WITHOUT touching the static — a silent no-op
     * that let a previously-set custom value survive forever in a long-lived worker. Fails without
     * the fix (would return 200000000).
     */
    public function testSetFileEncryptBlocksBytesNullActuallyResetsAPreviouslySetValue(): void
    {
        Security::setFileEncryptBlocksBytes(200000000);
        $this->assertSame(200000000, Security::getFileEncryptBlocksBytes());

        Security::setFileEncryptBlocksBytes(null);

        $this->assertSame(3200000, Security::getFileEncryptBlocksBytes());
    }

    /** A non-positive block size is a caller bug: rejected loudly, never silently ignored. */
    public function testSetFileEncryptBlocksBytesRejectsZero(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('must be >= 1 byte');
        Security::setFileEncryptBlocksBytes(0);
    }

    public function testSetFileEncryptBlocksBytesRejectsNegative(): void
    {
        $this->expectException(\Exception::class);
        Security::setFileEncryptBlocksBytes(-1);
    }

    /** A rejected value must not clobber the value already in effect. */
    public function testRejectedBlockSizeLeavesTheCurrentValueIntact(): void
    {
        Security::setFileEncryptBlocksBytes(4096);

        try {
            Security::setFileEncryptBlocksBytes(-1);
            $this->fail('Expected an Exception for a negative block size.');
        } catch (\Exception) {
            // expected
        }

        $this->assertSame(4096, Security::getFileEncryptBlocksBytes());
    }

    // ---------------------------------------------------------------------------------------
    // encryptFileV2 / decryptFileV2 — authenticated chunked file format
    // ---------------------------------------------------------------------------------------

    public function testFileRoundTripMatchesAcrossManyBlocks(): void
    {
        Security::setFileEncryptBlocksBytes(16); // force ~7 data blocks + trailer
        $payload = random_bytes(100);
        [, $enc] = $this->makeEncryptedFile($payload);
        $dec = $this->tempPath('dec');

        $returned = Security::decryptFileV2($enc, self::masterKey(), $dec);

        $this->assertFileExists($returned);
        $this->assertSame(realpath($dec), $returned);
        $this->assertSame($payload, file_get_contents($dec));
    }

    public function testFileRoundTripWithSaltEmbedsSaltSoDecryptNeedsNoSaltArgument(): void
    {
        $payload = 'salted payload';
        $src = $this->tempPath('src');
        $enc = $this->tempPath('enc');
        $dec = $this->tempPath('dec');
        file_put_contents($src, $payload);

        Security::encryptFileV2($src, self::masterKey(), $enc, 'per-subject-salt');
        Security::decryptFileV2($enc, self::masterKey(), $dec);

        $this->assertSame($payload, file_get_contents($dec));
    }

    public function testFileRoundTripOfEmptySourceProducesEmptyOutput(): void
    {
        $src = $this->tempPath('src');
        $enc = $this->tempPath('enc');
        $dec = $this->tempPath('dec');
        file_put_contents($src, '');

        Security::encryptFileV2($src, self::masterKey(), $enc);
        Security::decryptFileV2($enc, self::masterKey(), $dec);

        $this->assertSame('', file_get_contents($dec));
    }

    /** Truncation: dropping the authenticated end marker must be detected. */
    public function testFileTruncationDroppingEndMarkerIsRejected(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocks = self::parseBlocks(file_get_contents($enc));
        file_put_contents($enc, self::rebuildBlocks(array_slice($blocks, 0, count($blocks) - 3)));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('truncated');
        Security::decryptFileV2($enc, self::masterKey(), $dec);
    }

    /** Reorder: the AAD binds each block to its index, so swapping two triples must fail. */
    public function testFileBlockReorderIsRejected(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocks = self::parseBlocks(file_get_contents($enc));
        for ($k = 0; $k < 3; $k++) {
            $tmp = $blocks[4 + $k];
            $blocks[4 + $k] = $blocks[7 + $k];
            $blocks[7 + $k] = $tmp;
        }
        file_put_contents($enc, self::rebuildBlocks($blocks));

        $this->expectException(\Exception::class);
        Security::decryptFileV2($enc, self::masterKey(), $dec);
    }

    public function testFileCiphertextTamperIsRejected(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocks = self::parseBlocks(file_get_contents($enc));
        $bin = base64_decode($blocks[6]); // first triple's ciphertext
        $bin[0] = chr(ord($bin[0]) ^ 0x01);
        $blocks[6] = base64_encode($bin);
        file_put_contents($enc, self::rebuildBlocks($blocks));

        $this->expectException(\Exception::class);
        Security::decryptFileV2($enc, self::masterKey(), $dec);
    }

    /** The header is not separately MAC'd — it is bound via the fileId in every block's AAD. */
    public function testFileHeaderFileIdTamperIsRejected(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocks = self::parseBlocks(file_get_contents($enc));
        $blocks[3] = base64_encode(str_repeat('0', 32)); // fileId
        file_put_contents($enc, self::rebuildBlocks($blocks));

        $this->expectException(\Exception::class);
        Security::decryptFileV2($enc, self::masterKey(), $dec);
    }

    /** Tampering the stored salt changes the derived key, so every block fails to authenticate. */
    public function testFileHeaderSaltTamperIsRejected(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocks = self::parseBlocks(file_get_contents($enc));
        $blocks[2] = base64_encode('another-salt');
        file_put_contents($enc, self::rebuildBlocks($blocks));

        $this->expectException(\Exception::class);
        Security::decryptFileV2($enc, self::masterKey(), $dec);
    }

    public function testFileVersionTamperIsRejected(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocks = self::parseBlocks(file_get_contents($enc));
        $blocks[1] = base64_encode('v3');
        file_put_contents($enc, self::rebuildBlocks($blocks));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cipher version does not match');
        Security::decryptFileV2($enc, self::masterKey(), $dec);
    }

    /** Cross-file splice: blocks from another file (different fileId) must not authenticate. */
    public function testFileCrossFileBlockSpliceIsRejected(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $encA] = $this->makeEncryptedFile(random_bytes(100));
        [, $encB] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocksA = self::parseBlocks(file_get_contents($encA));
        $blocksB = self::parseBlocks(file_get_contents($encB));
        for ($k = 0; $k < 3; $k++) {
            $blocksA[4 + $k] = $blocksB[4 + $k];
        }
        file_put_contents($encA, self::rebuildBlocks($blocksA));

        $this->expectException(\Exception::class);
        Security::decryptFileV2($encA, self::masterKey(), $dec);
    }

    /** The end marker must be the LAST block: appended data must not be silently ignored. */
    public function testFileTrailingDataAfterEndMarkerIsRejected(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocks = self::parseBlocks(file_get_contents($enc));
        $blocks[] = $blocks[4];
        $blocks[] = $blocks[5];
        $blocks[] = $blocks[6];
        file_put_contents($enc, self::rebuildBlocks($blocks));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Trailing data after end-of-file marker');
        Security::decryptFileV2($enc, self::masterKey(), $dec);
    }

    public function testFileWrongKeyIsRejected(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $this->expectException(\Exception::class);
        Security::decryptFileV2($enc, self::otherKey(), $dec);
    }

    public function testEncryptFileV2ThrowsOnMissingSource(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');
        Security::encryptFileV2(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpht_does_not_exist_' . bin2hex(random_bytes(6)),
            self::masterKey(),
            $this->tempPath('enc')
        );
    }

    public function testDecryptFileV2ThrowsOnMissingSource(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('File not found');
        Security::decryptFileV2(
            sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpht_does_not_exist_' . bin2hex(random_bytes(6)),
            self::masterKey(),
            $this->tempPath('dec')
        );
    }

    /** A failed decrypt must not leave unauthenticated plaintext behind. */
    public function testFailedDecryptRemovesThePartiallyWrittenDestination(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocks = self::parseBlocks(file_get_contents($enc));
        $bin = base64_decode($blocks[12]); // 3rd triple's ciphertext: blocks 0 and 1 write first
        $bin[0] = chr(ord($bin[0]) ^ 0x01);
        $blocks[12] = base64_encode($bin);
        file_put_contents($enc, self::rebuildBlocks($blocks));

        try {
            Security::decryptFileV2($enc, self::masterKey(), $dec);
            $this->fail('Expected an Exception for a tampered block.');
        } catch (\Exception) {
            // expected
        }

        $this->assertFileDoesNotExist($dec);
    }

    // ---------------------------------------------------------------------------------------
    // decryptFileV2 — $outReadMode contract (findings: documented 'w'/'a' vs accepted 'wb'/'ab')
    // ---------------------------------------------------------------------------------------

    /**
     * Pins the $outReadMode fix. The doc has always named 'w' and 'a', but the validator only
     * accepted 'wb'/'ab' and silently rewrote everything else to 'wb' — so the documented 'a'
     * TRUNCATED the destination instead of appending, destroying every previously-appended part.
     * Fails without the fix (the destination would contain only part 2).
     */
    public function testDecryptFileV2AppendModeAppendsInsteadOfSilentlyTruncating(): void
    {
        $partA = 'AAAAAAAAAAAAAAAA';
        $partB = 'BBBBBBBBBBBBBBBB';
        [, $encA] = $this->makeEncryptedFile($partA);
        [, $encB] = $this->makeEncryptedFile($partB);
        $dec = $this->tempPath('dec');

        Security::decryptFileV2($encA, self::masterKey(), $dec, null, 'w');
        Security::decryptFileV2($encB, self::masterKey(), $dec, null, 'a');

        $this->assertSame($partA . $partB, file_get_contents($dec));
    }

    /** 'ab' is the binary spelling of the same documented mode and must behave identically. */
    public function testDecryptFileV2AcceptsBinarySpellingOfAppendMode(): void
    {
        $partA = 'AAAA';
        $partB = 'BBBB';
        [, $encA] = $this->makeEncryptedFile($partA);
        [, $encB] = $this->makeEncryptedFile($partB);
        $dec = $this->tempPath('dec');

        Security::decryptFileV2($encA, self::masterKey(), $dec, null, 'wb');
        Security::decryptFileV2($encB, self::masterKey(), $dec, null, 'ab');

        $this->assertSame($partA . $partB, file_get_contents($dec));
    }

    /** The default mode truncates, so decrypting twice must not double the content. */
    public function testDecryptFileV2DefaultModeTruncates(): void
    {
        $payload = 'payload';
        [, $enc] = $this->makeEncryptedFile($payload);
        $dec = $this->tempPath('dec');

        Security::decryptFileV2($enc, self::masterKey(), $dec);
        Security::decryptFileV2($enc, self::masterKey(), $dec);

        $this->assertSame($payload, file_get_contents($dec));
    }

    /**
     * An unrecognized mode must be REJECTED, never silently rewritten to the truncating default —
     * that silent substitution is exactly what destroyed data before. Fails without the fix.
     */
    public function testDecryptFileV2RejectsUnknownOutReadModeInsteadOfDefaultingToTruncate(): void
    {
        [, $enc] = $this->makeEncryptedFile('payload');
        $dec = $this->tempPath('dec');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage("Invalid \$outReadMode");
        Security::decryptFileV2($enc, self::masterKey(), $dec, null, 'append');
    }

    public function testDecryptFileV2RejectsEmptyOutReadMode(): void
    {
        [, $enc] = $this->makeEncryptedFile('payload');
        $dec = $this->tempPath('dec');

        $this->expectException(\Exception::class);
        Security::decryptFileV2($enc, self::masterKey(), $dec, null, '');
    }

    /**
     * With append mode working, the error path must not destroy data it did not write. A bad part 3
     * must roll the destination back to exactly its pre-call length, leaving parts 1-2 intact —
     * otherwise the multi-part use case the mode exists for loses the whole archive on one bad part.
     */
    public function testFailedAppendRollsBackWithoutDestroyingPreviouslyAppendedParts(): void
    {
        Security::setFileEncryptBlocksBytes(16);

        $partA = 'AAAAAAAAAAAAAAAA';
        [, $encA] = $this->makeEncryptedFile($partA);
        [, $encB] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        Security::decryptFileV2($encA, self::masterKey(), $dec, null, 'w');
        $this->assertSame($partA, file_get_contents($dec));

        // Tamper the 3rd data triple of part B: blocks 0-1 decrypt and get appended, then it fails.
        $blocks = self::parseBlocks(file_get_contents($encB));
        $bin = base64_decode($blocks[12]);
        $bin[0] = chr(ord($bin[0]) ^ 0x01);
        $blocks[12] = base64_encode($bin);
        file_put_contents($encB, self::rebuildBlocks($blocks));

        try {
            Security::decryptFileV2($encB, self::masterKey(), $dec, null, 'a');
            $this->fail('Expected an Exception for a tampered block.');
        } catch (\Exception) {
            // expected
        }

        $this->assertFileExists($dec);
        $this->assertSame($partA, file_get_contents($dec));
    }

    // ---------------------------------------------------------------------------------------
    // decryptFileV2 — fail-loud return contract
    // ---------------------------------------------------------------------------------------

    /**
     * Pins the @return fix. The doc promised "string|false ... or FALSE in case of error" and the
     * signature declared string|false, but no `return false` was ever reachable — every error
     * throws. A caller who followed the doc wrote `if ($out === false)`, which is dead code, and
     * the first tampered file escaped as an uncaught \Exception. The declared type is now `string`.
     */
    public function testDecryptFileV2DeclaresStringReturnBecauseFalseIsUnreachable(): void
    {
        $type = (new \ReflectionMethod(Security::class, 'decryptFileV2'))->getReturnType();

        $this->assertInstanceOf(\ReflectionNamedType::class, $type);
        $this->assertSame('string', $type->getName());
        $this->assertFalse($type->allowsNull());
    }

    /** The behavioural half of the same contract: failure throws, it never returns a falsy value. */
    public function testDecryptFileV2ThrowsRatherThanReturningFalseOnTamper(): void
    {
        Security::setFileEncryptBlocksBytes(16);
        [, $enc] = $this->makeEncryptedFile(random_bytes(100));
        $dec = $this->tempPath('dec');

        $blocks = self::parseBlocks(file_get_contents($enc));
        $bin = base64_decode($blocks[6]);
        $bin[0] = chr(ord($bin[0]) ^ 0x01);
        $blocks[6] = base64_encode($bin);
        file_put_contents($enc, self::rebuildBlocks($blocks));

        $returned = 'sentinel';
        try {
            $returned = Security::decryptFileV2($enc, self::masterKey(), $dec);
            $this->fail('Expected an Exception; decryptFileV2 must never return false.');
        } catch (\Exception $e) {
            $this->assertNotSame(false, $returned);
            $this->assertNotSame('', $e->getMessage());
        }
    }

    /**
     * A successful call returns the destination path — the only value it ever returns. Note the
     * returned path is the RESOLVED destination, which is not necessarily the string that was
     * passed in (symlinks, "..", and Windows 8.3 short names are all normalized away), so callers
     * must use the RETURN VALUE rather than assume it echoes their argument.
     */
    public function testDecryptFileV2ReturnsResolvedDestinationPathOnSuccess(): void
    {
        [, $enc] = $this->makeEncryptedFile('payload');
        $dec = $this->tempPath('dec');

        $returned = Security::decryptFileV2($enc, self::masterKey(), $dec);

        $this->assertFileExists($returned);
        $this->assertSame(realpath($dec), $returned);
        $this->assertSame('payload', file_get_contents($returned));
    }

    // ---------------------------------------------------------------------------------------
    // applySecurityFunctionArray
    // ---------------------------------------------------------------------------------------

    /**
     * The method built the callable string "Security::encryptLocal", which call_user_func resolves
     * against the GLOBAL namespace — never finding VD\PHPHelper\Security. EVERY call raised
     * "class Security not found" (a \TypeError, not an \Exception), so the whole method was dead
     * while its docblock described a working contract. Fails without the fix.
     */
    public function testApplySecurityFunctionArrayResolvesThisClassAndRoundTripsNestedArrays(): void
    {
        $input = ['a' => 'hello', 'nested' => ['b' => 'world']];

        $encrypted = Security::applySecurityFunctionArray($input, self::masterKey(), '', 'encryptLocal');

        $this->assertNotSame('hello', $encrypted['a']);
        $this->assertNotSame('world', $encrypted['nested']['b']);

        $decrypted = Security::applySecurityFunctionArray($encrypted, self::masterKey(), '', 'decryptLocal');

        $this->assertSame($input, $decrypted);
    }

    public function testApplySecurityFunctionArrayAcceptsOptionalClassPrefixes(): void
    {
        $input = ['a' => 'hello'];

        foreach (['encryptLocal', 'Security::encryptLocal', 'self::encryptLocal'] as $fnName) {
            $encrypted = Security::applySecurityFunctionArray($input, self::masterKey(), '', $fnName);
            $decrypted = Security::applySecurityFunctionArray($encrypted, self::masterKey(), '', 'decryptLocal');

            $this->assertSame($input, $decrypted, "Failed for fnName '{$fnName}'");
        }
    }

    public function testApplySecurityFunctionArrayHandlesScalarsAndObjects(): void
    {
        $hash = Security::applySecurityFunctionArray('alice', self::masterKey(), '', 'generateSearchHash');
        $this->assertSame(Security::generateSearchHash('alice', self::masterKey()), $hash);

        $object = new \stdClass();
        $object->a = 'hello';
        $walked = Security::applySecurityFunctionArray($object, self::masterKey(), '', 'encryptLocal');

        // An object is converted to an array and comes back as one (documented).
        $this->assertIsArray($walked);
        $this->assertSame('hello', Security::decryptLocal($walked['a'], self::masterKey()));
    }

    public function testApplySecurityFunctionArrayReturnsItemUnchangedForEmptyFunctionName(): void
    {
        $input = ['a' => 'hello'];

        $this->assertSame($input, Security::applySecurityFunctionArray($input, self::masterKey(), '', ''));
    }

    /**
     * encryptDataDB's 3rd parameter is $aad, not $salt. Dispatching it here would bind the caller's
     * salt as the AAD and silently destroy the per-cell relocation binding — and it would round-trip
     * symmetrically, so nothing would ever look broken. It must fail loudly instead.
     */
    public function testApplySecurityFunctionArrayRejectsDataDbMethodsToPreventSilentAadMisbinding(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported function');
        Security::applySecurityFunctionArray(['a' => 'x'], self::masterKey(), 'salt', 'encryptDataDB');
    }

    public function testApplySecurityFunctionArrayRejectsDecryptDataDb(): void
    {
        $this->expectException(\Exception::class);
        Security::applySecurityFunctionArray(['a' => 'x'], self::masterKey(), 'salt', 'decryptDataDB');
    }

    /** Not a general dispatcher: a foreign callable is refused with a catchable \Exception. */
    public function testApplySecurityFunctionArrayRejectsForeignCallables(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Unsupported function');
        Security::applySecurityFunctionArray(['a' => 'x'], self::masterKey(), '', 'MyVault::encrypt');
    }

    public function testApplySecurityFunctionArrayRejectsArbitraryPhpFunctions(): void
    {
        $this->expectException(\Exception::class);
        Security::applySecurityFunctionArray(['a' => 'x'], self::masterKey(), '', 'strtoupper');
    }

    /** A non-allowlisted method of this very class is still refused (wrong 3rd-arg shape). */
    public function testApplySecurityFunctionArrayRejectsNonAllowlistedSecurityMethods(): void
    {
        $this->expectException(\Exception::class);
        Security::applySecurityFunctionArray(['a' => 'x'], self::masterKey(), '', 'encryptPassword');
    }

    public function testApplySecurityFunctionArrayForwardsSaltAsThirdArgument(): void
    {
        $withSalt = Security::applySecurityFunctionArray(['a' => 'alice'], self::masterKey(), 'tenant-1', 'generateSearchHash');

        $this->assertSame(
            Security::generateSearchHash('alice', self::masterKey(), 'tenant-1'),
            $withSalt['a']
        );
        $this->assertNotSame(
            Security::generateSearchHash('alice', self::masterKey(), 'tenant-2'),
            $withSalt['a']
        );
    }

    // ---------------------------------------------------------------------------------------
    // xssCleanRecursive — a BLACKLIST, documented as such
    // ---------------------------------------------------------------------------------------

    public function testXssCleanStripsSpaceSeparatedEventHandler(): void
    {
        $this->assertStringNotContainsString('onload', Security::xssCleanRecursive('<svg onload=alert(1)>'));
    }

    public function testXssCleanRemovesScriptTags(): void
    {
        $cleaned = Security::xssCleanRecursive('<script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>', $cleaned);
        $this->assertStringContainsString('[REMOVED]', $cleaned);
    }

    public function testXssCleanNeutralizesJavascriptProtocol(): void
    {
        $this->assertStringNotContainsString(
            'javascript:',
            Security::xssCleanRecursive('<a href="javascript:alert(1)">x</a>')
        );
    }

    /**
     * The docblock names `<svg/onload=alert(1)>` and `<img/onerror=... src=x>` as KNOWN BYPASSES
     * that pass through unchanged, and tells callers this is not an XSS defence. This test pins
     * that honesty: if a future change silently fixed these two, the docblock's warning would be
     * stale — and if someone weakens the doc back to "prevents XSS" while these still pass, the
     * claim is a lie. Either way, this test forces the doc and the code to be reconciled together.
     */
    public function testDocumentedXssBypassesGenuinelySurviveTheFilter(): void
    {
        foreach (['<svg/onload=alert(1)>', '<img/onerror=alert(1) src=x>'] as $bypass) {
            $this->assertSame(
                $bypass,
                Security::xssCleanRecursive($bypass),
                'Documented bypass no longer survives; xssCleanRecursive\'s docblock must be updated.'
            );
        }
    }

    public function testXssCleanRecursesIntoArrays(): void
    {
        $cleaned = Security::xssCleanRecursive(['a' => '<script>x</script>', 'n' => ['b' => '<script>y</script>']]);

        $this->assertStringContainsString('[REMOVED]', $cleaned['a']);
        $this->assertStringContainsString('[REMOVED]', $cleaned['n']['b']);
    }

    public function testXssCleanRecursesIntoObjects(): void
    {
        $object = new \stdClass();
        $object->a = '<script>x</script>';

        $cleaned = Security::xssCleanRecursive($object);

        $this->assertIsObject($cleaned);
        $this->assertStringContainsString('[REMOVED]', $cleaned->a);
    }

    public function testXssCleanPassesThroughNonStringScalarsUnchanged(): void
    {
        $this->assertNull(Security::xssCleanRecursive(null));
        $this->assertSame('', Security::xssCleanRecursive(''));
        $this->assertTrue(Security::xssCleanRecursive(true));
        $this->assertFalse(Security::xssCleanRecursive(false));
        $this->assertSame(42, Security::xssCleanRecursive(42));
    }

    public function testXssCleanLeavesBenignTextIntact(): void
    {
        $this->assertSame('Sodium hydroxide 4%', Security::xssCleanRecursive('Sodium hydroxide 4%'));
    }

    // ---------------------------------------------------------------------------------------
    // filterValue
    // ---------------------------------------------------------------------------------------

    public function testFilterValueExtractsByKey(): void
    {
        $this->assertSame('value', Security::filterValue(['k' => 'value'], 'k'));
    }

    public function testFilterValueReturnsIfNullForMissingOrNullKey(): void
    {
        $this->assertSame('fallback', Security::filterValue(['k' => 'v'], 'absent', 'fallback'));
        $this->assertSame('fallback', Security::filterValue(['k' => null], 'k', 'fallback'));
        $this->assertSame('fallback', Security::filterValue(null, null, 'fallback'));
    }

    /** $key extraction is arrays-only by contract; an object source yields $ifNull. */
    public function testFilterValueWithKeyOnObjectSourceReturnsIfNull(): void
    {
        $object = new \stdClass();
        $object->k = 'value';

        $this->assertSame('fallback', Security::filterValue($object, 'k', 'fallback'));
    }

    public function testFilterValueAppliesTrimAndStripTags(): void
    {
        $filtered = Security::filterValue(
            ['k' => '  <b>bold</b>  '],
            'k',
            null,
            false, false, true, false, false, false, true
        );

        $this->assertSame('bold', $filtered);
    }

    public function testFilterValueAsIntegerKeepsOnlyDigits(): void
    {
        $filtered = Security::filterValue(
            ['k' => 'a1b2c3'],
            'k',
            null,
            false, false, false, false, false, false, false, false, true
        );

        $this->assertSame('123', $filtered);
    }

    public function testFilterValueAsBooleanReflectsEmptiness(): void
    {
        // $asBoolean is the 13th parameter: source, key, ifNull, decodeStr, xssClean, stripTags,
        // htmlEntities, addSlashes, escapeDB, trim, formatDecimal, asInteger, asBoolean.
        $this->assertTrue(Security::filterValue(
            ['k' => 'x'],
            'k',
            null,
            false, false, false, false, false, false, false, false, false, true
        ));
        $this->assertFalse(Security::filterValue(
            ['k' => ''],
            'k',
            null,
            false, false, false, false, false, false, false, false, false, true
        ));
    }

    public function testFilterValueBase64RoundTrips(): void
    {
        $encoded = Security::filterValue(
            ['k' => 'hello'],
            'k',
            null,
            false, false, false, false, false, false, false, false, false, false, true
        );
        $this->assertSame(base64_encode('hello'), $encoded);

        $decoded = Security::filterValue(
            ['k' => $encoded],
            'k',
            null,
            false, false, false, false, false, false, false, false, false, false, false, true
        );
        $this->assertSame('hello', $decoded);
    }

    public function testFilterValueRecursesIntoArraysApplyingFiltersToEveryLeaf(): void
    {
        $filtered = Security::filterValue(
            ['a' => '  x  ', 'n' => ['b' => '  y  ']],
            null,
            null,
            false, false, false, false, false, false, true
        );

        $this->assertSame('x', $filtered['a']);
        $this->assertSame('y', $filtered['n']['b']);
    }

    /**
     * Pins the object-walk fix. filterValue's `is_object($value)` branch advertised object support
     * and then subscripted the object with [], which is a fatal \Error for any object without
     * ArrayAccess — i.e. every stdClass json_decode() produces. The doc said "array or variable"
     * and declared no @throws, so nothing warned the caller. Fails without the fix (fatal Error).
     */
    public function testFilterValueWalksDecodedJsonObjectsWithoutFatalError(): void
    {
        $object = json_decode('{"a":"  x  ","n":{"b":"  y  "}}');

        $filtered = Security::filterValue(
            $object,
            null,
            null,
            false, false, false, false, false, false, true
        );

        $this->assertIsObject($filtered);
        $this->assertSame('x', $filtered->a);
        $this->assertSame('y', $filtered->n->b);
    }

    /** The documented mutation asymmetry: objects are filtered in place, arrays are copied. */
    public function testFilterValueMutatesObjectsInPlaceButNotArrays(): void
    {
        $object = new \stdClass();
        $object->a = '  x  ';
        Security::filterValue($object, null, null, false, false, false, false, false, false, true);
        $this->assertSame('x', $object->a, 'Objects are documented as filtered in place.');

        $array = ['a' => '  x  '];
        Security::filterValue($array, null, null, false, false, false, false, false, false, true);
        $this->assertSame('  x  ', $array['a'], 'Arrays are copy-on-write and must not be mutated.');
    }

    public function testFilterValueScalarSourceIsFilteredDirectly(): void
    {
        $filtered = Security::filterValue(
            '  x  ',
            null,
            null,
            false, false, false, false, false, false, true
        );

        $this->assertSame('x', $filtered);
    }
}
