<?php

namespace VD\PHPHelper;

class Formatter {
    /**
     * Largest |exponent| formatNumber() expands. Every finite double fits (their decimal
     * exponents run from -324 to 308); beyond it the expansion is a denial-of-service lever, not
     * a number anyone formats: "1e999999999" used to allocate a gigabyte of zeros.
     */
    private const MAX_EXPONENT = 1000;

    /**
     * Formats a number for display according to the given separator / prefix / suffix rules.
     *
     * This method is TOTAL and LENIENT: it never throws and never returns null — not even on
     * invalid UTF-8. Any character that is not a digit, a sign, $decimalSeparatorFrom or an
     * exponent marker is discarded before parsing, and an input carrying no digit at all (null,
     * "", "abc", "---", "+", ".") formats as "0". It is a FORMATTER, not a validator — it will
     * happily format a value it cannot make sense of, and it reports nothing when it discards
     * part of the input. Validate BEFORE calling if correctness matters. A string with more than
     * one decimal separator keeps only the digits up to the second one ("1.234.567" reads as
     * 1.234).
     *
     * Decimals are TRUNCATED, never rounded: formatNumber('1.999', decimalPlaces: 2) is '1.99'.
     * The digits are handled as a STRING end to end, so no precision is lost at any magnitude.
     *
     * Scientific notation ("1.5E+20", "1.5e-7") is accepted and expanded to a plain decimal
     * string. An "e"/"E" counts as an exponent marker ONLY when it sits directly between a digit
     * (or the decimal separator) and an optionally signed digit; every other "e" is noise and is
     * discarded like any other letter. (Previously every "e" survived the filter, so the "e" of a
     * trailing word switched on the exponent path: 'R$ 1234,56 reais' formatted as 123456.)
     * An |exponent| above 1000 is not expanded; the value degrades to "0" like other unusable
     * input.
     *
     * Float input is converted with PHP's string cast, which is locale-independent but keeps
     * only `precision` (ini, default 14) significant digits: 123456789012345678.0 formats as
     * '123456789012350000'. Pass a string when every digit matters. INF and NAN format as "0".
     * PHP renders any float of magnitude >= 1e15 in E notation, so the exponent path is reached
     * without the caller asking for it.
     *
     * BEHAVIOR CHANGES (all of them silent-wrong-output fixes):
     * - $decimalPlaces now yields EXACTLY that many decimals, zero-padded: it used to pad only
     *   when the thousands separator kicked in, so 123.5 gave "R$ 123,5" but 1234.5 gave
     *   "R$ 1.234,50" under the same arguments.
     * - The integer part is normalised: '.5' is '0.5' (was '.5'), '007' is '7'.
     * - A value whose kept digits are all zero never carries a sign: '-0.001' at 2 places is
     *   '0.00', not '-0.00'.
     * - Thousands grouping is done on the digit string. It used to go through number_format(),
     *   i.e. through a float: '12345678901234567890.12' lost its low-order digits.
     * - Characters of $prefix/$suffix are no longer stripped out of the separators: the prefix
     *   'Total, R$' used to delete the "," decimal separator and fall back to ".".
     *
     * @param string|float|int|null $number Raw number to format. NULL and "" format as "0".
     * @param string $decimalSeparatorFrom Decimal separator used in STRING input (float input is
     *                                     always read with ".", which is what PHP's float cast
     *                                     emits). It doubles as a whitelist: any character of
     *                                     $number that is not a digit, a sign, an exponent marker
     *                                     or this separator is discarded BEFORE parsing. So
     *                                     passing a separator the input does not actually use
     *                                     silently deletes the real one and rescales the value —
     *                                     formatNumber('1.5', ',') is '15', not '1.5'. This must
     *                                     match the input's real separator. Digits and signs are
     *                                     stripped from it; if nothing is left it falls back to
     *                                     "." (so "" and "9" both mean ".").
     * @param string $decimalSeparatorTo Decimal separator for the OUTPUT. Same stripping and
     *                                   "." fallback as above.
     * @param string $thousandsSeparatorTo Thousands separator for the OUTPUT; "" for none. Digits
     *                                     and signs are stripped from it, and it is ignored when
     *                                     it would equal $decimalSeparatorTo.
     * @param string $prefix Optional prefix, separated from the number by a space (e.g. "R$").
     * @param string $suffix Optional suffix, separated from the number by a space (e.g. "%").
     * @param int|null $decimalPlaces EXACT number of decimal places: the fraction is truncated
     *                                or zero-padded to it. NULL = keep every decimal the input
     *                                had (none added); 0 or a negative value = integer only.
     * @param bool $allowNegative Controls the SIGN of the output only. This is NOT a validation
     *                            gate: when FALSE a negative input is NOT rejected — its sign is
     *                            silently DISCARDED and the ABSOLUTE VALUE is returned
     *                            (formatNumber('-50', allowNegative: false) === '50'). Never use
     *                            this flag to guard financial or signed input; a -50 debit comes
     *                            back as a +50 credit with no error. Check the sign yourself
     *                            before calling.
     * @return string Formatted number string. Never null, never throws.
     */
    public static function formatNumber(
        string|float|int|null $number,
        string $decimalSeparatorFrom = '.',
        string $decimalSeparatorTo = '.',
        string $thousandsSeparatorTo = '',
        string $prefix = '',
        string $suffix = '',
        ?int $decimalPlaces = null,
        bool $allowNegative = true
    ): string {
        $decimalSeparatorFrom = is_float($number) ? '.' : self::sanitizeSeparator($decimalSeparatorFrom, '.');
        $decimalSeparatorTo = self::sanitizeSeparator($decimalSeparatorTo, '.');
        $thousandsSeparatorTo = self::sanitizeSeparator($thousandsSeparatorTo, '');
        if ($thousandsSeparatorTo === $decimalSeparatorTo) {
            $thousandsSeparatorTo = '';
        }

        [$isNegative, $integerDigits, $fractionDigits] = self::parseDecimal($number, $decimalSeparatorFrom);

        if ($decimalPlaces !== null) {
            $decimalPlaces = max(0, $decimalPlaces);
            $fractionDigits = str_pad(substr($fractionDigits, 0, $decimalPlaces), $decimalPlaces, '0');
        }

        // Judged on the digits actually SHOWN, after truncation: "-0.001" at 2 places displays
        // no non-zero digit, and "-0.00" is not a number anyone means.
        if (trim($integerDigits . $fractionDigits, '0') === '') {
            $isNegative = false;
        }

        if ($thousandsSeparatorTo !== '') {
            $integerDigits = self::groupThousands($integerDigits, $thousandsSeparatorTo);
        }

        $result = ($isNegative && $allowNegative ? '-' : '')
            . $integerDigits
            . ($fractionDigits === '' ? '' : $decimalSeparatorTo . $fractionDigits);

        return trim(trim($prefix) . " " . $result . " " . trim($suffix));
    }

