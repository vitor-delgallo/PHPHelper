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

    /**
     * Blind indexes written by this version for KEY_K: [value, salt, hash]. A blind index is a
     * persisted format too (it sits in an indexed column): a change to its derivation would still
     * match itself while every stored index stopped matching — and a UNIQUE constraint on it
     * stopped catching duplicates.
     */
    private const SEARCH_HASH_VECTORS = [
        ['a@b.com', '', '251da3efcc5812e2043cdeb87f557a1f466d5f282af62e54564aebf943ecdafc'],
        ['a@b.com', 'tenant-1', '8e0365080d46fa91ba29117a189bfb33003d08ec63a4e1756c8fbf7fddbe1919'],
        [1, '', '05da9182eb9a68144a31fbaffcb3c22146c67a1040624efdf07c4370e400a600'],
        ['Ação ü', 's', '303896f44c72b244279f60e5e71287588f43b290a380abd2ec6a7122eebf72ea'],
    ];

    /** A key whose id is made of decimal digits only (about one key in 2.000 is): PHP int-ifies it as an array key. */
    private const DIGIT_ID_KEY_B64 = 'CnZENWy1Xi/6+Yr5aNJtZWnVn85NSaoPsAyFIM734t4=';
    private const DIGIT_ID = '6629894354780748';

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

    /** "Every method that takes $key accepts a Keyring" includes keyId(): the id of its current key. */
    public function testKeyIdAcceptsAKeyringAndReturnsTheIdOfItsCurrentKey(): void
    {
        $this->assertSame(Security::keyId(self::KEY_Z), Security::keyId(self::rotated()));
        $this->assertSame(self::rotated()->currentId(), Security::keyId(self::rotated()));
    }

    /**
     * An all-digit key id is a valid id. PHP turns it into an int when it is an ARRAY KEY, so
     * ids() and currentId() normalize to strings (a strict in_array() of the current id in ids()
     * used to be false for such a key); the keys of all() and generateSearchHashes() stay ints, as
     * documented, and compare as (string). The derivation itself must not change: it is a format.
     */
    public function testAnAllDigitKeyIdIsAStringInIdsAndCurrentIdAndRoundTrips(): void
    {
        $key = Security::keyFromBase64(self::DIGIT_ID_KEY_B64);
        $this->assertSame(self::DIGIT_ID, Security::keyId($key), 'premise: an all-digit id');

        $ring = new Keyring($key, self::KEY_K);
        $this->assertSame(self::DIGIT_ID, $ring->currentId());
        $this->assertSame([self::DIGIT_ID, self::KEY_K_ID], $ring->ids());
        $this->assertTrue(in_array($ring->currentId(), $ring->ids(), true));
        $this->assertSame($key, $ring->get(self::DIGIT_ID));
        $this->assertSame([self::DIGIT_ID, self::KEY_K_ID], array_map('strval', array_keys($ring->all())));
        $this->assertSame([self::DIGIT_ID, self::KEY_K_ID], array_map('strval', array_keys(Security::generateSearchHashes('a@b.com', $ring))));

        $aad = Security::dbContext('t', 'c', 1);
        $envelope = Security::encryptDataDB('x', $ring, $aad);
        $this->assertStringStartsWith('v2:' . self::DIGIT_ID . ':', $envelope);
        $this->assertSame('x', Security::decryptDataDB($envelope, $ring, $aad));
        $this->assertSame('y', Security::decryptLocal(Security::encryptLocal('y', $ring), new Keyring(self::KEY_K, $key)));
    }

    // ---------------------------------------------------------------------------------------
    // Salts
    // ---------------------------------------------------------------------------------------

    /**
     * HKDF-Extract is HMAC keyed with the salt; HMAC NUL-pads a short key and hashes a long one,
     * so "s" and "s\0" — and S and SHA-256(S) past 64 bytes — would derive the SAME key. Every
     * derivation refuses such salts, so a salt names exactly one key; 64 bytes is the maximum.
     */
    #[DataProvider('invalidSaltProvider')]
    public function testASaltWithANulByteOrOver64BytesIsRefusedByEveryDerivation(string $salt): void
    {
        $aad = Security::dbContext('t', 'c', 1);
        $calls = [
            'encryptDataDB'        => fn () => Security::encryptDataDB('x', self::KEY_K, $aad, $salt),
            'decryptDataDB'        => fn () => Security::decryptDataDB(self::DB_VECTOR, self::KEY_K, $aad, $salt),
            'encryptLocal'         => fn () => Security::encryptLocal('x', self::KEY_K, $salt),
            'decryptLocal'         => fn () => Security::decryptLocal(self::LOCAL_VECTOR, self::KEY_K, $salt),
            'encryptCrossPlatform' => fn () => Security::encryptCrossPlatform('x', self::KEY_K, $salt),
            'generateSearchHash'   => fn () => Security::generateSearchHash('x', self::KEY_K, $salt),
            'generateSearchHashes' => fn () => Security::generateSearchHashes('x', self::rotated(), $salt),
        ];
        foreach ($calls as $method => $call) {
            try {
                $call();
                $this->fail("{$method} must refuse the salt " . bin2hex($salt));
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('Invalid salt', $e->getMessage(), $method);
            }
        }
    }

    public static function invalidSaltProvider(): array
    {
        return [
            'trailing NUL'   => ["salt\0"],
            'only a NUL'     => ["\0"],
            'NUL inside'     => ["sa\0lt"],
            '65 bytes'       => [str_repeat('s', 65)],
        ];
    }

    public function testA64ByteSaltIsTheLongestAccepted(): void
    {
        $salt = str_repeat('s', 64);
        $aad = Security::dbContext('t', 'c', 1);

        $this->assertSame('x', Security::decryptDataDB(Security::encryptDataDB('x', self::KEY_K, $aad, $salt), self::KEY_K, $aad, $salt));
        $this->assertSame('x', Security::decryptLocal(Security::encryptLocal('x', self::KEY_K, $salt), self::KEY_K, $salt));
        $this->assertSame(64, strlen(Security::generateSearchHash('x', self::KEY_K, $salt)));
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

        // The attribute is per FRAME: an exception thrown inside a private helper (here the
        // refusal of an array, in scalarToString) carried the plaintext in that helper's frame
        // while the public method's frame showed SensitiveParameterValue.
        try {
            Security::encryptLocal(['cpf' => $plaintext, 'senha' => 'hunter2-SECRET'], self::KEY_K, 'visible-salt');
            $this->fail('An array must be refused.');
        } catch (\InvalidArgumentException $e) {
            $trace = $e->getTraceAsString() . print_r($e->getTrace(), true);

            $this->assertStringContainsString('visible-salt', $trace, 'control');
            $this->assertStringNotContainsString('hunter2-SECRET', $trace);
            $this->assertStringNotContainsString(substr($plaintext, 0, 10), $trace);
            $this->assertStringNotContainsString(substr(self::KEY_K, 0, 10), $trace);
        }
    }

    /**
     * Every parameter that carries a key or a plaintext — in the public methods AND in the private
     * helpers they pass it to — is marked #[\SensitiveParameter]. A helper without it is the frame
     * where a disk-full or a type refusal is thrown, with the secret in its arguments.
     */
    #[DataProvider('sensitiveParameterProvider')]
    public function testEveryKeyAndPlaintextParameterIsMarkedSensitive(string $class, string $method, array $parameters): void
    {
        $reflection = new \ReflectionMethod($class, $method);
        $found = [];
        foreach ($reflection->getParameters() as $parameter) {
            $found[$parameter->getName()] = $parameter->getAttributes(\SensitiveParameter::class) !== [];
        }

        foreach ($parameters as $name) {
            $this->assertArrayHasKey($name, $found, "{$class}::{$method}() has no parameter \${$name}");
            $this->assertTrue($found[$name], "{$class}::{$method}(\${$name}) must be #[\\SensitiveParameter]");
        }
    }

    public static function sensitiveParameterProvider(): array
    {
        $cases = [
            [Security::class, 'encryptDataDB', ['str', 'key']],
            [Security::class, 'decryptDataDB', ['key']],
            [Security::class, 'reencryptDataDB', ['key']],
            [Security::class, 'encryptLocal', ['str', 'key']],
            [Security::class, 'decryptLocal', ['key']],
            [Security::class, 'encryptCrossPlatform', ['var', 'key']],
            [Security::class, 'decryptCrossPlatform', ['key']],
            [Security::class, 'generateSearchHash', ['str', 'key']],
            [Security::class, 'generateSearchHashes', ['str', 'key']],
            [Security::class, 'encryptFile', ['key']],
            [Security::class, 'decryptFile', ['key']],
            [Security::class, 'applySecurityFunctionArray', ['item', 'key']],
            [Security::class, 'keyId', ['key']],
            [Security::class, 'keyFromBase64', ['encoded']],
            [Security::class, 'encryptPassword', ['password']],
            [Security::class, 'verifyPassword', ['password']],
            [Security::class, 'scalarToString', ['value']],
            [Security::class, 'writeAll', ['data']],
            [Security::class, 'writeLengthEncodedBlock', ['raw']],
            [Security::class, 'writeFileV3Block', ['key', 'plaintext']],
            [Security::class, 'openEnvelope', ['keys']],
            [Security::class, 'fileV3BodyDecryptor', ['keys']],
            [Security::class, 'deriveKey', ['key']],
            [Security::class, 'assertKeyLength', ['key']],
            [Security::class, 'keyring', ['key']],
            [Security::class, 'searchHashUnder', ['str', 'key']],
            [Security::class, 'localKeys', ['masterKey']],
            [Security::class, 'aesBridgeGcmDecrypt', ['passphrases']],
            [Keyring::class, '__construct', ['current', 'previous']],
            [Keyring::class, 'fromBase64', ['current', 'previous']],
        ];

        $provider = [];
        foreach ($cases as [$class, $method, $parameters]) {
            $provider[(new \ReflectionClass($class))->getShortName() . "::{$method}"] = [$class, $method, $parameters];
        }

        return $provider;
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
    public function testDbContextRejectsInvalidParts(string $table, string $column, string|int|float|bool $rowId, ?int $version): void
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
            // A float used to be COERCED to int (1.5 -> row "1", with only a deprecation), so two
            // rows shared one context and a ciphertext moved between them still decrypted.
            'float row id'     => ['t', 'c', 1.5, null],
            'whole float id'   => ['t', 'c', 2.0, null],
            'bool row id'      => ['t', 'c', true, null],
        ];
    }

    /** The row id is part of the context: a float must not be silently rounded onto another row. */
    public function testDbContextRefusesAFloatRowIdInsteadOfTruncatingIt(): void
    {
        try {
            Security::dbContext('t', 'c', 1.5);
            $this->fail('A float row id must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('float given', $e->getMessage());
        }
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

    /**
     * The salt goes through reencryptDataDB on BOTH sides. Dropping it on the encrypt side would
     * migrate every salted row to salt "" — and every later read with the tenant's salt would
     * throw, with the original envelope already overwritten.
     */
    public function testReencryptDataDbKeepsTheSaltOnBothSides(): void
    {
        $old = Security::encryptDataDB('salted', self::KEY_K, Security::dbContext('t', 'c', 1), 'tenant-1');

        $migrated = Security::reencryptDataDB($old, self::rotated(), Security::dbContext('t', 'c', 1), Security::dbContext('t', 'c', 1, 2), 'tenant-1');

        $this->assertStringStartsWith('v2:' . Security::keyId(self::KEY_Z) . ':', $migrated);
        $this->assertSame('salted', Security::decryptDataDB($migrated, self::KEY_Z, Security::dbContext('t', 'c', 1, 2), 'tenant-1'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('authentication tag mismatch');
        Security::decryptDataDB($migrated, self::KEY_Z, Security::dbContext('t', 'c', 1, 2), '');
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

    /**
     * Pointing an envelope at another key of the ring fails. (That the failure comes from the key
     * switch as much as from the AAD — the key id IS in the AAD, "v2|{id}|{aad}" — is what
     * testTheDbEnvelopeFollowsItsDocumentedFormat and the fixed vector pin.)
     */
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
            'v1 envelope (no key id)'   => ['v1:' . base64_encode(str_repeat('a', 40)), "Unsupported envelope version 'v1'"],
            'no key id'                 => ['v2:' . base64_encode(str_repeat('a', 40)), 'invalid key id'],
            'uppercase key id'          => ['v2:' . strtoupper(self::KEY_K_ID) . ':AAAA', 'invalid key id'],
            'short key id'              => ['v2:0735d1be:AAAA', 'invalid key id'],
            'no separator at all'       => [base64_encode(str_repeat('a', 40)), 'missing version prefix'],
            'not a version tag'         => ['mysecret:rest', 'Unsupported envelope version (not a version tag)'],
        ];
    }

    /**
     * A value that was never encrypted ("secret:1" — a column decrypted by mistake during a
     * migration) must not have its first bytes copied into the exception message, hence into logs.
     * Only a version-shaped prefix ("v1") is echoed.
     */
    public function testAnUnencryptedValueIsNotEchoedInTheEnvelopeError(): void
    {
        foreach (['decryptDataDB' => fn ($v) => Security::decryptDataDB($v, self::KEY_K, Security::dbContext('t', 'c', 1)),
                  'decryptLocal' => fn ($v) => Security::decryptLocal($v, self::KEY_K)] as $method => $call) {
            try {
                $call('mysecret:rest');
                $this->fail("{$method} must refuse a non-envelope.");
            } catch (\Exception $e) {
                $this->assertStringNotContainsString('mysecret', $e->getMessage(), $method);
                $this->assertStringContainsString('(not a version tag)', $e->getMessage(), $method);
            }
        }
    }

    /**
     * PHP's strict base64_decode() still skips whitespace, accepts missing padding and ignores
     * non-zero bits under the padding, so several strings decoded to the same ciphertext and
     * decrypted. The envelope is CANONICAL now: an attacker with write access cannot rewrite a
     * value into an equivalent spelling that an equality check on the stored text would miss.
     */
    #[DataProvider('nonCanonicalEnvelopeProvider')]
    public function testANonCanonicalBase64EnvelopeIsRejected(string $method, string $variant): void
    {
        $aad = Security::dbContext('t', 'c', 1);
        $envelope = ($method === 'db' ? Security::encryptDataDB('x', self::KEY_K, $aad) : Security::encryptLocal('x', self::KEY_K));
        [$version, $keyId, $payload] = explode(':', $envelope, 3);

        $mutated = self::nonCanonicalVariant($payload, $variant);
        if ($mutated === null) {
            $this->markTestSkipped("The payload has no padding, so '{$variant}' does not apply.");
        }
        $this->assertSame(base64_decode($payload, true), base64_decode($mutated, true), 'premise: PHP decodes the variant to the same bytes');
        $this->assertNotSame($payload, $mutated);

        try {
            $method === 'db'
                ? Security::decryptDataDB("{$version}:{$keyId}:{$mutated}", self::KEY_K, $aad)
                : Security::decryptLocal("{$version}:{$keyId}:{$mutated}", self::KEY_K);
            $this->fail("A non-canonical envelope must be rejected ({$variant}).");
        } catch (\Exception $e) {
            $this->assertStringContainsStringIgnoringCase('invalid base64', $e->getMessage());
        }
    }

    public static function nonCanonicalEnvelopeProvider(): array
    {
        $cases = [];
        foreach (['db', 'local'] as $method) {
            foreach (['newline inside', 'trailing CRLF', 'padding stripped', 'non-zero padding bits'] as $variant) {
                $cases["{$method}: {$variant}"] = [$method, $variant];
            }
        }

        return $cases;
    }

    /** A base64 string that PHP's strict decoder reads as the same bytes as $payload, or null when $variant cannot be built. */
    private static function nonCanonicalVariant(string $payload, string $variant): ?string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/';
        $padding = strlen($payload) - strlen(rtrim($payload, '='));

        return match ($variant) {
            'newline inside' => substr_replace($payload, "\n", 10, 0),
            'trailing CRLF' => $payload . "\r\n",
            'padding stripped' => $padding === 0 ? null : rtrim($payload, '='),
            'non-zero padding bits' => (function () use ($payload, $padding, $alphabet): ?string {
                if ($padding === 0) {
                    return null;
                }
                $position = strlen($payload) - $padding - 1;
                $index = strpos($alphabet, $payload[$position]);

                return substr_replace($payload, $alphabet[$index | 1], $position, 1);
            })(),
        };
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

    /**
     * Re-pointing the key id is a MAC failure, not a silent key switch. (The MAC would fail here
     * from the key switch alone; that the prefix is COVERED by the MAC is pinned by
     * testTheLocalEnvelopeFollowsItsDocumentedFormat.)
     */
    public function testRewritingTheKeyIdOfALocalEnvelopeIsDetected(): void
    {
        [, , $payload] = explode(':', Security::encryptLocal('x', self::KEY_K), 3);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('MAC does not match');
        Security::decryptLocal('l1:' . Security::keyId(self::KEY_Z) . ':' . $payload, self::rotated());
    }

    /**
     * Independent check of the documented local format: "l1:{key id}:" + base64(mac || iv || ct),
     * HMAC-SHA256 over prefix || iv || ct under HKDF(master, salt, "local") -> "local-authentication",
     * AES-256-CTR under -> "local-encryption". Pinned with nothing but hash_hkdf/hash_hmac/openssl,
     * so the prefix binding cannot quietly disappear (the fixed vector alone would still pass a
     * regenerated vector).
     */
    public function testTheLocalEnvelopeFollowsItsDocumentedFormat(): void
    {
        $envelope = Security::encryptLocal('documented', self::KEY_K, 'salt-1');
        [$version, $keyId, $payload] = explode(':', $envelope, 3);
        $this->assertSame(['l1', self::KEY_K_ID], [$version, $keyId]);

        $raw = base64_decode($payload, true);
        $key = hash_hkdf('sha256', self::KEY_K, 32, 'local', 'salt-1');
        $encryptionKey = hash_hkdf('sha256', $key, 32, 'local-encryption');
        $authenticationKey = hash_hkdf('sha256', $key, 32, 'local-authentication');
        $mac = substr($raw, 0, 32);
        $iv = substr($raw, 32, 16);
        $ciphertext = substr($raw, 48);

        $this->assertTrue(hash_equals(hash_hmac('sha256', "{$version}:{$keyId}:" . $iv . $ciphertext, $authenticationKey, true), $mac), 'HMAC over prefix || iv || ct');
        $this->assertFalse(hash_equals(hash_hmac('sha256', $iv . $ciphertext, $authenticationKey, true), $mac), 'the prefix IS covered');
        $this->assertSame('documented', openssl_decrypt($ciphertext, 'aes-256-ctr', $encryptionKey, OPENSSL_RAW_DATA, $iv));
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

    /**
     * The blind index is a persisted format: pinned by fixed vectors AND by its documented
     * derivation, HMAC-SHA256(value, HKDF-SHA256(master, salt, "search-hash")). Every other test of
     * it compared generateSearchHash() with itself, so a changed label or salt placement passed.
     */
    public function testTheBlindIndexMatchesItsFixedVectorsAndDocumentedDerivation(): void
    {
        foreach (self::SEARCH_HASH_VECTORS as [$value, $salt, $expected]) {
            $label = json_encode([$value, $salt]);
            $this->assertSame($expected, Security::generateSearchHash($value, self::KEY_K, $salt), "vector {$label}");
            $this->assertSame(
                hash_hmac('sha256', (string) $value, hash_hkdf('sha256', self::KEY_K, 32, 'search-hash', $salt)),
                Security::generateSearchHash($value, self::KEY_K, $salt),
                "derivation {$label}"
            );
        }
        $this->assertSame(self::SEARCH_HASH_VECTORS[0][2], Security::generateSearchHashes('a@b.com', self::rotated())[self::KEY_K_ID]);
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
