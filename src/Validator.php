<?php

namespace VD\PHPHelper;

class Validator {
    /**
     * Function isHex.
     * Checks if the given string is a valid hexadecimal value.
     *
     * @param string|null $value The string to be tested
     * @return bool
     *
     * @ref https://stackoverflow.com/questions/13112934/ishex-and-isocta-functions
     */
    public static function isHex(?string $value): bool {
        if(!extension_loaded('ctype') || $value === null || $value === '') {
            return false;
        }
        return ctype_xdigit($value);
    }

    /**
     * Function isOctal.
     * Checks if the given string is a valid octal value.
     *
     * @param string|null $value The string to be tested
     * @return bool
     *
     * @ref https://stackoverflow.com/questions/13112934/ishex-and-isocta-functions
     */
    public static function isOctal(?string $value): bool {
        if($value === null || $value === '') {
            return false;
        }
        return decoct(octdec($value)) == $value;
    }

    /**
     * Checks if the given date string is valid according to the specified format.
     *
     * @param string|null $date The date string to validate
     * @param string|null $format The expected date format
     * @return bool
     *
     * @ref https://stackoverflow.com/questions/19271381/correctly-determine-if-date-string-is-a-valid-date-in-that-format
     */
    public static function validateDate(?string $date, ?string $format = null): bool {
        return DateTime::validateDate($date, $format);
    }

    /**
     * Validates an email address.
     *
     * @param string|null $email The email address to validate
     * @return bool TRUE if the email is valid, FALSE otherwise
     */
    public static function validateMail(?string $email): bool {
        return Mailer::validateMail($email);
    }

    /**
     * Function isStrongPassword.
     * Validates whether the given password meets the strength requirements.
     *
     * @param string|null $password The password string to be tested
     * @param array $rules Validation rules:
     *                      'minLength'          => Minimum total characters
     *                      'maxLength'          => Maximum total characters
     *                      'minLowercase'       => Minimum lowercase letters
     *                      'maxLowercase'       => Maximum lowercase letters
     *                      'minUppercase'       => Minimum uppercase letters
     *                      'maxUppercase'       => Maximum uppercase letters
     *                      'minDigits'          => Minimum digits
     *                      'maxDigits'          => Maximum digits
     *                      'minSpecialChars'    => Minimum special characters
     *                      'maxSpecialChars'    => Maximum special characters
     *
     * @return bool TRUE if the password is strong, FALSE otherwise
     *
     * @ref https://stackoverflow.com/questions/2637896/php-regular-expression-for-strong-password-validation/2639151
     */
    public static function validatePassword(?string $password, array $rules = []): bool {
        if (empty($password)) return false;

        $rules['minLength'] = $rules['minLength'] ?? 8;
        $rules['minLowercase'] = $rules['minLowercase'] ?? 1;
        $rules['minUppercase'] = $rules['minUppercase'] ?? 1;
        $rules['minDigits'] = $rules['minDigits'] ?? 1;
        $rules['minSpecialChars'] = $rules['minSpecialChars'] ?? 1;

        $defaultKeys = [
            'minLength', 'maxLength',
            'minLowercase', 'maxLowercase',
            'minUppercase', 'maxUppercase',
            'minDigits', 'maxDigits',
            'minSpecialChars', 'maxSpecialChars'
        ];

        foreach ($defaultKeys as $key) {
            if (str_starts_with($key, 'min') && (!isset($rules[$key]) || !filter_var($rules[$key], FILTER_VALIDATE_INT))) {
                $rules[$key] = "0";
            }

            if (str_starts_with($key, 'max') && (!isset($rules[$key]) || !filter_var($rules[$key], FILTER_VALIDATE_INT))) {
                $rules[$key] = "";
            }

            $minKey = str_replace('max', 'min', $key);
            if (
                str_starts_with($key, 'max') &&
                $rules[$key] !== "" &&
                $rules[$minKey] !== "" &&
                (int) $rules[$key] < (int) $rules[$minKey]
            ) {
                $rules[$key] = $rules[$minKey];
            }
        }

        $regex  = '/^(?=(?:.*[A-Z]){' . $rules['minUppercase'] . ',' . $rules['maxUppercase'] . '})';
        $regex .= '(?=(?:.*[a-z]){' . $rules['minLowercase'] . ',' . $rules['maxLowercase'] . '})';
        $regex .= '(?=(?:.*\\d){' . $rules['minDigits'] . ',' . $rules['maxDigits'] . '})';
        $regex .= '(?=(?:.*[!@#$%^&*()\\-_=+{};:,<.>]){' . $rules['minSpecialChars'] . ',' . $rules['maxSpecialChars'] . '})';
        $regex .= '(.{' . $rules['minLength'] . ',' . $rules['maxLength'] . '})$/';

        return !!preg_match($regex, $password);
    }