    /**
     * Strips digits and signs from a separator — they would make the output ambiguous — and
     * falls back to $fallback when nothing is left.
     *
     * Byte-based on purpose: the stripped characters are all ASCII, which never occur inside a
     * UTF-8 multibyte sequence, so this is exact on valid UTF-8 and cannot fail on invalid UTF-8.
     */
    private static function sanitizeSeparator(string $separator, string $fallback): string {
        $separator = str_replace(['0', '1', '2', '3', '4', '5', '6', '7', '8', '9', '+', '-'], '', $separator);

        return $separator === '' ? $fallback : $separator;
    }

    /**
     * Splits a lenient numeric input into [isNegative, integer digits, fraction digits].
     *
     * The integer digits carry no leading zeros ("0" when there are none); the fraction digits
     * are returned verbatim, trailing zeros included. Scientific notation is expanded here.
     *
     * @return array{0: bool, 1: string, 2: string}
     */
    private static function parseDecimal(string|float|int|null $number, string $decimalSeparator): array {
        $zero = [false, '0', ''];

        if ($number === null || (is_float($number) && !is_finite($number))) {
            return $zero;
        }
        $number = (string) $number;

        // U+2212 MINUS SIGN is what typeset/copied numbers carry ("−5"); the ASCII-only filter
        // below would drop it and silently flip the sign. Validator::isNegativeNumber() accepts it
        // too, so the two agree. A plain byte replace is safe on invalid UTF-8.
        $number = str_replace("\u{2212}", '-', $number);

        // Byte-mode filter, so invalid UTF-8 cannot make it fail. The separator is matched as a
        // whole sequence, and an "e"/"E" survives only in exponent position (preceded by a digit
        // or the separator, followed by an optionally signed digit).
        $separator = preg_quote($decimalSeparator, '/');
        preg_match_all(
            '/' . $separator . '|[0-9+\-]|(?<=[0-9]|' . $separator . ')[eE](?=[+\-]?[0-9])/',
            $number,
            $matches
        );
        $number = implode('', $matches[0] ?? []);

        // An input carrying no digit at all has nothing to format. Testing emptiness alone is not
        // enough: "---", "+" and "." all survive the filter above.
        if (!preg_match('/[0-9]/', $number)) {
            return $zero;
        }

        $isNegative = str_starts_with($number, '-');
        $exponentParts = preg_split('/[eE]/', $number);

        $mantissaParts = explode($decimalSeparator, $exponentParts[0]);
        $integerDigits = self::digitsOf($mantissaParts[0]);
        $fractionDigits = count($mantissaParts) > 1 ? self::digitsOf($mantissaParts[1]) : '';

        if (count($exponentParts) > 1) {
            // Expand scientific notation by shifting the decimal point over the mantissa's
            // DIGITS, purely on strings: converting to float first would lose precision. The
            // exponent's sign is read from the raw part, because digitsOf() strips it (losing it
            // is how 1.5E+20 once formatted as 0.000...015).
            $exponentDigits = ltrim(self::digitsOf($exponentParts[1]), '0');
            if (strlen($exponentDigits) > strlen((string) self::MAX_EXPONENT) || (int) $exponentDigits > self::MAX_EXPONENT) {
                return $zero;
            }
            $exponent = str_starts_with($exponentParts[1], '-') ? -(int) $exponentDigits : (int) $exponentDigits;

            $digits = $integerDigits . $fractionDigits;
            if (trim($digits, '0') === '') {
                return $zero;
            }

            // Where the decimal point sits inside $digits once the exponent has moved it.
            $pointPosition = strlen($integerDigits) + $exponent;
            if ($pointPosition <= 0) {
                $integerDigits = '';
                $fractionDigits = str_repeat('0', -$pointPosition) . $digits;
            } elseif ($pointPosition >= strlen($digits)) {
                $integerDigits = $digits . str_repeat('0', $pointPosition - strlen($digits));
                $fractionDigits = '';
            } else {
                $integerDigits = substr($digits, 0, $pointPosition);
                $fractionDigits = substr($digits, $pointPosition);
            }
        }

        $integerDigits = ltrim($integerDigits, '0');

        return [$isNegative, $integerDigits === '' ? '0' : $integerDigits, $fractionDigits];
    }

