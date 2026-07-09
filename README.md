# PHPHelper by VD

A modern, modular and extensible utility library for PHP 8.2+, built for real-world applications. This library provides reliable helpers for strings, dates, arrays, numbers, HTTP, system operations, encryption, parsing, email, spreadsheet handling, and more.

> Clean, stateless, and built to work in any PHP environment.

---

## ✅ Features

- 📦 Organized into static utility classes under the namespace `VD\PHPHelper`
- 🔒 Secure-by-default implementations for encryption, encoding, validation, and parsing
- 💡 Clear and fully-documented functions with modern PHP 8 syntax
- ⚙️ Optional integrations with popular libraries (PHPMailer, PhpSpreadsheet, etc.)
- 🧩 Easy to include in legacy or modern applications (Composer-ready)

---

## 📂 Available Classes

These classes are auto-loaded under the namespace `VD\PHPHelper`:

- `DateTime` – date formatting, age calculation, diff tools
- `DBF` – legacy DBF file support
- `File` – file handling and utility helpers
- `Formatter` – number, mask, and data formatting
- `HTTP` – HTTP requests and header helpers
- `Mailer` – abstraction for PHPMailer integration
- `Number` – numeric manipulation and memory unit helpers
- `Parser` – data sanitization and filtering
- `S3Storage` – data storage on s3 services
- `Security` – hashing, encryption (AES, GCM), secure encoding
- `Spreadsheet` – Excel and CSV utilities (via PhpSpreadsheet)
- `SQL` – safe SQL snippet generators and query formatters
- `Str` – string manipulation and pattern helpers
- `System` – CLI detection, environment tools
- `URL` – URL formating/encoding and query params appending
- `Validator` – validation tools for CPF, CNPJ, dates, etc.

---

## 🧪 Requirements

- PHP **>= 8.2**
- Extensions:
    - `ext-mbstring`
    - `ext-json`

---

## 🔌 Optional Dependencies

The following packages are **only required if you use the related functionality**:

| Package                         | Purpose                                    |
|---------------------------------|--------------------------------------------|
| `phpmailer/phpmailer`           | Required for email sending via `Mailer`    |
| `phpoffice/phpspreadsheet`      | Required for Excel/CSV operations          |
| `mervick/aes-bridge`            | Optional cross-platform AES encryption     |
| `rebasedata/php-client`         | Required for file format conversions       |
| `ext-curl`                      | Required for external HTTP requests        |
| `ext-simplexml`                 | Required for XML manipulation              |
| `ext-openssl`                   | Required for GCM/SSL encryption            |
| `ext-dom`                       | Required for DOM and HTML/XML handling     |
| `ext-ctype`                     | Required for type validation               |
| `ext-calendar`                  | Required for date/calendar features        |
| `ext-zip`                       | Required for working with `.zip` files     |
| `ext-libxml`                    | Required for parsing and validation of XML |

---

## 📦 Installation

You can include this library directly or via Composer (if structured as a package):

```bash
composer config repositories.phphelper vcs https://github.com/vitor-delgallo/PHPHelper
composer require vitor-delgallo/phphelper:dev-master
```

---

## 🔒 Security

This library encrypts sensitive data. Read **[`SECURITY.md`](SECURITY.md)** for the cryptographic
contract (AES-256-GCM with **required AAD** context binding, HKDF per-domain keys, keyed HMAC blind
indexes, authenticated files) and for caller responsibilities (SSRF, XSS output-encoding,
parameterized SQL). Encryption keys must be **>= 32 bytes**.

---

## 🧪 Testing

```bash
php tests/SecurityCryptoTest.php
```

---

## 🤝 Contributing
If you want to contribute, feel free to open **issues** and **pull requests** in the repository!

---

## 📜 License
This project is licensed under the **MIT** license.