    /**
     * Validates whether a given string is a valid JSON format.
     *
     * @param string|null $jsonString The string to test
     * @return bool TRUE if valid JSON, FALSE otherwise
     *
     * @ref https://stackoverflow.com/questions/6041741/fastest-way-to-check-if-a-string-is-json-in-php
     */
    public static function validateJson(?string $jsonString): bool {
        if (!empty($jsonString)) {
            @json_decode($jsonString);
            return json_last_error() === JSON_ERROR_NONE;
        }

        return false;
    }

    /**
     * Checks whether a given string is valid Base64 encoded.
     *
     * @param string|null $input The string to verify
     * @return bool TRUE if valid Base64, FALSE otherwise
     */
    public static function isBase64Encoded(?string $input): bool {
        if (empty($input)) {
            return false;
        }

        return base64_encode(Parser::base64Decode($input)) === $input;
    }

    /**
     * Checks whether a given string is valid Base64 URL-safe encoded.
     *
     * @param string|null $input The string to verify
     * @return bool TRUE if valid Base64 URL-safe, FALSE otherwise
     */
    public static function isBase64UrlEncoded(?string $input): bool {
        if (empty($input)) {
            return false;
        }

        return base64_url_encode(base64_url_decode($input, true)) === $input;
    }

    /**
     * Validates whether a given string is a valid XML.
     *
     * @param string|null $xmlContent The string to test
     * @return bool TRUE if valid XML, FALSE otherwise
     *
     * @ref https://stackoverflow.com/questions/4554233/how-check-if-a-string-is-a-valid-xml-with-out-displaying-a-warning-in-php
     */
    public static function validateXml(?string $xmlContent): bool {
        $xmlContent = trim($xmlContent);

        if (
            !extension_loaded('simplexml') ||
            !extension_loaded('libxml') ||
            empty($xmlContent) ||
            stripos($xmlContent, '<!DOCTYPE html>') !== false
        ) {
            return false;
        }

        libxml_use_internal_errors(true);
        simplexml_load_string($xmlContent);
        $errors = libxml_get_errors();
        libxml_clear_errors();

        return empty($errors);
    }

    /**
     * Checks if a variable is considered empty, **except** when it's numeric zero (0 or "0").
     *
     * @param mixed $value Value to check
     * @return bool True if empty (excluding zero); false otherwise
     */
    public static function emptyExceptZero($value): bool {
        return
            $value === null ||
            $value === "" ||
            $value === "\0" ||
            (
                is_object($value) &&
                empty((array) $value)
            ) ||
            (
                is_array($value) &&
                count($value) <= 0
            );
    }

    /**
     * Checks if a property exists on an object or array (converted to object).
     *
     * @param string $property Property to check
     * @param array|object $target The array or object to inspect
     * @return bool
     */
    public static function hasProperty(string $property, array|object $target): bool {
        if (empty($target) || (!is_array($target) && !is_object($target))) {
            return false;
        }

        if (is_array($target)) {
            $target = (object) $target;
        }
        return property_exists($target, $property);
    }

    /**
     * Checks if the given array uses only numeric keys.
     *
     * @param array $array Array to be checked
     * @return bool True if the array has numeric keys, false otherwise
     */
    public static function isNumericArray(array $array): bool {
        if (empty($array)) return false;

        reset($array);
        return filter_var(key($array), FILTER_VALIDATE_INT) !== false;
    }