    /** ASCII digits of $value, in order (byte-based). */
    private static function digitsOf(string $value): string {
        return preg_replace('/[^0-9]/', '', $value);
    }

    /**
     * Inserts $separator every three digits from the right. Plain concatenation, not
     * preg_replace(): a separator such as "$1" or "\\" would otherwise be read as a backreference.
     */
    private static function groupThousands(string $digits, string $separator): string {
        $length = strlen($digits);
        $head = $length % 3 ?: 3;

        $grouped = substr($digits, 0, $head);
        for ($i = $head; $i < $length; $i += 3) {
            $grouped .= $separator . substr($digits, $i, 3);
        }

        return $grouped;
    }

    /**
     * Builds a nested tree structure from a flat array based on parent-child relationships.
     *
     * CONSUMES $items. It is taken by reference and every element placed into the tree is
     * REMOVED from it. On return $items holds only the ORPHANS — elements whose $parentField
     * matched no element's $idField and which are therefore absent from the returned tree.
     * That is the only way to detect them; they are dropped silently otherwise. Pass a copy if
     * you still need the flat list afterwards.
     *
     * Parent and child are matched with STRICT comparison (===), so the values of $idField and
     * $parentField must share the same PHP type. A driver that returns ids as strings ("1") but
     * parent references as ints (1) — or vice versa — nests NOTHING and every row comes back as
     * a root. Cast the rows to one consistent type before calling.
     *
     * $childrenField is only SET on elements that actually have children; a leaf does not carry
     * an empty $childrenField key. Test with isset(), not array_key_exists() on every node.
     *
     * Every element is placed AT MOST ONCE, so malformed data terminates: a self-parented row,
     * a parent cycle, or a root whose id equals $parentId (e.g. {id: null, idFather: null})
     * is nested once and the cycle is cut there. (These used to recurse until memory ran out.)
     *
     * @param array $items Flat list of elements, by reference and consumed (see above). EVERY
     *                     element must contain both $idField and $parentField; a missing key
     *                     raises an E_WARNING rather than being treated as a root.
     * @param string $parentField Field name holding the parent ID reference. A root element is
     *                            one whose $parentField === $parentId.
     * @param string $idField Field name holding the element's unique ID.
     * @param string $childrenField Field name under which children are nested.
     * @param int|string|null $parentId ID of the parent to build from; NULL (default) builds
     *                                  from the roots, i.e. elements whose $parentField is NULL.
     * @return array The nested tree: the matching elements, each with its descendants nested
     *               under $childrenField. Empty array when $items is empty or nothing matches.
     *
     * @see https://stackoverflow.com/questions/29384548/php-how-to-build-tree-structure-list
     */
    public static function buildNestedArray(
        array &$items,
        string $parentField = 'idFather',
        string $idField = 'id',
        string $childrenField = 'children',
        int|string|null $parentId = null
    ): array {
        if (empty($items)) {
            return [];
        }

        $branch = [];
        foreach ($items as $key => $element) {
            // foreach iterates a snapshot; skip what a deeper call has already placed.
            if (!array_key_exists($key, $items)) {
                continue;
            }

            if ($element[$parentField] === $parentId) {
                // Consume the element BEFORE descending. Removing it afterwards let the recursion
                // pick the very same element up again whenever it was its own ancestor — a row
                // whose parent is itself, a parent cycle, or simply {id: null, idFather: null}
                // (an unsaved row) — and recurse until memory or the stack ran out. Each element
                // can now be placed at most once, so the depth is bounded by count($items).
                unset($items[$key]);

                $children = self::buildNestedArray(
                    $items,
                    $parentField,
                    $idField,
                    $childrenField,
                    $element[$idField]
                );

                if (!empty($children)) {
                    $element[$childrenField] = $children;
                }

                $branch[] = $element;
            }
        }

        return $branch;
    }

