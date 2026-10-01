<?php

namespace VD\PHPHelper;

class Parser {
    /**
     * Nesting limit for arrayToXml(), matching json_encode()'s default depth. Anything deeper is
     * almost always a reference cycle, which used to recurse until PHP ran out of memory.
     */
    private const XML_MAX_DEPTH = 512;

    /**
     * XML 1.0 NameStartChar / NameChar, minus ':' (a prefix would need a namespace declaration
     * that arrayToXml() never emits).
     */
    private const XML_NAME_START_CHARS = 'A-Z_a-z\x{C0}-\x{D6}\x{D8}-\x{F6}\x{F8}-\x{2FF}\x{370}-\x{37D}\x{37F}-\x{1FFF}'
        . '\x{200C}-\x{200D}\x{2070}-\x{218F}\x{2C00}-\x{2FEF}\x{3001}-\x{D7FF}\x{F900}-\x{FDCF}\x{FDF0}-\x{FFFD}\x{10000}-\x{EFFFF}';
    private const XML_NAME_CHARS = self::XML_NAME_START_CHARS . '\-.0-9\x{B7}\x{300}-\x{36F}\x{203F}-\x{2040}';

    /**
     * Decodes TEXT-type values (e.g. Unicode escape sequences like í).
     *
     * A UTF-16 surrogate PAIR (😀, the form every JSON encoder uses for a character
     * outside the BMP such as an emoji) is decoded as the single character it encodes. Each half
     * used to be converted on its own, which produced bytes that are not valid UTF-8 at all — so
     * the result then failed json_encode(), mb_* functions and database writes. A LONE surrogate
     * (half a pair) encodes nothing and becomes U+FFFD.
     *
     * @param string|null $str String to decode
     * @return string|null
     *
     * @ref https://stackoverflow.com/questions/2934563/how-to-decode-unicode-escape-sequences-like-u00ed-to-proper-utf-8-encoded-cha
     */
    public static function decodeText(?string $str): ?string {
        if (empty($str)) {
            return $str;
        }

        return preg_replace_callback(
            '/\\\\u([dD][89abAB][0-9a-fA-F]{2})\\\\u([dD][c-fC-F][0-9a-fA-F]{2})|\\\\u([0-9a-fA-F]{4})/',
            static function (array $match): string {
                // With the alternation, a single-unit match leaves groups 1-2 empty and sets 3.
                if (isset($match[3])) {
                    $unit = hexdec($match[3]);
                    if ($unit >= 0xD800 && $unit <= 0xDFFF) {
                        return "\u{FFFD}";
                    }

                    return mb_convert_encoding(pack('H*', $match[3]), 'UTF-8', 'UTF-16BE');
                }

                return mb_convert_encoding(pack('H*', $match[1] . $match[2]), 'UTF-8', 'UTF-16BE');
            },
            $str
        );
    }

