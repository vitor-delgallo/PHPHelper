<?php

namespace VD\PHPHelper;

class Formatter {
    /**
     * Formats a number for display according to the given separator / prefix / suffix rules.
     *
     * This method is TOTAL and LENIENT: it never throws and never returns null. Any character
     * that is not a digit, a sign, an "e"/"E" or $decimalSeparatorFrom is discarded before
     * parsing, and an input carrying no digit at all (null, "", "abc", "---", "+", ".") formats
     * as "0". It is a FORMATTER, not a validator — it will happily format a value it cannot make
     * sense of, and it reports nothing when it discards part of the input. Validate BEFORE
     * calling if correctness matters.
     *
     * Decimals are TRUNCATED, never rounded: formatNumber('1.999', decimalPlaces: 2) is '1.99'.
     *
     * Scientific notation ("1.5E+20", "1.5e-7") is accepted and expanded to a plain decimal
     * string, including for positive exponents. Note that PHP renders any float of magnitude
     * >= ~1e15 in E notation on string cast, so this path is reached without the caller asking
     * for it. Within the E branch the mantissa's decimal separator is ALWAYS ".", regardless of
     * $decimalSeparatorFrom, because that is what PHP itself emits.
     *
     * @param string|float|int|null $number Raw number to format. NULL and "" format as "0".
     * @param string $decimalSeparatorFrom Decimal separator used in the INPUT. It doubles as a
     *                                     whitelist: any character of $number that is not a
     *                                     digit, a sign, "e"/"E" or this separator is discarded
     *                                     BEFORE parsing. So passing a separator the input does
     *                                     not actually use silently deletes the real one and
     *                                     rescales the value — formatNumber('1.5', ',') is '15',
     *                                     not '1.5'. Passing "" or a digit/sign has the same
     *                                     effect. This must match the input's real separator.
     * @param string $decimalSeparatorTo Decimal separator for the OUTPUT. Same stripping and
     *                                   "." fallback as above.
     * @param string $thousandsSeparatorTo Thousands separator for the OUTPUT; "" for none. It is
     *                                     ignored when it would equal $decimalSeparatorTo, and
     *                                     only applies once the integer part reaches 4 digits.
     *                                     WARNING: this path routes the value through
     *                                     number_format(), so the result is subject to float
     *                                     precision (~15-17 significant digits). Values beyond
     *                                     that lose their low-order digits.
     * @param string $prefix Optional prefix, separated from the number by a space (e.g. "R$").
     * @param string $suffix Optional suffix, separated from the number by a space (e.g. "%").
     * @param int|null $decimalPlaces Max decimal places to keep. NULL = keep every decimal the
     *                                input had; 0 = integer only.
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
        $result = '';

        if($number === null) {
            $number = "";
        }
        $number = (string) $number;

        $number = Str::keepOnlyCharacters($number, '0123456789+-eE' . $decimalSeparatorFrom);
        // An input carrying no digit at all has nothing to format. Testing emptiness alone is not
        // enough: "---", "+" and "." all survive the filter above and would otherwise fall
        // through to return the leftover sign/separator ("-") or an empty string as if it were a
        // formatted number.
        if (Str::onlyNumbers($number) === '') $number = "0";
        $number = Str::strToUpper($number);

        if (Str::containsString($number, "E")) {
            $decimalSeparatorFrom = '.';
        } else {
            $decimalSeparatorFrom = Str::removeCharacters($decimalSeparatorFrom, '0123456789+-' . $prefix . $suffix);
            if (empty($decimalSeparatorFrom)) $decimalSeparatorFrom = '.';
        }

        $decimalSeparatorTo = Str::removeCharacters($decimalSeparatorTo, '0123456789+-' . $prefix . $suffix);
        if (empty($decimalSeparatorTo)) $decimalSeparatorTo = '.';
        $thousandsSeparatorTo = Str::removeCharacters($thousandsSeparatorTo, '0123456789+-' . $prefix . $suffix);
        if (empty($thousandsSeparatorTo) || $thousandsSeparatorTo === $decimalSeparatorTo) $thousandsSeparatorTo = '';

        if (empty($decimalPlaces) && $decimalPlaces !== null) {
            $decimalPlaces = 0;
        }

        $numberParts = explode("E", $number);
        if (count($numberParts) > 1) {
            // Expand scientific notation into a plain decimal string by shifting the decimal
            // point over the mantissa's DIGITS. This is done purely on strings: converting the
            // mantissa to float first would lose precision and, worse, would make the sign of
            // both the mantissa and the exponent easy to drop.
            $mantissa = Str::keepOnlyCharacters($numberParts[0], '0123456789-.');
            $isNegative = Validator::isNegativeNumber($mantissa);

            // The exponent's sign MUST be captured before onlyNumbers() strips it, otherwise
            // every exponent reads as negative and 1.5E+20 formats as 0.000...015.
            $exponentIsNegative = Validator::isNegativeNumber($numberParts[1]);
            $exponent = (int) Str::onlyNumbers($numberParts[1]);

            $mantissaParts = explode('.', $mantissa);
            $integerDigits = Str::onlyNumbers($mantissaParts[0]);
            $fractionDigits = count($mantissaParts) > 1 ? Str::onlyNumbers($mantissaParts[1]) : '';

            $digits = $integerDigits . $fractionDigits;
            // Where the decimal point sits inside $digits once the exponent has moved it:
            // right for a positive exponent, left for a negative one.
            $pointPosition = strlen($integerDigits) + ($exponentIsNegative ? -$exponent : $exponent);

            if (trim($digits, '0') === '') {
                $number = "0";
            } else {
                if ($pointPosition <= 0) {
                    $integerPart = '0';
                    $fractionPart = str_repeat('0', -$pointPosition) . $digits;
                } elseif ($pointPosition >= strlen($digits)) {
                    $integerPart = $digits . str_repeat('0', $pointPosition - strlen($digits));
                    $fractionPart = '';
                } else {
                    $integerPart = substr($digits, 0, $pointPosition);
                    $fractionPart = substr($digits, $pointPosition);
                }

                $integerPart = ltrim($integerPart, '0');
                if ($integerPart === '') $integerPart = '0';

                $number = ($isNegative ? "-" : "")
                    . $integerPart
                    . ($fractionPart === '' ? '' : $decimalSeparatorFrom . $fractionPart);
            }
        }

        $numberParts = explode($decimalSeparatorFrom, $number);
        if (count($numberParts) > 1) {
            $numberParts[1] = Str::onlyNumbers($numberParts[1]);
        }

        if (Validator::isNegativeNumber($numberParts[0]) && $allowNegative) {
            $result .= '-';
        }

        $numberParts[0] = Str::onlyNumbers($numberParts[0]);
        $result .= $numberParts[0];

        if ($decimalPlaces === null) {
            $decimalPlaces = count($numberParts) > 1 ? strlen($numberParts[1]) : 0;
        }

        if (count($numberParts) > 1 && $decimalPlaces > 0) {
            $result .= $decimalSeparatorTo . substr((string) $numberParts[1], 0, $decimalPlaces);
        }

        if (!empty($thousandsSeparatorTo) && strlen($numberParts[0]) >= 4) {
            $result = number_format(
                str_replace($decimalSeparatorTo, ".", $result),
                $decimalPlaces,
                $decimalSeparatorTo,
                $thousandsSeparatorTo
            );
        }

        return trim(trim($prefix) . " " . $result . " " . trim($suffix));
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
            if ($element[$parentField] === $parentId) {
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
                unset($items[$key]);
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
     * Emptiness uses PHP's empty(), so "0", 0, "" and false all count as empty.
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

        $filteredElements = $elements;
        foreach ($elements as $index => $item) {
            if (Validator::hasProperty($childrenKey, $item)) {
                $filteredChildren = self::cleanEmptyTree(
                    $item[$childrenKey],
                    $childrenKey,
                    $requiredFieldIfEmpty
                );

                if (empty($filteredChildren)) {
                    unset($filteredElements[$index]);
                } else {
                    $filteredElements[$index][$childrenKey] = $filteredChildren;
                }
            } elseif (!empty($requiredFieldIfEmpty) && empty($item[$requiredFieldIfEmpty])) {
                unset($filteredElements[$index]);
            }
        }

        return $filteredElements;
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
     * Formats a numeric string into a CNPJ format: "00.000.000/0000-00".
     *
     * Presentation only — this performs NO CNPJ validation: the check digits are not verified,
     * and letters are NOT rejected (only non-alphanumerics are stripped), so "ABC" masks to
     * "00.000.000/000A-BC". Validate before calling if the value must be a real CNPJ.
     *
     * Length handling: shorter than 14 is left-padded with zeros; LONGER THAN 14 IS SILENTLY
     * TRUNCATED to the first 14 characters, dropping the rest without any error.
     *
     * @param string|null $number Raw CNPJ value; formatting characters are stripped first.
     * @return string Masked CNPJ, or "" when $number is null or "" (note: NOT null).
     */
    public static function formatCnpj(?string $number): string {
        if ($number === null || $number === "") {
            return "";
        }

        $clean = Str::onlyLettersAndNumbers($number);
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
     * @return string Masked CPF, or "" when $number is null or "" (note: NOT null).
     */
    public static function formatCpf(?string $number): string {
        if ($number === null || $number === "") {
            return "";
        }

        $clean = Str::onlyLettersAndNumbers($number);
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
     *                     gives "" out.
     */
    public static function formatCep(?string $number): ?string {
        if ($number === null || $number === '') {
            return $number;
        }

        $clean = Str::onlyLettersAndNumbers($number);
        $padded = str_pad($clean, 8, '0', STR_PAD_LEFT);

        return self::applyGenericMask($padded, '#####-###');
    }

    /**
     * Removes formatting from a CEP string, returning only numeric characters.
     *
     * @param string|null $cep The formatted CEP string.
     * @return string|null The digits of $cep, or null when $cep is empty by PHP's empty()
     *                     semantics — which includes the string "0", so unformatCep("0") is null,
     *                     not "0". An input with no digits at all (e.g. "abc") returns "".
     */
    public static function unformatCep(?string $cep): ?string {
        if (empty($cep)) {
            return null;
        }

        return Str::onlyNumbers($cep);
    }

    /**
     * Removes formatting from a CPF / CNPJ / RG and others string, returning only alphanumeric
     * characters. Letters are KEPT (an RG may carry one); accented letters are not.
     *
     * @param string|null $value Document with formatting.
     * @return string|null The letters and digits of $value, or null when $value is empty by PHP's
     *                     empty() semantics — which includes the string "0", so
     *                     unformatDocument("0") is null, not "0". An input with no alphanumerics
     *                     at all (e.g. "--") returns "".
     */
    public static function unformatDocument(?string $value): ?string {
        if (empty($value)) {
            return null;
        }

        return Str::onlyLettersAndNumbers($value);
    }

}