    /**
     * Cleans empty elements from a tree-structured multi-dimensional array.
     *
     * The rule applied per element is NOT symmetric, and the branch case dominates:
     *  - An element that HAS $childrenKey is kept only if its subtree survives filtering. Its own
     *    $requiredFieldIfEmpty is NEVER consulted. Consequently an element carrying an EMPTY
     *    children array is ALWAYS dropped, even when its required field is filled.
     *  - An element WITHOUT $childrenKey (a leaf) is dropped only when $requiredFieldIfEmpty is
     *    given and that field is empty() on the element.
     * With $requiredFieldIfEmpty = null, leaves are always kept and only empty branches go.
     *
     * A $childrenKey whose value is not an array (typically NULL from a query) counts as an EMPTY
     * children array, so that element is dropped too; it used to raise a TypeError. An element
     * that is not an array has no $childrenKey and is judged as a leaf: an object by its property
     * $requiredFieldIfEmpty, a scalar as having that field empty.
     *
     * Emptiness uses PHP's empty(), so "0", 0, "" and false all count as empty. (That applies to
     * the field's VALUE only: the field NAME "0" is a real key and enables the check.)
     *
     * ORIGINAL KEYS ARE PRESERVED, at every depth. Removals leave gaps in the numeric keys and
     * the result is NOT reindexed, so json_encode() renders a filtered list as a JSON OBJECT
     * ({"1":{...}}), not an array. Run array_values() over it before serialising to a client
     * that expects a list.
     *
     * @param array $elements Input array with hierarchical structure. Returned as-is when empty.
     * @param string $childrenKey Key identifying the children array on an element.
     * @param string|null $requiredFieldIfEmpty Field name that must be non-empty for a LEAF to
     *                                          survive. NULL disables the check.
     * @return array Filtered array, keys preserved (see above).
     */
    public static function cleanEmptyTree(
        array $elements,
        string $childrenKey = 'children',
        ?string $requiredFieldIfEmpty = null
    ): array {
        if (empty($elements)) {
            return $elements;
        }

        $checkRequiredField = $requiredFieldIfEmpty !== null && $requiredFieldIfEmpty !== '';

        $filteredElements = $elements;
        foreach ($elements as $index => $item) {
            if (is_array($item) && array_key_exists($childrenKey, $item)) {
                $filteredChildren = is_array($item[$childrenKey])
                    ? self::cleanEmptyTree($item[$childrenKey], $childrenKey, $requiredFieldIfEmpty)
                    : [];

                if (empty($filteredChildren)) {
                    unset($filteredElements[$index]);
                } else {
                    $filteredElements[$index][$childrenKey] = $filteredChildren;
                }
            } elseif ($checkRequiredField && self::fieldIsEmpty($item, $requiredFieldIfEmpty)) {
                unset($filteredElements[$index]);
            }
        }

        return $filteredElements;
    }

