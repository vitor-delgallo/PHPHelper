<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Keyring;
use VD\PHPHelper\Security;
use VD\PHPHelper\SQL;

/**
 * Key management around Security: generating and loading keys, key ids, the Keyring (rotation),
 * dbContext() (canonical AAD, versions against replay), reencryptDataDB(), and what a keyring
 * changes for every envelope — DB and local carry a key id, files and cross-platform values are
 * tried key by key.
 */
final class SecurityKeysTest extends TestCase
{
    /** Fixed 32-byte keys, so the vectors below stay reproducible. */
    private const KEY_K = 'kkkkkkkkkkkkkkkkkkkkkkkkkkkkkkkk';
    private const KEY_Z = 'zzzzzzzzzzzzzzzzzzzzzzzzzzzzzzzz';
    private const KEY_Q = 'qqqqqqqqqqqqqqqqqqqqqqqqqqqqqqqq';

    /** keyId(KEY_K), pinned: the id is written into every envelope, so it must never drift. */
    private const KEY_K_ID = '0735d1be7e009715';

    /**
     * Envelopes written by this version for KEY_K, salt "salt-1": files at rest and rows in a
     * database must stay decryptable across library versions.
     */
    private const DB_VECTOR = 'v2:0735d1be7e009715:yvLVrjddGSJ/mRG0pOI/8Y/BZe6Fb5LH+c35Z3GHkhU6LIsLLNAR+Qzy';
    private const LOCAL_VECTOR = 'l1:0735d1be7e009715:yXX+Ph3jV4ftStnVjqn0uD+/PR0IZrOpWc03HpdRUnBDOnAHJf9/LoeGEuKmGvRutolon3ydntB8aFfzcDvh';

    private ?string $dir = null;

