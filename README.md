# PHPHelper by VD

A collection of static helper classes for PHP 8.2+: strings, dates, numbers, Brazilian documents
(CPF / CNPJ / CEP), files and zip archives, HTTP, e-mail, spreadsheets, Amazon S3 and authenticated
encryption.

Everything lives under the `VD\PHPHelper` namespace and is called statically — no instances, no
container, no configuration file.

---

## ✅ Highlights

- 🔒 **Secure by default** — AES-256-GCM with context binding (AAD), HKDF per-domain keys, streamed
  and authenticated file encryption, CR/LF-safe headers, zip-slip and symlink-safe file operations.
- 📣 **Fails loudly** — invalid input throws (usually `InvalidArgumentException`) instead of
  producing a plausible-looking wrong result. Each method's docblock states exactly what it returns
  and when it throws.
- 🇧🇷 **Brazil-ready** — CPF and CNPJ (including the alphanumeric CNPJ), CEP, `R$ 1.234,56`
  formatting, pt-BR date texts, Windows-1252 CSV files.
- 🧩 **Pay for what you use** — PHPMailer, PhpSpreadsheet, the AWS SDK and aes-bridge are only
  needed by the class that uses them.
- 🧪 **Tested** — about 2,400 PHPUnit tests, run on Windows and Linux.

---

## 📂 Classes

| Class | What it does |
|---|---|
| `DateTime` | Strict date parsing/validation, format and timezone conversion, intervals with month-end clamping, date ranges, age, durations (`HH:mm:ss` ↔ seconds), pt-BR texts |
| `DBF` | Reads dBase (`.dbf`) files, with a configurable text encoding |
| `File` | Directories, temp files, `.env` read/update, zip/unzip, uploads, file downloads, recursive delete (never follows links) |
| `Formatter` | Number formatting (`R$ 1.234,56`), CPF/CNPJ/CEP masks, flat list → nested tree |
| `HTTP` | cURL requests (`callWebService`), status headers, client IP, `Accept-Language`, JSON/XML responses, downloads |
| `Keyring` | The keys `Security` uses: one current key plus previous keys, for key rotation |
| `Mailer` | E-mail through PHPMailer: attachments, embedded images, provider shortcuts (Gmail, Office 365, …) |
| `Number` | Rounding (round/floor/ceil), random decimals, parity |
| `Parser` | Conversions: array ↔ XML/object, JSON extraction, base64/base64url, hex/binary, booleans, text lines |
| `S3Storage` | Upload, download, copy, move, delete, list and find objects on Amazon S3 or an S3-compatible endpoint |
| `Security` | Field, file and string encryption, key generation and rotation, blind-index hashes, passwords (Argon2id), XSS sanitizing, input filtering |
| `Spreadsheet` | Reads `.xlsx` / `.xls` / `.ods` / `.csv` / `.html` into arrays |
| `SQL` | Literal escaping and batched MySQL `INSERT … ON DUPLICATE KEY UPDATE` builder |
| `Str` | Multibyte-safe string helpers: accents, casing, substrings, occurrences, random keys and GUIDs |
| `System` | Memory units and usage, server memory, seeds, timers |
| `URL` | HTTP header arrays, URL encoding and normalization, appending query parameters |
| `Validator` | CPF, CNPJ, e-mail, password rules, JSON, XML, HTML, base64, dates, emptiness checks |

---

## 🧪 Requirements

- PHP **>= 8.2** with `ext-mbstring` and `ext-json`.
- Anything else is needed only by the classes listed below:

| Dependency | Needed by |
|---|---|
| `ext-openssl` | `Security` encryption (and `SQL::encryptDataDB`, which delegates to it) |
| `ext-dom`, `ext-libxml`, `ext-simplexml` | `Security::xssCleanRecursive`, `Parser` XML helpers, `Validator::validateXml`, `Mailer` embedded images |
| `ext-curl` | `HTTP::callWebService` |
| `ext-zip` | `File` zip/unzip helpers |
| `ext-intl` | *(optional)* `URL` — converts internationalized hosts (`münchen.de`) to punycode |
| `ext-fileinfo` | *(optional)* `S3Storage` — content-type detection on upload |
| `ext-iconv` | *(optional)* `DBF` — code pages mbstring lacks (CP437, CP852, CP1250) |
| `phpmailer/phpmailer` | `Mailer` |
| `phpoffice/phpspreadsheet` | `Spreadsheet` |
| `aws/aws-sdk-php` | `S3Storage` |
| `mervick/aes-bridge` | `Security::encryptCrossPlatform` / `decryptCrossPlatform` |

`Security::encryptPassword` also needs a PHP build with Argon2 support (`PASSWORD_ARGON2ID`).

---

## 📦 Installation

```bash
composer config repositories.phphelper vcs https://github.com/vitor-delgallo/PHPHelper
composer require vitor-delgallo/phphelper:^1.0
```