    /**
     * empty() on an array key or object property, without the Error that `$object['key']` or
     * `$scalar['key']` would raise. A scalar has no fields, so its field counts as empty.
     */
    private static function fieldIsEmpty(mixed $item, string $field): bool {
        if (is_array($item)) {
            return empty($item[$field]);
        }
        if (is_object($item)) {
            return empty($item->{$field});
        }

        return true;
    }

    /**
     * Applies a generic mask pattern to a given value.
     *
     * The mask uses '#' to represent characters from the input string.
     * Example: applyGenericMask("12345678901", "###.###.###-##") → "123.456.789-01"
     *
     * @param string $value The raw value to be masked
     * @param string $mask The mask pattern (use '#' for dynamic chars)
     * @return string Masked string
     *
     * @link http://blog.clares.com.br/php-mascara-cnpj-cpf-data-e-qualquer-outra-coisa/
     */
    private static function applyGenericMask(string $value, string $mask): string {
        $masked = '';
        $charIndex = 0;

        for ($i = 0; $i < strlen($mask); $i++) {
            if ($mask[$i] === '#') {
                if (isset($value[$charIndex])) {
                    $masked .= $value[$charIndex++];
                }
            } else {
                $masked .= $mask[$i];
            }
        }

        return $masked;
    }

    /**
     * Formats a CNPJ string into the mask "00.000.000/0000-00".
     *
     * Presentation only — this performs NO CNPJ validation: the check digits are not verified.
     * Letters are KEPT and UPPERCASED (only non-alphanumerics are stripped): since July 2026 the
     * Receita Federal issues ALPHANUMERIC CNPJs, whose letters are uppercase, so
     * "12abc34500de99" masks to "12.ABC.345/00DE-99". Validate before calling if the value must
     * be a real CNPJ.
     *
     * Length handling: shorter than 14 is left-padded with zeros; LONGER THAN 14 IS SILENTLY
     * TRUNCATED to the first 14 characters, dropping the rest without any error.
     *
     * @param string|null $number Raw CNPJ value; formatting characters are stripped first.
     * @return string Masked CNPJ, or "" when $number is null, "" or has no letter/digit at all
     *                (note: NOT null). A blank or punctuation-only value, e.g. a CHAR(14) column
     *                full of spaces, used to be masked as the fabricated "00.000.000/0000-00".
     */
    public static function formatCnpj(?string $number): string {
        if ($number === null || $number === "") {
            return "";
        }

        $clean = strtoupper(Str::onlyLettersAndNumbers($number));
        if ($clean === '') {
            return '';
        }
        $padded = str_pad($clean, 14, '0', STR_PAD_LEFT);

        return self::applyGenericMask($padded, '##.###.###/####-##');
    }

