# Security notes — `VD\PHPHelper\Security` and related helpers

This document records the cryptographic contract of the library and the security-relevant behavior
of the other helpers. Tests are the PHPUnit suite (`composer test`); the crypto tests live in
`tests/SecurityTest.php`, `tests/SecurityKeysTest.php` and `tests/SecurityFileEncryptionTest.php`.

> **Breaking changes** were made deliberately — the library is not consumed by any production
> project, so signatures, formats and behavior changed wherever that closed a real weakness.

## Keys

Everything below is only as strong as the master key and where it is kept.

**Make keys with `Security::generateKey()`** (base64 of 32 random bytes) and load them with
`Security::keyFromBase64()` or `Keyring::fromBase64()`, which accept exactly 32 bytes. Every method
also accepts any string of at least 32 bytes, but a passphrase typed by a person is weak: HKDF is
fast and adds no strength, so it can be brute-forced offline from a single ciphertext.

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # same as Security::generateKey()
```

```php
$keys = Keyring::fromBase64(getenv('APP_CRYPTO_KEY'), getenv('APP_CRYPTO_KEY_OLD'));
```

**Where to keep it**
- In an environment variable or a file outside the web root, readable only by the application's
  user. Never in the repository, never in the database or its backups — encryption at rest
  protects a leaked dump or backup only while the key is not in it.
- With an **offline copy** (a password manager or vault). A lost key means the data is lost: there
  is no recovery.
- Encryption at rest does **not** protect against an attacker who controls the application server:
  the key is there. Keep the database user of the application on the least privileges it needs,
  which also limits what an SQL injection can write (see *Replay*).

**Key ids.** `Security::keyId($key)` is a 16-hex fingerprint of a key (HKDF, info `key-id`); it
reveals nothing about the key. The DB and local envelopes carry the id of the key that wrote them.

**Hygiene.** Every key and plaintext parameter is marked `#[\SensitiveParameter]`, so it is redacted
from stack traces. A `Keyring` shows only its key ids in `var_dump()`/`print_r()` and cannot be
serialized; do not `var_export()` it (PHP offers no hook to hide private properties there).

### Key rotation — `Keyring`

A `Keyring` holds one **current** key, used for every encryption, and any number of **previous**
keys, used only to decrypt what they wrote. Every method that takes `$key` accepts a plain string
(a keyring of one) or a `Keyring`.

1. Generate a new key and deploy `Keyring::fromBase64(NEW, OLD)`. From then on everything new is
   written under NEW and everything old still reads.
