<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * decryptFileV2 must read its version from self::FILE_V2_VERSION, the same constant
     * encryptFileV2 writes and binds into every block's AAD — NOT from a hardcoded "v2" literal.
     *
     * The defect is LATENT, so no round-trip can catch it: today the literal and the constant are
     * both "v2", and bumping the constant is what would silently break decryption of every newly
     * written file. A PHP class constant cannot be rebound at runtime to simulate that, so this
     * asserts the source itself is wired to the constant. It fails against the pre-fix body, which
     * contained `$version = "v2";`.
     */
    public function testDecryptFileV2DerivesItsVersionFromTheConstantNotALiteral(): void
    {
        $method = new \ReflectionMethod(Security::class, 'decryptFileV2');
        $lines  = file($method->getFileName());
        $body   = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertMatchesRegularExpression(
            '/\$version\s*=\s*self::FILE_V2_VERSION\s*;/',
            $body,
            'decryptFileV2 must take its version from self::FILE_V2_VERSION.'
        );
        $this->assertDoesNotMatchRegularExpression(
            '/\$version\s*=\s*[\'"]v2[\'"]\s*;/',
            $body,
            'decryptFileV2 still hardcodes its version literal; bumping FILE_V2_VERSION would silently break decryption.'
        );
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

    /**
     * The message must state the floor that is ACTUALLY enforced. This previously reported
     * "at least 16 bytes" from a dead local guard, while the real (correct) 32-byte floor lived in
     * deriveKey — so a 16..31-byte key was told 16 and then refused for being under 32.
     */
    public function testEncryptLocalRejectsKeyShorterThan32BytesWithA32ByteMessage(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('at least 32 bytes');
        Security::encryptLocal('x', str_repeat('k', 15));
    }

    /**
     * encryptLocal delegates the key floor to deriveKey, which enforces 32 (HKDF cannot add
     * entropy). A 16..31-byte key is refused — pinned so the floor cannot silently regress to the
     * weaker 16 the old guard advertised.
     */
    public function testEncryptLocalStillRefusesKeysBelowTheThirtyTwoByteDerivationFloor(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('at least 32 bytes');
        Security::encryptLocal('x', str_repeat('k', 16));
    }

    /** Every key-floor message across the local/cross-platform surface must say 32, not 16. */
    public function testEveryKeyFloorMessageStates32Bytes(): void
    {
        $calls = [
            'encryptLocal'          => fn () => Security::encryptLocal('x', str_repeat('k', 16)),
            'decryptLocal'          => fn () => Security::decryptLocal(base64_encode(str_repeat('a', 64)), str_repeat('k', 16)),
            'encryptCrossPlatform'  => fn () => Security::encryptCrossPlatform('x', str_repeat('k', 16)),
            'decryptCrossPlatform'  => fn () => Security::decryptCrossPlatform('x', str_repeat('k', 16)),
        ];

        foreach ($calls as $name => $call) {
            try {
                $call();
                $this->fail("{$name} accepted a 16-byte key");
            } catch (\Exception $e) {
                $this->assertStringContainsString('at least 32 bytes', $e->getMessage(), $name);
                $this->assertStringNotContainsString('16 bytes', $e->getMessage(), $name);
            }
        }
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
    // xssCleanRecursive — an ALLOW-LIST sanitizer (parse + rebuild), not a pattern blacklist
    // ---------------------------------------------------------------------------------------

    /**
     * The safe subset the sanitizer promises to emit: element => attributes allowed on it, on top of
     * XSS_ORACLE_GLOBAL_ATTRIBUTES.
     *
     * Deliberately a hand-maintained MIRROR of Security::XSS_ALLOWED_ELEMENTS rather than a read of
     * the private constant. Deriving it from the source would make the oracle agree with whatever
     * the source happens to allow — including a future "just add svg" — which is precisely the
     * failure mode these tests exist to catch. Widening the subset must mean editing this list, in a
     * diff a reviewer sees.
     *
     * @var array<string, string[]>
     */
    private const XSS_ORACLE_ELEMENTS = [
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

    /** @var string[] Attributes the safe subset allows on every element. */
    private const XSS_ORACLE_GLOBAL_ATTRIBUTES = ['title', 'lang', 'dir', 'class'];

    /**
     * Attributes a browser DEREFERENCES, so a scheme in them is executable rather than prose. The
     * scheme check applies to these ONLY: title="javascript: the language" is inert text, and
     * flagging it would be a false alarm that pressures someone into weakening the oracle.
     *
     * Wider than the source's own list on purpose — it includes the URL-bearing attributes of
     * elements the sanitizer must never emit (action/formaction/data/poster/xlink:href/srcdoc), so
     * that if one ever starts being emitted, the scheme check is already watching it.
     *
     * @var string[]
     */
    private const XSS_ORACLE_URL_ATTRIBUTES = [
        'href', 'src', 'cite', 'action', 'formaction', 'xlink:href', 'data', 'background',
        'poster', 'srcdoc', 'longdesc', 'usemap', 'dynsrc', 'lowsrc',
    ];

    /**
     * Schemes that execute or smuggle a document. Checked as a DENY-list on purpose: the source
     * checks an ALLOW-list of safe schemes, so an oracle sharing that list would rubber-stamp a bug
     * in it. These two disagree unless the value really is inert.
     *
     * @var string[]
     */
    private const XSS_ORACLE_DENIED_SCHEMES = ['javascript', 'vbscript', 'data', 'blob', 'file'];

    /**
     * Names the sanitizer must never emit at the byte level: every raw-text element (whose content
     * is not parsed as markup, so escaping does not stay escaped) and every foreign-content element
     * (which switches the parser into XML rules). This is the source's own mXSS soundness argument,
     * pinned against the serialization.
     *
     * @var string[]
     */
    private const XSS_ORACLE_NEVER_EMITTED = [
        'script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript',
        'noembed', 'noframes', 'xmp', 'base', 'link', 'form', 'input', 'textarea', 'button',
        'frame', 'frameset', 'applet', 'meta', 'marquee', 'html', 'head', 'body',
    ];

    /**
     * Resolves the scheme a BROWSER would see in a URL attribute value, or null if it is relative.
     *
     * Entities are decoded (to a fixed point) and every control character and every kind of
     * whitespace is removed first, because browsers ignore those when resolving a scheme:
     * "jav&#x09;ascript:" is "jav\tascript:" is javascript:.
     */
    private static function xssOracleScheme(string $value): ?string
    {
        $probe = $value;
        for ($i = 0; $i < 3; $i++) {
            $decoded = html_entity_decode($probe, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if ($decoded === $probe) {
                break;
            }
            $probe = $decoded;
        }

        $probe = preg_replace('/[\p{C}\p{Z}\s]+/u', '', $probe);
        if ($probe === null || $probe === '') {
            return null;
        }
        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $probe, $matches)) {
            return strtolower($matches[1]);
        }

        return str_starts_with($probe, ':') ? '(empty)' : null;
    }

    /**
     * Every reason the given HTML still carries ACTIVE content. Empty array = provably inert.
     *
     * TWO INDEPENDENT LAYERS, because either alone is unsound:
     *
     *  1. BYTE-LEVEL scan of each tag-like region. Necessary because layer 2's parser is libxml's
     *     HTML4 parser, which is NOT the HTML5 algorithm a browser runs: libxml silently discards
     *     "/onerror=alert(1)" from <img/onerror=alert(1) src=x> and drops <body onload=alert(1)>
     *     whole, while a browser builds the handler in both cases. An oracle that only re-parsed
     *     would be blind to the exact two bypasses this sanitizer was written to kill. It cannot
     *     false-positive on escaped text: inert text has no raw "<", so it forms no tag region.
     *  2. STRUCTURAL re-parse of the output, asserting the resulting tree against the safe subset:
     *     no element outside the allow-list, no attribute outside it, no on* handler under any
     *     casing, no denied scheme in a URL attribute — and no attribute smuggled onto <html>/
     *     <head>/<body>. Necessary because layer 1 only sees shapes it can spell.
     *
     * Escaped text ("&lt;script&gt;") is inert by construction and deliberately NOT flagged.
     *
     * @return string[]
     */
    private static function xssActiveContentFindings(string $html): array
    {
        $findings = [];

        // ---- Layer 1: the serialization itself.
        foreach (self::XSS_ORACLE_NEVER_EMITTED as $tag) {
            if (stripos($html, '<' . $tag) !== false) {
                $findings[] = "serialization contains <{$tag}";
            }
        }

        preg_match_all('/<[a-zA-Z][^>]*>?/', $html, $tagRegions);
        foreach ($tagRegions[0] as $region) {
            if (preg_match('/[\s\/"\'\x00-\x20]on[a-z-]+\s*=/i', $region)) {
                $findings[] = "tag region carries an event handler: {$region}";
            }

            $urlAttributes = '/[\s\/"\'](?:href|src|action|formaction|xlink:href|data|cite'
                . '|poster|background|srcdoc)\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i';
            if (preg_match_all($urlAttributes, $region, $matches)) {
                foreach ($matches[1] as $rawValue) {
                    $scheme = self::xssOracleScheme(trim($rawValue, "\"'"));
                    if ($scheme !== null && in_array($scheme, self::XSS_ORACLE_DENIED_SCHEMES, true)) {
                        $findings[] = "tag region carries the {$scheme}: scheme: {$region}";
                    }
                }
            }
        }

        // ---- Layer 2: the tree a parser actually builds from that serialization.
        $document = new \DOMDocument();
        $previousErrorMode = libxml_use_internal_errors(true);
        $loaded = $document->loadHTML(
            '<html><head><meta http-equiv="Content-Type" content="text/html; charset=utf-8" '
            . 'data-oracle-wrapper="1"></head><body>' . $html . '</body></html>',
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrorMode);

        if (!$loaded || $document->documentElement === null) {
            $findings[] = 'the sanitizer output could not be re-parsed';

            return array_values(array_unique($findings));
        }

        $walk = static function (\DOMNode $node) use (&$walk, &$findings): void {
            if ($node instanceof \DOMElement) {
                // The wrapper's own <meta>, not something the sanitizer emitted.
                if ($node->hasAttribute('data-oracle-wrapper')) {
                    return;
                }

                $tag = strtolower($node->nodeName);
                $isWrapper = in_array($tag, ['html', 'head', 'body'], true);

                if (!$isWrapper && !array_key_exists($tag, self::XSS_ORACLE_ELEMENTS)) {
                    $findings[] = "element <{$tag}> is outside the allow-list";
                }

                foreach ($node->attributes as $attribute) {
                    $name = strtolower($attribute->nodeName);

                    if (str_starts_with($name, 'on')) {
                        $findings[] = "event-handler attribute {$tag}[{$name}]";
                        continue;
                    }
                    // The wrappers are ours and carry no attributes: anything here was smuggled in.
                    if ($isWrapper) {
                        $findings[] = "attribute {$name} was smuggled onto <{$tag}>";
                        continue;
                    }

                    $allowed = array_merge(
                        self::XSS_ORACLE_GLOBAL_ATTRIBUTES,
                        self::XSS_ORACLE_ELEMENTS[$tag] ?? []
                    );
                    if (!in_array($name, $allowed, true)) {
                        $findings[] = "attribute {$tag}[{$name}] is outside the allow-list";
                    }

                    if (in_array($name, self::XSS_ORACLE_URL_ATTRIBUTES, true)) {
                        $scheme = self::xssOracleScheme($attribute->nodeValue ?? '');
                        if ($scheme !== null && in_array($scheme, self::XSS_ORACLE_DENIED_SCHEMES, true)) {
                            $findings[] = "attribute {$tag}[{$name}] resolves to the {$scheme}: scheme";
                        }
                    }
                }
            }

            foreach ($node->childNodes as $child) {
                $walk($child);
            }
        };
        $walk($document->documentElement);

        return array_values(array_unique($findings));
    }

    /**
     * Asserts the sanitizer's output is provably inert — see xssActiveContentFindings().
     *
     * This REPLACES an oracle that searched the output for a handful of literal substrings
     * ("<script", "javascript:", a \son[a-z]+= regex). That oracle was unsound in both directions:
     * a live payload could avoid every substring it looked for, and 6 of the 39 battery cases
     * passed against a sanitizer whose body had been DELETED — they asserted nothing.
     */
    private function assertNoActiveContent(string $cleaned, string $payload): void
    {
        $findings = self::xssActiveContentFindings($cleaned);

        $this->assertSame(
            [],
            $findings,
            "Active content survived for payload: {$payload}\n"
            . "  sanitized output: {$cleaned}\n"
            . '  findings: ' . implode("\n            ", $findings)
        );
    }

    /**
     * The oracle above is the foundation every other XSS test rests on, so it gets its own test: an
     * oracle that cannot fail proves nothing. Each payload is fed to it RAW — exactly what a
     * sanitizer whose body was deleted would return — and the oracle must reject every one.
     *
     * Without this, a weakened assertNoActiveContent() (the defect this replaced) is invisible:
     * the battery goes green either way.
     */
    #[DataProvider('xssBypassPayloadProvider')]
    public function testTheOracleItselfRejectsEveryUnsanitizedPayload(string $payload): void
    {
        $this->assertNotSame(
            [],
            self::xssActiveContentFindings($payload),
            "The XSS oracle found nothing wrong with a RAW, unsanitized payload, so it would not "
            . "notice if the sanitizer stopped working: {$payload}"
        );
    }

    /** The flip side: an oracle that flags everything is equally useless. Benign output must pass. */
    #[DataProvider('inertHtmlProvider')]
    public function testTheOracleAcceptsInertOutput(string $inert): void
    {
        $this->assertSame([], self::xssActiveContentFindings($inert), "False positive on: {$inert}");
    }

    public static function inertHtmlProvider(): array
    {
        return array_map(static fn ($h) => [$h], [
            '<a href="http://ok.example/path?q=1">good</a>',
            '<a href="mailto:a@b.example">mail</a>',
            '<a href="/relative/page#frag">rel</a>',
            '<a href="tel:+5511999">call</a>',
            '<b>bold</b> and <i>italic</i>',
            '<p title="hi">t</p>',
            '<ul><li>a</li><li>b</li></ul>',
            'café ☕ <b>x</b>',
            'Sodium hydroxide 4%',
            // Inert TEXT that merely mentions the dangerous shapes: escaped, so not a tag region.
            'a &lt; b &amp;&amp; c &gt; d',
            'talk about onerror=x and javascript: in plain text',
            '&lt;script&gt;alert(1)&lt;/script&gt;',
            '<del cite="http://x.example" datetime="2020-01-01">d</del>',
            '<img src="http://x.example/a.png" alt="data: is fine in prose" width="2" />',
            '<table><tbody><tr><td colspan="2">c</td></tr></tbody></table>',
        ]);
    }

    /**
     * The exact two payloads the previous BLACKLIST let through COMPLETELY UNCHANGED, because its
     * on*-attribute rule required whitespace before "on" and "/" is also a valid HTML attribute
     * separator. The old suite pinned their survival; the allow-list rebuild now neutralises them.
     * These are the headline regression guards for that fix.
     */
    public function testPreviouslyDocumentedBypassesAreNeutralised(): void
    {
        foreach (['<svg/onload=alert(1)>', '<img/onerror=alert(1) src=x>'] as $bypass) {
            $cleaned = Security::xssCleanRecursive($bypass);

            $this->assertNotSame($bypass, $cleaned, "Bypass passed through unchanged: {$bypass}");
            $this->assertNoActiveContent($cleaned, $bypass);
        }
    }

    /**
     * A blacklist is beaten by a shape its author did not foresee, so the fix is only meaningful if
     * it holds across separators, entity encodings, casing, malformed tags, nesting, foreign
     * content (SVG/MathML) and mutation-XSS vectors — none of which are enumerated by the
     * sanitizer. Each of these must come out inert.
     */
    #[DataProvider('xssBypassPayloadProvider')]
    public function testXssBypassBatteryIsNeutralised(string $payload): void
    {
        $this->assertNoActiveContent(Security::xssCleanRecursive($payload), $payload);
    }

    public static function xssBypassPayloadProvider(): array
    {
        return array_map(static fn ($p) => [$p], [
            // Separator tricks that defeated the on*-attribute regex. The CR, LF, TAB and FF cases
            // are DOUBLE-QUOTED: in single quotes "\r" is a backslash and an "r", which is a
            // different (and harmless) payload — the separator class would go untested.
            '<svg/onload=alert(1)>',
            '<img/onerror=alert(1) src=x>',
            "<img\ronerror=alert(1) src=x>",
            "<img\nonerror=alert(1) src=x>",
            "<img\tonerror=alert(1) src=x>",
            "<img\x0conerror=alert(1) src=x>",
            '<svg onload=alert(1)>',
            '<img src=x onerror=alert(1)>',
            '<img src=x OnErRoR=alert(1)>',
            '<body onload=alert(1)>',
            '<div onclick="alert(1)">click</div>',
            // Scripting schemes, including entity-encoded and whitespace-split forms.
            '<a href="javascript:alert(1)">x</a>',
            '<a href="jav&#x09;ascript:alert(1)">x</a>',
            '<a href="jav&#x0A;ascript:alert(1)">x</a>',
            '<a href="&#106;avascript:alert(1)">x</a>',
            '<a href="JaVaScRiPt:alert(1)">x</a>',
            '<a href=" javascript:alert(1)">x</a>',
            '<a href="vbscript:msgbox(1)">x</a>',
            // data: URIs.
            '<img src="data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg==">',
            '<a href="data:text/html,<script>alert(1)</script>">x</a>',
            // Elements that execute or load.
            '<script>alert(1)</script>',
            '<iframe src="javascript:alert(1)"></iframe>',
            '<object data="javascript:alert(1)">',
            '<embed src="javascript:alert(1)">',
            '<base href="javascript:">',
            // Malformed / nested tags.
            '<<script>alert(1)//<</script>',
            '<img src="x" onerror="alert(1)"',
            '<scr<script>ipt>alert(1)</scr</script>ipt>',
            '<img src=x onerror=alert(1)//',
            // SVG / MathML foreign content and mutation-XSS vectors.
            '<svg><script>alert(1)</script></svg>',
            '<svg><style><!--</style><img src=x onerror=alert(1)>-->',
            '<math><mtext><table><mglyph><style><img src=x onerror=alert(1)>',
            '<svg><animate onbegin=alert(1) attributeName=x dur=1s>',
            '<svg><a xlink:href="javascript:alert(1)"><text>x</text></a></svg>',
            // Raw-text / parser-confusion elements.
            '<template><img src=x onerror=alert(1)></template>',
            '<noscript><p title="</noscript><img src=x onerror=alert(1)>">',
            '<xmp><img src=x onerror=alert(1)></xmp>',
            '<style>@import "javascript:alert(1)";</style>',
            '<div style="background:url(javascript:alert(1))">x</div>',
            '<div style="-moz-binding:url(evil.xml)">x</div>',
            // Forms and DOM clobbering.
            '<form action="javascript:alert(1)"><input name=x></form>',
            '<a id="location" href="x">clobber</a>',
        ]);
    }

    public function testXssCleanStripsEventHandlerAttributesButKeepsTheElement(): void
    {
        $this->assertSame('<div>click</div>', Security::xssCleanRecursive('<div onclick="alert(1)">click</div>'));
    }

    public function testXssCleanDropsScriptElementEntirelyIncludingItsSource(): void
    {
        // The whole subtree goes: the script body must not survive even as inert visible text.
        $this->assertSame('', Security::xssCleanRecursive('<script>alert(1)</script>'));
        $this->assertSame('hi', Security::xssCleanRecursive('<script>alert(1)</script>hi'));
    }

    public function testXssCleanDropsJavascriptHrefButKeepsTheLinkText(): void
    {
        $this->assertSame('<a>x</a>', Security::xssCleanRecursive('<a href="javascript:alert(1)">x</a>'));
    }

    /** A sanitizer that destroys every link is useless: benign URLs must survive intact. */
    public function testXssCleanPreservesSafeUrlSchemes(): void
    {
        $this->assertSame(
            '<a href="http://ok.example/path?q=1">good</a>',
            Security::xssCleanRecursive('<a href="http://ok.example/path?q=1">good</a>')
        );
        $this->assertSame(
            '<a href="mailto:a@b.example">mail</a>',
            Security::xssCleanRecursive('<a href="mailto:a@b.example">mail</a>')
        );
        $this->assertSame(
            '<a href="/relative/page#frag">rel</a>',
            Security::xssCleanRecursive('<a href="/relative/page#frag">rel</a>')
        );
    }

    /** Allow-listed formatting markup is the reason to sanitize rather than escape wholesale. */
    public function testXssCleanPreservesAllowListedMarkup(): void
    {
        $this->assertSame('<b>bold</b> and <i>italic</i>', Security::xssCleanRecursive('<b>bold</b> and <i>italic</i>'));
        $this->assertSame('<p title="hi">t</p>', Security::xssCleanRecursive('<p title="hi">t</p>'));
        $this->assertSame(
            '<ul><li>a</li><li>b</li></ul>',
            Security::xssCleanRecursive('<ul><li>a</li><li>b</li></ul>')
        );
    }

    public function testXssCleanEscapesBareMarkupCharactersInText(): void
    {
        $this->assertSame('a &lt; b &amp;&amp; c &gt; d', Security::xssCleanRecursive('a < b && c > d'));
    }

    public function testXssCleanPreservesMultibyteText(): void
    {
        $this->assertSame('café ☕ <b>x</b>', Security::xssCleanRecursive('café ☕ <b>x</b>'));
    }

    public function testXssCleanRecursesIntoArrays(): void
    {
        $cleaned = Security::xssCleanRecursive([
            'a' => '<script>x</script>hi',
            'n' => ['b' => '<img/onerror=alert(1) src=x>'],
        ]);

        $this->assertSame('hi', $cleaned['a']);
        $this->assertNoActiveContent($cleaned['n']['b'], '<img/onerror=alert(1) src=x>');
    }

    public function testXssCleanRecursesIntoObjects(): void
    {
        $object = new \stdClass();
        $object->a = '<script>x</script>hi';
        $object->nested = '<svg/onload=alert(1)>';

        $cleaned = Security::xssCleanRecursive($object);

        $this->assertIsObject($cleaned);
        $this->assertSame('hi', $cleaned->a);
        $this->assertNoActiveContent($cleaned->nested, '<svg/onload=alert(1)>');
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

    // ---------------------------------------------------------------------------------------
    // The object walk: a NARROW contract that REFUSES what it cannot do correctly
    //
    // Three passes tried to walk arbitrary container objects in place and each shipped a
    // different fail-open:
    //   - `$object->$k = ...` wrote a PHANTOM DYNAMIC property (foreach dispatches to the
    //     ITERATOR on any Traversable) while the real storage kept the live payload;
    //   - `offsetSet($iterationKey, ...)` works only while getIterator() yields storage offsets.
    //     On a container that re-keys, it writes to INVENTED offsets: the payload survives at the
    //     real key AND the container silently grows an entry, on the success path.
    //
    // The contract is now: strings, arrays (recursively), and a PLAIN object's PUBLIC properties.
    // Everything else THROWS. The tests below pin the refusal, because a container returned
    // unwalked-but-looking-sanitized is the fail-open itself.
    // ---------------------------------------------------------------------------------------

    /** The live payload used across the container tests. */
    private const XSS_LIVE_PAYLOAD = '<img src=x onerror=alert(1)>';

    /**
     * CONTRACT CHANGE (was: "sanitizes ArrayObject storage via offsetSet"). Writing back by
     * ITERATION KEY is only accidentally correct — see testXssCleanRefusesAContainerWhoseIterator
     * ReKeys, which is the container that proves it. Rather than be right for ArrayObject and
     * silently wrong (and corrupting) for everything else, the walk refuses every container.
     */
    public function testXssCleanRefusesAnArrayObjectInsteadOfGuessingAtItsStorage(): void
    {
        $subject = new \ArrayObject(['bio' => self::XSS_LIVE_PAYLOAD]);

        try {
            Security::xssCleanRecursive($subject);
            $this->fail('An ArrayObject must be refused, not silently returned unsanitized.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('ArrayObject', $e->getMessage());
            $this->assertStringContainsString(
                'getArrayCopy',
                $e->getMessage(),
                'The refusal must tell the caller what to do instead.'
            );
        }

        // The refusal must not leave debris behind: no phantom dynamic property, storage intact.
        $this->assertSame([], get_object_vars($subject));
        $this->assertSame(['bio' => self::XSS_LIVE_PAYLOAD], $subject->getArrayCopy());
    }

    /**
     * THE CONTAINER THAT PROVES THE POINT. Its getIterator() re-keys (array_values()), exactly
     * like a sorted/paginated/array_values()'d view. Pass 3's offsetSet-by-iteration-key walk
     * wrote to INVENTED offset 0 here: the live payload survived at 'bio' and count went 1 -> 2,
     * on the SUCCESS path, with no error. Refusing is the only honest answer.
     */
    public function testXssCleanRefusesAContainerWhoseIteratorReKeysRatherThanCorruptIt(): void
    {
        $subject = new SecurityTestReKeyingCollection(['bio' => self::XSS_LIVE_PAYLOAD]);

        $this->expectException(\InvalidArgumentException::class);

        try {
            Security::xssCleanRecursive($subject);
        } finally {
            // The bug this replaces: an entry nobody inserted, beside the surviving payload.
            $this->assertCount(1, $subject->items(), 'The walk must never grow the caller container.');
            $this->assertSame(['bio' => self::XSS_LIVE_PAYLOAD], $subject->items());
        }
    }

    public function testXssCleanRefusesAnArrayIterator(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Security::xssCleanRecursive(new \ArrayIterator(['bio' => self::XSS_LIVE_PAYLOAD]));
    }

    /** A refused container must be refused from anywhere in the graph, not just the top. */
    public function testXssCleanRefusesAContainerNestedInsideAPlainObject(): void
    {
        $subject = new \stdClass();
        $subject->profile = new \ArrayObject(['bio' => self::XSS_LIVE_PAYLOAD]);

        $this->expectException(\InvalidArgumentException::class);

        Security::xssCleanRecursive($subject);
    }

    /** ...including from inside an array, which is the shape that reaches this from $_POST. */
    public function testXssCleanRefusesAContainerNestedInsideAnArray(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Security::xssCleanRecursive(['profile' => new \ArrayObject(['bio' => self::XSS_LIVE_PAYLOAD])]);
    }

    /**
     * Traversable WITHOUT ArrayAccess is refused too. Its public properties could be walked, but
     * the data it exists to expose — what iteration yields — could not be, so "sanitized" would be
     * a lie about the part that matters.
     */
    public function testXssCleanRefusesAnIteratorAggregateWhoseIteratedDataItCannotAddress(): void
    {
        $subject = new SecurityTestLyingIteratorAggregate();
        $subject->bio = self::XSS_LIVE_PAYLOAD;

        $this->expectException(\InvalidArgumentException::class);

        Security::xssCleanRecursive($subject);
    }

    public function testXssCleanRefusesAnArrayAccessCollection(): void
    {
        $subject = new SecurityTestCollection(['bio' => self::XSS_LIVE_PAYLOAD]);
        $subject->label = self::XSS_LIVE_PAYLOAD;

        $this->expectException(\InvalidArgumentException::class);

        Security::xssCleanRecursive($subject);
    }

    public function testXssCleanRefusesSplObjectStorage(): void
    {
        // ArrayAccess whose OFFSETS are objects while iteration yields integer positions. Pass 3
        // hard-coded a one-class exclusion and returned it unsanitized; it is refused now.
        $storage = new \SplObjectStorage();
        $storage->attach(new \stdClass(), self::XSS_LIVE_PAYLOAD);

        $this->expectException(\InvalidArgumentException::class);

        Security::xssCleanRecursive($storage);
    }

    /**
     * WeakMap raised an UNCAUGHT TypeError from iterator_to_array() (its keys are objects): the
     * shape pass 3 guarded for SplObjectStorage but missed. Now it is refused like every other
     * container, with an exception the documented `catch` actually catches.
     */
    public function testXssCleanRefusesAWeakMapInsteadOfRaisingATypeError(): void
    {
        $map = new \WeakMap();
        $map[new \stdClass()] = self::XSS_LIVE_PAYLOAD;

        $this->expectException(\InvalidArgumentException::class);

        Security::xssCleanRecursive($map);
    }

    /**
     * An enum is NOT refused, and that is deliberate: its cases are compile-time constants written
     * in source, never caller data, so there is nothing in one to sanitize. Returning it untouched
     * is the complete, correct result — not a slot skipped — which is exactly what separates it
     * from a readonly property (that one CAN hold attacker data, so it throws). Refusing enums
     * would break every DTO with a status/role property for no security gain.
     */
    public function testXssCleanReturnsAnEnumUntouchedInsteadOfRaisingAnError(): void
    {
        $this->assertSame(SecurityTestSuit::Hearts, Security::xssCleanRecursive(SecurityTestSuit::Hearts));
        $this->assertSame(SecurityTestSuit::Hearts->value, 'H');

        $wrapper = new \stdClass();
        $wrapper->suit = SecurityTestSuit::Hearts;
        $wrapper->bio = self::XSS_LIVE_PAYLOAD;

        $cleaned = Security::xssCleanRecursive($wrapper);

        $this->assertSame(SecurityTestSuit::Hearts, $cleaned->suit, 'An enum leaf must survive the walk.');
        $this->assertNoActiveContent($cleaned->bio, self::XSS_LIVE_PAYLOAD);
    }

    /**
     * CONTRACT CHANGE (was: "skips readonly properties"). A public readonly property is on the
     * object's public data surface and CAN hold attacker data — `new Dto(bio: $_POST['bio'])` —
     * so skipping it handed back an object that looked sanitized with the payload still live.
     * What the walk can SEE it must handle; it cannot write this, so it refuses.
     */
    public function testXssCleanRefusesAPublicReadonlyPropertyInsteadOfSkippingItSilently(): void
    {
        $subject = new SecurityTestReadonly(self::XSS_LIVE_PAYLOAD, 'mutable');

        try {
            Security::xssCleanRecursive($subject);
            $this->fail('A readonly property holding live markup must not be silently skipped.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('frozen', $e->getMessage(), 'Name the offending property.');
        }
    }

    /** The refusal is decided BEFORE any write, so a refused object is not left half-rewritten. */
    public function testXssCleanRefusesAReadonlyHolderWithoutRewritingItsOtherProperties(): void
    {
        $subject = new SecurityTestReadonly('<b>frozen</b>', self::XSS_LIVE_PAYLOAD);

        try {
            Security::xssCleanRecursive($subject);
        } catch (\InvalidArgumentException) {
            // expected
        }

        $this->assertSame('<b>frozen</b>', $subject->frozen);
        $this->assertSame(
            self::XSS_LIVE_PAYLOAD,
            $subject->mutable,
            'A refused object must come back untouched, not partly rewritten.'
        );
    }

    /**
     * THE SHARPEST EDGE OF THE CONTRACT CHANGE, pinned so nobody softens it by accident: a DTO
     * whose readonly property is a plain int id is refused too, even though skipping that id would
     * have been harmless. The old code called this case out by name ("rather than crash on an
     * object that merely has a readonly id"). It is refused anyway: a rule the caller can predict
     * from the class shape beats one they must re-evaluate per value. The remedy is in the message.
     */
    public function testXssCleanRefusesAReadonlyHolderEvenWhenTheReadonlyValueNeededNoChange(): void
    {
        $subject = new SecurityTestReadonlyId(7, self::XSS_LIVE_PAYLOAD);

        try {
            Security::xssCleanRecursive($subject);
            $this->fail('A public readonly property must be refused predictably, by shape.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('$id', $e->getMessage());
            $this->assertStringContainsString(
                'BEFORE constructing',
                $e->getMessage(),
                'A readonly holder must get readonly advice, not "write it back through the container API".'
            );
            $this->assertStringNotContainsString('getArrayCopy', $e->getMessage());
        }
    }

    /** A private/protected-only object is NOT refused: it is outside the contract, not skipped inside it. */
    public function testXssCleanWalksAPlainObjectsPublicPropertiesAndLeavesPrivateStateAlone(): void
    {
        $subject = new SecurityTestPrivateState(self::XSS_LIVE_PAYLOAD);
        $subject->bio = self::XSS_LIVE_PAYLOAD;

        $cleaned = Security::xssCleanRecursive($subject);

        $this->assertSame($subject, $cleaned);
        $this->assertNoActiveContent($cleaned->bio, self::XSS_LIVE_PAYLOAD);
        $this->assertSame(
            self::XSS_LIVE_PAYLOAD,
            $subject->secret(),
            'Private state is documented as NOT walked — pinning the limit, not endorsing it.'
        );
    }

    // ---------------------------------------------------------------------------------------
    // Cycle guard: a back-reference used to die with an UNCATCHABLE fatal (memory exhausted)
    // ---------------------------------------------------------------------------------------

    public function testXssCleanTerminatesOnASelfReferencingObject(): void
    {
        $subject = new \stdClass();
        $subject->bio = self::XSS_LIVE_PAYLOAD;
        $subject->self = $subject;

        $cleaned = Security::xssCleanRecursive($subject);

        $this->assertNoActiveContent($cleaned->bio, self::XSS_LIVE_PAYLOAD);
        $this->assertSame($subject, $cleaned->self, 'The back-reference must survive as the same instance.');
    }

    public function testXssCleanTerminatesOnAMutuallyReferencingObjectGraph(): void
    {
        $parent = new \stdClass();
        $child = new \stdClass();
        $parent->child = $child;
        $child->parent = $parent;
        $child->bio = self::XSS_LIVE_PAYLOAD;

        $cleaned = Security::xssCleanRecursive($parent);

        $this->assertNoActiveContent($child->bio, self::XSS_LIVE_PAYLOAD);
        $this->assertSame($child, $cleaned->child);
        $this->assertSame($parent, $child->parent);
    }

    public function testFilterValueTerminatesOnASelfReferencingObject(): void
    {
        $subject = new \stdClass();
        $subject->a = '  x  ';
        $subject->self = $subject;

        Security::filterValue($subject, null, null, false, false, false, false, false, false, true);

        $this->assertSame('x', $subject->a);
        $this->assertSame($subject, $subject->self);
    }

    /**
     * The guard must not outlive the call that set it. If a refusal deep in the graph left an
     * object marked "in progress" forever, the caller's NEXT call would skip it and return it
     * UNSANITIZED — a fail-open manufactured by the cycle guard itself.
     */
    public function testARefusedWalkDoesNotPoisonTheCycleGuardForLaterCalls(): void
    {
        $subject = new \stdClass();
        $subject->bio = self::XSS_LIVE_PAYLOAD;
        $subject->container = new \ArrayObject(['x' => 'y']);

        try {
            Security::xssCleanRecursive($subject);
        } catch (\InvalidArgumentException) {
            // expected
        }

        unset($subject->container);
        $subject->bio = self::XSS_LIVE_PAYLOAD;

        $cleaned = Security::xssCleanRecursive($subject);

        $this->assertNoActiveContent($cleaned->bio, self::XSS_LIVE_PAYLOAD);
    }

    // ---------------------------------------------------------------------------------------
    // filterValue shares the walk and therefore the contract
    // ---------------------------------------------------------------------------------------

    public function testFilterValueRefusesAnArrayObject(): void
    {
        // CONTRACT CHANGE (was: "sanitizes ArrayObject storage"). Same walk, same refusal — the
        // two must never offer different contracts for the same object.
        $subject = new \ArrayObject(['bio' => self::XSS_LIVE_PAYLOAD]);

        $this->expectException(\InvalidArgumentException::class);

        Security::filterValue($subject, null, null, false, true);
    }

    public function testFilterValueStillRewritesPlainObjectPropertiesInPlace(): void
    {
        // The stdClass case pass 1 was trying to fix must keep working.
        $subject = new \stdClass();
        $subject->a = '  x  ';

        $filtered = Security::filterValue($subject, null, null, false, false, false, false, false, false, true);

        $this->assertSame($subject, $filtered);
        $this->assertSame('x', $subject->a);
    }

    public function testFilterValueDoesNotRaiseOnAnEnum(): void
    {
        $subject = new \stdClass();
        $subject->suit = SecurityTestSuit::Hearts;
        $subject->a = '  x  ';

        Security::filterValue($subject, null, null, false, false, false, false, false, false, true);

        $this->assertSame(SecurityTestSuit::Hearts, $subject->suit);
        $this->assertSame('x', $subject->a);
    }

    public function testFilterValueRefusesAReadonlyHolder(): void
    {
        // CONTRACT CHANGE (was: "readonly is skipped, not rewritten").
        $subject = new \stdClass();
        $subject->frozen = new SecurityTestReadonly('  keep  ', '  x  ');

        $this->expectException(\InvalidArgumentException::class);

        Security::filterValue($subject, null, null, false, false, false, false, false, false, true);
    }

    // ---------------------------------------------------------------------------------------
    // Array keys are documented as NOT walked
    // ---------------------------------------------------------------------------------------

    public function testXssCleanLeavesArrayKeysByteForByteIntact(): void
    {
        // Pinning the DOCUMENTED limit, not endorsing it: sanitizing keys could collide two
        // entries into one and silently drop data, so keys are left alone and the docblock says
        // so. This test exists so the limit cannot change without a reviewer seeing it.
        $cleaned = Security::xssCleanRecursive([self::XSS_LIVE_PAYLOAD => 'value']);

        $this->assertSame([self::XSS_LIVE_PAYLOAD], array_keys($cleaned));
        $this->assertSame('value', $cleaned[self::XSS_LIVE_PAYLOAD]);
    }

    // ---------------------------------------------------------------------------------------
    // Depth: libxml truncates silently, the sanitizer must not
    // ---------------------------------------------------------------------------------------

    public function testXssCleanKeepsContentNestedAtTheLibxmlDepthLimit(): void
    {
        $html = str_repeat('<div>', 255) . 'DEEP' . str_repeat('</div>', 255);

        $this->assertStringContainsString('DEEP', Security::xssCleanRecursive($html));
    }

    public function testXssCleanFailsClosedInsteadOfSilentlyTruncatingTooDeepHtml(): void
    {
        // libxml records a level-3 fatal ("Excessive depth in document: 256") but loadHTML() STILL
        // returns true, so the !$loaded guard never fired and everything past depth 255 was
        // silently DISCARDED. Now it falls back to escaping: markup dies, content survives, and
        // nothing executes.
        foreach ([256, 300] as $depth) {
            $html = str_repeat('<div>', $depth) . 'DEEP' . str_repeat('</div>', $depth);

            $cleaned = Security::xssCleanRecursive($html);

            $this->assertStringContainsString('DEEP', $cleaned, "Content at depth {$depth} must not vanish.");
            $this->assertStringNotContainsString('<div>', $cleaned, 'The fallback escapes rather than emits markup.');
        }
    }

    public function testXssCleanStillSanitizesInputThatOnlyRaisesNonFatalLibxmlErrors(): void
    {
        // Guard against over-reacting: hostile-but-parseable markup raises level-2 libxml ERRORS
        // (`<svg/onload=1>`, mismatched tags, a raw `&`). Failing closed on those would escape
        // almost every real input and destroy the allow-list.
        $this->assertNoActiveContent(Security::xssCleanRecursive('<svg/onload=alert(1)>'), '<svg/onload=alert(1)>');
        // Mismatched nesting: libxml repairs the tree and the allow-list rebuilds it, still markup.
        $this->assertSame('<b><i>x</i></b>', Security::xssCleanRecursive('<b><i>x</b></i>'));
        $this->assertSame('a &amp; b', Security::xssCleanRecursive('a & b'));
    }

    public function testXssCleanIgnoresLibxmlErrorsQueuedByTheCallerBeforeTheCall(): void
    {
        // libxml's error queue is PROCESS-WIDE. A caller who left a fatal queued from their own
        // unrelated parse must not make this sanitizer attribute it to our input and escape every
        // valid value from then on. Only errors raised by our own loadHTML() may be judged.
        $previousMode = libxml_use_internal_errors(true);

        try {
            $foreign = new \DOMDocument();
            $foreign->loadHTML(
                str_repeat('<div>', 300) . 'x' . str_repeat('</div>', 300),
                LIBXML_NOERROR | LIBXML_NOWARNING
            );
            $this->assertNotEmpty(libxml_get_errors(), 'Precondition: the caller has a fatal queued.');

            $this->assertSame('<b>bold</b>', Security::xssCleanRecursive('<b>bold</b>'));
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousMode);
        }
    }

    // ---------------------------------------------------------------------------------------
    // decryptFileV2: a crafted zero-length block must not escape the documented contract
    // ---------------------------------------------------------------------------------------

    /** Splits a V2 file into its raw "{len}-{payload}" block spans. */
    private static function v2BlockSpans(string $raw): array
    {
        $spans = [];
        $i = 0;
        while ($i < strlen($raw)) {
            $dash = strpos($raw, '-', $i);
            if ($dash === false) {
                break;
            }
            $len = (int) substr($raw, $i, $dash - $i);
            $spans[] = [$i, $dash + 1 + $len];
            $i = $dash + 1 + $len;
        }

        return $spans;
    }

    public function testDecryptFileV2ThrowsAndRollsBackOnACraftedZeroLengthBlock(): void
    {
        $source = $this->tempPath('plain');
        file_put_contents($source, 'HELLO WORLD SECRET');

        $encrypted = Security::encryptFileV2($source, self::masterKey(), $this->tempPath('enc'));
        $raw = file_get_contents($encrypted);

        // Header is 4 blocks; the first data triple is blocks 4..6. Keep them, then splice in a
        // zero-length block: fread($fp, 0) raises a ValueError — an \Error, NOT an \Exception — so
        // it escaped the catch AND skipped the rollback, stranding the first block's decrypted
        // plaintext on disk.
        $spans = self::v2BlockSpans($raw);
        $crafted = $this->tempPath('crafted');
        file_put_contents($crafted, substr($raw, 0, $spans[6][1]) . '0-');

        $destination = $this->tempPath('out');

        try {
            Security::decryptFileV2($crafted, self::masterKey(), $destination);
            $this->fail('A crafted zero-length block must not decrypt.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('Error on reading IV ciphertext', $e->getMessage());
        }

        clearstatcache(true, $destination);
        $this->assertFileDoesNotExist(
            $destination,
            'The rollback must run: unauthenticated plaintext may never survive a failure.'
        );
    }

    public function testDecryptFileV2RejectsAZeroLengthHeaderBlock(): void
    {
        $crafted = $this->tempPath('crafted');
        file_put_contents($crafted, '0-');

        $this->expectException(\Exception::class);
        Security::decryptFileV2($crafted, self::masterKey(), $this->tempPath('out'));
    }

    public function testDecryptFileV2RollsBackToTheEntryLengthOnACraftedBlockInAppendMode(): void
    {
        $source = $this->tempPath('plain');
        file_put_contents($source, 'PART TWO PAYLOAD');

        $encrypted = Security::encryptFileV2($source, self::masterKey(), $this->tempPath('enc'));
        $raw = file_get_contents($encrypted);
        $spans = self::v2BlockSpans($raw);

        $crafted = $this->tempPath('crafted');
        file_put_contents($crafted, substr($raw, 0, $spans[6][1]) . '0-');

        // A part an earlier successful call already appended: it belongs to the caller and must
        // survive a bad part untouched.
        $destination = $this->tempPath('out');
        file_put_contents($destination, 'PART ONE;');

        try {
            Security::decryptFileV2($crafted, self::masterKey(), $destination, null, 'a');
            $this->fail('A crafted zero-length block must not decrypt.');
        } catch (\Exception $e) {
            // expected
        }

        $this->assertSame('PART ONE;', file_get_contents($destination));
    }

    public function testDecryptFileV2LeavesAnExistingDestinationAloneWhenItFailsBeforeOpeningIt(): void
    {
        // The rollback may only undo what this call did. A failure raised before the destination
        // is ever opened has written nothing, so deleting the caller's file would be pure data
        // destruction — and routing the key rejection through the rollback made that a live bug.
        $source = $this->tempPath('plain');
        file_put_contents($source, 'DATA');
        $encrypted = Security::encryptFileV2($source, self::masterKey(), $this->tempPath('enc'));

        $destination = $this->tempPath('precious');

        // (a) a key deriveKey rejects, before the destination is opened
        file_put_contents($destination, 'PRECIOUS');
        try {
            Security::decryptFileV2($encrypted, str_repeat('k', 31), $destination);
            $this->fail('A short key must be rejected.');
        } catch (\Exception $e) {
            // expected
        }
        $this->assertSame('PRECIOUS', @file_get_contents($destination), 'Short key must not delete the destination.');

        // (b) a source whose header does not match, also before the destination is opened
        $junk = $this->tempPath('junk');
        file_put_contents($junk, '12-QUJDREVGRw==');
        file_put_contents($destination, 'PRECIOUS');
        try {
            Security::decryptFileV2($junk, self::masterKey(), $destination);
            $this->fail('A bad header must be rejected.');
        } catch (\Exception $e) {
            // expected
        }
        $this->assertSame('PRECIOUS', @file_get_contents($destination), 'Bad header must not delete the destination.');
    }

    public function testDecryptFileV2StillDeletesADestinationItTruncatedItself(): void
    {
        // The counterpart: once we HAVE opened (and so truncated) the destination, its old content
        // is already gone and unauthenticated plaintext must not survive — it gets deleted.
        $source = $this->tempPath('plain');
        file_put_contents($source, 'HELLO WORLD SECRET');
        $encrypted = Security::encryptFileV2($source, self::masterKey(), $this->tempPath('enc'));

        // Truncate away the authenticated end marker: this fails AFTER data blocks are written.
        $raw = file_get_contents($encrypted);
        $spans = self::v2BlockSpans($raw);
        $truncated = $this->tempPath('trunc');
        file_put_contents($truncated, substr($raw, 0, $spans[6][1]));

        $destination = $this->tempPath('out');
        file_put_contents($destination, 'OLD');

        try {
            Security::decryptFileV2($truncated, self::masterKey(), $destination);
            $this->fail('A truncated file must not decrypt.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('truncated', $e->getMessage());
        }

        clearstatcache(true, $destination);
        $this->assertFileDoesNotExist($destination);
    }

    public function testDecryptFileV2StillRoundTripsAfterTheZeroLengthGuard(): void
    {
        // The guard rejects "0-", which writeLengthEncodedBlock never emits. Prove the legitimate
        // path is untouched, including a file whose size is an exact multiple of the block size.
        Security::setFileEncryptBlocksBytes(16);
        try {
            $payload = str_repeat('A', 32);
            $source = $this->tempPath('plain');
            file_put_contents($source, $payload);

            $encrypted = Security::encryptFileV2($source, self::masterKey(), $this->tempPath('enc'));
            $decrypted = Security::decryptFileV2($encrypted, self::masterKey(), $this->tempPath('out'));

            $this->assertSame($payload, file_get_contents($decrypted));
        } finally {
            Security::setFileEncryptBlocksBytes(null);
        }
    }

    // ---------------------------------------------------------------------------------------
    // encryptFileV2 / decryptFileV2 — in-place (destination === source) is REFUSED
    //
    // Both functions open the destination for writing (truncating it) and only then read the
    // source. Passing one path as both shredded the file and STILL reported success:
    // encryptFileV2($f, $key, $f) returned $f, left 284 bytes of its own envelope header, and the
    // original was unrecoverable. decryptFileV2 was worse: for a ciphertext small enough to fit
    // PHP's 8192-byte stream read buffer it "worked", so it passed in dev; above that it failed
    // mid-read and the rollback then DELETED the caller's only ciphertext copy.
    // ---------------------------------------------------------------------------------------

    /** The exact call from the report: encrypt a file onto itself. */
    public function testEncryptFileV2RefusesToWriteOntoItsOwnSource(): void
    {
        $file = $this->tempPath('inplace');
        file_put_contents($file, 'CONTEUDO IMPORTANTE DO USUARIO');

        try {
            Security::encryptFileV2($file, self::masterKey(), $file);
            $this->fail('Expected an Exception: encrypting a file onto itself destroys it.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('must not be the same file', $e->getMessage());
        }

        // The whole point: the caller's file is still there, byte for byte.
        $this->assertSame('CONTEUDO IMPORTANTE DO USUARIO', file_get_contents($file));
    }

    /**
     * decryptFileV2 in place, on BOTH sides of the stream-buffer threshold that made this defect
     * invisible in development. The 10-byte case used to return SUCCESS; the 100 KB case used to
     * throw AND unlink the ciphertext.
     */
    #[DataProvider('inPlaceDecryptSizeProvider')]
    public function testDecryptFileV2RefusesToWriteOntoItsOwnSource(int $payloadSize): void
    {
        [, $enc] = $this->makeEncryptedFile(str_repeat('A', $payloadSize));
        $before = file_get_contents($enc);

        try {
            Security::decryptFileV2($enc, self::masterKey(), $enc);
            $this->fail('Expected an Exception: decrypting a file onto itself destroys the ciphertext.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('must not be the same file', $e->getMessage());
        }

        // The ciphertext must survive UNTOUCHED — it may be the caller's only copy.
        $this->assertFileExists($enc);
        $this->assertSame($before, file_get_contents($enc), 'The ciphertext was modified or destroyed.');
    }

    /** @return array<string, array{0:int}> */
    public static function inPlaceDecryptSizeProvider(): array
    {
        return [
            'small: fits the 8192-byte stream buffer, used to silently "work"' => [10],
            'just under the buffer'                                            => [1000],
            'larger than the buffer: used to throw AND delete the ciphertext'  => [100000],
        ];
    }

    /**
     * A raw string compare on the caller's paths is trivially defeated. Every spelling below names
     * the SAME file and must be refused just like the identical path.
     */
    #[DataProvider('equivalentPathSpellingProvider')]
    public function testFileV2RefusesInPlaceForEquivalentPathSpellings(string $mutation): void
    {
        $file = $this->tempPath('spell');
        file_put_contents($file, 'CONTEUDO');

        $dir  = dirname($file);
        $name = basename($file);

        $destination = match ($mutation) {
            'dot-slash'      => $dir . DIRECTORY_SEPARATOR . '.' . DIRECTORY_SEPARATOR . $name,
            'trailing-slash' => $file . DIRECTORY_SEPARATOR,
            'dot-dot'        => $dir . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . basename($dir) . DIRECTORY_SEPARATOR . $name,
            'case'           => $dir . DIRECTORY_SEPARATOR . strtoupper($name),
        };

        if ($mutation === 'case' && DIRECTORY_SEPARATOR !== '\\') {
            $this->markTestSkipped('Path case only aliases the same file on Windows.');
        }

        try {
            Security::encryptFileV2($file, self::masterKey(), $destination);
            $this->fail("Expected an Exception for the '{$mutation}' spelling of the source path.");
        } catch (\Exception $e) {
            $this->assertStringContainsString('must not be the same file', $e->getMessage());
        }

        $this->assertSame('CONTEUDO', file_get_contents($file));
    }

    /** @return array<string, array{0:string}> */
    public static function equivalentPathSpellingProvider(): array
    {
        return [
            './x vs x'      => ['dot-slash'],
            'trailing sep'  => ['trailing-slash'],
            '.. segment'    => ['dot-dot'],
            'different case'=> ['case'],
        ];
    }

    /** A symlink is a second name for the same file; realpath() collapses it. */
    public function testFileV2RefusesInPlaceThroughASymlink(): void
    {
        $file = $this->tempPath('symsrc');
        file_put_contents($file, 'CONTEUDO');
        $link = $this->tempPath('symlink');

        if (!@symlink($file, $link)) {
            $this->markTestSkipped('This platform/account cannot create symlinks.');
        }

        try {
            Security::encryptFileV2($file, self::masterKey(), $link);
            $this->fail('Expected an Exception: the destination symlink resolves to the source.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('must not be the same file', $e->getMessage());
        }

        $this->assertSame('CONTEUDO', file_get_contents($file));
    }

    /**
     * A HARD link is the case a path comparison CANNOT see: both names are equally real, so
     * realpath() correctly reports two different paths for one file. Only the device+inode
     * comparison catches it. Verified on Windows too — its stat() does report a real inode.
     */
    public function testFileV2RefusesInPlaceThroughAHardLink(): void
    {
        $file = $this->tempPath('hardsrc');
        file_put_contents($file, 'CONTEUDO');
        $hard = $this->tempPath('hardlink');

        if (!@link($file, $hard)) {
            $this->markTestSkipped('This platform/filesystem cannot create hard links.');
        }

        // Pin the premise: if realpath() ever collapsed these, this test would prove nothing.
        $this->assertNotSame(realpath($file), realpath($hard), 'Premise: a hard link has its own realpath.');

        try {
            Security::encryptFileV2($file, self::masterKey(), $hard);
            $this->fail('Expected an Exception: the destination hard link IS the source file.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('must not be the same file', $e->getMessage());
        }

        $this->assertSame('CONTEUDO', file_get_contents($file));
    }

    // ---------------------------------------------------------------------------------------
    // REGRESSION GUARD: the ordinary source != destination path must be completely unaffected.
    // The in-place guard runs on EVERY call; a false positive here would break all file crypto.
    // ---------------------------------------------------------------------------------------

    public function testFileV2RoundTripIsUnaffectedByTheInPlaceGuard(): void
    {
        $payload = random_bytes(200000); // spans several stream buffers
        $src = $this->tempPath('ok_src');
        file_put_contents($src, $payload);
        $enc = $this->tempPath('ok_enc');
        $dec = $this->tempPath('ok_dec');

        Security::encryptFileV2($src, self::masterKey(), $enc);
        Security::decryptFileV2($enc, self::masterKey(), $dec);

        $this->assertSame($payload, file_get_contents($dec));
    }

    /**
     * Both sides EXISTING is what makes the inode branch run for real. If it compared a
     * not-reported inode (0 === 0) or a shared device, this would fail — every normal overwrite
     * would be rejected.
     */
    public function testFileV2AllowsOverwritingPreExistingDistinctDestinations(): void
    {
        $payload = 'CONTEUDO IMPORTANTE';
        $src = $this->tempPath('pre_src');
        file_put_contents($src, $payload);
        $enc = $this->tempPath('pre_enc');
        $dec = $this->tempPath('pre_dec');

        // Pre-create both destinations in the SAME directory as the source.
        file_put_contents($enc, 'stale');
        file_put_contents($dec, 'stale');

        Security::encryptFileV2($src, self::masterKey(), $enc);
        Security::decryptFileV2($enc, self::masterKey(), $dec);

        $this->assertSame($payload, file_get_contents($dec));
    }

    /** Append mode reassembly must still work — the guard must not fire on a shared destination. */
    public function testFileV2AppendModeIsUnaffectedByTheInPlaceGuard(): void
    {
        $a = $this->tempPath('part_a');
        $b = $this->tempPath('part_b');
        file_put_contents($a, 'PARTE-A|');
        file_put_contents($b, 'PARTE-B');
        $encA = $this->tempPath('part_a_enc');
        $encB = $this->tempPath('part_b_enc');
        $out  = $this->tempPath('joined');

        Security::encryptFileV2($a, self::masterKey(), $encA);
        Security::encryptFileV2($b, self::masterKey(), $encB);
        Security::decryptFileV2($encA, self::masterKey(), $out, null, 'w');
        Security::decryptFileV2($encB, self::masterKey(), $out, null, 'a');

        $this->assertSame('PARTE-A|PARTE-B', file_get_contents($out));
    }

    /**
     * The in-place guard must NOT fold case to decide identity.
     *
     * Windows is usually case-insensitive, which tempts a case-insensitive comparison — but a
     * directory can opt into case sensitivity (fsutil; WSL-created directories have it by
     * default), and there 'x.txt' and 'X.TXT' are two genuinely different files with different
     * inodes. Folding case rejected that legitimate call. Nothing is lost by not folding: on a
     * case-INsensitive volume the two spellings are one file, so realpath() returns the same
     * on-disk name for both and they share an inode — proved by the 'different case' data set of
     * testFileV2RefusesInPlaceForEquivalentPathSpellings, which still passes.
     *
     * Self-validating: if the premise (two coexisting case-differing files) does not hold on this
     * filesystem, the test skips rather than asserting something meaningless.
     */
    public function testFileV2AllowsDistinctFilesDifferingOnlyInCase(): void
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpht_casesens_' . bin2hex(random_bytes(6));
        if (!@mkdir($dir)) {
            $this->markTestSkipped('Could not create the probe directory.');
        }

        $lower = $dir . DIRECTORY_SEPARATOR . 'segredo.txt';
        $upper = $dir . DIRECTORY_SEPARATOR . 'SEGREDO.TXT';
        $this->tempPaths[] = $lower;
        $this->tempPaths[] = $upper;

        try {
            if (DIRECTORY_SEPARATOR === '\\') {
                if (!function_exists('exec')) {
                    $this->markTestSkipped('exec() is unavailable; cannot opt the directory into case sensitivity.');
                }
                // Opt this directory into case sensitivity; needs no elevation, but can fail.
                $out = [];
                $rc  = -1;
                @exec('fsutil file setCaseSensitiveInfo "' . $dir . '" enable 2>&1', $out, $rc);
                if ($rc !== 0) {
                    $this->markTestSkipped('This filesystem does not support per-directory case sensitivity.');
                }
            }

            file_put_contents($lower, 'SOU O ARQUIVO MINUSCULO');
            file_put_contents($upper, 'SOU O ARQUIVO MAIUSCULO');
            clearstatcache();

            // The premise: they must really be two separate files here.
            if (!is_file($lower) || !is_file($upper) || fileinode($lower) === fileinode($upper)
                || file_get_contents($lower) === file_get_contents($upper)) {
                $this->markTestSkipped('This filesystem folds case; the two spellings are one file.');
            }

            // Encrypting one onto the other is a perfectly legitimate call.
            Security::encryptFileV2($lower, self::masterKey(), $upper);

            $this->assertSame('SOU O ARQUIVO MINUSCULO', file_get_contents($lower), 'The source must be untouched.');
            $this->assertNotSame('SOU O ARQUIVO MAIUSCULO', file_get_contents($upper), 'The destination should hold the ciphertext.');
        } finally {
            @unlink($lower);
            @unlink($upper);
            @rmdir($dir);
        }
    }

    /** A destination whose path merely CONTAINS the source path must be allowed. */
    public function testFileV2AllowsADestinationThatExtendsTheSourcePath(): void
    {
        $src = $this->tempPath('prefix');
        file_put_contents($src, 'CONTEUDO');
        $enc = $src . '.enc';
        $this->tempPaths[] = $enc;

        Security::encryptFileV2($src, self::masterKey(), $enc);

        $this->assertFileExists($enc);
        $this->assertSame('CONTEUDO', file_get_contents($src));
    }

    // ---------------------------------------------------------------------------------------
    // decryptFileV2 — a pre-open failure must leave the destination's PERMISSIONS alone too
    // ---------------------------------------------------------------------------------------

    /**
     * The docblock promises a failure raised before the destination is opened leaves an existing
     * destination untouched, but the chmod() applying $permissionMode ran BEFORE the header was
     * even read — so a rejected source left the caller's 0400 secret at 0644. The chmod is now
     * deferred to immediately before the fopen.
     *
     * Runs everywhere: it asserts on the READ-ONLY bit, the one permission Windows does model
     * (verified: chmod(0444) reports 0444/not-writable, chmod(0644) reports 0666/writable).
     */
    public function testDecryptFileV2DoesNotWidenDestinationPermissionsOnAPreOpenFailure(): void
    {
        $junk = $this->tempPath('notcipher');
        file_put_contents($junk, 'this is not a V2 envelope at all');

        $destination = $this->tempPath('secret');
        file_put_contents($destination, 'SEGREDO PREEXISTENTE');

        try {
            chmod($destination, 0444);
            clearstatcache(true, $destination);
            $modeBefore = fileperms($destination) & 0777;
            $this->assertFalse(is_writable($destination), 'Premise: the destination starts read-only.');

            try {
                // Fails on the header, i.e. BEFORE the destination is ever opened.
                Security::decryptFileV2($junk, self::masterKey(), $destination, '0666');
                $this->fail('Expected an Exception for a non-envelope source.');
            } catch (\Exception) {
                // expected
            }

            clearstatcache(true, $destination);
            $this->assertSame($modeBefore, fileperms($destination) & 0777, 'A pre-open failure widened the destination permissions.');
            $this->assertFalse(is_writable($destination), 'A pre-open failure made the caller\'s read-only file writable.');
            $this->assertSame('SEGREDO PREEXISTENTE', file_get_contents($destination), 'A pre-open failure altered the destination contents.');
        } finally {
            // A read-only file cannot be unlink()ed on Windows; let tearDown clean up.
            @chmod($destination, 0666);
        }
    }

    /**
     * The other half of the deferral: the chmod must still happen BEFORE the fopen. If it had been
     * moved to after the destination was opened, a read-only destination could no longer be opened
     * for writing and this would throw. Portable for the same reason as the test above.
     */
    public function testDecryptFileV2StillChmodsTheDestinationBeforeOpeningIt(): void
    {
        [, $enc] = $this->makeEncryptedFile('CONTEUDO');

        $dec = $this->tempPath('ro_dec');
        file_put_contents($dec, 'stale');

        try {
            chmod($dec, 0444);
            clearstatcache(true, $dec);
            $this->assertFalse(is_writable($dec), 'Premise: the destination starts read-only.');

            // $permissionMode makes it writable; that chmod must still precede the fopen.
            Security::decryptFileV2($enc, self::masterKey(), $dec, '0666');

            $this->assertSame('CONTEUDO', file_get_contents($dec));
        } finally {
            @chmod($dec, 0666);
        }
    }

    /** The deferral must not stop a REAL decrypt from applying the exact $permissionMode. */
    public function testDecryptFileV2StillAppliesPermissionModeOnSuccess(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('Windows models only the read-only bit; the exact mode is asserted on POSIX.');
        }

        [, $enc] = $this->makeEncryptedFile('CONTEUDO');
        $dec = $this->tempPath('perm_dec');
        file_put_contents($dec, 'stale');
        chmod($dec, 0666);

        Security::decryptFileV2($enc, self::masterKey(), $dec, '0600');

        clearstatcache(true, $dec);
        $this->assertSame(0600, fileperms($dec) & 0777);
        $this->assertSame('CONTEUDO', file_get_contents($dec));
    }

    // ---------------------------------------------------------------------------------------
    // Short fwrite must fail loud (contradicted the FAILS LOUD contract)
    // ---------------------------------------------------------------------------------------

    /**
     * fwrite() returns the number of bytes it actually wrote: on a full disk or a quota it returns
     * a SHORT COUNT, not false. Testing only `=== false` accepted a partial write, so decryptFileV2
     * returned the destination path as a success while the plaintext on disk was truncated.
     *
     * A short write cannot be provoked portably (it needs a genuinely full device; there is no
     * /dev/full on Windows, and the destination must be a real path a stream wrapper cannot fake),
     * so — exactly like testDecryptFileV2DerivesItsVersionFromTheConstantNotALiteral above — this
     * pins the wiring in the source. It fails against the pre-fix body, which only tested for false.
     */
    public function testDecryptFileV2DetectsAShortPlaintextWrite(): void
    {
        $method = new \ReflectionMethod(Security::class, 'decryptFileV2');
        $lines  = file($method->getFileName());
        $body   = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*fwrite\s*\(\s*\$fpOut\s*,\s*\$plaintext\s*\)\s*===\s*false\s*\)/',
            $body,
            'decryptFileV2 still accepts a short fwrite() as success, truncating the plaintext silently.'
        );
        $this->assertMatchesRegularExpression(
            '/\$written\s*=\s*fwrite\s*\(\s*\$fpOut\s*,\s*\$plaintext\s*\)\s*;/',
            $body,
            'decryptFileV2 must capture the fwrite() return value.'
        );
        $this->assertMatchesRegularExpression(
            '/\$written\s*!==\s*\$plaintextLength/',
            $body,
            'decryptFileV2 must compare the bytes written against the plaintext length.'
        );
    }

    /** The same defect on encryptFileV2's write path: every block goes through this helper. */
    public function testWriteLengthEncodedBlockDetectsAShortWrite(): void
    {
        $method = new \ReflectionMethod(Security::class, 'writeLengthEncodedBlock');
        $lines  = file($method->getFileName());
        $body   = implode('', array_slice(
            $lines,
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1
        ));

        $this->assertDoesNotMatchRegularExpression(
            '/if\s*\(\s*fwrite\s*\([^)]*\)\s*===\s*false\s*\)/',
            $body,
            'writeLengthEncodedBlock still accepts a short fwrite(): encryptFileV2 would report success for a truncated ciphertext.'
        );
        $this->assertMatchesRegularExpression(
            '/\$written\s*===\s*false\s*\|\|\s*\$written\s*!==\s*mb_strlen\s*\(\s*\$payload\s*,\s*[\'"]8bit[\'"]\s*\)/',
            $body,
            'writeLengthEncodedBlock must demand that every byte of the block was written.'
        );
    }

    /** Writing a full envelope must still succeed — the stricter check must not reject good writes. */
    public function testWriteLengthEncodedBlockStillWritesCompleteEnvelopes(): void
    {
        $payload = random_bytes(50000);
        $src = $this->tempPath('short_src');
        file_put_contents($src, $payload);
        $enc = $this->tempPath('short_enc');
        $dec = $this->tempPath('short_dec');

        Security::encryptFileV2($src, self::masterKey(), $enc);
        Security::decryptFileV2($enc, self::masterKey(), $dec);

        $this->assertSame($payload, file_get_contents($dec));
    }
}

/** An ArrayAccess+Traversable collection with its own storage AND a public property. */
final class SecurityTestCollection implements \ArrayAccess, \IteratorAggregate
{
    public string $label = '';

    public function __construct(private array $items = [])
    {
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->items);
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;

            return;
        }
        $this->items[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}

/** Traversable but NOT ArrayAccess, and its iterator yields a key that is not a property. */
final class SecurityTestLyingIteratorAggregate implements \IteratorAggregate
{
    public string $bio = '';

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator(['ghost' => 'not-a-property']);
    }
}

/**
 * A container whose getIterator() RE-KEYS — the ordinary shape of a sorted, paginated or
 * array_values()'d view. This is the counter-example to "iteration keys are storage offsets":
 * offsetSet($iterationKey, ...) writes to offset 0, which addresses NOTHING here, so the live
 * payload survives at 'bio' and the container grows an entry nobody inserted.
 */
final class SecurityTestReKeyingCollection implements \ArrayAccess, \IteratorAggregate
{
    public function __construct(private array $items = [])
    {
    }

    /** @return array The real storage, keys and all. */
    public function items(): array
    {
        return $this->items;
    }

    public function getIterator(): \Traversable
    {
        return new \ArrayIterator(array_values($this->items));
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->items[$offset]);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet(mixed $offset): mixed
    {
        return $this->items[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        if ($offset === null) {
            $this->items[] = $value;

            return;
        }
        $this->items[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->items[$offset]);
    }
}

/** A readonly property whose value never needed sanitizing — refused all the same, by shape. */
final class SecurityTestReadonlyId
{
    public function __construct(public readonly int $id, public string $bio)
    {
    }
}

/** A plain object with a public property alongside private state the walk cannot reach. */
final class SecurityTestPrivateState
{
    public string $bio = '';

    public function __construct(private string $secret)
    {
    }

    public function secret(): string
    {
        return $this->secret;
    }
}

/** A public readonly property alongside a writable one. */
final class SecurityTestReadonly
{
    public function __construct(public readonly string $frozen, public string $mutable)
    {
    }
}

enum SecurityTestSuit: string
{
    case Hearts = 'H';
    case Spades = 'S';
}
