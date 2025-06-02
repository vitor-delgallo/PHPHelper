<?php

namespace VD\PHPHelper;

class Str {
    /**
     * Remove Invisible Characters
     * This prevents sandwiching null characters between ascii characters, like Java\0script.
     *
     * @param string|null $str String to be read
     * @param bool $urlEncoded Define if it needs to be encoded for URL
     *
     * @return string
     */
    public static function removeInvisibleCharacters(?string $str, bool $urlEncoded = false): string
    {
        if(empty($str)) {
            return $str;
        }
        $nonDisplayables = [];

        // every control character except newline (dec 10),
        // carriage return (dec 13) and horizontal tab (dec 09)
        if ($urlEncoded) {
            $nonDisplayables[] = '/%0[0-8bcef]/';  // url encoded 00-08, 11, 12, 14, 15
            $nonDisplayables[] = '/%1[0-9a-f]/';   // url encoded 16-31
        }

        $nonDisplayables[] = '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]+/S';   // 00-08, 11, 12, 14-31, 127

        do {
            $str = preg_replace($nonDisplayables, '', $str, -1, $count);
        } while ($count);

        return $str;
    }

    /**
     * Returns a string containing only numeric characters.
     *
     * @param string|null $str The input string to extract numbers from
     * @return string
     *
     * @ref http://www.cesar.inf.br/blog/?p=191
     */
    public static function onlyNumbers(?string $str): string {
        if ($str === NULL) return "";
        return preg_replace("/[^0-9]/", "", $str);
    }

    /**
     * Decodes TEXT-type values (e.g. Unicode escape sequences like \u00ed).
     *
     * @param string|null $str String to decode
     * @return string|null
     *
     * @ref https://stackoverflow.com/questions/2934563/how-to-decode-unicode-escape-sequences-like-u00ed-to-proper-utf-8-encoded-cha
     */
    public static function decodeText(?string $str): ?string {
        return Parser::decodeText($str);
    }

    /**
     * Checks if one string contains another.
     *
     * @param string|null $text The string to be searched
     * @param string|null $search The string to search for
     * @param bool $ignoreCase Whether to ignore case sensitivity
     * @return bool TRUE if the string contains the search value, FALSE otherwise
     */
    public static function containsString(?string $text, ?string $search, bool $ignoreCase = false): bool {
        if($text === null || $text === "" || $search === null || $search === "") {
            return false;
        }

        return ($ignoreCase ? self::strIPos($text, $search) : self::strPos($text, $search)) !== false;
    }

    /**
     * Replaces all occurrences of the search string with the replacement string, with optional case sensitivity.
     *
     * @param string|array|null $search The value being searched for.
     * @param string|array|null $replace The replacement value.
     * @param string|null $subject The string or array being searched and replaced on.
     * @param bool $ignoreCase Whether to ignore case sensitivity.
     * @return string|array The string or array with the replaced values.
     */
    public static function replaceString(string|array|null $search, string|array|null $replace, ?string $subject, bool $ignoreCase = false): string|array {
        if($subject === null || $subject === "") {
            return "";
        }
        if($search === null || $search === "") {
            return $subject;
        }
        if($replace === null) {
            $replace = "";
        }

        return $ignoreCase
            ? str_ireplace($search, $replace, $subject)
            : str_replace($search, $replace, $subject);
    }

    /**
     * Converts the first character of a multibyte string to uppercase.
     *
     * @param string|null $text The input string
     * @return string|null The string with the first character capitalized
     */
    public static function mbUcFirst(?string $text): ?string {
        if ($text === null) {
            return null;
        }

        $firstChar = self::subStr($text, 0, 1);
        $rest = self::subStr($text, 1);

        return self::strToUpper($firstChar) . $rest;
    }