    /**
     * Recursively decodes TEXT-type values within an array.
     *
     * Only strings (and strings inside nested arrays) are decoded; every other value, including
     * objects, is returned untouched. Keys are preserved.
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
        unset($item);

        return $arr;
    }

    /**
     * Converts an array or object into XML, filling $xml in place.
     *
     * Mapping rules:
     *  - An INTEGER key (i.e. any list element) becomes an `<item>` element, because a number is not
     *    a legal XML element name. So `['ids' => [1, 2]]` yields `<ids><item>1</item><item>2</item></ids>`.
     *  - A string key that is a legal XML element name (XML 1.0 Name, without ':') becomes an element
     *    of that name.
     *  - ANY OTHER string key — '', 'bad key', '1abc', 'ns:el', 'a><evil' — becomes
     *    `<item key="…">`, the original key carried (escaped) in the attribute. Such keys used to be
     *    emitted as element names verbatim, producing MALFORMED XML (and, for a key built from input,
     *    markup injection), and '' threw a ValueError.
     *  - Nested arrays/objects recurse; scalars become text content. NULL and FALSE become an empty
     *    element, TRUE becomes "1" (PHP string casting).
     *  - An OBJECT is read the way json_encode() reads it: a \JsonSerializable contributes whatever
     *    jsonSerialize() returns, a \DateTimeInterface becomes its DATE_ATOM string, an enum its value
     *    (backed) or name (pure), and any other object its PUBLIC properties only. It used to be cast
     *    with (array), which exposes PRIVATE and PROTECTED properties under NUL-mangled keys — an
     *    entity's password hash went out as `<>HASH</>`, in malformed XML.
     *  - Text is escaped, so any value (including '&' and '<') round-trips through a parser. Invalid
     *    UTF-8 and characters XML 1.0 forbids (most C0 controls) are replaced with U+FFFD; both used
     *    to make libxml DROP THE WHOLE VALUE, silently or with a warning.
     *
     * @param array|object $data The data to convert. An empty array yields just the empty root node.
     * @param \SimpleXMLElement|null $xml BY REFERENCE. Pass null (the default) to have the root node
     *                                    created and assigned here; pass an existing node to append into it.
     * @param string $rootNode Name of the root node, used ONLY when $xml is null. Falls back to 'root'
     *                         when empty. MUST be a legal XML element name.
     *
     * @return void The result is written to $xml, which is never null after a successful call.
     *
     * @throws \InvalidArgumentException If $rootNode is not a legal XML element name (it used to be
     *                                   pasted into markup and parsed, so 'r evil="1"' silently added
     *                                   an attribute), or if the data nests deeper than 512 levels —
     *                                   which in practice means a reference cycle, and used to exhaust
     *                                   memory with an uncatchable fatal error.
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
            if (!self::isXmlName($rootNode)) {
                throw new \InvalidArgumentException('arrayToXml(): $rootNode must be a legal XML element name.');
            }
            // Safe to interpolate only BECAUSE it was validated as a Name just above.
            $xml = new \SimpleXMLElement("<{$rootNode}/>");
        }

        self::appendXmlChildren($xml, $data, 0);
    }

    /**
     * Appends $data under $xml; the recursive body of arrayToXml().
     *
     * @param \SimpleXMLElement $xml The node to fill.
     * @param mixed $data The array/object to append. An object that normalises to a scalar sets $xml's text.
     * @param int $depth Current nesting depth.
     */
    private static function appendXmlChildren(\SimpleXMLElement $xml, mixed $data, int $depth): void {
        if ($depth > self::XML_MAX_DEPTH) {
            throw new \InvalidArgumentException(
                'arrayToXml(): data nests deeper than ' . self::XML_MAX_DEPTH . ' levels (a reference cycle?).'
            );
        }

        $data = self::normaliseXmlValue($data);
        if (!is_array($data)) {
            // Assigning to [0] sets the element's text, escaped by SimpleXML itself.
            $xml[0] = self::xmlSafeText($data);
            return;
        }

        foreach ($data as $key => $value) {
            $keyIsForeign = is_string($key) && !self::isXmlName($key);
            $name = is_int($key) || $keyIsForeign ? 'item' : $key;
            $value = self::normaliseXmlValue($value);

            if (is_array($value)) {
                $child = $xml->addChild($name);
                self::appendXmlChildren($child, $value, $depth + 1);
            } else {
                // addChild() parses its value for entity references — a raw '&' made it warn and DROP
                // the value — so it gets pre-escaped text. Our own entities are not double-escaped.
                $child = $xml->addChild($name, htmlspecialchars(self::xmlSafeText($value), ENT_XML1 | ENT_QUOTES, 'UTF-8'));
            }

            if ($keyIsForeign) {
                // addAttribute() escapes on its own; pre-escaping here would double-escape.
                $child->addAttribute('key', self::xmlSafeText($key));
            }
        }
    }

