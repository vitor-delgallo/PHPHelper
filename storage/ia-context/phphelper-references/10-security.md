# Security Helper

Class: `VD\PHPHelper\Security`
Source file: `src/Security.php`

Use for data/file encryption, searchable hashes, passwords, XSS cleanup, and input filtering. This class is critical: review behavior and requirements before using it with real data.

## Methods

| Method | Use |
| --- | --- |
| `getFileEncryptBlocksBytes()` | Returns the block size for file encryption; current fallback is 3,200,000 bytes. |
| `setFileEncryptBlocksBytes(?int $fileEncryptBlocksBytes)` | Sets the block size for file processing. |
| `generateSearchHash(mixed $str, string $key, ?string $salt = "")` | Generates a searchable HMAC without exposing the original value. |
| `encryptFileV1(string $source, string $key, string $destination, ?string $salt = null, ?string $permissionMode = null)` | Encrypts a file with version V1. |
| `decryptFileV1(string $source, string $key, string $destination, ?string $permissionMode = null, string $outReadMode = "w")` | Decrypts a V1 file. |
| `encryptFileV2(string $source, string $key, string $destination, ?string $salt = null, ?string $permissionMode = null)` | Encrypts a file with AES-256-GCM. |
| `decryptFileV2(string $source, string $key, string $destination, ?string $permissionMode = null, string $outReadMode = "w")` | Decrypts a V2 file. |
| `encryptDataDB(mixed $str, string $key, ?string $salt = "")` | Encrypts a value for database storage. |
| `decryptDataDB(?string $str, string $key, ?string $salt = "")` | Decrypts a value coming from the database. |
| `encryptLocal(mixed $str, string $key, ?string $salt = "")` | Encrypts a local string with AES-256-CTR + HMAC. |
| `decryptLocal(?string $str, string $key, ?string $salt = "")` | Decrypts a local string. |
| `encryptCrossPlatform(mixed $var, string $key, ?string $salt = "")` | Encrypts a value with cross-platform support. |
| `decryptCrossPlatform(mixed $encrypted, string $key, ?string $salt = "")` | Decrypts a cross-platform value. |
| `applySecurityFunctionArray(mixed $item, string $key, ?string $salt, string $fnName)` | Applies a security function recursively to arrays/items. |
| `xssCleanRecursive(mixed $input)` | Cleans input recursively against XSS. |
| `filterValue(mixed $source, ?string $key = null, mixed $ifNull = null, bool $decodeStr = false, bool $xssClean = false, bool $stripTags = false, bool $htmlEntities = false, bool $addSlashes = false, bool $escapeDB = false, bool $trim = false, bool $formatDecimal = false, bool $asInteger = false, bool $asBoolean = false, bool $base64Encode = false, bool $base64Decode = false, bool $base64UrlEncode = false, bool $base64UrlDecode = false, bool $urlEncode = false, bool $urlDecode = false, bool $jsonEncode = false, bool $jsonDecode = false)` | Filters a value with many options: decode, XSS, strip tags, htmlentities, addslashes, DB escape, trim, decimal, integer, boolean, base64, URL, and JSON. |
| `encryptPassword(?string $password)` | Generates a password hash using Argon2id. |
| `verifyPassword(string $password, string $hash)` | Verifies a password against a hash. |

## Cautions

- Encryption keys must be at least 16 bytes long.
- Crypto resources depend on `ext-openssl` and related functions.
- `encryptCrossPlatform` may depend on `mervick/aes-bridge`; do not install it without permission.
- Do not confuse searchable hashes with password hashes. For passwords, use `encryptPassword` and `verifyPassword`.
- `filterValue` is powerful but hard to audit when many flags are used; prefer explicit, tested configurations.
- For user HTML, clean/sanitize before rendering. Avoid `innerHTML` on the front end and unescaped output in PHP.