    /**
     * Removes the given prefix from the string if it starts with it.
     *
     * @param string|null $string The string to check
     * @param string|null $prefix The prefix to remove
     * @return string|null Resulting string without the prefix
     */
    public static function removeStringPrefix(?string $string, ?string $prefix): ?string {
        if (empty($string) || empty($prefix)) return $string;

        if (str_starts_with($string, $prefix)) {
            $string = substr_replace($string, "", 0, strlen($prefix));
        }

        return $string;
    }

    /**
     * Function removeStringSuffix.
     * Removes the given suffix from the string if it ends with it.
     *
     * @param string|null $string The string to check
     * @param string|null $suffix The suffix to remove
     * @return string|null Resulting string without the suffix
     */
    public static function removeStringSuffix(?string $string, ?string $suffix): ?string {
        if (empty($string) || empty($suffix)) return $string;

        if (str_starts_with($string, $suffix)) {
            $string = substr_replace($string, "", (-1 * strlen($suffix)));
        }

        return $string;
    }

    /**
     * Generates a unique key string composed of random hash segments, with optional prefix, suffix, and ID column.
     *
     * @param int $segmentLength Number of characters per segment (column)
     * @param int $segmentCount Number of segments (columns) in the final key
     * @param string $separator String used to separate each segment
     * @param string $uniqueId Unique ID to embed in the key (padded and optionally truncated)
     * @param string $prefix Optional prefix to prepend to the key
     * @param string $suffix Optional suffix to append to the key
     * @param bool $ignoreLengthOnId If true, the unique ID will not be truncated to segment length
     *
     * @return string The generated unique key
     */
    public static function generateUniqueKey(
        int $segmentLength = 5,
        int $segmentCount = 5,
        string $separator = '-',
        string $uniqueId = '',
        string $prefix = '',
        string $suffix = '',
        bool $ignoreLengthOnId = true
    ): string {
        $key = '';
        $hash = hash('whirlpool', DateTime::getCurrentFormattedDate('Y-m-d H:i:s') . md5(uniqid((string) rand(), true)) . DateTime::getCurrentFormattedDate('Y-m-d H:i:s'));
        $hashLength = strlen($hash);

        // Sanitize segment length
        if ($segmentLength <= 0 || $segmentLength >= $hashLength) {
            $segmentLength = 5;
        }

        // Sanitize segment count
        if ($segmentCount <= 0) {
            $segmentCount = 5;
        }

        // Normalize optional strings
        $prefix = $prefix !== null && $prefix !== '' ? ($prefix . $separator) : '';
        $suffix = $suffix !== null && $suffix !== '' ? ($suffix . $separator) : '';

        // Prepare unique ID
        $uniqueIdFormatted = '';
        if (!empty($uniqueId)) {
            $uniqueId = str_pad($uniqueId, max($segmentLength, strlen($uniqueId)), '0', STR_PAD_LEFT);
            if (!$ignoreLengthOnId) {
                $uniqueId = substr($uniqueId, 0, $segmentLength);
            }
            $uniqueIdFormatted = $uniqueId . $separator;
        }

        // Assemble key
        for ($i = 0; $i < $segmentCount; $i++) {
            if ($i === 0) {
                $key .= $prefix;
                continue;
            }

            if ($i === 2 && $uniqueIdFormatted !== '') {
                $key .= $uniqueIdFormatted;
                continue;
            }

            if (($i + 1) === $segmentCount && $suffix !== '') {
                $key .= $suffix;
                continue;
            }

            $start = rand(0, $hashLength - $segmentLength);
            $segment = substr($hash, $start, $segmentLength);
            $key .= $segment . $separator;
        }

        // Remove trailing separator
        if (str_ends_with($key, $separator)) {
            $key = substr($key, 0, -strlen($separator));
        }

        return $key;
    }

