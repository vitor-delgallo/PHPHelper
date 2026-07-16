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
     * Converts an array or object into XML, filling $xml in place.
     *
     * Mapping rules:
     *  - A NON-numeric key becomes an element of that name.
     *  - A NUMERIC key (i.e. any list element) becomes an `<item>` element, because a number is not
     *    a legal XML element name. So `['ids' => [1, 2]]` yields `<ids><item>1</item><item>2</item></ids>`.
     *  - Nested arrays/objects recurse; scalars become text content. NULL and FALSE become an empty
     *    element, TRUE becomes "1" (PHP string casting).
     *  - Values are entity-escaped, so any text (including '&' and '<') round-trips through a parser.
     *
     * @param array|object $data The data to convert. An empty array yields just the empty root node.
     * @param \SimpleXMLElement|null $xml BY REFERENCE. Pass null (the default) to have the root node
     *                                    created and assigned here; pass an existing node to append into it.
     * @param string $rootNode Name of the root node, used ONLY when $xml is null. Falls back to 'root'
     *                         when empty. MUST be a valid XML element name.
     *
     * @return void The result is written to $xml, which is never null after a successful call.
     *
     * @throws \Exception If $rootNode is not a valid XML element name (SimpleXMLElement cannot parse it).
     *
     * ELEMENT NAMES ARE NOT VALIDATED OR SANITIZED: non-numeric keys are used verbatim. A key that is
     * not a legal XML name (e.g. 'bad key') is emitted as-is, producing a MALFORMED document with no
     * warning and no exception — it only fails later, in whatever parses it. VALUES are escaped and are
     * always safe; KEYS are not. Never build element names from untrusted input.
     *
     * @ref https://stackoverflow.com/questions/37618094/php-convert-array-to-xml
     */
    public static function arrayToXml(array|object $data, ?\SimpleXMLElement &$xml = null, string $rootNode = "root"): void {
        // Must be `$xml === null`, NOT `empty($xml)`: a SimpleXMLElement with no children casts to
        // FALSE, so empty() would discard a real, caller-supplied node and build a detached root
        // whose contents are never attached to the caller's tree.
        if ($xml === null) {
            // empty() is deliberate: '' and '0' are both invalid XML element names.
            if (empty($rootNode)) $rootNode = "root";
            $xml = new \SimpleXMLElement("<{$rootNode}/>");
        }

        if (is_object($data)) {
            $data = (array) $data;
        }

        foreach ($data as $key => $value) {
            $name = is_numeric($key) ? 'item' : (string) $key;

            if (is_array($value) || is_object($value)) {
                $subnode = $xml->addChild($name);
                // addChild() returns null on an invalid element name (after warning). Never recurse
                // with null: the by-reference param would build an orphan root that is silently
                // dropped, losing the whole subtree without a trace.
                if ($subnode === null) {
                    continue;
                }
                self::arrayToXml($value, $subnode);
                continue;
            }

            // addChild() escapes '<' and '>' but NOT '&': given a raw '&' it warns and DROPS the
            // value entirely. Pre-escaping avoids that, and does not double-escape — addChild
            // leaves the '&' of our own entities untouched.
            $xml->addChild($name, htmlspecialchars((string) $value, ENT_XML1, 'UTF-8'));
        }
    }

    /**
     * Removes null values from an array recursively.
     *
     * KEY-PRESERVING, like array_filter(): 'name' => 'Ana' stays under 'name'. Use
     * resetArrayIndexes() afterwards if you want a gapless 0-based list.
     *
     * Only the value NULL is removed. Other falsy values ('', 0, false, []) are kept. A nested array
     * whose entries were all null survives as an empty array, it is not itself removed.
     *
     * @param array|null $array The input array. NULL is treated as [].
     * @return array The same structure with every null entry removed, original keys intact.
     */
    public static function arrayRemoveNulls(?array $array): array {
        if (empty($array)) $array = [];

        $cleaned = [];
        foreach ($array as $key => $item) {
            if ($item === null) continue;

            if (!is_array($item)) {
                $cleaned[$key] = $item;
            } else {
                $cleaned[$key] = self::arrayRemoveNulls($item);
            }
        }

        return $cleaned;
    }

    /**
     * Converts an array into a PHP object.
     *
     * Performs a JSON round-trip, so only JSON-representable data survives: nested associative arrays
     * become stdClass, nested LIST arrays stay PHP arrays, and resources/closures are lost.
     *
     * A top-level list (e.g. [1, 2, 3] or a result set) JSON-encodes to an array, which cannot satisfy
     * the declared object return type, so it is cast to stdClass with the numeric keys as property
     * names ('0', '1', ...). objectToArray() reverses that faithfully.
     *
     * @param array $array The array to convert. Any shape is accepted (list or associative).
     * @return object|null NULL when $array is empty, AND ALSO when the data cannot be JSON-encoded
     *                     (e.g. malformed UTF-8 from a latin1/cp850 source). NULL is therefore
     *                     "no object", not proof of emptiness. Never throws.
     *
     * @ref https://stackoverflow.com/questions/9169892/how-to-convert-multidimensional-array-to-object-in-php
     */
    public static function arrayToObject(array $array): object|null {
        if (empty($array)) return null;

        $json = json_encode($array);
        if ($json === false) return null;

        $decoded = json_decode($json);
        if ($decoded === null) return null;

        // A JSON array decodes to a PHP array; cast it so the declared `object` return type holds
        // instead of throwing a TypeError on the single most common input shape (a result set).
        return is_object($decoded) ? $decoded : (object) $decoded;
    }

    /**
     * Converts an object into an associative array.
     *
     * Performs a JSON round-trip, so this is a DEEP conversion that sees only what JSON sees: public
     * properties (private/protected are dropped, unlike a (array) cast), or whatever jsonSerialize()
     * returns for a JsonSerializable object.
     *
     * @param object|null $object The object to convert. NULL/property-less objects yield [].
     * @return array The resulting array. Returns [] — never null, never throws — when $object is
     *               empty, when it cannot be JSON-encoded (e.g. malformed UTF-8 from a latin1/cp850
     *               source), or when its JSON form is not an object/array (e.g. a JsonSerializable
     *               returning a scalar). [] therefore means "nothing convertible", NOT "was empty";
     *               validate the input yourself if you must tell those apart.
     *
     * @ref https://stackoverflow.com/questions/9169892/how-to-convert-multidimensional-array-to-object-in-php
     */
    public static function objectToArray(object|null $object): array {
        if (empty($object)) return [];

        $json = json_encode($object);
        if ($json === false) return [];

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Converts an XML string or file path into an associative array.
     *
     * @param string $xmlSource XML content, OR a path to an XML file (see the warning below).
     * @return array The resulting array. Returns [] when the simplexml extension is missing, when
     *               $xmlSource is empty or a directory, and when the XML is malformed — a malformed
     *               document also emits a PHP warning unless you have called
     *               libxml_use_internal_errors(true). [] is thus "nothing parsed", not "empty document".
     *
     * @ref https://www.php.net/manual/en/function.simplexml-load-file.php
     */
    public static function xmlToArray(string $xmlSource): array {
        $result = [];
        if (!extension_loaded('simplexml')) {
            return $result;
        }

        if (empty($xmlSource) || is_dir($xmlSource)) return $result;

        // Harden against XXE: hard-block external entity resolution and forbid network access
        // (LIBXML_NONET). No LIBXML_NOENT, so entities are never expanded. NOTE: a string that
        // happens to be an existing path is loaded as a FILE — never pass untrusted input here
        // without confirming it is XML content, not a local path (arbitrary-file read).
        if (\function_exists('libxml_set_external_entity_loader')) {
            libxml_set_external_entity_loader(static fn() => null);
        }

        if (is_file($xmlSource)) {
            // Read the file and parse it as a STRING. simplexml_load_file() cannot be used while the
            // XXE guard above is installed: libxml routes its request for the MAIN DOCUMENT through
            // the same external-entity loader, which returns null for everything, so every file load
            // failed with "Failed to load external entity" and this method silently returned [].
            // Loading the bytes ourselves keeps the guard strictly intact — the loader still refuses
            // every entity — while making the documented file-path input actually work.
            $contents = file_get_contents($xmlSource);
            if ($contents === false || $contents === '') {
                return $result;
            }
            $xml = simplexml_load_string($contents, \SimpleXMLElement::class, LIBXML_NONET) ?: null;
        } else {
            $xml = simplexml_load_string($xmlSource, \SimpleXMLElement::class, LIBXML_NONET) ?: null;
        }

        return self::objectToArray($xml);
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
     * Encodes a string to a URL-safe Base64 VARIANT.
     *
     * NOT RFC 4648 base64url. This uses a private alphabet — '+' => '.', '/' => '_', '=' => '-' —
     * whereas RFC 4648 §5 uses '+' => '-', '/' => '_' and strips padding. The two disagree on the
     * meaning of '-', so a standard decoder (JS atob after the usual replaces, or any compliant
     * library) silently produces CORRUPT output rather than failing. Output of this method is only
     * safe to decode with base64UrlDecode(). Kept as-is deliberately: switching alphabets would
     * invalidate every token and URL already issued.
     *
     * @param string|null $input The string to encode.
     * @return string|null Encoded string, or NULL if $input is null or ''.
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
     * Decodes a string produced by base64UrlEncode() back to its original form.
     *
     * Only decodes this library's private alphabet ('.' => '+', '_' => '/', '-' => '='); see
     * base64UrlEncode() — this is NOT RFC 4648 base64url and will mangle standard base64url input.
     *
     * Decoding is ALWAYS strict: input containing characters outside the Base64 alphabet fails. There
     * is no strictness option (a previous version of this docblock advertised a $strict parameter that
     * never existed — PHP silently ignores the extra argument, so passing one has no effect).
     *
     * @param string|null $input The encoded string to decode.
     * @return bool|string The decoded string, or FALSE if $input is null/'' or is not valid Base64.
     *                     Mirrors base64Decode(). Test with `=== false`: a failure is never reported
     *                     as '' or null, so a strict check is reliable.
     *
     * @see https://stackoverflow.com/questions/1374753/passing-base64-encoded-strings-in-url
     */
    public static function base64UrlDecode(?string $input): bool|string {
        if ($input === null || $input === '') {
            return false;
        }

        // The `bool|string` return type is load-bearing: under `?string` PHP would coerce
        // base64Decode()'s FALSE into '', making the documented `=== false` guard dead code and
        // letting malformed tokens pass as a "successfully decoded" empty string.
        return self::base64Decode(strtr($input, '._-', '+/='));
    }

    /**
     * Converts a string into its binary representation: one space-separated group of exactly 8 bits
     * per BYTE, most significant bit first.
     *
     * Operates on bytes, not on Unicode characters: a multi-byte UTF-8 character produces one 8-bit
     * group per byte (so "é" yields two groups).
     *
     * The fixed 8-bit width is what makes the output portable — it can be decoded by binaryToString()
     * or by any external consumer doing the conventional str_split($bits, 8) / int-parse-base-2.
     *
     * @param string|null $input The string to convert.
     * @return string Space-separated 8-bit groups, or '' if $input is null or ''.
     *
     * @ref https://stackoverflow.com/questions/6382738/convert-string-to-binary-then-back-again-using-php
     * @ref http://www.inanzzz.com/index.php/post/swf8/converting-string-to-binary-and-binary-to-string-with-php
     */
    public static function stringToBinary(?string $input): string {
        if ($input === null || $input === '') return "";

        $characters = str_split($input);
        $binary = [];

        foreach ($characters as $index => $char) {
            $bin = base_convert(unpack('H*', $char)[1], 16, 2);
            // Pad to 8, NOT to strlen($bin) * 8: the latter makes the group width depend on the
            // byte's VALUE (56 bits for 'A', 64 for 0xFF), which no external decoder can read.
            $binary[$index] = str_pad($bin, 8, "0", STR_PAD_LEFT);
        }

        return implode(' ', $binary);
    }

    /**
     * Converts a space-separated binary string back to a normal string. Exact inverse of
     * stringToBinary(): binaryToString(stringToBinary($s)) === $s for every byte value, including
     * NUL, TAB and LF.
     *
     * Groups wider than 8 bits are also accepted (leading zeros are numerically irrelevant), so
     * output written by older, ragged-width versions still decodes correctly.
     *
     * @param string|null $binaryInput Space-separated groups of binary digits. MUST contain only
     *                                 '0', '1' and spaces: any other character triggers a PHP
     *                                 deprecation from base_convert() and is silently ignored,
     *                                 yielding garbage. Validate untrusted input before calling.
     * @return string The decoded string, or '' if $binaryInput is null or ''.
     *
     * @ref https://stackoverflow.com/questions/6382738/convert-string-to-binary-then/-back-again-using-php
     * @ref http://www.inanzzz.com/index.php/post/swf8/converting-string-to-binary-and-binary-to-string-with-php
     */
    public static function binaryToString(?string $binaryInput): string {
        // '' must short-circuit: explode(' ', '') is [''], and base_convert('', 2, 16) is '0',
        // which would emit a spurious NUL byte instead of an empty string.
        if ($binaryInput === null || $binaryInput === '') return "";

        $binaries = explode(' ', $binaryInput);
        $output = "";

        foreach ($binaries as $binary) {
            $hex = base_convert($binary, 2, 16);
            // base_convert() drops leading zeros, so any byte < 0x10 yields a single hex digit.
            // pack('H*') pads an odd-length string on the RIGHT, turning 0x0A ('a') into 0xA0 —
            // silently corrupting every byte 0x00-0x0F, i.e. LF and TAB. Pad left to even length.
            if (strlen($hex) % 2 !== 0) {
                $hex = '0' . $hex;
            }
            $output .= pack('H*', $hex);
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
     * Normalizes any value to a boolean, the way a human-entered flag is meant to read.
     *
     * FALSE is returned for:
     *  - boolean false;
     *  - numeric zero in any form: 0, 0.0, -0, '0', '0.0', '00', '0e0' (leading/trailing spaces ok);
     *  - null, '' and "\0";
     *  - the empty array [] and an object with no properties;
     *  - these words, case-insensitively and with EVERY whitespace character removed first — not
     *    merely trimmed: 'false', 'null', 'undefined', 'no', 'n', 'tno', '{}', '[]'.
     *
     * TRUE is returned for everything else, including 'true', 'yes', any non-zero number, any
     * non-empty array/object, and any other non-empty string.
     *
     * READ THAT WHITESPACE RULE LITERALLY, because it is surprising and it is not a typo: the
     * sentinel comparison strips INTERNAL whitespace too, so 'fa lse', 'n o', 'F A L S E' and
     * '{ }' are all FALSE, and only a string that collapses to something OTHER than a sentinel
     * stays TRUE. A user who fat-fingers a space into the middle of a word gets the word's
     * meaning, not the garbage-is-truthy fallback you would expect. Do not rely on this to
     * sanitise anything.
     *
     * This is deliberate rather than merely tolerated. The normalisation lives in
     * Validator::isCompletelyEmpty(), which also backs Security's `asBoolean` sanitize option, so
     * a submitted string MUST mean the same thing through both doors. Loosening it here — and only
     * here — would fork the two: 'n o' would be a flag that reads false when parsed and true when
     * sanitised, which is a far nastier defect than a boolean parser being generous about spaces.
     * Change it in isCompletelyEmpty() for both callers, or not at all.
     *
     * An object implementing __toString() is judged by its string value (so a wrapper around 'no' is
     * FALSE); any other object is judged only on whether it has properties.
     *
     * This is NOT PHP's own (bool) cast: PHP reads '0.0', 'false' and 'no' as TRUE. It is meant for
     * flags arriving as text from a form, a query string, JSON, or a legacy DB column.
     *
     * @param mixed $value The value to normalize. Any type is accepted; never throws.
     * @return bool
     */
    public static function getBool(mixed $value): bool {
        // Judge a Stringable by its STRING value rather than letting it reach isCompletelyEmpty()
        // as an object. That helper defers to emptyExceptZero(), which calls any property-less
        // object empty — so a wrapper around 'yes' would read as FALSE. This guard is load-bearing
        // and is NOT redundant with isCompletelyEmpty().
        //
        // Every other type is delegated. The bool/int/float/array/object/numeric-string guards that
        // used to sit here existed only because isCompletelyEmpty()'s zero-catching branch was dead
        // code (`filter_var(...) && ...` short-circuited on the falsy int(0) it was looking for), so
        // delegating reported 0/'0' as TRUE. That branch now tests `!== false` and detects zero, and
        // isCompletelyEmpty() guards arrays, resources and non-Stringable objects before any string
        // cast, so the duplication is gone.
        if ($value instanceof \Stringable) {
            $value = (string) $value;
        }

        return !Validator::isCompletelyEmpty($value);
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
     *
     * Breaks on line breaks ONLY — CRLF, CR, LF, and the HTML tags <br>, <br/>, <br /> (any case).
     * Spaces and tabs are NOT delimiters: they stay inside the line, so an address or a full name
     * survives a splitLines()/joinLines() round-trip intact.
     *
     * A <br> tag immediately followed by a newline counts as ONE break — that newline is source
     * formatting, not a second line. Otherwise every break splits: consecutive breaks yield
     * empty-string entries, i.e. blank lines are preserved rather than discarded.
     *
     * @param string|null $text The string to split.
     * @return array The lines. Returns [] for null, '' and '0' (empty() semantics).
     */
    public static function splitLines(?string $text): array {
        if (empty($text)) {
            return [];
        }

        // The old pattern was `[\s\t\n\r]`, whose `\s` matches a SPACE — it split on every word, so
        // joinLines(splitLines($address)) replaced each space with '<br />'. Match real breaks only.
        return preg_split('/<br\s*\/?>\r?\n?|\r\n|\r|\n/i', $text);
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
     * @param string|null $numericText String composed of 3-digit ASCII codes, as produced by
     *                                 stringToNumericSequence(). Returned unchanged if null or ''.
     * @return string|null Decoded original string
     *
     * @throws \TypeError If $numericText contains anything but digits — chr() rejects a non-numeric
     *                    string. Codes are taken in groups of 3 and reduced modulo 256, so a length
     *                    that is not a multiple of 3 decodes the trailing group as a short number
     *                    rather than failing. Validate untrusted input before calling.
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
     * @param array|null $input The array of arrays to modify. NULL and [] are legal and yield [].
     *                          EVERY element must itself be an array — see @throws.
     * @param string|null $key The key to set/overwrite in each sub-array. NULL and '' are legal and
     *                         make the call a no-op returning $input unchanged. Note '0' IS a valid
     *                         key here (emptyExceptZero, not empty()).
     * @param mixed $value The value to assign to the key.
     *
     * @return array The modified array, outer keys preserved. Never null.
     *
     * @throws \Error If any element of $input is not an array — a scalar element cannot take an array
     *                offset and an object element cannot be used as an array. A null element is
     *                silently promoted to [$key => $value].
     */
    public static function setValueForKeyInArray(?array $input, ?string $key, mixed $value = null): array {
        if (empty($input) || Validator::emptyExceptZero($key)) {
            // `?? []`, not `$input`: returning the raw null would throw a TypeError against the
            // declared `: array` return type on exactly the nullable input the signature invites.
            return $input ?? [];
        }

        array_walk($input, function (&$item) use ($key, $value) {
            $item[$key] = $value;
        });
        return $input;
    }

    /**
     * Retrieves the first item from a list of arrays or objects whose $keyName equals $keyValue.
     *
     * $arrayOfItems may have ANY keys — a 0-based list, an id-keyed map, or the gapped result of an
     * array_filter() all work. Elements that are not arrays/objects, or that lack $keyName (or hold
     * null there), are skipped rather than shifting the search.
     *
     * Comparison is a non-strict STRING comparison (strval on both sides), so the int 3 matches the
     * string '3'. Array/object values in the key are never matched.
     *
     * @param array|null $arrayOfItems List of associative arrays and/or objects. NULL/[] yield [].
     * @param string|null $keyName The key (array) or public property (object) to search by. NULL/''
     *                             yield [].
     * @param string|int|null $keyValue The value to match. NULL and '' yield [] without searching —
     *                                  this method cannot be used to find a null/empty-valued key.
     *
     * @return array The matched item. An OBJECT match is converted with objectToArray(), so only its
     *               public/JSON-serializable properties survive, and an object that cannot be encoded
     *               yields []. Returns [] when nothing matches.
     */
    public static function findItemByKey(?array $arrayOfItems, ?string $keyName, string|int|null $keyValue): array {
        if (empty($arrayOfItems) || empty($keyName) || $keyValue === null || $keyValue === '') {
            return [];
        }

        // Deliberately a linear scan, not array_column()+array_flip(). array_column() returns a NEW
        // 0-based list, so its indexes are positional in the COLUMN, not keys of $arrayOfItems: one
        // key-less row shifted every later index and the lookup silently returned a DIFFERENT
        // record, and any non-0-based input returned [] for items that were present.
        $needle = strval($keyValue);

        foreach ($arrayOfItems as $item) {
            if (is_object($item)) {
                $candidate = $item->{$keyName} ?? null;
            } elseif (is_array($item)) {
                $candidate = $item[$keyName] ?? null;
            } else {
                continue;
            }

            if ($candidate === null || is_array($candidate) || is_object($candidate)) {
                continue;
            }

            if (strval($candidate) === $needle) {
                // The declared `: array` return type would fatal on a raw object, though the params
                // above explicitly invite one.
                return is_object($item) ? self::objectToArray($item) : $item;
            }
        }

        return [];
    }

}