    protected function tearDown(): void
    {
        if ($this->dir !== null && is_dir($this->dir)) {
            foreach (scandir($this->dir) as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    @unlink($this->dir . DIRECTORY_SEPARATOR . $entry);
                }
            }
            @rmdir($this->dir);
        }
    }

    private function tempDir(): string
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phpht_keys_' . bin2hex(random_bytes(8));
        mkdir($this->dir);

        return $this->dir;
    }

    /** A keyring after a rotation: KEY_Z is current, KEY_K is the old key. */
    private static function rotated(): Keyring
    {
        return new Keyring(self::KEY_Z, self::KEY_K);
    }

    // ---------------------------------------------------------------------------------------
    // generateKey / keyFromBase64 / keyId
    // ---------------------------------------------------------------------------------------

    public function testGenerateKeyReturnsBase64OfThirtyTwoFreshRandomBytes(): void
    {
        $a = Security::generateKey();
        $b = Security::generateKey();

        $this->assertSame(44, strlen($a));
        $this->assertSame(32, strlen(base64_decode($a, true)));
        $this->assertNotSame($a, $b);
    }

    public function testKeyFromBase64RoundTripsAGeneratedKeyAndIgnoresSurroundingWhitespace(): void
    {
        $encoded = Security::generateKey();

        $this->assertSame(base64_decode($encoded, true), Security::keyFromBase64($encoded));
        $this->assertSame(base64_decode($encoded, true), Security::keyFromBase64("  {$encoded}\r\n"));
    }

    #[DataProvider('invalidEncodedKeyProvider')]
    public function testKeyFromBase64RejectsAnythingButStrictBase64OfExactly32Bytes(string $encoded): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('exactly 32 bytes');
        Security::keyFromBase64($encoded);
    }

    public static function invalidEncodedKeyProvider(): array
    {
        return [
            'empty'                     => [''],
            '31 bytes'                  => [base64_encode(str_repeat('a', 31))],
            '33 bytes'                  => [base64_encode(str_repeat('a', 33))],
            'a passphrase, not base64'  => ['minha senha super secreta 123456'],
            'invalid base64 character'  => [substr(base64_encode(str_repeat('a', 32)), 0, 40) . '*==='],
            'hex instead of base64'     => [bin2hex(str_repeat('a', 32))],
        ];
    }

    public function testKeyIdIsSixteenHexCharactersPinnedAndDistinctPerKey(): void
    {
        $this->assertSame(self::KEY_K_ID, Security::keyId(self::KEY_K));
        $this->assertMatchesRegularExpression('/\A[0-9a-f]{16}\z/', Security::keyId(self::KEY_Z));
        $this->assertNotSame(Security::keyId(self::KEY_K), Security::keyId(self::KEY_Z));
        $this->assertStringNotContainsString(bin2hex(self::KEY_K), Security::keyId(self::KEY_K));
    }

    public function testKeyIdRefusesAShortKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 bytes');
        Security::keyId(str_repeat('k', 31));
    }

    // ---------------------------------------------------------------------------------------
    // Keyring
    // ---------------------------------------------------------------------------------------

    public function testKeyringKeepsTheCurrentKeyFirst(): void
    {
        $ring = new Keyring(self::KEY_Z, self::KEY_K, self::KEY_Q);

        $this->assertSame(self::KEY_Z, $ring->currentKey());
        $this->assertSame(Security::keyId(self::KEY_Z), $ring->currentId());
        $this->assertSame([Security::keyId(self::KEY_Z), self::KEY_K_ID, Security::keyId(self::KEY_Q)], $ring->ids());
        $this->assertSame(self::KEY_K, $ring->get(self::KEY_K_ID));
        $this->assertNull($ring->get('0000000000000000'));
        $this->assertSame([Security::keyId(self::KEY_Z) => self::KEY_Z, self::KEY_K_ID => self::KEY_K, Security::keyId(self::KEY_Q) => self::KEY_Q], $ring->all());
    }

    /** The same key twice is always a mistake — typically an "old" key that was never changed. */
    public function testKeyringRefusesTheSameKeyTwice(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('same key');
        new Keyring(self::KEY_K, self::KEY_Z, self::KEY_K);
    }

    public function testKeyringRefusesAShortKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at least 32 bytes');
        new Keyring(self::KEY_K, 'short');
    }

    /** An unset "old key" environment variable must not need a special case. */
    public function testKeyringFromBase64SkipsNullAndBlankPreviousKeys(): void
    {
        $ring = Keyring::fromBase64(base64_encode(self::KEY_Z), null, '', "  \n", base64_encode(self::KEY_K));

        $this->assertSame([Security::keyId(self::KEY_Z), self::KEY_K_ID], $ring->ids());
    }

    /** getenv() returns false for an unset variable: usable directly, with a clear error for the current key. */
    public function testKeyringFromBase64AcceptsGetenvResults(): void
    {
        $ring = Keyring::fromBase64(base64_encode(self::KEY_Z), false);
        $this->assertSame([Security::keyId(self::KEY_Z)], $ring->ids());

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('current key is missing');
        Keyring::fromBase64(false);
    }

    public function testKeyringFromBase64RejectsAnInvalidKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Keyring::fromBase64(base64_encode(self::KEY_Z), 'not-a-key');
    }

    /** Dumping a keyring for debugging must print ids, never the raw keys. */
    public function testDumpingAKeyringShowsIdsButNeverTheKeys(): void
    {
        $ring = self::rotated();

        ob_start();
        var_dump($ring);
        $dump = ob_get_clean() . print_r($ring, true);

        $this->assertStringContainsString(self::KEY_K_ID, $dump);
        $this->assertStringNotContainsString(self::KEY_K, $dump);
        $this->assertStringNotContainsString(self::KEY_Z, $dump);
    }

    public function testAKeyringCannotBeSerialized(): void
    {
        $this->expectException(\LogicException::class);
        serialize(self::rotated());
    }

    /** #[\SensitiveParameter]: a key passed as a string must not show up in a stack trace either. */
    public function testKeysAreRedactedFromStackTraces(): void
    {
        $plaintext = 'CPF 529.982.247-25';
        try {
            Security::encryptDataDB($plaintext, self::KEY_K, '', 'visible-salt');
            $this->fail('An empty AAD must be refused.');
        } catch (\Exception $e) {
            $trace = $e->getTraceAsString() . print_r($e->getTrace(), true);

            // Control: the salt is not sensitive, so it shows up whenever PHP records arguments.
            if (!str_contains($trace, 'visible-salt')) {
                $this->markTestSkipped('zend.exception_ignore_args is on: traces carry no arguments to redact.');
            }
            $this->assertStringNotContainsString(substr(self::KEY_K, 0, 10), $trace);
            $this->assertStringNotContainsString(substr($plaintext, 0, 10), $trace);
        }
    }

    // ---------------------------------------------------------------------------------------
    // dbContext
    // ---------------------------------------------------------------------------------------

    public function testDbContextIsCanonical(): void
    {
        $this->assertSame('["db","wallets","balance","42"]', Security::dbContext('wallets', 'balance', 42));
        $this->assertSame('["db","wallets","balance","42",9]', Security::dbContext('wallets', 'balance', 42, 9));
        $this->assertSame(Security::dbContext('t', 'c', 42), Security::dbContext('t', 'c', '42'), 'an int id and its string form are the same row');
        $this->assertSame('["db","usuários","e-mail","a/b"]', Security::dbContext('usuários', 'e-mail', 'a/b'));
    }

    /** Hand-built "a.b:c" strings collide when a name contains a separator; dbContext cannot. */
    public function testDbContextIsUnambiguous(): void
    {
        $this->assertNotSame(Security::dbContext('a.b', 'c', 1), Security::dbContext('a', 'b.c', 1));
        $this->assertNotSame(Security::dbContext('a', 'b', '1:2'), Security::dbContext('a', 'b:1', '2'));
        $this->assertNotSame(Security::dbContext('a', 'b', 1), Security::dbContext('a', 'b', 1, 0));
        $this->assertNotSame(Security::dbContext('a', 'b', 1, 1), Security::dbContext('a', 'b', 1, 2));
    }

    #[DataProvider('invalidContextProvider')]
    public function testDbContextRejectsInvalidParts(string $table, string $column, string|int $rowId, ?int $version): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Security::dbContext($table, $column, $rowId, $version);
    }

    public static function invalidContextProvider(): array
    {
        return [
            'empty table'      => ['', 'c', 1, null],
            'empty column'     => ['t', '', 1, null],
            'empty row id'     => ['t', 'c', '', null],
            'negative version' => ['t', 'c', 1, -1],
            'invalid UTF-8'    => ["t\xC3", 'c', 1, null],
        ];
    }

    // ---------------------------------------------------------------------------------------
    // Replay: a version in the AAD
    // ---------------------------------------------------------------------------------------

    /**
     * The replay scenario from SECURITY.md: the attacker saves the envelope of their balance,
     * spends it, then writes the old envelope back. Bound to the row's version, the old envelope
     * no longer decrypts once the application has moved the row to the next version.
     */
    public function testAnOlderValueNoLongerDecryptsOnceTheVersionMovedOn(): void
    {
        $saved = Security::encryptDataDB('1000.00', self::KEY_K, Security::dbContext('wallets', 'balance', 42, 7));
        $current = Security::encryptDataDB('0.00', self::KEY_K, Security::dbContext('wallets', 'balance', 42, 8));

        $this->assertSame('0.00', Security::decryptDataDB($current, self::KEY_K, Security::dbContext('wallets', 'balance', 42, 8)));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('authentication tag mismatch');
        Security::decryptDataDB($saved, self::KEY_K, Security::dbContext('wallets', 'balance', 42, 8));
    }

    /**
     * What the version does NOT cover, pinned so the documentation stays honest: an attacker who
     * also writes the old version number back restores a consistent old row, and it decrypts.
     * Detecting that needs the latest version kept outside the database (SECURITY.md).
     */
    public function testAFullRollbackOfValueAndVersionStillDecrypts(): void
    {
        $saved = Security::encryptDataDB('1000.00', self::KEY_K, Security::dbContext('wallets', 'balance', 42, 7));

        $this->assertSame('1000.00', Security::decryptDataDB($saved, self::KEY_K, Security::dbContext('wallets', 'balance', 42, 7)));
    }

    public function testReencryptDataDbMovesAValueToTheNextVersion(): void
    {
        $v7 = Security::encryptDataDB('1000.00', self::KEY_K, Security::dbContext('wallets', 'balance', 42, 7));

        $v8 = Security::reencryptDataDB($v7, self::KEY_K, Security::dbContext('wallets', 'balance', 42, 7), Security::dbContext('wallets', 'balance', 42, 8));

        $this->assertSame('1000.00', Security::decryptDataDB($v8, self::KEY_K, Security::dbContext('wallets', 'balance', 42, 8)));
        $this->assertNotSame($v7, $v8);
    }

    // ---------------------------------------------------------------------------------------
    // DB envelope and key rotation
    // ---------------------------------------------------------------------------------------

    public function testTheDbEnvelopeFollowsItsDocumentedFormat(): void
    {
        $aad = Security::dbContext('t', 'c', 1);
        $envelope = Security::encryptDataDB('documented', self::KEY_K, $aad, 'salt-1');

        [$version, $keyId, $payload] = explode(':', $envelope, 3);
        $this->assertSame(['v2', self::KEY_K_ID], [$version, $keyId]);

        // Independent decrypt: iv(12) || tag(16) || ciphertext, key HKDF(master, salt, "db-cell"),
        // AAD "v2|{key id}|{aad}".
        $raw = base64_decode($payload, true);
        $plain = openssl_decrypt(
            substr($raw, 28),
            'aes-256-gcm',
            hash_hkdf('sha256', self::KEY_K, 32, 'db-cell', 'salt-1'),
            OPENSSL_RAW_DATA,
            substr($raw, 0, 12),
            substr($raw, 12, 16),
            "v2|{$keyId}|{$aad}"
        );
        $this->assertSame('documented', $plain);
    }

    public function testFixedDbAndLocalVectorsStillDecrypt(): void
    {
        $this->assertSame('vetor fixo ✓', Security::decryptDataDB(self::DB_VECTOR, self::KEY_K, Security::dbContext('vectors', 'value', 1, 3), 'salt-1'));
        $this->assertSame('vetor local ✓', Security::decryptLocal(self::LOCAL_VECTOR, self::KEY_K, 'salt-1'));
        $this->assertSame('vetor fixo ✓', Security::decryptDataDB(self::DB_VECTOR, self::rotated(), Security::dbContext('vectors', 'value', 1, 3), 'salt-1'));
    }

    public function testAKeyringEncryptsUnderItsCurrentKeyAndDecryptsUnderAPreviousOne(): void
    {
        $aad = Security::dbContext('t', 'c', 1);
        $old = Security::encryptDataDB('written before the rotation', self::KEY_K, $aad);
        $new = Security::encryptDataDB('written after the rotation', self::rotated(), $aad);

        $this->assertStringStartsWith('v2:' . Security::keyId(self::KEY_Z) . ':', $new);
        $this->assertSame('written before the rotation', Security::decryptDataDB($old, self::rotated(), $aad));
        $this->assertSame('written after the rotation', Security::decryptDataDB($new, self::rotated(), $aad));
        $this->assertSame('written after the rotation', Security::decryptDataDB($new, self::KEY_Z, $aad));
    }

    public function testAValueUnderAKeyTheKeyringDoesNotHoldNamesTheMissingKeyId(): void
    {
        $aad = Security::dbContext('t', 'c', 1);
        $old = Security::encryptDataDB('x', self::KEY_K, $aad);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No key with id ' . self::KEY_K_ID);
        Security::decryptDataDB($old, new Keyring(self::KEY_Z, self::KEY_Q), $aad);
    }

    /** The key id is authenticated: pointing an envelope at another key of the ring fails. */
    public function testRewritingTheKeyIdOfAnEnvelopeIsDetected(): void
    {
        $aad = Security::dbContext('t', 'c', 1);
        [, , $payload] = explode(':', Security::encryptDataDB('x', self::KEY_K, $aad), 3);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('authentication tag mismatch');
        Security::decryptDataDB('v2:' . Security::keyId(self::KEY_Z) . ':' . $payload, self::rotated(), $aad);
    }

    #[DataProvider('malformedEnvelopeProvider')]
    public function testMalformedDbEnvelopesAreRejected(string $envelope, string $message): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage($message);
        Security::decryptDataDB($envelope, self::KEY_K, Security::dbContext('t', 'c', 1));
    }

    public static function malformedEnvelopeProvider(): array
    {
        return [
            'v1 envelope (no key id)'   => ['v1:' . base64_encode(str_repeat('a', 40)), 'Unsupported envelope version'],
            'no key id'                 => ['v2:' . base64_encode(str_repeat('a', 40)), 'invalid key id'],
            'uppercase key id'          => ['v2:' . strtoupper(self::KEY_K_ID) . ':AAAA', 'invalid key id'],
            'short key id'              => ['v2:0735d1be:AAAA', 'invalid key id'],
            'no separator at all'       => [base64_encode(str_repeat('a', 40)), 'missing version prefix'],
        ];
    }

    /** The rotation migration: re-encrypt what is still under the old key, until nothing is. */
    public function testReencryptDataDbMigratesAValueToTheCurrentKey(): void
    {
        $aad = Security::dbContext('t', 'c', 1);
        $old = Security::encryptDataDB('migrate me', self::KEY_K, $aad);
        $ring = self::rotated();

        $this->assertFalse(str_starts_with($old, 'v2:' . $ring->currentId() . ':'), 'precondition: the value is under the old key');
        $migrated = Security::reencryptDataDB($old, $ring, $aad);

        $this->assertStringStartsWith('v2:' . $ring->currentId() . ':', $migrated);
        $this->assertSame('migrate me', Security::decryptDataDB($migrated, self::KEY_Z, $aad), 'the old key is no longer needed');
    }

    public function testReencryptDataDbPassesNullThroughAndRejectsABlankValue(): void
    {
        $this->assertNull(Security::reencryptDataDB(null, self::rotated(), Security::dbContext('t', 'c', 1)));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Empty value');
        Security::reencryptDataDB('', self::rotated(), Security::dbContext('t', 'c', 1));
    }

    public function testSqlWrappersAcceptAKeyring(): void
    {
        $aad = Security::dbContext('t', 'c', 1);
        $old = SQL::encryptDataDB('via SQL', self::KEY_K, $aad);

        $this->assertSame('via SQL', SQL::decryptDataDB($old, self::rotated(), $aad));
        $this->assertStringStartsWith('v2:' . Security::keyId(self::KEY_Z) . ':', SQL::encryptDataDB('x', self::rotated(), $aad));
    }

    // ---------------------------------------------------------------------------------------
    // Local envelope
    // ---------------------------------------------------------------------------------------

    public function testLocalValuesRotateLikeDbValues(): void
    {
        $old = Security::encryptLocal('local before', self::KEY_K);
        $new = Security::encryptLocal('local after', self::rotated());

        $this->assertStringStartsWith('l1:' . self::KEY_K_ID . ':', $old);
        $this->assertStringStartsWith('l1:' . Security::keyId(self::KEY_Z) . ':', $new);
        $this->assertSame('local before', Security::decryptLocal($old, self::rotated()));
        $this->assertSame('local after', Security::decryptLocal($new, self::rotated()));
    }

    /** The HMAC covers the prefix: re-pointing the key id is a MAC failure, not a silent key switch. */
    public function testRewritingTheKeyIdOfALocalEnvelopeIsDetected(): void
    {
        [, , $payload] = explode(':', Security::encryptLocal('x', self::KEY_K), 3);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('MAC does not match');
        Security::decryptLocal('l1:' . Security::keyId(self::KEY_Z) . ':' . $payload, self::rotated());
    }

    public function testALocalValueUnderAMissingKeyNamesItsKeyId(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('No key with id ' . self::KEY_K_ID);
        Security::decryptLocal(Security::encryptLocal('x', self::KEY_K), self::KEY_Z);
    }

    public function testApplySecurityFunctionArrayAcceptsAKeyringAndKeepsNull(): void
    {
        $encrypted = Security::applySecurityFunctionArray(['a' => 'x', 'b' => null, 'c' => ['d' => '']], self::KEY_K, '', 'encryptLocal');

        $this->assertNull($encrypted['b']);
        $this->assertSame(
            ['a' => 'x', 'b' => null, 'c' => ['d' => '']],
            Security::applySecurityFunctionArray($encrypted, self::rotated(), '', 'decryptLocal')
        );
    }

    // ---------------------------------------------------------------------------------------
    // Cross-platform values and files: no key id, each key is tried
    // ---------------------------------------------------------------------------------------

    public function testCrossPlatformValuesDecryptUnderAPreviousKeyOfTheKeyring(): void
    {
        $old = Security::encryptCrossPlatform('cross before', self::KEY_K);

        $this->assertSame('cross before', Security::decryptCrossPlatform($old, self::rotated()));
        $this->assertSame('cross after', Security::decryptCrossPlatform(Security::encryptCrossPlatform('cross after', self::rotated()), self::KEY_Z));
    }

    public function testACrossPlatformValueUnderNoKeyOfTheKeyringIsRejected(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('authentication tag mismatch');
        Security::decryptCrossPlatform(Security::encryptCrossPlatform('x', self::KEY_K), new Keyring(self::KEY_Z, self::KEY_Q));
    }

    #[DataProvider('fileContentProvider')]
    public function testFilesDecryptUnderAPreviousKeyOfTheKeyring(string $content): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/plain", $content);
        Security::setFileEncryptBlocksBytes(4);
        try {
            Security::encryptFile("{$dir}/plain", self::KEY_K, "{$dir}/old.enc");
            Security::encryptFile("{$dir}/plain", self::rotated(), "{$dir}/new.enc");
        } finally {
            Security::setFileEncryptBlocksBytes(null);
        }

        Security::decryptFile("{$dir}/old.enc", self::rotated(), "{$dir}/old.dec");
        Security::decryptFile("{$dir}/new.enc", self::KEY_Z, "{$dir}/new.dec");

        $this->assertSame($content, file_get_contents("{$dir}/old.dec"));
        $this->assertSame($content, file_get_contents("{$dir}/new.dec"));
    }

    public static function fileContentProvider(): array
    {
        return [
            'several blocks' => ['content spanning several 4-byte blocks'],
            'empty file'     => [''],
        ];
    }

    public function testAFileUnderNoKeyOfTheKeyringIsRejectedAndLeavesNothing(): void
    {
        $dir = $this->tempDir();
        file_put_contents("{$dir}/plain", 'secret file');
        Security::encryptFile("{$dir}/plain", self::KEY_K, "{$dir}/file.enc");

        try {
            Security::decryptFile("{$dir}/file.enc", new Keyring(self::KEY_Z, self::KEY_Q), "{$dir}/file.dec");
            $this->fail('A file under a key the keyring does not hold must be rejected.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('No key with id ' . self::KEY_K_ID, $e->getMessage());
        }

        $this->assertSame(['file.enc', 'plain'], array_values(array_diff(scandir($dir), ['.', '..'])));
    }

    // ---------------------------------------------------------------------------------------
    // Blind indexes
    // ---------------------------------------------------------------------------------------

    public function testGenerateSearchHashUsesTheCurrentKey(): void
    {
        $this->assertSame(Security::generateSearchHash('a@b.com', self::KEY_Z), Security::generateSearchHash('a@b.com', self::rotated()));
    }

    /** During a rotation, WHERE hash IN (...) must find rows hashed under either key. */
    public function testGenerateSearchHashesReturnsOneHashPerKeyCurrentFirst(): void
    {
        $hashes = Security::generateSearchHashes('a@b.com', self::rotated());

        $this->assertSame([Security::keyId(self::KEY_Z), self::KEY_K_ID], array_keys($hashes));
        $this->assertSame(Security::generateSearchHash('a@b.com', self::KEY_Z), $hashes[Security::keyId(self::KEY_Z)]);
        $this->assertSame(Security::generateSearchHash('a@b.com', self::KEY_K), $hashes[self::KEY_K_ID]);
        $this->assertSame([], Security::generateSearchHashes(null, self::rotated()));
        $this->assertSame([], Security::generateSearchHashes('', self::rotated()));
    }
}