    /**
     * Generates a globally unique identifier (GUID).
     *
     * On Windows, uses `com_create_guid` if available.
     * On other systems, uses `openssl_random_pseudo_bytes` if available.
     * If neither is available, falls back to a manual GUID generation using MD5 and uniqid.
     *
     * @param bool $trim If true, returns the GUID without curly braces; otherwise includes them
     * @return string The generated GUID
     *
     * @see https://www.php.net/manual/en/function.com-create-guid.php
     */
    public static function generateGuid(bool $trim = true): string {
        // Windows
        if (function_exists('com_create_guid') === true) {
            return $trim ? trim(com_create_guid(), '{}') : com_create_guid();
        }

        // OSX/Linux
        if (function_exists('openssl_random_pseudo_bytes') === true) {
            $data = openssl_random_pseudo_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }

        // Fallback (PHP 4.2+)
        mt_srand((int)(microtime(true) * 10000));
        $charId = Str::strToLower(md5(uniqid((string)rand(), true)));
        $hyphen = '-';
        $leftBrace = $trim ? '' : '{';
        $rightBrace = $trim ? '' : '}';

        return $leftBrace .
            substr($charId, 0, 8) . $hyphen .
            substr($charId, 8, 4) . $hyphen .
            substr($charId, 12, 4) . $hyphen .
            substr($charId, 16, 4) . $hyphen .
            substr($charId, 20, 12) .
            $rightBrace;
    }

    /**
     * Removes all extra whitespace from a string.
     *
     * @param mixed $str The string to be cleaned
     * @param bool $keepSingleSpace If true, keeps only one space where multiple spaces are found. If false, removes all.
     * @return string The cleaned string
     */
    public static function removeExcessSpaces(mixed $str, bool $keepSingleSpace = true): string {
        return empty($str)
            ? ''
            : trim(
                preg_replace(
                    '/([\s\t\n\r])|(<br\s*\/?>)+/i',
                    $keepSingleSpace ? ' ' : '',
                    $str
                )
            );
    }

    /**
     * Converts a string to uppercase using multibyte support.
     *
     * @param string|null $str String to convert
     * @return string|null Converted string or null
     */
    public static function strToUpper(?string $str): ?string {
        return $str === null ? null : mb_strtoupper($str);
    }

    /**
     * Converts a string to lowercase using multibyte support.
     *
     * @param string|null $str String to convert
     * @return string|null Converted string or null
     */
    public static function strToLower(?string $str): ?string {
        return $str === null ? null : mb_strtolower($str);
    }

    /**
     * Trims unnecessary spaces from the beginning and end of the string.
     * Returns empty string if input is null.
     *
     * @param string|null $str String to be trimmed
     * @return string|null Trimmed string or null
     */
    public static function trim(?string $str): ?string {
        return $str === null ? null : trim($str);
    }

    /**
     * Returns the number of characters in a string using multibyte support.
     *
     * @param string|null $str String to measure
     * @return int Length of the string
     */
    public static function strLen(?string $str): int {
        return $str === null ? 0 : mb_strlen($str);
    }

    /**
     * Returns part of a string using multibyte support.
     *
     * @param string|null $str Input string
     * @param int $offset Starting position
     * @param int|null $length Number of characters to extract
     * @return string|null Substring or null
     */
    public static function subStr(?string $str, int $offset = 0, ?int $length = null): ?string {
        return $str === null ? null : mb_substr($str, $offset, $length);
    }

    /**
     * Finds the position of the first occurrence of a substring in a string (case-sensitive).
     *
     * @param string|null $str Input string
     * @param string $strSearch Substring to find
     * @param int $offset Search offset
     * @return int|false Position of the first occurrence or false
     */
    public static function strPos(?string $str, string $strSearch, int $offset = 0): int|false {
        return $str === null ? false : mb_strpos($str, $strSearch, $offset);
    }

    /**
     * Finds the position of the first occurrence of a substring in a string (case-insensitive).
     *
     * @param string|null $str Input string
     * @param string $strSearch Substring to find
     * @param int $offset Search offset
     * @return int|false Position of the first occurrence or false
     */
    public static function strIPos(?string $str, string $strSearch, int $offset = 0): int|false {
        return $str === null ? false : mb_stripos($str, $strSearch, $offset);
    }

