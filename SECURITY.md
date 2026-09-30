# Security notes — `VD\PHPHelper\Security` and related helpers

This document records the cryptographic contract of the library and the security-relevant behavior
of the other helpers. Tests are the PHPUnit suite (`composer test`); the crypto tests live in
`tests/SecurityTest.php` and `tests/SecurityFileEncryptionTest.php`.

> **Breaking changes** were made deliberately — the library is not consumed by any production
> project, so signatures and behavior changed wherever that closed a real weakness.

## Cryptographic contract

- **Keys are >= 32 bytes.** HKDF cannot add entropy, so the AES-256 paths require a 32-byte master
  key. Derivation is HKDF-SHA256 with **per-domain** `info` labels (`db-cell`, `file-v2`, `local`,
  `search-hash`, …) so unrelated subsystems never share a key.
- **Randomness fails closed.** Nonces come from `random_bytes()` with no weak fallback; if no strong
  RNG is available the call throws instead of encrypting with a predictable nonce.
- **Decryption of a tampered/relocated value THROWS.** It never returns a falsy value a caller could
  mistake for success. This includes `decryptCrossPlatform` (aes-bridge itself returns `""` on an
  authentication failure; the library parses the format itself and throws instead).
- **Non-scalar input is refused.** Arrays and plain objects throw `InvalidArgumentException` instead
  of being encrypted (or blind-indexed) as the literal text `"Array"`.
- **Empty in, empty out.** `null` and `""` are not encrypted: the encrypt functions return `""`, and
  decrypting `""` returns `""` without an error. A ciphertext that was deleted or blanked therefore
  reads back as an empty value, not as a failure — if an empty value is never legitimate for a
  field, check for it yourself.

### Field encryption — `encryptDataDB` / `decryptDataDB`

AES-256-GCM with a **required AAD** binding each value to its location, and a self-describing
versioned envelope `"v1:" + base64(iv || tag || ciphertext)`. The version is folded into the AAD.

```php
$aad = "product_formula.name_encrypted:{$rowId}";   // {table}.{column}:{row_id}
$ct  = Security::encryptDataDB($plain, $key, $aad, $userSalt);
$pt  = Security::decryptDataDB($ct, $key, $aad, $userSalt);
```

Without the AAD, a valid ciphertext could be copied from one row/column to another and still
decrypt. An empty AAD is rejected. Use a **stable per-cell** context and the **same** salt on both
sides. `encryption_version` (the `"v1"` prefix) allows future key/algorithm rotation.

### Blind index — `generateSearchHash`

Keyed HMAC-SHA256 over an HKDF-derived subkey → 64 hex chars, deterministic. Use a **fixed** salt
per index domain (equal input must map to equal hash for lookup/uniqueness):

```php
$hash = Security::generateSearchHash($normalizedEmail, $blindIndexKey); // salt "" = stable
```

Being deterministic, it reveals which rows share a value — inherent to any blind index. Normalize
the input (case, spaces, masks) before hashing, or equal values will not match.

### Authenticated files — `encryptFileV2` / `decryptFileV2`

Streaming AES-256-GCM. Each block's AAD binds `fileId | version | "D" | index`, and an authenticated
end marker binds the total block count. This defeats **truncation, reordering, duplication, and
cross-file splicing** — all rejected on decrypt. The container encoding (`{len}-{base64}` blocks) is
parsed strictly, so non-canonical encodings (leading zeros, `+`, whitespace, bad padding) are
rejected too. The salt is stored in clear in the header (a salt is not a secret); the key never is.

Two process-global settings (reset with `null`):

| Setting | Default | Meaning |
|---|---|---|
| `setFileEncryptBlocksBytes()` | 3,200,000 | Plaintext bytes per encrypted block (min 1). |
| `setFileMaxEncodedBlockBytes()` | 268,435,456 (256 MiB) | Largest encoded block accepted, on **both** encrypt and decrypt (min 44 — the size of the file-id block every file contains). |

`encryptFileV2` refuses, before creating anything, a block size or salt whose encoded block would
exceed the limit — it never writes a file that `decryptFileV2` could not read back. With the default
limit the largest usable block size is 201,326,592 bytes. A file encrypted under a raised limit
needs that limit on the decrypting side as well; the error message names the size required.

Output is written to a hidden staging file next to the destination and renamed into place only on
success, so a failure (wrong key, tamper, full disk, crash) never destroys an existing destination
and never leaves unauthenticated plaintext behind. In-place operation (destination == source, by
any spelling, symlink or hard link) is refused.

### Local strings — `encryptLocal` / `decryptLocal`

AES-256-CTR with encrypt-then-HMAC-SHA256 (verified with `hash_equals` before decrypt; a wrong key
or any modification throws). It is **not bound to a context**: a value encrypted under the same key
and salt decrypts wherever it is pasted. Use `encryptDataDB` with an AAD when a value must not be
movable.

### Cross-platform — `encryptCrossPlatform` / `decryptCrossPlatform`

aes-bridge GCM format. The passphrase handed to aes-bridge is **not** the master key but the raw
32 bytes of `HKDF-SHA256(master key, salt, info "derived-key")`; a peer must derive the same bytes.
See the method docblock for the exact recipe.

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

- **SSRF** — `HTTP::callWebService` has no private-IP/allowlist guard. Do not pass user-controlled
  URLs without an egress allowlist; never disable peer verification in production.
- **Forwarded client IPs** — only `REMOTE_ADDR` is trustworthy; trust `X-Forwarded-For` only behind
  a known proxy.
- **`filterValue(addSlashes: true)`** is not SQL escaping. `filterValue(escapeDB: true)` delegates to
  `SQL::escapeString` and has the same limits. Use prepared statements.
- **S3 uploads** — `S3Storage::upload` detects the content type from the file's bytes; pass an
  explicit `ContentType` for user uploads if the bucket is served from a trusted domain.
- **Seeds** — `System::makeSeed` produces a seed for `mt_rand`-style generators, not a secret.
