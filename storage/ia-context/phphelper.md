---
name: phphelper-context
description: Operational context for the vitor-delgallo/phphelper library for AI agents. Use before creating helpers, formatters, validators, parsers, file utilities, date utilities, URL utilities, HTTP helpers, SQL helpers, security helpers, email helpers, spreadsheet helpers, or S3 storage helpers.
---

# PHPHelper Context

This file is the entry point for **vitor-delgallo/phphelper** context for AI agents, coding agents, and developers.

Keep this document short enough to remain in context by default. Open the documents in `phphelper-references/` when you need details about a specific class.

## Essential Context

- Composer package: `vitor-delgallo/phphelper`.
- Namespace: `VD\PHPHelper`.
- Source code: `src/*.php`.
- Minimum PHP version: `>=8.2`.
- Required extensions: `ext-json` and `ext-mbstring`.
- The library is made of static utility classes.
- Before creating any new helper in the project, first check whether this library already solves the need.
- To use it in a project, import the specific class, for example `use VD\PHPHelper\Str;`.
- Do not install optional dependencies without user's permission. Some classes have features that depend on PHPMailer, PhpSpreadsheet, AWS SDK, curl, openssl, simplexml, dom, zip, libxml, or other extensions.
- Some methods send headers, start downloads, call `exit`, or perform write operations. Check behavior before using them in controllers or routes.
- For user data, prefer `Validator`, `Security`, `Parser`, `Formatter`, and `Str` before creating local validation or cleanup logic.
- For files, uploads, zip files, `.env`, and paths, prefer `File` before creating new utilities.
- For encryption, searchable hashing, passwords, and XSS cleanup, prefer `Security` and respect key requirements.

## Installation

```bash
composer config repositories.phphelper vcs https://github.com/vitor-delgallo/PHPHelper
composer require vitor-delgallo/phphelper:dev-master
```

Before installing or changing dependencies, ask the user.

## Optional Dependencies

| Feature | Dependency |
| --- | --- |
| Email sending | `phpmailer/phpmailer` |
| Excel/CSV spreadsheets | `phpoffice/phpspreadsheet` |
| Cross-platform encryption | `mervick/aes-bridge` |
| File conversions | `rebasedata/php-client` |
| S3 | `aws/aws-sdk-php` |
| External HTTP | `ext-curl` |
| XML | `ext-simplexml`, `ext-dom`, `ext-libxml` |
| Encryption | `ext-openssl` |
| ZIP | `ext-zip` |
| Type validation | `ext-ctype` |
| Calendar features | `ext-calendar` |

## Class Overview