2. Migrate what is still under OLD:
   - **DB values** — the envelope says which key wrote it:
     ```php
     $rows = $db->fetchAll("SELECT id, cpf_enc FROM users WHERE cpf_enc NOT LIKE ?", ['v2:' . $keys->currentId() . ':%']);
     foreach ($rows as $row) {
         $aad = Security::dbContext('users', 'cpf', $row['id']);
         $db->execute('UPDATE users SET cpf_enc = ? WHERE id = ?', [Security::reencryptDataDB($row['cpf_enc'], $keys, $aad), $row['id']]);
     }
     ```
   - **Blind indexes** — recompute each row's hash with the current key (decrypt the value, then
     `generateSearchHash`). While that runs, search with every key:
     `WHERE email_hash IN (...Security::generateSearchHashes($email, $keys))`.
   - **Files** — a v3 file names its key in its header: `Security::fileKeyId($path)` reads it
     without decrypting. Re-encrypt every file whose id is not `$keys->currentId()`
     (`decryptFile` with the keyring, then `encryptFile`).
   - **Cross-platform values** carry no key id (the format is aes-bridge's): `decryptCrossPlatform`
     tries each key of the keyring. Re-encrypt them and keep track of which ones are done.
   - **Local values** (`encryptLocal`) are usually short-lived tokens: let them expire, or
     re-encrypt them like DB values.
3. When nothing is left under OLD, remove it from the keyring and destroy it.

## Cryptographic contract

- **Keys are >= 32 bytes.** HKDF cannot add entropy, so the AES-256 paths require a 32-byte master
  key. Derivation is HKDF-SHA256 with **per-domain** `info` labels (`db-cell`, `file-v3`, `local`,
  `search-hash`, …) so unrelated subsystems never share a key.
- **Randomness fails closed.** Nonces come from `random_bytes()` with no weak fallback; if no strong
  RNG is available the call throws instead of encrypting with a predictable nonce.
- **Decryption of a tampered/relocated value THROWS.** It never returns a value a caller could
  mistake for success. This includes `decryptCrossPlatform` (aes-bridge itself returns `""` on an
  authentication failure; the library parses the format itself and throws instead).
- **`null` is "no value"; `""` is a value.** `null` passes through every encrypt/decrypt method
  unchanged. An empty string is **encrypted like any other value**, so no method ever produces
  `""` — and decrypting `""` **throws** (`Empty value: …`). A column someone blanked out is
  therefore detected instead of reading back as a legitimately empty value.
- **Non-scalar input is refused.** Arrays and plain objects throw `InvalidArgumentException` instead
  of being encrypted (or blind-indexed) as the literal text `"Array"`.

### Field encryption — `encryptDataDB` / `decryptDataDB`

AES-256-GCM with a **required AAD** binding each value to its location, and a self-describing
envelope `"v2:{key id}:" + base64(iv || tag || ciphertext)`. The version, the key id and the AAD are
all authenticated (GCM AAD `v2|{key id}|{aad}`). Envelopes of the earlier `v1` format are no longer
read.

Build the AAD with **`Security::dbContext($table, $column, $rowId, $version = null)`**: one
canonical, unambiguous form (hand-built strings like `"a.b:c"` collide when a name contains a
separator, and two code paths formatting the same cell differently cannot decrypt each other's
values).

```php
$aad = Security::dbContext('users', 'cpf', $userId);
$ct  = Security::encryptDataDB($cpf, $keys, $aad, $userSalt);
$pt  = Security::decryptDataDB($ct, $keys, $aad, $userSalt);
```

Without the AAD, a valid ciphertext could be copied from one row/column to another and still
decrypt. An empty AAD is rejected. Use the **same** salt on both sides. The AAD must not include
anything that changes over the row's life other than the version (not an e-mail, not a status), or
the value stops decrypting when it changes.

#### Replay — and the version in the AAD

GCM proves that a value is **genuine and belongs to this cell**. It cannot prove that it is the
**latest** value of that cell. An attacker who can write to the database — through an SQL
injection, a leaked database account, a restored backup — but does not have the key can put back
an **older genuine value** of the same cell, and it decrypts:

1. Monday: the attacker's wallet holds R$ 1.000. Through the injection they read their own row and
   save `balance_enc` (`v2:…:Ab3…`).
2. Tuesday: they spend it all; the application writes a new `balance_enc` (R$ 0).
3. Wednesday: `UPDATE wallets SET balance_enc = 'v2:…:Ab3…' WHERE id = 42`.
4. The application decrypts it: same key, same cell, valid tag → `"1000.00"`. Repeat at will.

What the attacker still cannot do: copy another user's balance into their row (another row id is
another AAD → exception), or invent a value (no key). Replay is limited to values that already
existed **in that cell** — and without encryption they could simply write any number. It only
matters for fields where an old value is worth something: balances and limits, roles and
permissions, a revoked 2FA secret, a rotated token.

**Level 1 — a version in the AAD.** Keep a version column next to the protected value, increment it
on every write, and bind it with `dbContext(..., $version)`. The version is an ordinary column of
the same row, so you read it together with the value:

```php
// Read
$row = $db->fetch('SELECT id, balance_enc, balance_version FROM wallets WHERE id = ?', [$id]);
$balance = Security::decryptDataDB(
    $row['balance_enc'], $keys,
    Security::dbContext('wallets', 'balance', $row['id'], $row['balance_version'])
);

// Write: the next version, and only if nobody else wrote in between (optimistic locking)
$next = $row['balance_version'] + 1;
$enc  = Security::encryptDataDB($newBalance, $keys, Security::dbContext('wallets', 'balance', $row['id'], $next));
$db->execute(
    'UPDATE wallets SET balance_enc = ?, balance_version = ? WHERE id = ? AND balance_version = ?',
    [$enc, $next, $row['id'], $row['balance_version']]
); // 0 rows affected: someone else wrote first — reload and retry

// Insert: start at version 1
```

Now step 3 above fails: the saved value was bound to version 7, the row is at version 8. Prefer one
version column **per protected value** (`balance_version`); if several encrypted columns share one
row version, every one of them must be re-encrypted (`Security::reencryptDataDB($value, $keys,
$oldAad, $newAad)`) whenever it moves.

**What level 1 does not stop:** an attacker who writes the old version number back as well restores
a consistent old row, and it decrypts. Both columns live in the database they control.

**Level 2 — the latest version outside the database.** Keep the latest version of each protected
value somewhere the database attacker cannot write (a store with separate credentials) and refuse
a row whose version is lower on read. This detects a full rollback, at the cost of keeping the two
in step.