Use `dev-master` instead of `^1.0` to track the latest unreleased code. Then require only the
optional packages you use, e.g. `composer require phpmailer/phpmailer`. Release notes are on the
[releases page](https://github.com/vitor-delgallo/PHPHelper/releases).

---

## 🚀 Quick start

```php
use VD\PHPHelper\{DateTime, Formatter, HTTP, Security, Str, URL, Validator};

// Brazilian documents and money
Validator::validateCpf('529.982.247-25');                          // true
Validator::validateCnpj('12.ABC.345/01DE-35');                     // true (alphanumeric CNPJ)
Formatter::formatCpf('52998224725');                               // '529.982.247-25'
Formatter::formatNumber('1234.5', '.', ',', '.', 'R$', '', 2);      // 'R$ 1.234,50'

// Dates
DateTime::convertDateToFormat('31/01/2024', 'Y-m-d', 'd/m/Y');      // '2024-01-31'
DateTime::applyInterval('P1M', '2024-01-31', true, 'Y-m-d', 'Y-m-d'); // '2024-02-29' (clamped)

// Strings and URLs
Str::removeAccents('Ação Coração');                                // 'Acao Coracao'
Str::generateGuid();                                               // random v4 UUID
URL::appendParamsToUrl('https://example.com/search#top', ['q' => 'café']);
// 'https://example.com/search?q=caf%C3%A9#top'

// HTTP: returns the raw 2xx body, or a JSON error envelope for any failure / non-2xx status
$body = HTTP::callWebService(
    'https://api.example.com/orders',
    'POST',
    postData: ['id' => 1],
    useRaw: true,                                   // send $postData as a JSON body
    headers: ['Authorization' => 'Bearer <token>'],
);
```

### Encryption

Generate a key once and keep it outside the code and the database — an environment variable or a
secrets file:

```bash
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"    # or Security::generateKey()
```

```php
use VD\PHPHelper\{Keyring, Security};

// The current key, plus (after a rotation) the old one, still needed to read older values
$keys = Keyring::fromBase64(getenv('APP_CRYPTO_KEY'), getenv('APP_CRYPTO_KEY_OLD'));

// A database cell, bound to where it lives: moved to another row/column, it no longer decrypts
$aad    = Security::dbContext('customers', 'document', $customerId);
$cipher = Security::encryptDataDB('529.982.247-25', $keys, $aad);  // 'v2:{key id}:…'
$plain  = Security::decryptDataDB($cipher, $keys, $aad);           // throws if tampered, moved or blanked

// null stays null; '' is encrypted like any other value, and a blank column throws on decrypt
Security::encryptDataDB(null, $keys, $aad);                        // null

// Deterministic blind index, to search an encrypted column
$hash = Security::generateSearchHash('529.982.247-25', $keys);     // 64 hex chars

// Files: streamed block by block and authenticated end to end
Security::encryptFileV2('/data/report.pdf', $keys, '/data/report.pdf.enc');
Security::decryptFileV2('/data/report.pdf.enc', $keys, '/tmp/report.pdf');

// Passwords (Argon2id)
$stored = Security::encryptPassword($password);
Security::verifyPassword($password, $stored);                      // true

// XSS: allowlist sanitizer, walks arrays and plain objects
Security::xssCleanRecursive(['bio' => '<b>hi</b><img src=x onerror=alert(1)>']);
// ['bio' => '<b>hi</b><img src="x" />']
```

A plain 32-byte string works wherever a `Keyring` does. Read **[`SECURITY.md`](SECURITY.md)** before
relying on these: it documents the exact cryptographic contract, key rotation, how to protect a
value against replay with `dbContext(..., $version)`, the file format limits and what remains the
caller's responsibility.

---

## ⚙️ Process-wide settings

The classes are static, so these settings last for the whole PHP process — on a long-lived worker
(FPM, queue workers, Swoole) they carry over between requests until changed. The two `Security`
setters and `File::setDownloadBlockSize()` accept `null` to restore their default.

| Setting | Default |
|---|---|
| `Security::setFileEncryptBlocksBytes()` — plaintext bytes per encrypted file block | 3,200,000 |
| `Security::setFileMaxEncodedBlockBytes()` — largest encoded block accepted when encrypting and decrypting files | 256 MiB |
| `DateTime::setDefaultTimezone()` / `setDefaultFormat()` | PHP's default timezone / `Y-m-d` |
| `File::setDefaultMode()` / `setDownloadBlockSize()` | `0755` / 3 MiB |
| `S3Storage::setKey()`, `setSecret()`, `setRegion()`, `setBucket()`, `setEndpoint()` … (`reset()` clears them) | — |

---

## 🧪 Testing

```bash
composer install
composer test                               # the whole suite
vendor/bin/phpunit --exclude-group slow     # skips the exhaustive bit-flip and 64 MiB file tests
vendor/bin/phpunit tests/SecurityFileEncryptionTest.php
```

- The dev dependencies need `ext-zip` enabled.
- No test touches the network: HTTP and Mailer run against loopback servers, and S3 runs on the AWS
  SDK's `MockHandler`.
- `tests/SQLEngineTest.php` checks the SQL escaping against a real SQLite (`pdo_sqlite`), and
  against a live MySQL only when `VDPH_TEST_MYSQL_DSN` (plus `_USER` / `_PASSWORD`) is set.
- Tests that need something the platform lacks (symlink privilege, POSIX permissions, a locale) are
  skipped with the reason.

---

## 🤝 Contributing

Issues and pull requests are welcome.

---

## 📜 License

This project is licensed under the **MIT** license — see [`LICENSE`](LICENSE).