| Class | When to use | Public methods |
| --- | --- | --- |
| `DateTime` | Dates, formats, timezones, differences, age, and time conversion. | `getDefaultTimezone`, `setDefaultTimezone`, `isValidTimezone`, `getDefaultFormat`, `setDefaultFormat`, `validateDate`, `getCurrentFormattedDate`, `convertTimestampToDate`, `getWeekDay`, `getMonth`, `convertDateToFormat`, `toDate`, `dateDiffDays`, `dateFullTextPtBr`, `applyInterval`, `getDateRangeList`, `calculateAge`, `getDateDifference`, `getDateDifferenceInSeconds`, `timeToSeconds`, `secondsToTime`, `getGreetingPeriodCode`, `convertTimezone` |
| `DBF` | Basic reading of legacy DBF files. | `DBFFormatPath`, `DBFReadBasic` |
| `File` | Directories, paths, files, `.env`, zip, upload, and download. | `getDownloadBlockSize`, `setDownloadBlockSize`, `getDefaultMode`, `setDefaultMode`, `getPermissionMode`, `createDir`, `getPathInfo`, `writeFile`, `createTempFile`, `deleteFoldersRecursively`, `resetFolder`, `standardizeFilesCaseRecursive`, `parseEnvFile`, `updateEnvFile`, `unzipFile`, `getDirectoryContents`, `zipDirectory`, `zipMultipleFiles`, `deleteFiles`, `downloadFile`, `renameUploadFile`, `uploadFileTo` |
| `Formatter` | Numbers, CPF, CNPJ, CEP, and tree structures. | `formatNumber`, `buildNestedArray`, `cleanEmptyTree`, `formatCnpj`, `formatCpf`, `formatCpfOrCnpj`, `formatCep`, `unformatCep`, `unformatDocument` |
| `HTTP` | Headers, HTTP status, AJAX, IP, language, and downloads. | `isHeaderPresent`, `sendStatusHeader`, `isXmlHttpRequest`, `downloadFile`, `getClientIpAddresses`, `getBrowserLanguage`, `resolveAndExit` |
| `Mailer` | Email sending through PHPMailer and email validation. | `sendMail`, `validateMail`, `getImageSrc` |
| `Number` | Decimal rounding, random decimals, and parity. | `roundDecimal`, `randomDecimal`, `isEven` |
| `Parser` | Array/object/XML, Base64, binary, hex, HTML, lines, and embedded JSON conversions. | `decodeText`, `decodeTextArray`, `arrayToXml`, `arrayRemoveNulls`, `arrayToObject`, `objectToArray`, `xmlToArray`, `base64Decode`, `base64UrlEncode`, `base64UrlDecode`, `stringToBinary`, `binaryToString`, `strToHex`, `hexToStr`, `resetArrayIndexes`, `getBool`, `extractJsonBlocks`, `splitLines`, `joinLines`, `timeToSeconds`, `secondsToTime`, `encodeHtml`, `decodeHtml`, `stringToNumericSequence`, `numericSequenceToString`, `setValueForKeyInArray`, `findItemByKey` |
| `S3Storage` | S3 operations with AWS SDK, no-op mode, and internal error tracking. | `reset`, `setRegion`, `getRegion`, `getBucket`, `setKey`, `setSecret`, `setNoOperation`, `setBucket`, `setDebug`, `getDebug`, `createBucket`, `upload`, `copy`, `delete`, `move`, `rename`, `download`, `list`, `find`, `exists`, `getLastError` |
| `Security` | Encryption, searchable hashes, passwords, filters, and XSS cleanup. | `getFileEncryptBlocksBytes`, `setFileEncryptBlocksBytes`, `generateSearchHash`, `encryptFileV1`, `decryptFileV1`, `encryptFileV2`, `decryptFileV2`, `encryptDataDB`, `decryptDataDB`, `encryptLocal`, `decryptLocal`, `encryptCrossPlatform`, `decryptCrossPlatform`, `applySecurityFunctionArray`, `xssCleanRecursive`, `filterValue`, `encryptPassword`, `verifyPassword` |
| `Spreadsheet` | Reading Excel/CSV into arrays through PhpSpreadsheet. | `excelToArray` |
| `SQL` | Simple escaping, bridge to data encryption, and MySQL INSERT/UPDATE generation. | `escapeString`, `encryptDataDB`, `decryptDataDB`, `prepareInsertOrUpdateMySQL` |
| `Str` | Multibyte strings, cleanup, search, prefix/suffix, GUID, keys, camelCase, and extraction. | `removeInvisibleCharacters`, `onlyNumbers`, `decodeText`, `containsString`, `replaceString`, `mbUcFirst`, `removeStringPrefix`, `removeStringSuffix`, `generateUniqueKey`, `generateGuid`, `removeExcessSpaces`, `strToUpper`, `strToLower`, `trim`, `strLen`, `subStr`, `strPos`, `strIPos`, `getAdjacentCombinations`, `truncateAtFirstOccurrence`, `removeSubstrings`, `removeCharacters`, `keepOnlyCharacters`, `onlyLettersAndNumbers`, `onlyLetters`, `truncateWithTooltip`, `toCamelCase`, `flushOutput`, `containsExactWord`, `removeAccents`, `findAllOccurrences`, `extractSubstringsBetween` |
| `System` | Memory, unit conversion, seed, and simple timers. | `getMemoryUnitOrder`, `convertBytesToReadable`, `convertMemoryToBytes`, `getServerMemoryUsage`, `getMemoryUsage`, `isMemoryGreaterThan`, `makeSeed`, `timer` |
| `URL` | Headers, URL encoding, protocol, URL normalization, and query params. | `buildHttpHeaderArray`, `urlEncode`, `formatProtocol`, `getFormattedUrl`, `appendParamsToUrl` |
| `Validator` | Dates, email, password, JSON, Base64, XML, CPF, CNPJ, HTML, and structure validation. | `isHex`, `isOctal`, `validateDate`, `validateMail`, `validatePassword`, `validateJson`, `isBase64Encoded`, `isBase64UrlEncoded`, `validateXml`, `emptyExceptZero`, `hasProperty`, `isNumericArray`, `isCompletelyEmpty`, `isNegativeNumber`, `validateCpf`, `validateCnpj`, `validateHtml` |

## Document Summary

| Class | Document |
| --- | --- |
| `DateTime` | [01-datetime.md](phphelper-references/01-datetime.md) |
| `DBF` | [02-dbf.md](phphelper-references/02-dbf.md) |
| `File` | [03-file.md](phphelper-references/03-file.md) |
| `Formatter` | [04-formatter.md](phphelper-references/04-formatter.md) |
| `HTTP` | [05-http.md](phphelper-references/05-http.md) |
| `Mailer` | [06-mailer.md](phphelper-references/06-mailer.md) |
| `Number` | [07-number.md](phphelper-references/07-number.md) |
| `Parser` | [08-parser.md](phphelper-references/08-parser.md) |
| `S3Storage` | [09-s3storage.md](phphelper-references/09-s3storage.md) |
| `Security` | [10-security.md](phphelper-references/10-security.md) |
| `Spreadsheet` | [11-spreadsheet.md](phphelper-references/11-spreadsheet.md) |
| `SQL` | [12-sql.md](phphelper-references/12-sql.md) |
| `Str` | [13-str.md](phphelper-references/13-str.md) |
| `System` | [14-system.md](phphelper-references/14-system.md) |
| `URL` | [15-url.md](phphelper-references/15-url.md) |
| `Validator` | [16-validator.md](phphelper-references/16-validator.md) |

## Quick Decisions

| Need | Use first |
| --- | --- |
| Validate CPF/CNPJ/email/password/JSON/XML | `Validator` |
| Clean strings, remove accents, generate GUID or key | `Str` |
| Format CPF/CNPJ/CEP/number | `Formatter` |
| Convert array/object/XML/Base64/HTML/lines | `Parser` |
| Create directory, zip, upload, download, `.env` | `File` |
| Encrypt data, password, searchable hash, clean XSS | `Security` |
| Generate batch SQL or DB encryption | `SQL` + `Security` |
| Send email | `Mailer` + permission for PHPMailer if not installed |
| Read Excel/CSV | `Spreadsheet` + permission for PhpSpreadsheet if not installed |
| Use S3 | `S3Storage` + permission for AWS SDK if not installed |

## Main Principle

Before creating new code, search this library. It exists to avoid duplicating common helpers. If a ready function exists, use it; if an optional dependency is needed, ask permission before installing or enabling it.