    /**
     * Reduces an object to what json_encode() would see; leaves every other value alone.
     *
     * @param mixed $value Any value.
     * @return mixed A non-object: an array, or a scalar/null/resource.
     */
    private static function normaliseXmlValue(mixed $value): mixed {
        // Bounded: a jsonSerialize() may return another object, and one returning $this must not spin.
        for ($hops = 0; is_object($value) && $hops < 8; $hops++) {
            $value = match (true) {
                $value instanceof \JsonSerializable  => $value->jsonSerialize(),
                $value instanceof \DateTimeInterface => $value->format(DATE_ATOM),
                $value instanceof \BackedEnum        => $value->value,
                $value instanceof \UnitEnum          => $value->name,
                // Called from THIS class, so only the object's public properties are visible.
                default                              => get_object_vars($value),
            };
        }

        return is_object($value) ? get_object_vars($value) : $value;
    }

    /**
     * Turns a scalar into text XML 1.0 can carry: valid UTF-8 and no forbidden characters.
     *
     * @param mixed $value A non-array value.
     * @return string Raw (unescaped) text.
     */
    private static function xmlSafeText(mixed $value): string {
        $text = match (true) {
            $value === null, $value === false => '',
            $value === true                   => '1',
            default                           => (string) $value,
        };

        if (!mb_check_encoding($text, 'UTF-8')) {
            // ENT_SUBSTITUTE is the one core primitive that swaps each invalid sequence for U+FFFD.
            $text = htmlspecialchars_decode(htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), ENT_NOQUOTES);
        }

