# Spreadsheet Helper

Class: `VD\PHPHelper\Spreadsheet`
Source file: `src/Spreadsheet.php`

Use to convert Excel/CSV spreadsheets into arrays when the optional dependency is installed.

## Methods

| Method | Use |
| --- | --- |
| `excelToArray(string $filePath, bool $withHeader = true, ?string $sheetName = null, bool $removeEmptyRows = true)` | Reads a spreadsheet file and returns an array, optionally using the first row as header, a specific sheet, and empty-row removal. |

## Cautions

- Depends on `phpoffice/phpspreadsheet`; do not install it without user's permission.
- Validate extension, size, and file origin before processing uploads.
- For large files, consider memory and execution time.
