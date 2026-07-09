# Security notes — `VD\PHPHelper\Security` and related helpers

This document records the cryptographic contract of the library and the results of a security
review. Tests live in `tests/SecurityCryptoTest.php` (run: `php tests/SecurityCryptoTest.php`).

> **Breaking changes** were made deliberately — the library was not yet consumed by any project,
> so on-disk / on-column formats and some signatures changed to close real weaknesses.

## Cryptographic contract

- **Keys are >= 32 bytes.** HKDF cannot add entropy, so the AES-256 paths require a 32-byte master
  key. Derivation is HKDF-SHA256 with **per-domain** `info` labels (`db-cell`, `file-v2`, `local`,
  `search-hash`, …) so unrelated subsystems never share a key.
- **Randomness fails closed.** Nonces come from `random_bytes()` with no weak fallback; if no strong
  RNG is available the call throws instead of encrypting with a predictable nonce.
- **Decryption of a tampered/relocated value THROWS.** It never returns a falsy value a caller could
  mistake for success.

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

### Authenticated files — `encryptFileV2` / `decryptFileV2`

Streaming AES-256-GCM. Each block's AAD binds `fileId | version | "D" | index`, and an authenticated
end marker binds the total block count. This defeats **truncation, reordering, duplication, and
cross-file splicing** — all rejected on decrypt. The legacy unauthenticated
`encryptFileV1`/`decryptFileV1` (AES-128-CBC) were **removed**; only the authenticated V2 remains.

### Local strings — `encryptLocal` / `decryptLocal`

AES-256-CTR with encrypt-then-HMAC-SHA256 (verified with `hash_equals` before decrypt). Sound.

### Passwords — `encryptPassword` / `verifyPassword`

Argon2id via `password_hash`/`password_verify`. Never use the encryption helpers for passwords.

## Non-crypto fixes in this review

- `SQL::escapeString` now escapes the backslash (a trailing `\` previously broke out of the quoted
  literal → SQL injection). **Prefer parameterized queries; this helper is a last resort.**
- `File::unzipFile` rejects Zip-Slip entries (`..` / absolute paths escaping the destination).
- `File::deleteFiles` reduces each name to `basename()` (no path traversal).
- `Str::truncateWithTooltip` HTML-encodes with `htmlspecialchars` (was attribute-breakout XSS).
- `Str::removeStringSuffix` fixed (`str_ends_with`; the bug produced malformed SQL upstream).
- `Parser::xmlToArray` blocks external entities + `LIBXML_NONET` (XXE).
- `Security::readLengthEncodedBlock` bounds the declared length (decrypt-time memory-DoS).

## Deferred — caller responsibilities / recommended follow-ups (NOT changed here)

These need a policy/API decision by the maintainer:

- **`HTTP::callWebService` — SSRF.** No private-IP/allowlist guard and TLS verification can be
  disabled. Do not pass user-controlled URLs without an egress allowlist; never disable peer
  verification in production.
- **`HTTP::getClientIpAddresses`** trusts `X-Forwarded-For`/`Client-IP`. For any security decision
  (audit, rate-limit) use `REMOTE_ADDR`; trust XFF only behind a known proxy.
- **`Security::xssCleanRecursive` / `filterValue(xssClean:...)`** is a best-effort blacklist, **not
  an XSS boundary** (e.g. `<svg/onload=…>` bypasses it). Defend by output-encoding
  (`htmlspecialchars`) or a maintained sanitizer.
- **`filterValue(addSlashes/escapeDB)`** are not safe SQL escaping — use prepared statements.
- **`System::makeSeed`, `Str::generateUniqueKey`, `Str::generateGuid` fallback** use `rand()`/
  `uniqid()` — not cryptographic. Use `random_bytes()`/`random_int()` for any token/secret.
- **`Validator::isBase64UrlEncoded`, `System::isMemoryGreaterThan`** call undefined functions
  (latent fatals) — fix or remove.
