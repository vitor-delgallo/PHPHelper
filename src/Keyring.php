<?php

namespace VD\PHPHelper;

/**
 * The set of master keys Security may use: ONE current key, which every encrypt call uses, plus
 * any number of previous keys, which are only ever used to DECRYPT values written before a key
 * rotation.
 *
 * Every Security encryption, decryption and hashing method that takes a key accepts either a plain
 * string (a keyring of one) or a Keyring; Security::keyId() accepts one too and returns the id of
 * its current key. Each key is identified by its key id (Security::keyId()): a fingerprint derived
 * from the key itself, so there is nothing to name or number by hand. The DB and local envelopes
 * and the v3 file header carry the id of the key that wrote them, which is how decryption picks
 * the right key and how a migration finds the values still written under an old one.
 *
 * A key id is 16 hex characters; about one key in 2.000 gets an id made only of decimal digits,
 * which PHP turns into an INT when it is an array key. ids() and currentId() always return
 * strings; the KEYS of all() (and of Security::generateSearchHashes()) may be ints — compare
 * them as (string) $id, never with === on the raw key.
 *
 * ```php
 * $keys = Keyring::fromBase64(getenv('APP_KEY'), getenv('APP_KEY_OLD'));
 * $cipher = Security::encryptDataDB($value, $keys, $aad);   // always under APP_KEY
 * $value  = Security::decryptDataDB($cipher, $keys, $aad);  // APP_KEY or APP_KEY_OLD
 * ```
 *
 * var_dump() and print_r() show the key ids only (see __debugInfo()), stack traces show the keys
 * as redacted (#[\SensitiveParameter]), and a Keyring cannot be serialized, so the raw keys do not
 * end up in a session, a cache or a log by accident. var_export() still prints private properties
 * — PHP offers no hook for it — so never var_export() a keyring.
 */
final class Keyring {
    /**
     * Key id => raw key, the current key first. An all-digit id is an int key (PHP array
     * semantics); get() still finds it, since PHP applies the same conversion to the lookup.
     *
     * @var array<int|string, string>
     */
    private array $keys = [];

    /**
     * Id of the current key.
     *
     * @var string
     */
    private string $currentId;

    /**
     * @param string $current Raw key used for every encryption (>= 32 bytes, e.g. Security::keyFromBase64())
     * @param string ...$previous Raw keys only used to decrypt older values (>= 32 bytes each)
     *
     * @throws \InvalidArgumentException When a key is shorter than 32 bytes, or the same key is
     *                                   given twice (a duplicate is always a configuration mistake:
     *                                   usually the "old" key was not actually changed)
     */
    public function __construct(#[\SensitiveParameter] string $current, #[\SensitiveParameter] string ...$previous) {
        foreach ([$current, ...$previous] as $position => $key) {
            $id = Security::keyId($key);
            if (isset($this->keys[$id])) {
                throw new \InvalidArgumentException(
                    "Keyring: the key at position {$position} is the same key as an earlier one (key id {$id})."
                );
            }
            $this->keys[$id] = $key;
        }

        $this->currentId = (string) array_key_first($this->keys);
    }

    /**
     * Builds a keyring from base64-encoded keys, as Security::generateKey() emits them. Accepts
     * getenv()'s result directly: a previous key that is false, null or blank (an unset "old key"
     * variable) is skipped, and a missing current key is refused with a clear message.
     *
     * @param string|false|null $current Base64 of the current key (exactly 32 bytes once decoded)
     * @param string|false|null ...$previous Base64 of previous keys; false/null/blank are ignored
     *
     * @throws \InvalidArgumentException When the current key is missing, a key is not valid base64
     *                                   of exactly 32 bytes, or a key is repeated
     * @return self
     */
    public static function fromBase64(#[\SensitiveParameter] string|false|null $current, #[\SensitiveParameter] string|false|null ...$previous): self {
        if (!is_string($current) || trim($current) === '') {
            throw new \InvalidArgumentException('Keyring::fromBase64(): the current key is missing (is the environment variable set?).');
        }

        $decoded = [];
        foreach ($previous as $encoded) {
            if (is_string($encoded) && trim($encoded) !== '') {
                $decoded[] = Security::keyFromBase64($encoded);
            }
        }

        return new self(Security::keyFromBase64($current), ...$decoded);
    }

    /**
     * The raw key every encryption uses.
     *
     * @return string
     */
    public function currentKey(): string {
        return $this->keys[$this->currentId];
    }

    /**
     * Id of the current key. A DB value whose envelope does not start with "v2:{currentId}:" was
     * written under an older key and is due for Security::reencryptDataDB().
     *
     * @return string 16 lowercase hex characters
     */
    public function currentId(): string {
        return $this->currentId;
    }

    /**
     * The raw key with the given id, or null when this keyring does not hold it.
     *
     * @param string $id Key id, as returned by Security::keyId()
     *
     * @return string|null
     */
    public function get(string $id): ?string {
        return $this->keys[$id] ?? null;
    }

    /**
     * Every raw key, keyed by id, the current key first. For what must try (or use) every key:
     * decryptCrossPlatform(), whose aes-bridge format carries no key id, and
     * generateSearchHashes(), one blind index per key. DB and local envelopes and v3 files name
     * their key, so their decrypt methods use get() instead.
     *
     * @return array<int|string, string> An all-digit id is an int key: compare with (string) $id
     */
    public function all(): array {
        return $this->keys;
    }

    /**
     * Every key id, the current one first — always as strings, whatever PHP made of them as
     * array keys.
     *
     * @return string[]
     */
    public function ids(): array {
        return array_map('strval', array_keys($this->keys));
    }

    /**
     * Shows the key ids only: dumping a keyring must never print the keys.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array {
        return ['currentId' => $this->currentId, 'ids' => $this->ids()];
    }

    /**
     * @throws \LogicException Always: serializing would write the raw keys out.
     */
    public function __serialize(): array {
        throw new \LogicException('A Keyring holds raw keys and cannot be serialized.');
    }

    /**
     * @throws \LogicException Always.
     */
    public function __unserialize(array $data): void {
        throw new \LogicException('A Keyring cannot be unserialized.');
    }
}