    /**
     * Formats a numeric string into a CPF format: "000.000.000-00".
     *
     * Presentation only — NO CPF validation: check digits are not verified and letters are not
     * rejected (only non-alphanumerics are stripped).
     *
     * Length handling: shorter than 11 is left-padded with zeros; LONGER THAN 11 IS SILENTLY
     * TRUNCATED to the first 11 characters.
     *
     * @param string|null $number Raw CPF value; formatting characters are stripped first.
     * @return string Masked CPF, or "" when $number is null, "" or has no letter/digit at all
     *                (note: NOT null) — never a fabricated "000.000.000-00".
     */
    public static function formatCpf(?string $number): string {
        if ($number === null || $number === "") {
            return "";
        }

        $clean = Str::onlyLettersAndNumbers($number);
        if ($clean === '') {
            return '';
        }
        $padded = str_pad($clean, 11, '0', STR_PAD_LEFT);

        return self::applyGenericMask($padded, '###.###.###-##');
    }

    /**
     * Formats a numeric string as either CPF or CNPJ depending on its length.
     *
     * Length decides, alone: 11 characters or fewer (after non-alphanumerics are stripped) is
     * masked as CPF, anything longer as CNPJ. A 12- or 13-character value is therefore masked as
     * a zero-padded CNPJ rather than being reported as invalid. No CPF/CNPJ validation is
     * performed; the truncation and letter behaviour of formatCpf()/formatCnpj() applies.
     *
     * @param string|null $number Raw document value.
     * @return string Masked CPF or CNPJ, or "" when $number is null or "".
     */
    public static function formatCpfOrCnpj(?string $number): string {
        if ($number === null || $number === "") {
            return "";
        }

        $number = Str::onlyLettersAndNumbers($number);

        if (strlen($number) <= 11) {
            return self::formatCpf($number);
        }
        return self::formatCnpj($number);
    }

    /**
     * Formats a numeric string into Brazilian CEP format: "00000-000".
     *
     * Shorter than 8 is left-padded with zeros; LONGER THAN 8 IS SILENTLY TRUNCATED to the first
     * 8 characters. No CEP existence or validity check is performed, and letters are not
     * rejected (only non-alphanumerics are stripped).
     *
     * @param string|null $number Raw CEP value.
     * @return string|null Masked CEP. Unlike formatCpf()/formatCnpj(), the empty input is
     *                     returned UNCHANGED and keeps its type: null in gives null out, "" in
     *                     gives "" out. A value with no letter/digit at all (e.g. "        ")
     *                     gives "", not the fabricated "00000-000".
     */
    public static function formatCep(?string $number): ?string {
        if ($number === null || $number === '') {
            return $number;
        }

        $clean = Str::onlyLettersAndNumbers($number);
        if ($clean === '') {
            return '';
        }
        $padded = str_pad($clean, 8, '0', STR_PAD_LEFT);

        return self::applyGenericMask($padded, '#####-###');
    }

    /**
     * Removes formatting from a CEP string, returning only numeric characters.
     *
     * @param string|null $cep The formatted CEP string.
     * @return string|null The digits of $cep, or null when $cep is null or "". The string "0" is
     *                     content and returns "0" (it used to return null via empty()). An input
     *                     with no digits at all (e.g. "abc") returns "".
     */
    public static function unformatCep(?string $cep): ?string {
        if ($cep === null || $cep === '') {
            return null;
        }

        return Str::onlyNumbers($cep);
    }

    /**
     * Removes formatting from a CPF / CNPJ / RG and others string, returning only alphanumeric
     * characters. Letters are KEPT (an RG may carry one); accented letters are not.
     *
     * @param string|null $value Document with formatting.
     * @return string|null The letters and digits of $value, or null when $value is null or "".
     *                     The string "0" is content and returns "0" (it used to return null via
     *                     empty()). An input with no alphanumerics at all (e.g. "--") returns "".
     */
    public static function unformatDocument(?string $value): ?string {
        if ($value === null || $value === '') {
            return null;
        }

        return Str::onlyLettersAndNumbers($value);
    }

}