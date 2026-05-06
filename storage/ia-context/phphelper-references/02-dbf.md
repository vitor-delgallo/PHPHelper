# DBF Helper

Class: `VD\PHPHelper\DBF`
Source file: `src/DBF.php`

Use for basic reading of legacy DBF files.

## Methods

| Method | Use |
| --- | --- |
| `DBFFormatPath(string $dbfPath)` | Normalizes and validates the DBF file path using `File::getPathInfo`. |
| `DBFReadBasic(string $dbfPath, ?string $mode = null)` | Reads a DBF file in modes such as header, schema, records, structure, all, or scan. |

## Cautions

- The file must exist and be recognized as a valid file.
- Use this class only for simple DBF reading; for complex conversions, check optional dependencies first.
- `DBFReadBasic` returns an array; handle missing files or invalid modes carefully.