        return (string) preg_replace('/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', "\u{FFFD}", $text);
    }

    /**
     * Whether $name is a legal XML 1.0 element name without a namespace prefix.
     *
     * @param string $name Candidate name.
     * @return bool
     */
    private static function isXmlName(string $name): bool {
        return preg_match('/^[' . self::XML_NAME_START_CHARS . '][' . self::XML_NAME_CHARS . ']*\z/u', $name) === 1;
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
     * become stdClass, nested LIST arrays stay PHP arrays, and resources/closures are lost. A float
     * stays a float (10.0 does not come back as int 10).
     *
     * A top-level list (e.g. [1, 2, 3] or a result set) JSON-encodes to an array, which cannot satisfy
     * the declared object return type, so it is cast to stdClass with the numeric keys as property
     * names ('0', '1', ...). objectToArray() reverses that faithfully.
     *
     * @param array $array The array to convert. Any shape is accepted (list or associative).
     * @return object|null NULL when $array is empty, AND ALSO when the data cannot be JSON-encoded
     *                     (e.g. malformed UTF-8 from a latin1/cp850 source, NAN/INF, nesting deeper
     *                     than 512). NULL is therefore "no object", not proof of emptiness. Never throws.
     *
     * @ref https://stackoverflow.com/questions/9169892/how-to-convert-multidimensional-array-to-object-in-php
     */
    public static function arrayToObject(array $array): object|null {
        if (empty($array)) return null;

        $json = json_encode($array, JSON_PRESERVE_ZERO_FRACTION);
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
     * returns for a JsonSerializable object. A float stays a float (10.0 does not become int 10).
     *
     * @param object|null $object The object to convert. NULL/property-less objects yield [].
     * @return array The resulting array. Returns [] — never null, never throws — when $object is
     *               empty, when it cannot be JSON-encoded (e.g. malformed UTF-8 from a latin1/cp850
     *               source, a NAN/INF float anywhere inside, a reference cycle), or when its JSON form
     *               is not an object/array (e.g. a JsonSerializable returning a scalar). [] therefore
     *               means "nothing convertible", NOT "was empty"; validate the input yourself if you
     *               must tell those apart. ONE bad value anywhere discards the WHOLE object.
     *
     * @ref https://stackoverflow.com/questions/9169892/how-to-convert-multidimensional-array-to-object-in-php
     */
    public static function objectToArray(object|null $object): array {
        if (empty($object)) return [];

        $json = json_encode($object, JSON_PRESERVE_ZERO_FRACTION);
        if ($json === false) return [];

        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Converts an XML STRING into an associative array.
     *
     * BEHAVIOUR CHANGE: this no longer accepts a file path. It used to treat any string naming an
     * existing file as that file, so feeding it untrusted input — a webhook body, a form field —
     * was an arbitrary local-file read: posting "/app/config/secrets.xml" parsed and returned that
     * file. A path passed here is now just malformed XML (returns []). Use xmlFileToArray() for files.
     *
     * Hardening: external entities and DTDs are never loaded (a deny-all entity loader is installed
     * for the duration of the parse, LIBXML_NONET forbids the network, and LIBXML_NOENT is NOT set,
     * so no entity is expanded). The loader is RESTORED afterwards: it used to be left installed for
     * the rest of the process, after which every simplexml_load_file() / DOMDocument::load() in the
     * application failed with "failed to load external entity".
     *
     * Mapping (SimpleXML's JSON form): child elements become keys, repeated siblings become lists,
     * attributes appear under '@attributes', CDATA is read as text (it used to be DROPPED). Known
     * lossy spots, inherent to that form: an element with BOTH attributes and text keeps only the
     * text; an empty element becomes [] rather than ''; elements in a non-default namespace are
     * omitted; an unexpanded entity reference shows up as a nested key named after the entity.
     *
     * @param string $xmlSource XML content.
     * @return array The resulting array. Returns [] when the simplexml extension is missing, when
     *               $xmlSource is empty, and when the XML is malformed. No PHP warning is emitted for
     *               malformed input (libxml's diagnostics are collected internally); if you had
     *               libxml_use_internal_errors(true) on, they stay in libxml_get_errors() for you.
     *               [] is thus "nothing parsed", not "empty document".
     */
    public static function xmlToArray(string $xmlSource): array {
        if (!extension_loaded('simplexml') || trim($xmlSource) === '') {
            return [];
        }

        $previousLoader = \function_exists('libxml_get_external_entity_loader') ? libxml_get_external_entity_loader() : null;
        $callerCollectsErrors = libxml_use_internal_errors(true);
        libxml_set_external_entity_loader(static fn() => null);

        try {
            $xml = simplexml_load_string($xmlSource, \SimpleXMLElement::class, LIBXML_NONET | LIBXML_NOCDATA);
        } finally {
            libxml_set_external_entity_loader($previousLoader);
            if (!$callerCollectsErrors) {
                libxml_clear_errors();
                libxml_use_internal_errors(false);
            }
        }

        return $xml === false ? [] : self::objectToArray($xml);
    }

    /**
     * Reads an XML FILE and converts it into an associative array, exactly as xmlToArray() does.
     *
     * The file is read as bytes and parsed as a string, so the same entity hardening applies. Only
     * local paths are accepted: a stream-wrapper URL (http://, ftp://, php://, phar://, …) returns
     * [] without being opened; file:// is allowed. A wrapper name has at least two characters —
     * PHP's own rule, so that a Windows drive letter ("C://dir/x.xml", a path with a doubled
     * separator) is a path, not a scheme.
     *
     * @param string $path Path to the XML file. Treat it like any path: never build it from
     *                     untrusted input without confining it to a directory you control.
     * @return array The resulting array, or [] when $path is empty, is not a readable regular file,
     *               is empty, or holds malformed XML.
     */
    public static function xmlFileToArray(string $path): array {
        if ($path === '' || str_contains($path, "\0")) {
            return [];
        }
        if (preg_match('#^([a-z][a-z0-9+.\-]+)://#i', $path, $scheme) === 1 && strtolower($scheme[1]) !== 'file') {
            return [];
        }
        if (!is_file($path) || !is_readable($path)) {
            return [];
        }

        $contents = file_get_contents($path);

        return ($contents === false || $contents === '') ? [] : self::xmlToArray($contents);
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
     * Converts whitespace-separated binary groups back to a string. Exact inverse of
     * stringToBinary(): binaryToString(stringToBinary($s)) === $s for every byte value, including
     * NUL, TAB and LF.
     *
     * Groups wider than 8 bits are accepted as long as the extra bits are leading zeros, so output
     * written by older, ragged-width versions still decodes correctly. Any run of whitespace
     * separates groups; leading/trailing whitespace is ignored.
     *
     * @param string|null $binaryInput Whitespace-separated groups of binary digits.
     * @return string The decoded string, or '' if $binaryInput is null, '' or only whitespace.
     *
     * @throws \InvalidArgumentException If a group contains anything but '0'/'1', or its value does
     *                                   not fit in one byte. This used to be undetectable garbage: a
     *                                   stray character raised a base_convert() deprecation and was
     *                                   skipped, a double space inserted a NUL byte, and a 9-bit
     *                                   group expanded into two bytes.
     *
     * @ref https://stackoverflow.com/questions/6382738/convert-string-to-binary-then/-back-again-using-php
     * @ref http://www.inanzzz.com/index.php/post/swf8/converting-string-to-binary-and-binary-to-string-with-php
     */
    public static function binaryToString(?string $binaryInput): string {
        if ($binaryInput === null || $binaryInput === '') return "";

        $output = "";
        foreach (preg_split('/\s+/', trim($binaryInput), -1, PREG_SPLIT_NO_EMPTY) as $group) {
            $bits = ltrim($group, '0');
            if (strspn($group, '01') !== strlen($group) || strlen($bits) > 8) {
                throw new \InvalidArgumentException(
                    'binaryToString(): every group must be binary digits encoding one byte (0-255).'
                );
            }

            $output .= chr($bits === '' ? 0 : bindec($bits));
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

        return strtoupper(bin2hex($string));
    }

    /**
     * Converts a hexadecimal string back to a plain string. Case-insensitive.
     *
     * A trailing odd nibble is ignored ('616' decodes to 'a').
     *
     * @param string|null $hex The hexadecimal string to decode
     * @return string Decoded plain string ('' for null or '').
     *
     * @throws \InvalidArgumentException If $hex contains a non-hexadecimal character. Such a
     *                                   character used to raise a hexdec() deprecation and be dropped,
     *                                   turning 'zz' into a NUL byte.
     *
     * @ref https://stackoverflow.com/questions/14674834/php-convert-string-to-hex-and-hex-to-string
     */
    public static function hexToStr(?string $hex): string {
        if ($hex === null || $hex === '') return "";

        if (preg_match('/^[0-9a-fA-F]+\z/', $hex) !== 1) {
            throw new \InvalidArgumentException('hexToStr(): $hex must contain only hexadecimal digits.');
        }

        return (string) hex2bin(substr($hex, 0, strlen($hex) - strlen($hex) % 2));
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
     * non-empty array/object, and any other non-empty string — NOTE: 'off' is TRUE (it is not in the
     * list above), unlike filter_var(FILTER_VALIDATE_BOOLEAN).
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
     * A block starts at '{' or '[' and ends at the bracket that balances it. Brackets inside a
     * double-quoted string (with backslash escapes) do not count — '{"a":"}"}' is ONE block; it used
     * to be cut at the quoted '}'. A closing bracket of the wrong kind abandons the block in progress
     * and scanning continues right after it, so a stray '{]' no longer swallows the valid blocks that
     * follow. The scan is a single forward pass (linear time); the price is that a valid block NESTED
     * inside an abandoned or never-closed one is not recovered.
     *
     * Blocks are returned as found; they are balanced, not validated — json_decode() them yourself.
     *
     * @param string|null $input Input string possibly containing embedded JSON
     * @return string[] Array of extracted JSON-like strings, in order of appearance
     */
    public static function extractJsonBlocks(?string $input): array {
        $blocks = [];
        if ($input === null || $input === '') return $blocks;

        $length = strlen($input);
        $expected = []; // stack of the closers still owed
        $start = 0;
        $i = 0;

        while ($i < $length) {
            if ($expected === []) {
                $i += strcspn($input, '{[', $i);
                if ($i >= $length) break;

                $expected[] = $input[$i] === '{' ? '}' : ']';
                $start = $i++;
                continue;
            }

            $i += strcspn($input, '{}[]"', $i);
            if ($i >= $length) break;

            $char = $input[$i++];
            if ($char === '"') {
                // Skip the string body: to the next quote that is not escaped.
                while ($i < $length) {
                    $i += strcspn($input, '"\\', $i);
                    if ($i >= $length) break;
                    if ($input[$i] === '\\') {
                        $i += 2;
                        continue;
                    }
                    $i++;
                    break;
                }
                continue;
            }

            if ($char === '{' || $char === '[') {
                $expected[] = $char === '{' ? '}' : ']';
                continue;
            }

            if (array_pop($expected) !== $char) {
                $expected = [];
                continue;
            }

            if ($expected === []) {
                $blocks[] = substr($input, $start, $i - $start);
            }
        }

        return $blocks;
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
     * Delegates to DateTime::timeToSeconds(); see it for the authoritative contract.
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
     * Delegates to DateTime::secondsToTime(); see it for the authoritative contract.
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
     * Always treats $text as UTF-8 (whatever default_charset says) and encodes both quote styles,
     * so the result is safe in HTML text and inside a QUOTED attribute value. It is NOT enough for
     * an unquoted attribute, a <script>/<style> body, or a URL (javascript: survives it).
     *
     * @param string|null $text The string to encode
     * @return string|null Encoded HTML-safe string
     */
    public static function encodeHtml(?string $text): ?string {
        if ($text === null || $text === '') {
            return $text;
        }

        return htmlentities($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
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

        return html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE | ENT_HTML401, 'UTF-8');
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
     * @param string|null $numericText String composed of 3-digit byte codes (000-255), as produced by
     *                                 stringToNumericSequence(). Returned unchanged if null or ''.
     * @return string|null Decoded original string
     *
     * @throws \InvalidArgumentException If $numericText is not a whole number of 3-digit groups, or a
     *                                   group exceeds 255. This used to decode garbage instead: a
     *                                   short trailing group was read as a small number, a code above
     *                                   255 wrapped modulo 256, and a non-digit escaped as a TypeError.
     *
     * @link https://stackoverflow.com/questions/8087432/convert-a-string-to-number-and-back-to-string
     */
    public static function numericSequenceToString(?string $numericText): ?string {
        if ($numericText === null || $numericText === '') {
            return $numericText;
        }

        if (preg_match('/^(?:[0-9]{3})+\z/', $numericText) !== 1) {
            throw new \InvalidArgumentException(
                'numericSequenceToString(): $numericText must be a sequence of 3-digit byte codes.'
            );
        }

        $output = '';
        foreach (str_split($numericText, 3) as $code) {
            if ((int) $code > 255) {
                throw new \InvalidArgumentException("numericSequenceToString(): {$code} is not a byte code (000-255).");
            }
            $output .= chr((int) $code);
        }

        return $output;
    }

    /**
     * Sets a specific key to a given value in all elements of a numerically indexed array.
     *
     * Useful for applying a default or fixed value to a field across all rows.
     *
     * @param array|null $input The array of arrays to modify. NULL and [] are legal and yield [].
     *                          EVERY element must itself be an array (or null) — see @throws.
     * @param string|null $key The key to set/overwrite in each sub-array. NULL and '' are legal and
     *                         make the call a no-op returning $input unchanged. Note '0' IS a valid
     *                         key here (emptyExceptZero, not empty()).
     * @param mixed $value The value to assign to the key.
     *
     * @return array The modified array, outer keys preserved. Never null. A null element is promoted
     *               to [$key => $value].
     *
     * @throws \InvalidArgumentException If any element of $input is neither an array nor null. It is
     *                                   checked for EVERY element before any is modified. (This used
     *                                   to be an \Error thrown mid-walk — and a FALSE element was
     *                                   silently promoted to an array with a deprecation notice.)
     */
    public static function setValueForKeyInArray(?array $input, ?string $key, mixed $value = null): array {
        if (empty($input) || Validator::emptyExceptZero($key)) {
            // `?? []`, not `$input`: returning the raw null would throw a TypeError against the
            // declared `: array` return type on exactly the nullable input the signature invites.
            return $input ?? [];
        }

        foreach ($input as $row) {
            if ($row !== null && !is_array($row)) {
                throw new \InvalidArgumentException(
                    'setValueForKeyInArray(): every element must be an array or null, got ' . get_debug_type($row) . '.'
                );
            }
        }

        foreach ($input as &$row) {
            $row ??= [];
            $row[$key] = $value;
        }
        unset($row);

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
