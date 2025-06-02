<?php

namespace VD\PHPHelper;

class Parser {
    /**
     * Decodes TEXT-type values (e.g. Unicode escape sequences like \u00ed).
     *
     * @param string|null $str String to decode
     * @return string|null
     *
     * @ref https://stackoverflow.com/questions/2934563/how-to-decode-unicode-escape-sequences-like-u00ed-to-proper-utf-8-encoded-cha
     */
    public static function decodeText(?string $str): ?string {
        return empty($str) ? $str : preg_replace_callback('/\\\\u([0-9a-fA-F]{4})/i', function ($match) {
            return mb_convert_encoding(pack('H*', $match[1]), 'UTF-8', 'UCS-2BE');
        }, $str);
    }

    /**
     * Recursively decodes TEXT-type values within an array.
     *
     * @param array $arr Array to decode
     * @return array
     */
    public static function decodeTextArray(array $arr): array {
        if (empty($arr)) {
            return array();
        }

        foreach ($arr as &$item) {
            if (empty($item)) continue;
            if (is_array($item)) {
                $item = self::decodeTextArray($item);
                continue;
            }
            if (is_string($item)) $item = self::decodeText($item);
        }

        return $arr;
    }

    /**
     * Converts an array into XML format.
     *
     * @param array|object $data The array to convert
     * @param \SimpleXMLElement|null $xml XML object passed by reference to be modified
     * @param string $rootNode The name of the root node (default: 'root')
     *
     * @return void
     *
     * @ref https://stackoverflow.com/questions/37618094/php-convert-array-to-xml
     */
    public static function arrayToXml(array|object $data, ?\SimpleXMLElement &$xml = null, string $rootNode = "root"): void {
        if (empty($xml)) {
            if (empty($rootNode)) $rootNode = "root";
            $xml = new \SimpleXMLElement("<{$rootNode}/>");
            if (empty($data) || (!is_array($data) && !is_object($data))) {
                $xml->addChild($rootNode, $data);
            }
        }

        if (is_object($data)) {
            $data = (array) $data;
        }

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if(!is_numeric($key)) {
                    $subnode = $xml->addChild($key);
                }
                self::arrayToXml($value, $subnode);
            } else {
                if ($value !== []) {
                    $xml->addChild($key, $value);
                }
            }
        }
    }

    /**
     * Removes null values from an array recursively.
     *
     * @param array|null $array The input array
     * @return array The cleaned array without nulls
     */
    public static function arrayRemoveNulls(?array $array): array {
        if (empty($array)) $array = [];

        $cleaned = [];
        foreach ($array as $item) {
            if ($item === null) continue;

            if (!is_array($item)) {
                $cleaned[] = $item;
            } else {
                $cleaned[] = self::arrayRemoveNulls($item);
            }
        }

        return $cleaned;
    }

    /**
     * Converts an array into a PHP object.
     *
     * @param array $array The array to convert
     * @return object|null The resulting object, or null if empty
     *
     * @ref https://stackoverflow.com/questions/9169892/how-to-convert-multidimensional-array-to-object-in-php
     */
    public static function arrayToObject(array $array): object|null {
        if (empty($array)) return null;
        return json_decode(json_encode($array));
    }

    /**
     * Converts an object into an associative array.
     *
     * @param object|null $object The object to convert
     * @return array The resulting array
     *
     * @ref https://stackoverflow.com/questions/9169892/how-to-convert-multidimensional-array-to-object-in-php
     */
    public static function objectToArray(object|null $object): array {
        if (empty($object)) return [];
        return json_decode(json_encode($object), true);
    }

    /**
     * Converts an XML string or file path into an associative array.
     *
     * @param string $xmlSource XML content or path to XML file
     * @return array The resulting array from the XML
     *
     * @ref https://www.php.net/manual/en/function.simplexml-load-file.php
     */
    public static function xmlToArray(string $xmlSource): array {
        $result = [];
        if (!extension_loaded('simplexml')) {
            return $result;
        }

        if (empty($xmlSource) || is_dir($xmlSource)) return $result;

        if (is_file($xmlSource)) {
            return self::objectToArray(simplexml_load_file($xmlSource));
        }

        return self::objectToArray(simplexml_load_string($xmlSource));
    }

    /**
     * Executes a Base64 decode and handles cases where the string includes the data URI prefix.
     *
     * @param string|null $input String to be decoded
     * @return bool|string Returns the decoded string, or FALSE on failure
     */
    public static function base64Decode(?string $input): bool|string {
        if (empty($input)) {
            return false;
        }

        $parts = explode(";base64,", $input);
        if (count($parts) >= 2) {
            return base64_decode($parts[1], true);
        }

        return base64_decode($parts[0], true);
    }


    /**
     * Encodes a string to a URL-safe Base64 format.
     *
     * @param string|null $input The input string to be encoded.
     * @return string|null Encoded string or null if input is empty.
     *
     * @see https://stackoverflow.com/questions/1374753/passing-base64-encoded-strings-in-url
     */
    public static function base64UrlEncode(?string $input): ?string {
        if ($input === null || $input === '') {
            return null;
        }

        return strtr(base64_encode($input), '+/=', '._-');
    }

    /**
     * Decodes a URL-safe Base64 encoded string back to its original format.
     *
     * @param string|null $input The encoded string to decode.
     * @param bool $strict If true, decoding will return false if the input contains invalid characters.
     * @return string|null Decoded string or null if input is empty.
     *
     * @see https://stackoverflow.com/questions/1374753/passing-base64-encoded-strings-in-url
     */
    public static function base64UrlDecode(?string $input): ?string {
        if ($input === null || $input === '') {
            return null;
        }

        return self::base64Decode(strtr($input, '._-', '+/='));
    }

    /**
     * Converts a string into its binary representation, separating each character's binary with spaces.
     *
     * @param string|null $input The string to convert
     * @return string Binary representation of the string
     *
     * @ref https://stackoverflow.com/questions/6382738/convert-string-to-binary-then-back-again-using-php
     * @ref http://www.inanzzz.com/index.php/post/swf8/converting-string-to-binary-and-binary-to-string-with-php
     */
    public static function stringToBinary(?string $input): string {
        if ($input === null) return "";

        $characters = str_split($input);
        $binary = [];

        foreach ($characters as $index => $char) {
            $bin = base_convert(unpack('H*', $char)[1], 16, 2);
            $binary[$index] = str_pad($bin, strlen($bin) * 8, "0", STR_PAD_LEFT);
        }

        return implode(' ', $binary);
    }

    /**
     * Function binaryToString.
     * Converts a binary string (with space-separated characters) back to a normal string.
     *
     * @param string|null $binaryInput Binary string to convert
     * @return string Decoded string
     *
     * @ref https://stackoverflow.com/questions/6382738/convert-string-to-binary-then/-back-again-using-php
     * @ref http://www.inanzzz.com/index.php/post/swf8/converting-string-to-binary-and-binary-to-string-with-php
     */
    public static function binaryToString(?string $binaryInput): string {
        if ($binaryInput === null) return "";

        $binaries = explode(' ', $binaryInput);
        $output = "";

        foreach ($binaries as $binary) {
            $output .= pack('H*', base_convert($binary, 2, 16));
        }

        return $output;
    }

    /**
     * Converts a plain string to its hexadecimal representation.
     *
     * @param string|null $string The input string to convert
     * @return string Hexadecimal representation of the input string
     *
     * @ref https://stackoverflow.com/questions/14674834/php-convert-string-to-hex-and-hex-to-string
     */
    public static function strToHex(?string $string): string {
        if ($string === null) return "";
        $hex = '';

        for ($i = 0; $i < strlen($string); $i++) {
            $ord = ord($string[$i]);
            $hexCode = dechex($ord);
            $hex .= substr('0' . $hexCode, -2);
        }

        return strtoupper($hex);
    }

    /**
     * Converts a hexadecimal string back to a plain string.
     *
     * @param string|null $hex The hexadecimal string to decode
     * @return string Decoded plain string
     *
     * @ref https://stackoverflow.com/questions/14674834/php-convert-string-to-hex-and-hex-to-string
     */
    public static function hexToStr(?string $hex): string {
        if ($hex === null) return "";
        $string = '';

        for ($i = 0; $i < strlen($hex) - 1; $i += 2) {
            $string .= chr(hexdec($hex[$i] . $hex[$i + 1]));
        }

        return $string;
    }

    /**
     * Re-indexes an array to have sequential numeric keys starting from 0.
     *
     * @param array $array Array to be reindexed
     */
    public static function resetArrayIndexes(array &$array): void {
        if (empty($array)) return;
        $array = array_values($array);
    }

    /**
     * Determines if a value is considered truthy.
     * Returns false if the value is considered completely empty by custom rules.
     *
     * @param mixed $value The value to test
     * @return bool True if the value is not considered completely empty, false otherwise
     */
    public static function getBool(mixed $value): bool {
        if (Validator::isCompletelyEmpty($value)) {
            return false;
        }

        return true;
    }

    /**
     * Extracts JSON-like blocks from a mixed string using bracket balancing.
     *
     * @param string|null $input Input string possibly containing embedded JSON
     * @return string[] Array of extracted JSON-like strings
     */
    public static function extractJsonBlocks(?string $input): array {
        $ret = array();
        if (empty($input)) return $ret;

        $stacks = array();
        $temp = "";
        foreach (str_split($input) AS $char) {
            if (empty($stacks) && ($char === "{" || $char === "[")) {
                $stacks[] = $char;
                $temp .= $char;
            } elseif (!empty($stacks)) {
                $temp .= $char;
                if (
                    (
                        $stacks[(count($stacks) - 1)] === "{" && $char === "}"
                    ) || (
                        $stacks[(count($stacks) - 1)] === "[" && $char === "]"
                    )
                ) {
                    array_pop($stacks);
                    if (empty($stacks)) {
                        $ret[] = $temp;
                        $temp = "";
                    }
                } elseif ($char === "{" || $char === "}" || $char === "[" || $char === "]") {
                    $stacks[] = $char;
                }
            } elseif (strlen($temp) > 0) {
                $ret[] = $temp;
                $temp = "";
            }
        }
        $stacks = null;

        return $ret;
    }

    /**
     * Splits a string into an array of lines.
     * Supports breaking by newline characters or HTML <br> tags.
     *
     * @param string|null $text The string to split
     * @return array The resulting array of lines
     */
    public static function splitLines(?string $text): array {
        if (empty($text)) {
            return [];
        }

        return preg_split('/([\s\t\n\r])|(<br\s*\/?>)+/i', $text);
    }

    /**
     * Joins an array of strings into a single string separated by a delimiter.
     *
     * @param array|null $lines The array to join
     * @param string $glue The glue used to join elements (default: <br />)
     * @return string The resulting joined string
     */
    public static function joinLines(?array $lines, string $glue = '<br />'): string {
        if (empty($lines)) {
            return '';
        }

        return implode($glue, $lines);
    }

    /**
     * Converts a time string (HH:mm:ss) into its total equivalent in seconds.
     *
     * @param string|null $timeString Time string in format "HH:mm:ss"
     * @return int Total seconds, or 0 if the format is invalid
     *
     * @link https://stackoverflow.com/questions/2451165/function-for-converting-time-to-number-of-seconds
     */
    public static function timeToSeconds(?string $timeString): int {
        return DateTime::timeToSeconds($timeString);
    }

    /**
     * Converts a total number of seconds into a formatted time string (HH:mm:ss).
     *
     * @param int|string|null $seconds Number of seconds to convert (may be numeric string)
     * @return string Time string in format "HH:mm:ss"
     */
    public static function secondsToTime(int|string|null $seconds): string {
        return DateTime::secondsToTime($seconds);
    }

    /**
     * Encodes a string into HTML entities to prevent it from affecting HTML structure.
     *
     * Useful for displaying user-generated content safely inside HTML.
     *
     * @param string|null $text The string to encode
     * @return string|null Encoded HTML-safe string
     */
    public static function encodeHtml(?string $text): ?string {
        if ($text === null || $text === '') {
            return $text;
        }

        return htmlentities($text);
    }

    /**
     * Decodes a string containing HTML entities back into readable characters.
     *
     * Reverses the effect of htmlentities().
     *
     * @param string|null $text The HTML-encoded string
     * @return string|null Decoded string
     */
    public static function decodeHtml(?string $text): ?string {
        if ($text === null || $text === '') {
            return $text;
        }

        return html_entity_decode($text);
    }

    /**
     * Converts a string into a numeric-only representation using ASCII codes.
     *
     * Each character is converted to its 3-digit ASCII code, concatenated into a string.
     * Example: "ABC" → "065066067"
     *
     * @param string|null $text Input string to convert
     * @return string|null Numeric string representing ASCII values
     *
     * @link https://stackoverflow.com/questions/8087432/convert-a-string-to-number-and-back-to-string
     */
    public static function stringToNumericSequence(?string $text): ?string {
        if ($text === null || $text === '') {
            return $text;
        }

        return implode(
            '',
            array_map(
                fn($n) => sprintf('%03d', $n),
                unpack('C*', $text)
            )
        );
    }

    /**
     * Converts a numeric-only string (produced from ASCII codes) back to the original string.
     *
     * Example: "065066067" → "ABC"
     *
     * @param string|null $numericText String composed of 3-digit ASCII codes
     * @return string|null Decoded original string
     *
     * @link https://stackoverflow.com/questions/8087432/convert-a-string-to-number-and-back-to-string
     */
    public static function numericSequenceToString(?string $numericText): ?string {
        if ($numericText === null || $numericText === '') {
            return $numericText;
        }

        return implode(
            '',
            array_map(
                'chr',
                str_split($numericText, 3)
            )
        );
    }

    /**
     * Sets a specific key to a given value in all elements of a numerically indexed array.
     *
     * Useful for applying a default or fixed value to a field across all rows.
     *
     * @param array|null $input The array of arrays to modify
     * @param string|null $key The key to be set or overwritten in each sub-array
     * @param mixed $value The value to assign to the key
     *
     * @return array The modified array with the key set in each sub-element
     */
    public static function setValueForKeyInArray(?array $input, ?string $key, mixed $value = null): array {
        if (empty($input) || Validator::emptyExceptZero($key)) {
            return $input;
        }

        array_walk($input, function (&$item) use ($key, $value) {
            $item[$key] = $value;
        });
        return $input;
    }

    /**
     * Retrieves a specific object or array from a list of arrays or objects, based on a unique key-value pair.
     *
     * @param array|null $arrayOfItems List of associative arrays or objects
     * @param string|null $keyName The key/property name to search by
     * @param string|int|null $keyValue The value to match within the specified key/property
     *
     * @return array Returns the matched item as an array, or an empty array if not found
     */
    public static function findItemByKey(?array $arrayOfItems, ?string $keyName, string|int|null $keyValue): array {
        if (empty($arrayOfItems) || empty($keyName) || $keyValue === null || $keyValue === '') {
            return [];
        }

        $indexedColumn = array_column($arrayOfItems, $keyName);
        $mappedIndexes = array_flip(array_map('strval', $indexedColumn));

        return $arrayOfItems[$mappedIndexes[strval($keyValue)] ?? ''] ?? [];
    }

}