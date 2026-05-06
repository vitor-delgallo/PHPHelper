# Str Helper

Class: `VD\PHPHelper\Str`
Source file: `src/Str.php`

Use for multibyte string manipulation, cleanup, search, replacement, key/GUID generation, normalization, and substring extraction.

## Methods

| Method | Use |
| --- | --- |
| `removeInvisibleCharacters(?string $str, bool $urlEncoded = false)` | Removes invisible characters, optionally handling URL encoding. |
| `onlyNumbers(?string $str)` | Keeps only digits. |
| `decodeText(?string $str)` | Decodes text. |
| `containsString(?string $text, ?string $search, bool $ignoreCase = false)` | Checks for a substring, optionally case-insensitive. |
| `replaceString(string|array|null $search, string|array|null $replace, ?string $subject, bool $ignoreCase = false)` | Replaces string/array values, optionally case-insensitive. |
| `mbUcFirst(?string $text)` | Capitalizes the first letter with multibyte support. |
| `removeStringPrefix(?string $string, ?string $prefix)` | Removes a prefix when present. |
| `removeStringSuffix(?string $string, ?string $suffix)` | Removes a suffix when present. |
| `generateUniqueKey(int $segmentLength = 5, int $segmentCount = 5, string $separator = '-', string $uniqueId = '', string $prefix = '', string $suffix = '', bool $ignoreLengthOnId = true)` | Generates a segmented unique key with prefix/suffix. |
| `generateGuid(bool $trim = true)` | Generates a GUID/UUID-like string. |
| `removeExcessSpaces(mixed $str, bool $keepSingleSpace = true)` | Removes excess spaces. |
| `strToUpper(?string $str)` | Multibyte uppercase. |
| `strToLower(?string $str)` | Multibyte lowercase. |
| `trim(?string $str)` | Null-safe trim. |
| `strLen(?string $str)` | Null-safe multibyte length. |
| `subStr(?string $str, int $offset = 0, ?int $length = null)` | Multibyte substring. |
| `strPos(?string $str, string $strSearch, int $offset = 0)` | Case-sensitive position. |
| `strIPos(?string $str, string $strSearch, int $offset = 0)` | Case-insensitive position. |
| `getAdjacentCombinations(array $input, array &$cache = [])` | Generates adjacent combinations from an array. |
| `truncateAtFirstOccurrence(?string $str, ?string $occurrence)` | Cuts text at the first occurrence. |
| `removeSubstrings(string $input, array $substringsToRemove)` | Removes a list of substrings. |
| `removeCharacters(?string $input, ?string $charactersToRemove)` | Removes specific characters. |
| `keepOnlyCharacters(?string $input, ?string $allowedCharacters)` | Keeps only allowed characters. |
| `onlyLettersAndNumbers(?string $input)` | Keeps letters and numbers. |
| `onlyLetters(?string $input)` | Keeps only letters. |
| `truncateWithTooltip(?string $text, int $maxLength = 100, ?string $suffix = '...')` | Truncates text and generates tooltip markup. |
| `toCamelCase(?string $input, array $preserveWords = [])` | Converts text to camelCase. |
| `flushOutput(string $message, int $sleepSeconds = 0)` | Sends output and flushes, optionally sleeping. |
| `containsExactWord(?string $text, ?string $word, bool $caseSensitive = true)` | Checks for an exact word. |
| `removeAccents(string $text)` | Removes accents. |
| `findAllOccurrences(string $haystack, string $needle, bool $returnEndPosition = false, bool $caseSensitive = true, int $offset = 0, array &$results = [])` | Returns all positions of a substring. |
| `extractSubstringsBetween(?string $input, ?string $startDelimiter, ?string $endDelimiter, bool $caseSensitive = true, bool $includeDelimiters = false)` | Extracts text between delimiters. |

## Cautions

- The class depends on `ext-mbstring`.
- `truncateWithTooltip` generates HTML; use it carefully with user content.
- For validation, use `Validator`; for document formatting, use `Formatter`.