    /**
     * Checks whether a given value is considered completely empty,
     * including edge cases like "undefined", "null", "{}", "[]", etc.
     *
     * @param mixed $value The value to check
     * @return bool True if considered completely empty, false otherwise
     */
    public static function isCompletelyEmpty(mixed $value): bool {
        if (
            self::emptyExceptZero($value) ||
            (
                filter_var($value, FILTER_VALIDATE_INT) &&
                empty((int) $value)
            ) ||
            (
                filter_var($value, FILTER_VALIDATE_FLOAT) &&
                empty((float) $value)
            ) ||
            Str::strToUpper(Str::removeExcessSpaces((string) $value, false)) === "UNDEFINED" ||
            Str::strToUpper(Str::removeExcessSpaces((string) $value, false)) === "NULL" ||
            Str::strToUpper(Str::removeExcessSpaces((string) $value, false)) === "FALSE" ||
            Str::strToUpper(Str::removeExcessSpaces((string) $value, false)) === "{}" ||
            Str::strToUpper(Str::removeExcessSpaces((string) $value, false)) === "[]" ||
            Str::strToUpper(Str::removeExcessSpaces((string) $value, false)) === "N" ||
            Str::strToUpper(Str::removeExcessSpaces((string) $value, false)) === "NO" ||
            Str::strToUpper(Str::removeExcessSpaces((string) $value, false)) === "TNO"
        ) {
            return true;
        }

        return false;
    }

    /**
     * Checks if the string represents a negative number by detecting the minus sign at the beginning.
     *
     * @param mixed $value The string to check for a leading negative symbol
     * @return bool True if the string starts with a "-", otherwise false
     *
     * @see https://stackoverflow.com/questions/15814592/how-do-i-include-negative-decimal-numbers-in-this-regular-expression
     */
    public static function isNegativeNumber(mixed $value): bool {
        if (empty($value)) {
            return false;
        }

        return Str::subStr(Str::removeExcessSpaces((string) $value, false), 0, 1) === "-";
    }

    /**
     * Validates a CPF number.
     *
     * @param string $cpf CPF number to validate.
     * @return bool True if valid, false otherwise.
     *
     * @see https://www.geradorcpf.com/script-validar-cpf-php.htm
     */
    public static function validateCpf(string $cpf): bool {
        if (empty($cpf)) return false;

        $cpf = str_pad(Str::onlyNumbers($cpf), 11, '0', STR_PAD_LEFT);
        if (Str::strLen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) {
            return false;
        }

        for ($t = 9; $t < 11; $t++) {
            $d = 0;
            for ($c = 0; $c < $t; $c++) {
                $d += $cpf[$c] * (($t + 1) - $c);
            }
            $d = ((10 * $d) % 11) % 10;
            if ($cpf[$c] != $d) return false;
        }

        return true;
    }

    /**
     * Validates a CNPJ number.
     *
     * @param string $cnpj CNPJ number to validate.
     * @return bool True if valid, false otherwise.
     *
     * @see https://www.todoespacoonline.com/w/2014/08/validar-cnpj-com-php/
     */
    public static function validateCnpj(string $cnpj): bool {
        $mem = $cnpj;

        $base = Str::subStr(Str::onlyNumbers($cnpj), 0, 12);
        $calculate = function (string $number, int $position = 5): int {
            $sum = 0;
            for ($i = 0; $i < Str::strLen($number); $i++) {
                $sum += $number[$i] * $position;
                $position = ($position - 1 < 2) ? 9 : $position - 1;
            }
            return $sum;
        };

        $firstCheck = $calculate($base);
        $firstDigit = ($firstCheck % 11) < 2 ? 0 : 11 - ($firstCheck % 11);

        $base .= $firstDigit;

        $secondCheck = $calculate($base, 6);
        $secondDigit = ($secondCheck % 11) < 2 ? 0 : 11 - ($secondCheck % 11);

        return ($base . $secondDigit) === $mem;
    }

    /**
     * Checks whether a given string contains HTML markup.
     *
     * Compares the original string with its stripped (non-HTML) version.
     * If they differ, HTML is likely present.
     *
     * @param string|null $text The string to test
     * @return bool True if the string contains HTML, false otherwise
     *
     * @link https://subinsb.com/php-check-if-string-is-html/
     */
    public static function validateHtml(?string $text): bool {
        if (empty($text)) {
            return false;
        }

        return $text !== strip_tags($text);
    }
}