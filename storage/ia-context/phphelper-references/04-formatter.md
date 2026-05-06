# Formatter Helper

Class: `VD\PHPHelper\Formatter`
Source file: `src/Formatter.php`

Use for numeric formatting, Brazilian documents, CEP, and transforming flat lists into trees.

## Methods

| Method | Use |
| --- | --- |
| `formatNumber(string|float|int|null $number, string $decimalSeparatorFrom = '.', string $decimalSeparatorTo = '.', string $thousandsSeparatorTo = '', string $prefix = '', string $suffix = '', ?int $decimalPlaces = null, bool $allowNegative = true)` | Formats a number with separators, prefix, suffix, decimal places, and negative-value control. |
| `buildNestedArray(array &$items, string $parentField = 'idFather', string $idField = 'id', string $childrenField = 'children', int|string|null $parentId = null)` | Builds a tree from a flat list with a parent field. |
| `cleanEmptyTree(array $elements, string $childrenKey = 'children', ?string $requiredFieldIfEmpty = null)` | Removes empty branches from a tree. |
| `formatCnpj(?string $number)` | Formats CNPJ. |
| `formatCpf(?string $number)` | Formats CPF. |
| `formatCpfOrCnpj(?string $number)` | Detects length and formats CPF or CNPJ. |
| `formatCep(?string $number)` | Formats CEP. |
| `unformatCep(?string $cep)` | Removes CEP mask. |
| `unformatDocument(?string $value)` | Removes document formatting. |

## Cautions

- Formatting does not replace validation. Use `Validator::validateCpf` and `Validator::validateCnpj` when validity must be confirmed.
- For user input, normalize with `Str::onlyNumbers` or unformat methods before persisting.
- `buildNestedArray` uses/alters the list by reference; review side effects.