    /**
     * Generates all possible contiguous combinations from a string array,
     * using caching and custom comparison to optimize performance.
     *
     * @param string[] $input Input array of strings
     * @param array<string, string[]> &$cache Cache array for memoization (keyed by md5 hash of input)
     *
     * @return string[] List of combined strings, sorted by length descending
     */
    public static function getAdjacentCombinations(array $input, array &$cache = []): array {
        $hash = md5(serialize($input));
        if (isset($cache[$hash])) {
            return $cache[$hash];
        }

        $inputCount = count($input);
        if ($inputCount === 0) {
            return [];
        }

        $combinations = $input;

        // Generate combinations of length > 1
        for ($length = 2; $length <= $inputCount; $length++) {
            for ($i = 0; $i <= $inputCount - $length; $i++) {
                $chunk = [];
                for ($j = $i; $j < $i + $length; $j++) {
                    $chunk[] = $input[$j];
                }

                $combined = implode(' ', $chunk);
                if ($combined !== '') {
                    $combinations[] = $combined;
                }
            }
        }

        // Sort by descending number of words
        usort($combinations, function ($a, $b) {
            $lenA = count(explode(' ', $a));
            $lenB = count(explode(' ', $b));
            return $lenB <=> $lenA;
        });

        $cache[$hash] = $combinations;
        return $combinations;
    }

    /**
     * Truncates a string at the first occurrence of a given substring.
     * If the substring is found, everything from its position onwards will be removed.
     * If the substring is not found, the original string is returned unchanged.
     *
     * @param string|null $str The input string to be truncated
     * @param string|null $occurrence The substring to look for in the input string
     *
     * @return string|null The truncated string if the occurrence is found, otherwise the original string
     */
    public static function truncateAtFirstOccurrence(?string $str, ?string $occurrence): ?string {
        if($str === null || $str === "" || $occurrence === null || $occurrence === "") {
            return $str;
        }
        $pos = strpos($str, $occurrence);

        if ($pos !== false) {
            return substr($str, 0, $pos);
        }
        return $str;
    }

    /**
     * Removes all specified substrings from the main string and returns the result.
     *
     * @param string $input The main string from which substrings will be removed
     * @param array $substringsToRemove An array of substrings to be removed
     * @return string The resulting string with all specified substrings removed
     */
    public static function removeSubstrings(string $input, array $substringsToRemove): string {
        if (empty($substringsToRemove)) {
            return $input;
        }

        foreach ($substringsToRemove as $substring) {
            $input = str_replace($substring, '', $input);
        }

        return $input;
    }

    /**
     * Removes all specified characters from the input string.
     *
     * @param string|null $input The string from which characters will be removed
     * @param string|null $charactersToRemove A list of characters to remove
     * @return string The resulting string with characters removed
     */
    public static function removeCharacters(?string $input, ?string $charactersToRemove): string {
        if ($input === null) {
            return '';
        }
        if ($charactersToRemove === null) {
            return $input;
        }

        return preg_replace(
            sprintf('/[%s]/u', preg_quote($charactersToRemove, '/')),
            '',
            $input
        );
    }

    /**
     * Keeps only the specified characters in the input string and removes all others.
     *
     * @param string|null $input The string to be filtered
     * @param string|null $allowedCharacters The characters to keep in the result
     * @return string The resulting string containing only allowed characters
     */
    public static function keepOnlyCharacters(?string $input, ?string $allowedCharacters): string {
        if ($input === null || $allowedCharacters === null || $allowedCharacters === "") {
            return '';
        }

        return preg_replace(
            sprintf('/[^%s]/u', preg_quote($allowedCharacters, '/')),
            '',
            $input
        );
    }

