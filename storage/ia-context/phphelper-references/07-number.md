# Number Helper

Class: `VD\PHPHelper\Number`
Source file: `src/Number.php`

Use for small, direct numeric operations.

## Methods

| Method | Use |
| --- | --- |
| `roundDecimal(float $value, int $precision = 2, string $method = "round")` | Rounds a decimal using a method such as round, ceil, or floor. |
| `randomDecimal(float $min, float $max)` | Generates a random decimal between minimum and maximum. |
| `isEven(int $number)` | Checks whether a number is even. |

## Cautions

- For number formatting for display, use `Formatter::formatNumber`.
- For numeric input validation, combine with `Validator` and appropriate cleanup.
