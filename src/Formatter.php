<?php

namespace VD\PHPHelper;

class Formatter {
    /**
     * Formats a numeric string according to specified rules.
     *
     * @param string|float|int|null $number Raw number string to be formatted
     * @param string $decimalSeparatorFrom Decimal separator used in the input string
     * @param string $decimalSeparatorTo Decimal separator to use in the formatted output
     * @param string $thousandsSeparatorTo Thousands separator to use in the formatted output (leave empty for none)
     * @param string $prefix Optional prefix to prepend (e.g., "R$")
     * @param string $suffix Optional suffix to append (e.g., "%")
     * @param int|null $decimalPlaces Number of decimal places to limit.
     *                                NULL = unlimited, 0 = integers only
     * @param bool $allowNegative Whether negative values are allowed
     * @return string Formatted number string
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
        if (empty($number)) $number = "0";
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
            $numberParts[0] = Str::keepOnlyCharacters($numberParts[0], '0123456789-.');
            if (empty($numberParts[0])) $numberParts[0] = 0;

            $numberParts[1] = Str::onlyNumbers($numberParts[1]);
            if (empty($numberParts[1])) $numberParts[1] = 0;

            $eVal = $numberParts[0] * 1;
            $eMult = $numberParts[1] * 1;
            $isNegative = Validator::isNegativeNumber($eVal);
            while (Str::containsString($eVal, $decimalSeparatorFrom)) {
                $eVal *= 10;
                $eMult++;
            }

            if ($decimalPlaces === null) {
                $decimalPlaces = $eMult;
            }

            $eVal = str_pad(
                substr(str_pad($eVal, $eMult, "0", STR_PAD_LEFT), 0, $decimalPlaces),
                $decimalPlaces,
                "0",
                STR_PAD_RIGHT
            );

            $number = ($isNegative ? "-" : "") . "0" . $decimalSeparatorFrom . $eVal;

            if (empty($number * 1)) $number = "0";
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
     * @param array $items Reference to the flat array of elements
     * @param string $parentField The field name that holds the parent ID reference
     * @param string $idField The field name that holds the unique ID of the element
     * @param string $childrenField The field name where child elements will be nested
     * @param int|string|null $parentId The parent ID to build the tree from
     * @return array The nested tree structure
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
                    $element[$idField],
                    $parentField,
                    $idField,
                    $childrenField
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
     * @param array $elements Input array with hierarchical structure
     * @param string $childrenKey Key used to identify children arrays
     * @param string|null $requiredFieldIfEmpty Optional field name to remove element if it is empty
     *
     * @return array Filtered array
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
     * Pads with zeros on the left if length < 14.
     *
     * @param string|null $number Raw CNPJ digits
     * @return string Formatted CNPJ or original value if empty
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
     * Pads with zeros on the left if length < 11.
     *
     * @param string|null $number Raw CPF digits
     * @return string Formatted CPF or original value if empty
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
     * If the string length (after cleaning) is 11 or less, formats as CPF.
     * Otherwise, formats as CNPJ.
     *
     * @param string|null $number Raw numeric string
     * @return string Formatted CPF or CNPJ
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
     * Pads the number with leading zeros to ensure 8 digits.
     *
     * @param string|null $number Raw CEP number
     * @return string|null Formatted CEP or original value if empty
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
     * @param string|null $cep The formatted CEP string
     * @return string|null Only digits or null if empty
     */
    public static function unformatCep(?string $cep): ?string {
        if (empty($cep)) {
            return null;
        }

        return Str::onlyNumbers($cep);
    }

    /**
     * Removes formatting from a CPF / CNPJ / RG and others string, returning only alphanumeric characters.
     *
     * @param string|null $value Document with formatting
     * @return string|null Only digits and letters or null if empty
     */
    public static function unformatDocument(?string $value): ?string {
        if (empty($value)) {
            return null;
        }

        return Str::onlyLettersAndNumbers($value);
    }

}