    /**
     * Returns a string containing only letters (a–z, A–Z) and numbers (0–9), removing all other characters.
     * If the input is null, returns an empty string.
     *
     * @param string|null $input The input string to be filtered
     * @return string The filtered string with only alphanumeric characters
     *
     * @see https://stackoverflow.com/questions/4345621/php-regex-to-allow-letters-and-numbers-only
     */
    public static function onlyLettersAndNumbers(?string $input): string {
        if ($input === null) {
            return '';
        }

        return preg_replace("/[^a-zA-Z0-9]/", "", $input);
    }

    /**
     * Returns a string containing only letters (a–z, A–Z), removing all non-letter characters.
     * If the input is null, returns an empty string.
     *
     * @param string|null $input The input string to be filtered
     * @return string The filtered string with only alphabetic characters
     *
     * @see https://stackoverflow.com/questions/4345621/php-regex-to-allow-letters-and-numbers-only
     */
    public static function onlyLetters(?string $input): string {
        if ($input === null) {
            return '';
        }

        return preg_replace("/[^a-zA-Z]/", "", $input);
    }

    /**
     * Truncates a string to a defined length, preserving UTF-8 characters,
     * and wraps it in a tooltip-enabled span showing the full text on hover.
     *
     * @param string|null $text The text to be truncated
     * @param int $maxLength The maximum display length
     * @param string|null $suffix The string to append to the end (e.g., "...")
     * @return string The truncated HTML string with tooltip
     */
    public static function truncateWithTooltip(?string $text, int $maxLength = 100, ?string $suffix = '...'): string {
        if (empty($text) || empty($maxLength) || $maxLength <= 0) {
            return '';
        }
        if (empty($suffix)) {
            $suffix = '';
        }

        // Could use strip_tags or htmlentities
        $text = addslashes(strip_tags($text));

        if (mb_strwidth($text, 'UTF-8') <= $maxLength) {
            return '<span class="tooltip_ativo" title="' . $text . '">' . $text . '</span>';
        }

        $shortened = rtrim(mb_strimwidth($text, 0, $maxLength, '', 'UTF-8'));

        return '<span class="tooltip_ativo" title="' . $text . '">' . $shortened . $suffix . '</span>';
    }

    /**
     * Converts a string to camelCase format, optionally preserving words in a given ignore list.
     *
     * @param string|null $input The string to convert
     * @param array $preserveWords Words to preserve without altering (optional)
     * @return string The camelCased version of the string
     *
     * @see https://stackoverflow.com/questions/34597643/how-can-i-camelcase-a-string-in-php
     */
    public static function toCamelCase(?string $input, array $preserveWords = []): string {
        if (empty($input)) {
            return '';
        }

        $input = self::decodeText($input);

        // Replace special characters with spaces, capitalize, and remove spaces
        return lcfirst(
            str_replace(
                ' ',
                '',
                ucwords(
                    preg_replace(
                        '/[^a-z0-9' . implode('', $preserveWords) . ']+/i',
                        ' ',
                        $input
                    )
                )
            )
        );
    }


    /**
     * Immediately sends a string to the browser before the page finishes processing.
     * Useful for streaming logs or keeping long-running requests responsive.
     *
     * @param string $message The string to be printed to the output
     * @param int $sleepSeconds Optional: seconds to sleep after flushing (default: 0)
     * @return void
     *
     * @see https://www.php.net/manual/en/function.flush.php
     */
    public static function flushOutput(string $message, int $sleepSeconds = 0): void {
        if ($sleepSeconds < 0) {
            $sleepSeconds = 0;
        }

        if (ob_get_level() === 0) {
            ob_start();
        }

        echo $message;
        echo str_pad('', 4096) . "\n"; // Forces buffer flush on some servers

        ob_flush();
        flush();

        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }

