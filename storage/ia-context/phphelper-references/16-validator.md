# Validator Helper

Class: `VD\PHPHelper\Validator`
Source file: `src/Validator.php`

Use for common validation before creating new local rules.

## Methods

| Method | Use |
| --- | --- |
| `isHex(?string $value)` | Checks whether a string is hexadecimal. |
| `isOctal(?string $value)` | Checks whether a string is octal, used by permissions. |
| `validateDate(?string $date, ?string $format = null)` | Validates a date by format. |
| `validateMail(?string $email)` | Validates email. |
| `validatePassword(?string $password, array $rules = [])` | Validates password with a ruleset. |
| `validateJson(?string $jsonString)` | Validates JSON. |
| `isBase64Encoded(?string $input)` | Checks standard Base64. |
| `isBase64UrlEncoded(?string $input)` | Checks URL-safe Base64. |
| `validateXml(?string $xmlContent)` | Validates XML. |
| `emptyExceptZero($value)` | Checks emptiness while treating zero as a valid value. |
| `hasProperty(string $property, array|object $target)` | Checks property/key existence. |
| `isNumericArray(array $array)` | Checks whether an array is numeric/list-like. |
| `isCompletelyEmpty(mixed $value)` | Checks whether a structure is completely empty. |
| `isNegativeNumber(mixed $value)` | Checks whether a value is a negative number. |
| `validateCpf(string $cpf)` | Validates CPF. |
| `validateCnpj(string $cnpj)` | Validates CNPJ. |
| `validateHtml(?string $text)` | Validates HTML. |

## Cautions

- Validation is not sanitization. Combine with `Security`, `Parser`, `Formatter`, or `Str` when cleanup/normalization is needed.
- `validatePassword` depends on the provided rules; document the rules used by the project.
- XML/HTML can depend on parsing extensions available in the environment.
