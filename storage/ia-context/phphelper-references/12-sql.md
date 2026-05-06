# SQL Helper

Class: `VD\PHPHelper\SQL`
Source file: `src/SQL.php`

Use for simple escaping, bridge methods for database encryption, and batch MySQL SQL generation.

## Methods

| Method | Use |
| --- | --- |
| `escapeString(mixed $data)` | Applies simple escaping to strings/arrays. |
| `encryptDataDB(mixed $str, string $key, ?string $salt = "")` | Forwards data encryption to `Security::encryptDataDB`. |
| `decryptDataDB(?string $str, string $key, ?string $salt = "")` | Forwards decryption to `Security::decryptDataDB`. |
| `prepareInsertOrUpdateMySQL(array &$data, string $table, ?string $insertFields = null, ?string $updateFields = null, ?callable $rowFormatter = null, array $global = [])` | Generates batch INSERT/UPDATE SQL for MySQL from an array of data. |

## Cautions

- Simple escaping does not replace prepared statements for user input.
- For new queries, prefer framework/database prepared statements.
- `prepareInsertOrUpdateMySQL` generates a SQL string; review allowed fields and value formatting before executing it.
- For sensitive data, use the encryption bridge methods with the correct key/salt.
