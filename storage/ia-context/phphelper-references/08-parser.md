# Parser Helper

Class: `VD\PHPHelper\Parser`
Source file: `src/Parser.php`

Use for conversions between structures and formats: array, object, XML, Base64, binary, hexadecimal, HTML, lines, booleans, and embedded JSON.

## Methods

| Method | Use |
| --- | --- |
| `decodeText(?string $str)` | Decodes text. |
| `decodeTextArray(array $arr)` | Recursively decodes text values in an array. |
| `arrayToXml(array|object $data, ?\SimpleXMLElement &$xml = null, string $rootNode = "root")` | Converts array/object to XML. |
| `arrayRemoveNulls(?array $array)` | Removes null values from an array. |
| `arrayToObject(array $array)` | Converts array to object. |
| `objectToArray(object|null $object)` | Converts object to array. |
| `xmlToArray(string $xmlSource)` | Converts XML to array. |
| `base64Decode(?string $input)` | Decodes Base64 and returns false on failure. |
| `base64UrlEncode(?string $input)` | Encodes a string as URL-safe Base64. |
| `base64UrlDecode(?string $input)` | Decodes URL-safe Base64. |
| `stringToBinary(?string $input)` | Converts a string to a binary sequence. |
| `binaryToString(?string $binaryInput)` | Converts textual binary to string. |
| `strToHex(?string $string)` | Converts string to hexadecimal. |
| `hexToStr(?string $hex)` | Converts hexadecimal to string. |
| `resetArrayIndexes(array &$array)` | Reindexes an array by reference. |
| `getBool(mixed $value)` | Interprets a mixed value as boolean. |
| `extractJsonBlocks(?string $input)` | Extracts JSON blocks from text. |
| `splitLines(?string $text)` | Splits text into lines. |
| `joinLines(?array $lines, string $glue = '<br />')` | Joins lines with a separator. |
| `timeToSeconds(?string $timeString)` | Converts a time string to seconds. |
| `secondsToTime(int|string|null $seconds)` | Converts seconds to a time string. |
| `encodeHtml(?string $text)` | Encodes HTML. |
| `decodeHtml(?string $text)` | Decodes HTML entities. |
| `stringToNumericSequence(?string $text)` | Converts a string to a numeric sequence. |
| `numericSequenceToString(?string $numericText)` | Converts a numeric sequence to string. |
| `setValueForKeyInArray(?array $input, ?string $key, mixed $value = null)` | Sets a value by key in an array. |
| `findItemByKey(?array $arrayOfItems, ?string $keyName, string|int|null $keyValue)` | Finds an item by key and value in a list of arrays. |

## Cautions

- XML features depend on XML extensions (`simplexml`, `libxml`, depending on use).
- HTML encoding does not replace a complete sanitization strategy; for XSS cleanup, see `Security`.
- `resetArrayIndexes` modifies the array by reference.
