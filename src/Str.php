<?php

namespace VD\PHPHelper;

/**
 * Static string helpers.
 *
 * Conventions that hold class-wide, so individual methods need not repeat them:
 * - Every method is static and stateless. The only method with side effects is flushOutput().
 * - Methods documented as multibyte assume valid UTF-8 input. Methods documented as byte-based
 *   operate on raw bytes and are safe on binary/non-UTF-8 data.
 * - NOTHING here is contextual output escaping. removeInvisibleCharacters(), removeCharacters(),
 *   keepOnlyCharacters(), onlyLetters(), onlyNumbers() and friends REMOVE characters; they are
 *   not HTML/JS/SQL/shell escaping and must not be relied on as an injection defense.
 *   truncateWithTooltip() is the single method that emits HTML, and it encodes what it emits.
 */
class Str {
    /**
     * Removes invisible (control) characters from a string.
     *
     * Strips every ASCII control character except newline (10), carriage return (13) and
     * horizontal tab (9) — i.e. 00-08, 11, 12, 14-31 and 127. Stripping repeats until a pass
     * removes nothing, so characters sandwiched to reassemble after one pass (the classic
     * "Java\0script" trick) cannot survive.
     *
     * Byte-based: safe on invalid UTF-8. Removal only — this is NOT an XSS defense on its own.
     *
     * @param string|null $str String to clean. Both null and "" yield "".
     * @param bool $urlEncoded If true, ALSO strips the URL-encoded forms (%00-%08, %0b, %0c,
     *                         %0e, %0f, %10-%1f). The input is neither decoded nor encoded.
     *
     * @return string The cleaned string; "" when $str is null or "".
     */
    public static function removeInvisibleCharacters(?string $str, bool $urlEncoded = false): string
    {
        if ($str === null || $str === '') {
            return '';
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
     * Returns a string containing only the ASCII digits 0-9 of the input.
     *
     * Everything else is dropped, INCLUDING the sign and the decimal separator: "-12.50" becomes
     * "1250". This extracts digits (document numbers, phone numbers); it does not parse numbers.
     * Non-ASCII digits (e.g. Arabic-Indic) are not recognized and are removed.
     *
     * @param string|null $str The input string to extract digits from. Null yields "".
     * @return string The digits of $str, in order; "" when there are none.
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
     * Checks if one string contains another, anywhere (no word-boundary rule — see
     * containsExactWord() for that).
     *
     * @param string|null $text The string to be searched. Null or "" returns false.
     * @param string|null $search The string to search for. Null or "" returns FALSE: a blank
     *                            needle is never reported as contained, so a blank term cannot
     *                            match everything.
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
     * Replaces all occurrences of the search value(s) in $subject.
     *
     * @param string|array|null $search The value(s) being searched for. Null or "" returns
     *                                  $subject unchanged.
     * @param string|array|null $replace The replacement value(s). Null is treated as "".
     *                                   When $search is an array and $replace is a shorter
     *                                   array, the surplus searches are replaced with ""
     *                                   (str_replace semantics).
     * @param string|null $subject The string being searched and replaced on. Unlike the native
     *                             str_replace(), an ARRAY subject is NOT accepted here and
     *                             raises a TypeError at the call site. Null or "" returns "".
     * @param bool $ignoreCase If true, matches case-insensitively (str_ireplace).
     * @return string The string with the replaced values. Never an array: a string subject
     *                can only produce a string.
     */
    public static function replaceString(string|array|null $search, string|array|null $replace, ?string $subject, bool $ignoreCase = false): string {
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
     * @return string|null The string with the first character capitalized; null when $text is
     *                     null, "" when $text is "".
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
     * Byte-based and case-sensitive. Only ONE occurrence is removed: "aab" minus prefix "a" is
     * "ab", not "b".
     *
     * @param string|null $string The string to check
     * @param string|null $prefix The prefix to remove. Null or "" removes nothing.
     * @return string|null Resulting string without the prefix, or $string unchanged (including
     *                     null) when it does not start with $prefix.
     */
    public static function removeStringPrefix(?string $string, ?string $prefix): ?string {
        if ($string === null || $string === '' || $prefix === null || $prefix === '') return $string;

        if (str_starts_with($string, $prefix)) {
            $string = substr_replace($string, "", 0, strlen($prefix));
        }

        return $string;
    }

    /**
     * Removes the given suffix from the string if it ends with it.
     *
     * Byte-based and case-sensitive. Only ONE occurrence is removed: "abb" minus suffix "b" is
     * "ab", not "a".
     *
     * @param string|null $string The string to check
     * @param string|null $suffix The suffix to remove. Null or "" removes nothing.
     * @return string|null Resulting string without the suffix, or $string unchanged (including
     *                     null) when it does not end with $suffix.
     */
    public static function removeStringSuffix(?string $string, ?string $suffix): ?string {
        if ($string === null || $string === '' || $suffix === null || $suffix === '') return $string;

        if (str_ends_with($string, $suffix)) {
            $string = substr_replace($string, "", (-1 * strlen($suffix)));
        }

        return $string;
    }

    /**
     * Generates a key made of random hash segments, with an optional prefix, unique ID and suffix.
     *
     * Layout, joined by $separator:
     *     [$prefix] segment1 [$uniqueId] segment2 ... segmentN [$suffix]
     * $prefix, $uniqueId and $suffix are EXTRA parts: none of them ever consumes one of the
     * $segmentCount random segments, and none is ever silently dropped. The key therefore always
     * carries exactly $segmentCount random segments, whichever optional parts are supplied.
     *
     * NOT cryptographically secure. Every segment is cut at a rand() offset out of ONE whirlpool
     * hash of the current time + uniqid(), so segments within a key may repeat or overlap and the
     * whole key is only as unpredictable as rand()/uniqid(). Use it for readable identifiers —
     * never as a token, session ID, password-reset key or any value that must be unguessable.
     * For those use random_bytes()/generateGuid().
     *
     * @param int $segmentLength Characters per random segment. Must be 1..127; ANY other value
     *                           (including a value >= the 128-char hash length) silently falls
     *                           back to 5.
     * @param int $segmentCount Number of RANDOM segments. Must be >= 1; any other value silently
     *                          falls back to 5.
     * @param string $separator Placed between every part. "" yields a key with no separators.
     * @param string $uniqueId Value embedded as its own part directly after the first random
     *                         segment; "" means no ID. It is left-padded with "0" up to
     *                         $segmentLength characters, and is never dropped for any
     *                         $segmentCount.
     * @param string $prefix Prepended as the first part; "" means none.
     * @param string $suffix Appended as the last part; "" means none.
     * @param bool $ignoreLengthOnId If false, a $uniqueId longer than $segmentLength is truncated
     *                               to its FIRST $segmentLength characters — which can make two
     *                               different IDs produce the same key part. If true (default),
     *                               the ID is embedded whole and the key grows instead.
     *
     * @return string The generated key.
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

        // Prepare unique ID. Compared against "" rather than empty(), so the legitimate ID "0"
        // is embedded instead of silently discarded.
        $uniqueIdFormatted = '';
        if ($uniqueId !== '') {
            $uniqueIdFormatted = str_pad($uniqueId, max($segmentLength, strlen($uniqueId)), '0', STR_PAD_LEFT);
            if (!$ignoreLengthOnId) {
                $uniqueIdFormatted = substr($uniqueIdFormatted, 0, $segmentLength);
            }
        }

        // Assemble the parts. The optional parts are appended positionally instead of competing
        // for a loop index, so no part can be dropped and $segmentCount always means what it says.
        $parts = [];
        if ($prefix !== '') {
            $parts[] = $prefix;
        }

        for ($i = 0; $i < $segmentCount; $i++) {
            $start = rand(0, $hashLength - $segmentLength);
            $parts[] = substr($hash, $start, $segmentLength);

            if ($i === 0 && $uniqueIdFormatted !== '') {
                $parts[] = $uniqueIdFormatted;
            }
        }

        if ($suffix !== '') {
            $parts[] = $suffix;
        }

        return implode($separator, $parts);
    }

    /**
     * Generates a globally unique identifier (GUID).
     *
     * On Windows, uses `com_create_guid` if available.
     * On other systems, uses `openssl_random_pseudo_bytes` if available (a proper random,
     * RFC 4122 version-4 UUID).
     * If neither is available, falls back to a manual GUID built from md5(uniqid()). That
     * fallback is NOT cryptographically secure and is NOT a version-4 UUID (no version/variant
     * bits): it is a last resort for exotic hosts, not a source of secrets. When you need an
     * unguessable value, verify openssl is present or use random_bytes() directly.
     *
     * $trim is honored identically on all three paths.
     *
     * @param bool $trim If true (default), returns the GUID bare: "xxxxxxxx-xxxx-...-xxxxxxxxxxxx".
     *                   If false, returns it wrapped in curly braces: "{xxxxxxxx-...}".
     * @return string The generated GUID, 36 characters bare or 38 with braces.
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

            $guid = vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));

            return $trim ? $guid : '{' . $guid . '}';
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
     * Collapses runs of whitespace (and <br> tags) and trims the ends.
     *
     * A run of ANY mix of whitespace characters (space, tab, newline, CR, ...) and <br>/<br />
     * tags is treated as ONE run and replaced in a single shot, so "a \n <br> b" becomes "a b" —
     * not "a    b". The result is then trimmed at both ends.
     *
     * @param mixed $str The value to be cleaned. Scalars and Stringable objects are cast to
     *                   string; null, arrays and any other non-stringable value yield "".
     *                   Note "0" is a legitimate string and is returned as "0".
     * @param bool $keepSingleSpace If true (default), each run collapses to a SINGLE space.
     *                              If false, runs are deleted outright ("a b" -> "ab").
     * @return string The cleaned string; "" when there is nothing stringable to clean.
     */
    public static function removeExcessSpaces(mixed $str, bool $keepSingleSpace = true): string {
        if (is_scalar($str) || $str instanceof \Stringable) {
            $str = (string) $str;
        } else {
            return '';
        }

        if ($str === '') {
            return '';
        }

        // The quantifier must cover the whole alternation: with the run captured as one match,
        // N whitespace characters collapse to one space instead of being replaced one-for-one.
        return trim(
            preg_replace(
                '/(?:\s|<br\s*\/?>)+/i',
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
     * Trims whitespace from the beginning and end of the string.
     *
     * Null in, null out — this method preserves the difference between "absent" (null) and
     * "blank" (""), it does NOT normalize null to "". A caller checking a required field must
     * therefore test for both, e.g. `if (($v = Str::trim($in)) === null || $v === '')`.
     *
     * Trims the native trim() set " \t\n\r\0\x0B" only; it is byte-based and does not strip
     * Unicode whitespace such as U+00A0.
     *
     * @param string|null $str String to be trimmed
     * @return string|null Trimmed string, or null when $str is null
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
     * @param int $offset Starting position in CHARACTERS; negative counts from the end
     * @param int|null $length Number of characters to extract; null means "to the end"
     * @return string|null Substring, or null when $str is null
     */
    public static function subStr(?string $str, int $offset = 0, ?int $length = null): ?string {
        return $str === null ? null : mb_substr($str, $offset, $length);
    }

    /**
     * Finds the position of the first occurrence of a substring in a string (case-sensitive).
     *
     * @param string|null $str Input string; null returns false
     * @param string $strSearch Substring to find
     * @param int $offset Search offset in CHARACTERS; negative counts from the end
     * @return int|false Character position of the first occurrence, or false when not found
     *                   or when $str is null
     *
     * @throws \ValueError If $offset lies outside $str (mb_strpos rejects it). This is an
     *                     uncaught \Error subclass, not an \Exception — guard the offset.
     */
    public static function strPos(?string $str, string $strSearch, int $offset = 0): int|false {
        return $str === null ? false : mb_strpos($str, $strSearch, $offset);
    }

    /**
     * Finds the position of the first occurrence of a substring in a string (case-insensitive).
     *
     * @param string|null $str Input string; null returns false
     * @param string $strSearch Substring to find
     * @param int $offset Search offset in CHARACTERS; negative counts from the end
     * @return int|false Character position of the first occurrence, or false when not found
     *                   or when $str is null
     *
     * @throws \ValueError If $offset lies outside $str (mb_stripos rejects it). This is an
     *                     uncaught \Error subclass, not an \Exception — guard the offset.
     */
    public static function strIPos(?string $str, string $strSearch, int $offset = 0): int|false {
        return $str === null ? false : mb_stripos($str, $strSearch, $offset);
    }

    /**
     * Generates every contiguous combination of the given words, longest phrase first.
     *
     * The result contains each original element plus every space-joined run of 2..N adjacent
     * elements. For ['a','b','c'] that is: 'a b c', 'a b', 'b c', 'a', 'b', 'c'.
     *
     * Sorted by WORD COUNT descending — NOT by character length. A 2-word combination therefore
     * precedes a longer 1-word string: for ['aaaaaaaaaaaa','b','c'], 'b c' (3 chars) comes before
     * 'aaaaaaaaaaaa' (12 chars). If you are driving greedy longest-match replacement and need the
     * character-longest candidate first, re-sort the result yourself. Ties keep their relative
     * order (PHP's sort is stable), so equal-word-count entries stay in generation order.
     *
     * @param string[] $input Input array of strings, in order. An empty array returns [] and is
     *                        not cached.
     * @param array<string, string[]> &$cache Memoization store, keyed by md5(serialize($input)),
     *                                        passed BY REFERENCE and populated on each miss. Pass
     *                                        the same variable across calls to reuse it; it is
     *                                        never invalidated, so a stale entry is returned as-is.
     *
     * @return string[] List of combined strings, sorted by word count descending
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

        // Sort by descending number of words (NOT character length - see the docblock).
        usort($combinations, function ($a, $b) {
            $wordsA = count(explode(' ', $a));
            $wordsB = count(explode(' ', $b));
            return $wordsB <=> $wordsA;
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
     * Byte-based, case-sensitive, and applied IN ORDER: each substring is removed from the RESULT
     * of the previous removal, so a removal can create a new match for a later entry and the
     * order matters. Removing ['b', 'ac'] from "abc" yields "" (dropping "b" creates "ac"), while
     * removing ['ac', 'b'] from "abc" yields "ac".
     *
     * @param string $input The main string from which substrings will be removed
     * @param string[] $substringsToRemove Substrings to remove. An empty array returns $input
     *                                     unchanged. Entries must be strings; a null entry is
     *                                     deprecated in PHP 8.1+ and will raise a deprecation.
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
     * Removes all occurrences of the given characters from the input string.
     *
     * $charactersToRemove is a SET OF INDIVIDUAL CHARACTERS, not a substring and not a regex:
     * removeCharacters('a-b', '-') removes the dash, and removeCharacters('abc', 'a-c') removes
     * literal 'a', '-' and 'c' — never the range a..c. Every character is escaped before use.
     *
     * Multibyte (UTF-8): $input and $charactersToRemove must both be valid UTF-8.
     *
     * @param string|null $input The string from which characters will be removed. Null yields "".
     * @param string|null $charactersToRemove The characters to remove. Null or "" removes nothing
     *                                        and returns $input unchanged — an empty list is a
     *                                        no-op, not an error.
     * @return string The resulting string with those characters removed.
     *
     * @throws \InvalidArgumentException If the arguments are not valid UTF-8 (or the regex engine
     *                                   otherwise fails). Thrown deliberately: without it PCRE
     *                                   returns null here and the string return type raises a
     *                                   TypeError, which `catch (\Exception)` does NOT catch.
     */
    public static function removeCharacters(?string $input, ?string $charactersToRemove): string {
        if ($input === null) {
            return '';
        }
        if ($charactersToRemove === null || $charactersToRemove === '') {
            return $input;
        }

        $result = preg_replace(
            sprintf('/[%s]/u', preg_quote($charactersToRemove, '/')),
            '',
            $input
        );

        if ($result === null) {
            throw new \InvalidArgumentException(
                'Str::removeCharacters() failed: ' . preg_last_error_msg() . '. Both arguments must be valid UTF-8.'
            );
        }

        return $result;
    }

    /**
     * Keeps only the specified characters in the input string and removes all others.
     *
     * $allowedCharacters is a SET OF INDIVIDUAL CHARACTERS, not a regex: every character is
     * escaped before use, so 'a-c' allows literal 'a', '-' and 'c', never the range a..c.
     *
     * Note the deliberate asymmetry with removeCharacters(): there, an empty list means "remove
     * nothing" and returns the input; here, an empty list means "nothing is allowed" and returns
     * "". Both follow from an empty set, and both are allowlist-safe (an empty allowlist can only
     * ever deny, never pass input through unfiltered).
     *
     * Multibyte (UTF-8): $input and $allowedCharacters must both be valid UTF-8.
     *
     * @param string|null $input The string to be filtered. Null yields "".
     * @param string|null $allowedCharacters The characters to keep. Null or "" yields "".
     * @return string The resulting string containing only allowed characters.
     *
     * @throws \InvalidArgumentException If the arguments are not valid UTF-8 (or the regex engine
     *                                   otherwise fails) — see removeCharacters() for why this is
     *                                   thrown rather than left to become a TypeError.
     */
    public static function keepOnlyCharacters(?string $input, ?string $allowedCharacters): string {
        if ($input === null || $allowedCharacters === null || $allowedCharacters === "") {
            return '';
        }

        $result = preg_replace(
            sprintf('/[^%s]/u', preg_quote($allowedCharacters, '/')),
            '',
            $input
        );

        if ($result === null) {
            throw new \InvalidArgumentException(
                'Str::keepOnlyCharacters() failed: ' . preg_last_error_msg() . '. Both arguments must be valid UTF-8.'
            );
        }

        return $result;
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
     * Truncates a string to a defined display width, preserving UTF-8 characters, and wraps it in
     * a span whose title attribute carries the full text.
     *
     * Returns HTML. Any markup in $text is stripped (strip_tags) and the result is HTML-encoded
     * with ENT_QUOTES for both the body and the title attribute, so $text may be untrusted. The
     * surrounding markup is fixed and not caller-controlled. $suffix is encoded the same way, so
     * it CANNOT be used to inject markup — pass "&hellip;" and you will see the literal text.
     *
     * @param string|null $text The text to be truncated. Null or "" yields "" (no span at all).
     *                          Note "0" is real content and IS rendered.
     * @param int $maxLength Maximum display WIDTH (mb_strwidth: East-Asian wide characters count
     *                       as 2), not a character count. Zero or negative yields "".
     * @param string|null $suffix Appended (inside the span) only when the text was actually
     *                            truncated. Null means none. It is NOT counted against
     *                            $maxLength, so the rendered result can exceed $maxLength.
     * @return string The HTML span, or "" when there is nothing to render.
     */
    public static function truncateWithTooltip(?string $text, int $maxLength = 100, ?string $suffix = '...'): string {
        if ($text === null || $text === '' || $maxLength <= 0) {
            return '';
        }
        if ($suffix === null) {
            $suffix = '';
        }

        // Work on tag-stripped plain text; HTML-encode ONLY at output. addslashes is NOT
        // HTML-attribute encoding, so the old code allowed attribute-breakout XSS via a
        // payload like: cliente" onmouseover=alert(1) (no angle brackets to strip).
        $text = strip_tags($text);
        $encFull = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $encSuffix = htmlspecialchars($suffix, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (mb_strwidth($text, 'UTF-8') <= $maxLength) {
            return '<span class="tooltip_ativo" title="' . $encFull . '">' . $encFull . '</span>';
        }

        $shortened = rtrim(mb_strimwidth($text, 0, $maxLength, '', 'UTF-8'));
        $encShort = htmlspecialchars($shortened, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return '<span class="tooltip_ativo" title="' . $encFull . '">' . $encShort . $encSuffix . '</span>';
    }

    /**
     * Converts a string to camelCase.
     *
     * Every run of characters that is neither a-z, A-Z nor 0-9 becomes a word break; each word is
     * then capitalized and the breaks are removed ("hello world-foo" -> "helloWorldFoo").
     * Unicode escape sequences are decoded first (see decodeText), and accented letters are NOT
     * alphanumeric here, so they act as word breaks and are dropped — run removeAccents() first
     * if you need them folded to ASCII instead.
     *
     * $preserveCharacters preserves CHARACTERS, NOT WORDS. Each entry contributes its individual
     * characters to the "is a word character" set, globally. Passing ['order_id'] does not keep
     * the word "order_id" intact — it merely adds o, r, d, e, _, i to the set, so EVERY
     * underscore anywhere in $input survives. Passing an ordinary word like ['bar'] does nothing
     * at all, because b, a and r are already word characters. To keep a whole word verbatim,
     * split the input and handle that word yourself; this parameter cannot do it.
     *
     * @param string|null $input The string to convert. Null or "" yields "". Note "0" is real
     *                           content and is returned as "0".
     * @param array $preserveCharacters Additional characters to treat as word characters, given
     *                                  as one or more strings whose characters are unioned. Each
     *                                  entry is escaped, so regex metacharacters are taken
     *                                  literally and cannot alter the pattern.
     * @return string The camelCased version of the string.
     *
     * @see https://stackoverflow.com/questions/34597643/how-can-i-camelcase-a-string-in-php
     */
    public static function toCamelCase(?string $input, array $preserveCharacters = []): string {
        if ($input === null || $input === '') {
            return '';
        }

        $input = self::decodeText($input);

        // Escape each entry before splicing it into the character class: an unescaped
        // metacharacter (a lone backslash, say) silently corrupts the pattern, and preg_replace
        // then returns null, which used to blank the whole string.
        $preserved = '';
        foreach ($preserveCharacters as $preserveCharacter) {
            $preserved .= preg_quote((string) $preserveCharacter, '/');
        }

        // Replace special characters with spaces, capitalize, and remove spaces
        return lcfirst(
            str_replace(
                ' ',
                '',
                ucwords(
                    preg_replace(
                        '/[^a-z0-9' . $preserved . ']+/i',
                        ' ',
                        $input
                    )
                )
            )
        );
    }


    /**
     * Immediately sends a string to the output before the page finishes processing.
     * Useful for streaming logs or keeping long-running requests responsive.
     *
     * Emits $message VERBATIM followed by 4096 spaces and a newline — padding that forces the
     * proxy/server buffers of some stacks to release the response. No escaping is applied: a
     * caller writing to an HTML page must escape $message itself.
     *
     * Output buffering: if no buffer is active, one is opened and closed here. If the CALLER
     * already has a buffer open, this method flushes it but leaves it open and does not change
     * the buffering level.
     *
     * @param string $message The string to be printed to the output
     * @param int $sleepSeconds Seconds to sleep after flushing (default 0). Negative values are
     *                          clamped to 0. This blocks the request for that long.
     * @return void
     *
     * @see https://www.php.net/manual/en/function.flush.php
     */
    public static function flushOutput(string $message, int $sleepSeconds = 0): void {
        if ($sleepSeconds < 0) {
            $sleepSeconds = 0;
        }

        // Only close what we opened: ob_end_flush()-ing unconditionally would pop the CALLER's
        // buffer, silently releasing output they were still holding.
        $bufferStartedHere = false;
        if (ob_get_level() === 0) {
            ob_start();
            $bufferStartedHere = true;
        }

        echo $message;
        echo str_pad('', 4096) . "\n"; // Forces buffer flush on some servers

        ob_flush();
        flush();

        if ($sleepSeconds > 0) {
            sleep($sleepSeconds);
        }

        if ($bufferStartedHere) {
            ob_end_flush();
        }
    }

    /**
     * Checks whether $text contains $word as a standalone word.
     *
     * "Standalone" is PCRE's \b word boundary, so the word must not be flanked by [A-Za-z0-9_]:
     * containsExactWord('hello world', 'world') is true, containsExactWord('helloworld', 'world')
     * is false. CAVEAT: \b is defined between a word and a non-word character, so a $word that
     * begins or ends with a non-word character never matches — containsExactWord('a c++ b', 'c++')
     * is FALSE, because "+" followed by " " is not a boundary. Use containsString() for those.
     *
     * The word is escaped: $word is matched literally and cannot inject a pattern.
     *
     * SAFE FOR GATES: a null or empty $word returns FALSE (it is not "found in everything").
     * This method never reports a match for a blank term, so a blank blocklist/allowlist entry
     * cannot silently match every input.
     *
     * @param string|null $text The text to search in. Null or "" returns false.
     * @param string|null $word The word to search for. Null or "" returns false.
     * @param bool $caseSensitive Whether to match case-sensitively (default true).
     * @return bool True if the word is found as a standalone word, false otherwise.
     *
     * @see https://stackoverflow.com/questions/4366730/how-do-i-check-if-a-string-contains-a-specific-word
     */
    public static function containsExactWord(?string $text, ?string $word, bool $caseSensitive = true): bool {
        // A blank term is not found in anything. Returning true here (vacuous truth) makes every
        // boolean gate built on this method fail OPEN on a blank config value.
        if($word === null || $word === "") {
            return false;
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
     * Replaces Latin-1 Supplement and Latin Extended-A characters with their ASCII equivalents
     * ("Olá Ção" -> "Ola Cao"), including the multi-character expansions (Æ is not covered, but
     * Œ -> OE and Ĳ -> IJ are). Characters OUTSIDE those two blocks — Cyrillic, Greek, CJK,
     * Latin Extended-B, and combining marks applied to a base letter — are left untouched, so
     * this does not guarantee an ASCII-only result. Do not use it to make a string
     * filesystem-safe or URL-safe on its own; follow it with keepOnlyCharacters() or
     * onlyLettersAndNumbers() if you need that guarantee.
     *
     * @param string $text The input string with possible accented characters. Must be UTF-8;
     *                     pure-ASCII input (and "") is returned unchanged without work.
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
     * Finds all positions of a substring within a string.
     *
     * Matches do not overlap: the search resumes after each match, so searching "aaaa" for "aa"
     * yields [0, 2], not [0, 1, 2]. Multibyte (UTF-8): positions are CHARACTER offsets, not byte
     * offsets, so they line up with subStr()/strLen() and not with the native substr().
     *
     * @param string $haystack The full string to search within. "" returns [].
     * @param string $needle The substring to search for. "" returns []. Note "0" is a legitimate
     *                       needle and IS searched for.
     * @param bool $returnEndPosition If true, each entry is the position just AFTER the match
     *                                (start + length of $needle); otherwise the match start.
     * @param bool $caseSensitive If false, the search is case-insensitive
     * @param int $offset Internal offset pointer (used by recursion) — leave at 0
     * @param array $results Internal result accumulator (used by recursion) — leave empty. It is
     *                       taken BY REFERENCE and appended to, so a non-empty array passed here
     *                       is returned with the matches appended to it.
     *
     * @return int[] List of match positions found, ascending; [] when there is no match.
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
        if ($haystack === '' || $needle === '') {
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
     * Scanning is non-overlapping and left to right: after each pair, the scan resumes past the
     * end delimiter, so "[a][b]" between "[" and "]" gives ['a','b']. A start delimiter with no
     * matching end delimiter after it ends the scan and is not reported. An empty region yields
     * an empty string entry ("[]" gives ['']). Multibyte (UTF-8) aware.
     *
     * @param string|null $input The full string to search within. Null or "" returns [].
     * @param string|null $startDelimiter The starting delimiter. Null or "" returns []. "0" is a
     *                                    legitimate delimiter and IS honored.
     * @param string|null $endDelimiter The ending delimiter. Null or "" returns []. "0" is a
     *                                  legitimate delimiter and IS honored.
     * @param bool $caseSensitive If false, delimiters match case-insensitively
     * @param bool $includeDelimiters If true, each result is wrapped back in the delimiters as
     *                                they were PASSED (not as they were matched, which differs
     *                                when $caseSensitive is false)
     *
     * @return string[] An array of all substrings found between the delimiters; [] when none.
     */
    public static function extractSubstringsBetween(
        ?string $input,
        ?string $startDelimiter,
        ?string $endDelimiter,
        bool $caseSensitive = true,
        bool $includeDelimiters = false
    ): array {
        if ($input === null || $input === '' || $startDelimiter === null || $startDelimiter === ''
            || $endDelimiter === null || $endDelimiter === '') {
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