**Level 3 — for money and permissions, do not store a value that changes.** Keep an append-only
ledger (one encrypted row per movement, AAD bound to the movement's own id) and compute the balance
from it; give the application's database user only `INSERT`/`SELECT` on that table. An injection can
then neither delete a debit nor forge a credit (no key), nor re-insert an old credit (its id already
exists, and under a new id its AAD no longer matches).

### Blind index — `generateSearchHash`

Keyed HMAC-SHA256 over an HKDF-derived subkey → 64 hex chars, deterministic. Use a **fixed** salt
per index domain (equal input must map to equal hash for lookup/uniqueness):

```php
$hash = Security::generateSearchHash($normalizedEmail, $keys); // current key; salt "" = stable
```

Being deterministic, it reveals which rows share a value — inherent to any blind index. Normalize
the input (case, spaces, masks) before hashing, or equal values will not match.

**A blind index is not authenticated.** Someone who can write to the database can copy the victim's
hash into their own row, and a lookup by the victim's e-mail then returns the attacker's row. Two
defenses, both on the application side:
- Put a **`UNIQUE`** constraint on the hash column of a unique field (e-mail, CPF): the copy then
  collides with the victim's row.
- After a lookup, **decrypt the value and compare** it with what was searched:
  ```php
  $row = $db->fetch('SELECT id, email_enc FROM users WHERE email_hash = ?', [Security::generateSearchHash($email, $keys)]);
  if ($row !== null && !hash_equals($email, Security::decryptDataDB($row['email_enc'], $keys, Security::dbContext('users', 'email', $row['id'])))) {
      throw new RuntimeException('Blind index does not match the stored value');
  }
  ```

### Authenticated files — `encryptFile` / `decryptFile`

Streaming AES-256-GCM in the **v3** format — the only file format: `encryptFile` writes it and
`decryptFile` reads nothing else. Files in the earlier v1/v2 formats (the removed
`encryptFileV1`/`encryptFileV2`) are refused with an "only file format v3 is supported" error;
decrypt them with the library version that wrote them and encrypt them again with this one.

```
header:      "aes-256-gcm", "v3", key id, salt, file id (16 random bytes, hex)
data block i: ciphertext_i || tag_i            ← no IV stored
end marker:   ciphertext("{block count}") || tag
file key  = HKDF-SHA256(master key, salt, info "file-v3|{file id}")   ← one key per file
nonce i   = i as a 12-byte big-endian integer (the end marker of an n-block file uses n)
AAD       = "{file id}|v3|D|{i}"   ("{file id}|v3|F|{n}" for the end marker)
```

- **No nonce can repeat, at any block size or volume.** Every file has its own key, and within a
  file the nonce is the block number. (The removed v2 format drew a random nonce per block under
  one key shared by every file with the same master key and salt, which capped the safe volume at
  about 2³² blocks per key — 4 GiB of data at 1-byte blocks.) Nothing per block is stored but the
  tag, so files are also smaller: at 1-byte blocks, about half the size v2 needed.
- **Truncation, reordering, duplication and cross-file splicing** are all rejected: the end marker
  authenticates the block count, and every block is bound to its position by its nonce and AAD,
  and to its file by the file key.
- **The header names the key.** With a `Keyring`, `decryptFile` goes straight to the key with that
  id; an id the keyring does not hold fails with an error naming it. Rewriting the id to another key
  of the keyring makes every block fail. `Security::fileKeyId($path)` reads the id without
  decrypting (see *Key rotation*).
- The header is not authenticated on its own, but every field of it selects the key (key id, salt,
  file id) or is checked for equality (cipher, version), so tampering with it fails every block.
  The container encoding (`{len}-{base64}` blocks) is parsed strictly, so non-canonical encodings
  (leading zeros, `+`, whitespace, bad padding) are rejected too. The salt is stored in clear (a
  salt is not a secret); the key never is.

Two process-global settings (reset with `null`):

| Setting | Default | Meaning |
|---|---|---|
| `setFileEncryptBlocksBytes()` | 3,200,000 | Plaintext bytes per encrypted block (min 1). Any size is safe for the cipher; it trades memory against file size and speed. |
| `setFileMaxEncodedBlockBytes()` | 268,435,456 (256 MiB) | Largest encoded block accepted, on **both** encrypt and decrypt (min 44 — the size of the file-id block every file contains). |

`encryptFile` refuses, before creating anything, a block size or salt whose encoded block would
exceed the limit — it never writes a file that `decryptFile` could not read back. A block is written
with its 16-byte tag, so the largest usable block size is `3*floor(limit/4) - 16`: 201,326,576 bytes
under the default limit. A file encrypted under a raised limit needs that limit on the decrypting
side as well; the error message names the size required.

Output is written to a hidden staging file next to the destination and renamed into place only on
success, so a failure (wrong key, tamper, full disk, crash) never destroys an existing destination
and never leaves unauthenticated plaintext behind. In-place operation (destination == source, by
any spelling, symlink or hard link) is refused.

What the format does not hide, and what to do about it:
- **Size and name.** The ciphertext reveals the plaintext size (to the block), and the file name is
  yours. Store encrypted files under random names (a UUID) and keep the real name encrypted in the
  database (`encryptDataDB` with `dbContext('files', 'name', $fileId)`).
- **Staging files.** A crash during decryption can leave a `.phphelper-*.part` file with partial
  plaintext next to the destination. It is created owner-only on POSIX, but inherits the folder's
  ACL on Windows. Decrypt into a directory outside the web root with restricted permissions, and
  remove stale `.phphelper-*.part` files periodically.

### Local strings — `encryptLocal` / `decryptLocal`

AES-256-CTR with encrypt-then-HMAC-SHA256: envelope `"l1:{key id}:" + base64(MAC || IV ||
ciphertext)`, the MAC covering the prefix too, verified with `hash_equals` before decrypting; a
wrong key or any modification throws. It is **not bound to a context**: a value encrypted under the
same key and salt decrypts wherever it is pasted. Use `encryptDataDB` with an AAD when a value must
not be movable.

### Cross-platform — `encryptCrossPlatform` / `decryptCrossPlatform`

aes-bridge GCM format. The passphrase handed to aes-bridge is **not** the master key but the raw
32 bytes of `HKDF-SHA256(master key, salt, info "derived-key")`; a peer must derive the same bytes.
See the method docblock for the exact recipe. The format carries no key id: with a `Keyring`,
`decryptCrossPlatform` tries each key (one PBKDF2 run per key tried).

### Passwords — `encryptPassword` / `verifyPassword`

Argon2id via `password_hash`/`password_verify`. Never use the encryption helpers for passwords.

## Security-relevant behavior of the other helpers

- **SQL** — `SQL::escapeString` returns a quoted literal: it doubles the quote (`''`) and the
  backslash, and writes NUL as `\0`. No value can leave its literal under MySQL (either `sql_mode`),
  standard-conforming PostgreSQL or SQLite; outside MySQL's default mode a backslash or NUL is stored
  doubled, so the value changes but nothing is injected. It is **not** safe on
  big5/cp932/gbk/gb18030/sjis connections. Prefer prepared statements.
- **XSS** — `Security::xssCleanRecursive` is a DOM-based **allowlist** sanitizer (elements,
  attributes and URL schemes). Array keys and private/protected object state are not walked;
  escape them on output.
- **Files** — `File::unzipFile` rejects zip-slip entries, refuses to write through links inside the
  destination and verifies each entry's CRC. `File::deleteFoldersRecursively`/`resetFolder` never
  follow links and refuse blank paths (a blank path used to resolve to the working directory).
  `File::deleteFiles` accepts only plain leaf names. `File::downloadFile` builds a safe
  `Content-Disposition` (quotes escaped, RFC 5987 `filename*`).
- **HTTP** — `HTTP::callWebService` allows only `http`/`https`, for the request and for redirects;
  CR/LF in the method or header values is rejected; TLS verification is on unless the caller turns
  it off. `HTTP::getClientIpAddresses` lists `REMOTE_ADDR` first; forwarded headers follow.
- **Headers/mail** — `URL::buildHttpHeaderArray` rejects CR/LF/NUL; `Validator::validateMail` and
  `Mailer` reject addresses carrying whitespace or control characters; Mailer debug output goes to
  `error_log`, never to stdout, with the SMTP password masked.
- **XML** — `Parser::xmlToArray` parses **content only** (files go through `xmlFileToArray`),
  blocks external entities with `LIBXML_NONET`, and restores the previous entity loader afterwards.
  `Parser::arrayToXml` serializes only public object state, validates the root name, and writes a
  key that is not a valid element name as `<item key="…">` instead of pasting it into markup.
- **Spreadsheets** — CSV/HTML cells are returned verbatim; formulas in them are never evaluated.
- **Randomness** — `Str::generateGuid` and `Str::generateUniqueKey` use `random_bytes()`;
  `File::renameUploadFile` uses `random_int()`.

## Caller responsibilities (not enforced by the library)

- **The key** — generation, storage, backup and rotation, as described under *Keys*.
- **Replay** — a version per protected value (level 1) is the application's to keep; levels 2 and 3
  are design decisions. See *Replay*.
- **Blind-index lookups** — verify the decrypted value after a lookup, and make unique hashes
  `UNIQUE`. See *Blind index*.
- **SSRF** — `HTTP::callWebService` has no private-IP/allowlist guard. Do not pass user-controlled
  URLs without an egress allowlist; never disable peer verification in production.
- **Forwarded client IPs** — only `REMOTE_ADDR` is trustworthy; trust `X-Forwarded-For` only behind
  a known proxy.
- **`filterValue(addSlashes: true)`** is not SQL escaping. `filterValue(escapeDB: true)` delegates to
  `SQL::escapeString` and has the same limits. Use prepared statements.
- **S3 uploads** — `S3Storage::upload` detects the content type from the file's bytes; pass an
  explicit `ContentType` for user uploads if the bucket is served from a trusted domain.
- **Seeds** — `System::makeSeed` produces a seed for `mt_rand`-style generators, not a secret.