        ob_end_flush();
    }

    /**
     * Checks if a string contains exactly the specified search word.
     *
     * @param string|null $text The text to search in.
     * @param string|null $word The word to search for.
     * @param bool $caseSensitive Whether to use case sensitivity.
     * @return bool True if the word is found as a standalone word, false otherwise.
     *
     * @see https://stackoverflow.com/questions/4366730/how-do-i-check-if-a-string-contains-a-specific-word
     */
    public static function containsExactWord(?string $text, ?string $word, bool $caseSensitive = true): bool {
        if($word === null || $word === "") {
            return true;
        }
        if($text === null || $text === "") {
            return false;
        }

        return (bool) preg_match(
            '#\b' . preg_quote($word, '#') . '\b#' . (!$caseSensitive ? 'i' : ''),
            $text
        );
    }

    /**
     * Removes accent characters from a UTF-8 string.
     *
     * Replaces Latin-1 Supplement and Latin Extended-A characters with their ASCII equivalents.
     *
     * @param string $text The input string with possible accented characters
     * @return string The cleaned string with accents removed
     *
     * @link https://stackoverflow.com/questions/1017599/how-do-i-remove-accents-from-characters-in-a-php-string
     */
    public static function removeAccents(string $text): string {
        if ($text === '' || !preg_match('/[\x80-\xff]/', $text)) {
            return $text;
        }

        return strtr(
            $text,
            array(
                // Decompositions for Latin-1 Supplement
                chr(195).chr(128) => 'A', chr(195).chr(129) => 'A',
                chr(195).chr(130) => 'A', chr(195).chr(131) => 'A',
                chr(195).chr(132) => 'A', chr(195).chr(133) => 'A',
                chr(195).chr(135) => 'C', chr(195).chr(136) => 'E',
                chr(195).chr(137) => 'E', chr(195).chr(138) => 'E',
                chr(195).chr(139) => 'E', chr(195).chr(140) => 'I',
                chr(195).chr(141) => 'I', chr(195).chr(142) => 'I',
                chr(195).chr(143) => 'I', chr(195).chr(145) => 'N',
                chr(195).chr(146) => 'O', chr(195).chr(147) => 'O',
                chr(195).chr(148) => 'O', chr(195).chr(149) => 'O',
                chr(195).chr(150) => 'O', chr(195).chr(153) => 'U',
                chr(195).chr(154) => 'U', chr(195).chr(155) => 'U',
                chr(195).chr(156) => 'U', chr(195).chr(157) => 'Y',
                chr(195).chr(159) => 's', chr(195).chr(160) => 'a',
                chr(195).chr(161) => 'a', chr(195).chr(162) => 'a',
                chr(195).chr(163) => 'a', chr(195).chr(164) => 'a',
                chr(195).chr(165) => 'a', chr(195).chr(167) => 'c',
                chr(195).chr(168) => 'e', chr(195).chr(169) => 'e',
                chr(195).chr(170) => 'e', chr(195).chr(171) => 'e',
                chr(195).chr(172) => 'i', chr(195).chr(173) => 'i',
                chr(195).chr(174) => 'i', chr(195).chr(175) => 'i',
                chr(195).chr(177) => 'n', chr(195).chr(178) => 'o',
                chr(195).chr(179) => 'o', chr(195).chr(180) => 'o',
                chr(195).chr(181) => 'o', chr(195).chr(182) => 'o',
                chr(195).chr(182) => 'o', chr(195).chr(185) => 'u',
                chr(195).chr(186) => 'u', chr(195).chr(187) => 'u',
                chr(195).chr(188) => 'u', chr(195).chr(189) => 'y',
                chr(195).chr(191) => 'y',

                // Decompositions for Latin Extended-A
                chr(196).chr(128) => 'A', chr(196).chr(129) => 'a',
                chr(196).chr(130) => 'A', chr(196).chr(131) => 'a',
                chr(196).chr(132) => 'A', chr(196).chr(133) => 'a',
                chr(196).chr(134) => 'C', chr(196).chr(135) => 'c',
                chr(196).chr(136) => 'C', chr(196).chr(137) => 'c',
                chr(196).chr(138) => 'C', chr(196).chr(139) => 'c',
                chr(196).chr(140) => 'C', chr(196).chr(141) => 'c',
                chr(196).chr(142) => 'D', chr(196).chr(143) => 'd',
                chr(196).chr(144) => 'D', chr(196).chr(145) => 'd',
                chr(196).chr(146) => 'E', chr(196).chr(147) => 'e',
                chr(196).chr(148) => 'E', chr(196).chr(149) => 'e',
                chr(196).chr(150) => 'E', chr(196).chr(151) => 'e',
                chr(196).chr(152) => 'E', chr(196).chr(153) => 'e',
                chr(196).chr(154) => 'E', chr(196).chr(155) => 'e',
                chr(196).chr(156) => 'G', chr(196).chr(157) => 'g',
                chr(196).chr(158) => 'G', chr(196).chr(159) => 'g',
                chr(196).chr(160) => 'G', chr(196).chr(161) => 'g',
                chr(196).chr(162) => 'G', chr(196).chr(163) => 'g',
                chr(196).chr(164) => 'H', chr(196).chr(165) => 'h',
                chr(196).chr(166) => 'H', chr(196).chr(167) => 'h',
                chr(196).chr(168) => 'I', chr(196).chr(169) => 'i',
                chr(196).chr(170) => 'I', chr(196).chr(171) => 'i',
                chr(196).chr(172) => 'I', chr(196).chr(173) => 'i',
                chr(196).chr(174) => 'I', chr(196).chr(175) => 'i',
                chr(196).chr(176) => 'I', chr(196).chr(177) => 'i',
                chr(196).chr(178) => 'IJ',chr(196).chr(179) => 'ij',
                chr(196).chr(180) => 'J', chr(196).chr(181) => 'j',
                chr(196).chr(182) => 'K', chr(196).chr(183) => 'k',
                chr(196).chr(184) => 'k', chr(196).chr(185) => 'L',
                chr(196).chr(186) => 'l', chr(196).chr(187) => 'L',
                chr(196).chr(188) => 'l', chr(196).chr(189) => 'L',
                chr(196).chr(190) => 'l', chr(196).chr(191) => 'L',
                chr(197).chr(128) => 'l', chr(197).chr(129) => 'L',
                chr(197).chr(130) => 'l', chr(197).chr(131) => 'N',
                chr(197).chr(132) => 'n', chr(197).chr(133) => 'N',
                chr(197).chr(134) => 'n', chr(197).chr(135) => 'N',
                chr(197).chr(136) => 'n', chr(197).chr(137) => 'N',
                chr(197).chr(138) => 'n', chr(197).chr(139) => 'N',
                chr(197).chr(140) => 'O', chr(197).chr(141) => 'o',
                chr(197).chr(142) => 'O', chr(197).chr(143) => 'o',
                chr(197).chr(144) => 'O', chr(197).chr(145) => 'o',
                chr(197).chr(146) => 'OE',chr(197).chr(147) => 'oe',
                chr(197).chr(148) => 'R',chr(197).chr(149) => 'r',
                chr(197).chr(150) => 'R',chr(197).chr(151) => 'r',
                chr(197).chr(152) => 'R',chr(197).chr(153) => 'r',
                chr(197).chr(154) => 'S',chr(197).chr(155) => 's',
                chr(197).chr(156) => 'S',chr(197).chr(157) => 's',
                chr(197).chr(158) => 'S',chr(197).chr(159) => 's',
                chr(197).chr(160) => 'S', chr(197).chr(161) => 's',
                chr(197).chr(162) => 'T', chr(197).chr(163) => 't',
                chr(197).chr(164) => 'T', chr(197).chr(165) => 't',
                chr(197).chr(166) => 'T', chr(197).chr(167) => 't',
                chr(197).chr(168) => 'U', chr(197).chr(169) => 'u',
                chr(197).chr(170) => 'U', chr(197).chr(171) => 'u',
                chr(197).chr(172) => 'U', chr(197).chr(173) => 'u',
                chr(197).chr(174) => 'U', chr(197).chr(175) => 'u',
                chr(197).chr(176) => 'U', chr(197).chr(177) => 'u',
                chr(197).chr(178) => 'U', chr(197).chr(179) => 'u',
                chr(197).chr(180) => 'W', chr(197).chr(181) => 'w',
                chr(197).chr(182) => 'Y', chr(197).chr(183) => 'y',
                chr(197).chr(184) => 'Y', chr(197).chr(185) => 'Z',
                chr(197).chr(186) => 'z', chr(197).chr(187) => 'Z',
                chr(197).chr(188) => 'z', chr(197).chr(189) => 'Z',
                chr(197).chr(190) => 'z', chr(197).chr(191) => 's'
            )
        );
    }

    /**
     * Recursively finds all positions of a substring within a string.
     *
     * @param string $haystack The full string to search within
     * @param string $needle The substring to search for
     * @param bool $returnEndPosition If true, returns position + strlen($needle); otherwise, returns match start position
     * @param bool $caseSensitive If false, the search will be case-insensitive
     * @param int $offset Internal offset pointer (used by recursion)
     * @param array $results Internal result accumulator (used by recursion)
     *
     * @return array List of match positions found
     *
     * @ref https://gist.github.com/hassanjamal/6559484
     */
    public static function findAllOccurrences(
        string $haystack,
        string $needle,
        bool $returnEndPosition = false,
        bool $caseSensitive = true,
        int $offset = 0,
        array &$results = []
    ): array {
        if (empty($haystack) || empty($needle)) {
            return [];
        }

        $foundOffset = false;
        if($caseSensitive) {
            $foundOffset = self::strPos($haystack, $needle, $offset);
        } else {
            $foundOffset = self::strIPos($haystack, $needle, $offset);
        }
        if ($foundOffset === false) {
            return $results;
        }

        $results[] = $returnEndPosition
            ? ($foundOffset + self::strLen($needle))
            : $foundOffset;

        return self::findAllOccurrences(
            $haystack,
            $needle,
            $returnEndPosition,
            $caseSensitive,
            ($foundOffset + self::strLen($needle)),
            $results
        );
    }

    /**
     * Extracts all substrings found between two delimiters in a given string.
     *
     * This function identifies all segments in the input string that are enclosed
     * between the specified $startDelimiter and $endDelimiter. You can optionally
     * include the delimiters in the returned substrings.
     *
     * @param string|null $input The full string to search within
     * @param string|null $startDelimiter The starting delimiter
     * @param string|null $endDelimiter The ending delimiter
     * @param bool $caseSensitive If false, the search will be case-insensitive
     * @param bool $includeDelimiters If true, includes the start and end delimiters in the results
     *
     * @return array An array of all substrings found between the delimiters
     */
    public static function extractSubstringsBetween(
        ?string $input,
        ?string $startDelimiter,
        ?string $endDelimiter,
        bool $caseSensitive = true,
        bool $includeDelimiters = false
    ): array {
        if (empty($input) || empty($startDelimiter) || empty($endDelimiter)) {
            return [];
        }

        $results = [];
        $offset = 0;
        while (($startPos = $caseSensitive ? self::strPos($input, $startDelimiter, $offset) : self::strIPos($input, $startDelimiter, $offset)) !== false) {
            $startPos += self::strLen($startDelimiter);

            $endPos = ($caseSensitive ? self::strPos($input, $endDelimiter, $startPos) : self::strIPos($input, $endDelimiter, $startPos));
            if ($endPos === false) {
                break;
            }

            $extracted = self::subStr($input, $startPos, $endPos - $startPos);
            if ($includeDelimiters) {
                $extracted = $startDelimiter . $extracted . $endDelimiter;
            }

            $results[] = $extracted;
            $offset = $endPos + self::strLen($endDelimiter);
        }

        return $results;
    }
}