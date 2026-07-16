<?php

namespace VD\PHPHelper;

class Validator {
    /**
     * Values that isCompletelyEmpty() treats as "empty" once normalized
     * (whitespace stripped, uppercased). See isCompletelyEmpty().
     *
     * @var string[]
     */
    private const COMPLETELY_EMPTY_SENTINELS = [
        'UNDEFINED', 'NULL', 'FALSE', '{}', '[]', 'N', 'NO', 'TNO',
    ];

    /**
     * The characters validatePassword() counts as "special".
     * Anything outside this set (and outside A-Z a-z 0-9) counts only toward the total length.
     *
     * @var string
     */
    private const PASSWORD_SPECIAL_CHARS = '!@#$%^&*()-_=+{};:,<.>';

    /**
     * Checks whether the given string is a valid hexadecimal value.
     *
     * Accepts only the characters 0-9 a-f A-F. There is no "0x" prefix handling, no sign and
     * no whitespace tolerance: "0xFF", "+FF" and " FF" are all rejected.
     *
     * @param string $value The string to test.
     * @return bool TRUE only if every character is a hex digit. FALSE for null, for the empty
     *              string, and — because this check cannot be answered without it — FALSE if
     *              ext-ctype is not loaded. ctype ships with PHP and is enabled by default, so
     *              that last case means "unable to tell", not "not hex".
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
     * Checks whether the given string is a valid octal value.
     *
     * Validates the CHARACTER SET directly: every character must be 0-7. There is no sign, no
     * whitespace and no prefix tolerance — "+7", " 7", "7 ", "-0", "0o7" and "8" are all
     * rejected. A leading zero is fine ("0700"), since that is how octal is normally written.
     *
     * This method does NOT round-trip through octdec()/decoct(). octdec() silently DISCARDS
     * invalid characters, which made "+7" and " 7" report as octal and additionally raised
     * E_DEPRECATED on exactly the invalid input a validator exists to reject.
     * File::getPermissionMode() gates chmod modes on this method, so a false positive here
     * becomes a wrong file permission.
     *
     * @param string|null $value The string to test.
     * @return bool TRUE only if the string is non-empty and consists solely of the digits 0-7.
     *              FALSE for null and for the empty string.
     *
     * @ref https://stackoverflow.com/questions/13112934/ishex-and-isocta-functions
     */
    public static function isOctal(?string $value): bool {
        if($value === null || $value === '') {
            return false;
        }
        return strspn($value, '01234567') === strlen($value);
    }

    /**
     * Checks whether the given date string is valid according to the specified format.
     *
     * The date must match the format EXACTLY: the parsed date is re-formatted and compared
     * against the input, so "2024-1-5" is NOT valid for "Y-m-d" and overflow dates such as
     * "2024-02-30" are rejected rather than rolled over into March.
     *
     * @param string|null $date The date string to validate. Null or "" returns FALSE.
     * @param string|null $format The expected format (see date()). When null or "", the
     *                            PROCESS-WIDE default from DateTime::getDefaultFormat() is used
     *                            — which is mutable global state (DateTime::setDefaultFormat())
     *                            and defaults to "Y-m-d". Pass an explicit format if the result
     *                            must not depend on that global.
     * @return bool TRUE only if $date is a real date exactly matching $format.
     *
     * @ref https://stackoverflow.com/questions/19271381/correctly-determine-if-date-string-is-a-valid-date-in-that-format
     */
    public static function validateDate(?string $date, ?string $format = null): bool {
        return DateTime::validateDate($date, $format);
    }

    /**
     * Validates the SYNTAX of an email address (delegates to Mailer::validateMail()).
     *
     * This is a pattern check only. It does NOT verify that the domain resolves, that an MX
     * record exists, or that the mailbox can receive mail — a syntactically valid address may
     * still be undeliverable. Do not treat a TRUE here as proof the address is real.
     *
     * @param string|null $email The email address to validate. Null or "" returns FALSE.
     * @return bool TRUE if the address is syntactically valid, FALSE otherwise.
     */
    public static function validateMail(?string $email): bool {
        return Mailer::validateMail($email);
    }

    /**
     * Validates whether the given password meets the configured strength requirements.
     *
     * RULES. Every rule is optional; only the keys below are accepted. Each value must be an
     * integer >= 0 (an integer-like string such as "8" is accepted). Passing null for a rule
     * means "omitted". Anything else — a float, a bool, a negative number, "abc", or a key not
     * on this list — throws \InvalidArgumentException instead of being silently discarded: a
     * password policy that quietly degrades to "no requirement" is worse than a loud error.
     *
     *   'minLength'       Minimum total characters       (default 8)
     *   'maxLength'       Maximum total characters       (default: NO limit)
     *   'minLowercase'    Minimum a-z                    (default 1)
     *   'maxLowercase'    Maximum a-z                    (default: NO limit)
     *   'minUppercase'    Minimum A-Z                    (default 1)
     *   'maxUppercase'    Maximum A-Z                    (default: NO limit)
     *   'minDigits'       Minimum 0-9                    (default 1)
     *   'maxDigits'       Maximum 0-9                    (default: NO limit)
     *   'minSpecialChars' Minimum special characters     (default 1)
     *   'maxSpecialChars' Maximum special characters     (default: NO limit)
     *
     * A max* rule of 0 means AT MOST ZERO — it is honoured, not treated as "unset". Because the
     * matching min* rules default to 1, forbidding a category requires passing BOTH bounds:
     * ['minSpecialChars' => 0, 'maxSpecialChars' => 0]. Passing 'maxSpecialChars' => 0 alone
     * contradicts the default 'minSpecialChars' => 1 and throws rather than silently raising the
     * maximum to 1.
     *
     * EXACT CHARACTER CLASSES — a caller who assumes "special == non-alphanumeric" is wrong:
     *   - special chars are exactly: ! @ # $ % ^ & * ( ) - _ = + { } ; : , < . >
     *   - characters outside that set AND outside A-Z a-z 0-9 (for example ~ [ ] ' " / ? | \ `
     *     space, and every accented or non-Latin letter) count toward 'minLength'/'maxLength'
     *     ONLY — they are neither letters, digits nor special characters here. "É" does not
     *     satisfy 'minUppercase'.
     *
     * Lengths are counted in CHARACTERS, not bytes (the pattern is UTF-8 aware). A password that
     * is not valid UTF-8, or that contains a line break, returns FALSE.
     *
     * @param string|null $password The password to test. Null or "" returns FALSE.
     * @param array $rules Rule overrides; see above. Defaults to the rule set above.
     * @return bool TRUE if the password satisfies every rule, FALSE otherwise.
     *
     * @throws \InvalidArgumentException If a rule key is unknown, or a rule value is not an
     *                                   integer >= 0, or a max* rule is lower than its min*.
     *
     * @ref https://stackoverflow.com/questions/2637896/php-regular-expression-for-strong-password-validation/2639151
     */
    public static function validatePassword(?string $password, array $rules = []): bool {
        $defaultKeys = [
            'minLength', 'maxLength',
            'minLowercase', 'maxLowercase',
            'minUppercase', 'maxUppercase',
            'minDigits', 'maxDigits',
            'minSpecialChars', 'maxSpecialChars'
        ];

        $unknownKeys = array_diff(array_keys($rules), $defaultKeys);
        if (!empty($unknownKeys)) {
            throw new \InvalidArgumentException(
                'Validator::validatePassword(): unknown rule(s) "' . implode('", "', $unknownKeys)
                . '". Accepted rules: ' . implode(', ', $defaultKeys) . '.'
            );
        }

        $minDefaults = [
            'minLength' => 8,
            'minLowercase' => 1,
            'minUppercase' => 1,
            'minDigits' => 1,
            'minSpecialChars' => 1,
        ];

        foreach ($defaultKeys as $key) {
            // An omitted (or null) rule falls back to its default: 0 for min*, "" for max*.
            // "" builds the open-ended quantifier {n,} — i.e. no maximum.
            if (!isset($rules[$key])) {
                $rules[$key] = str_starts_with($key, 'min') ? ($minDefaults[$key] ?? 0) : "";
                continue;
            }

            // filter_var() returns int(0) for a legitimate 0, which is FALSY — testing its
            // truthiness (rather than `=== false`) is what used to turn "at most 0" into
            // "no maximum". Reject anything that is not a non-negative integer outright.
            $parsed = (is_int($rules[$key]) || is_string($rules[$key]))
                ? filter_var($rules[$key], FILTER_VALIDATE_INT)
                : false;

            if ($parsed === false || $parsed < 0) {
                throw new \InvalidArgumentException(
                    'Validator::validatePassword(): rule "' . $key . '" must be an integer >= 0, got '
                    . var_export($rules[$key], true) . '.'
                );
            }

            $rules[$key] = $parsed;
        }

        foreach (['Length', 'Lowercase', 'Uppercase', 'Digits', 'SpecialChars'] as $suffix) {
            $minKey = 'min' . $suffix;
            $maxKey = 'max' . $suffix;

            if ($rules[$maxKey] !== "" && $rules[$maxKey] < $rules[$minKey]) {
                throw new \InvalidArgumentException(
                    'Validator::validatePassword(): rule "' . $maxKey . '" (' . $rules[$maxKey] . ') is lower than "'
                    . $minKey . '" (' . $rules[$minKey] . '), so no password could ever satisfy both.'
                    . ' To forbid this category pass both "' . $minKey . '" => 0 and "' . $maxKey . '" => 0.'
                );
            }
        }

        if ($password === null || $password === '') {
            return false;
        }

        $characterClasses = [
            'Uppercase'    => '[A-Z]',
            'Lowercase'    => '[a-z]',
            'Digits'       => '\\d',
            'SpecialChars' => '[' . preg_quote(self::PASSWORD_SPECIAL_CHARS, '/') . ']',
        ];

        $regex = '/^';
        foreach ($characterClasses as $suffix => $characterClass) {
            $min = $rules['min' . $suffix];
            $max = $rules['max' . $suffix];

            // "At least $min": each repetition consumes up to one more member of the class.
            if ($min > 0) {
                $regex .= '(?=(?:.*' . $characterClass . '){' . $min . ',})';
            }

            // "At most $max" must be a NEGATIVE lookahead for $max + 1 occurrences. The upper
            // bound of a {min,max} quantifier inside a POSITIVE lookahead enforces nothing at
            // all: (?=(?:.*[A-Z]){1,2}) succeeds on a password with 50 uppercase letters,
            // because matching exactly 1 repetition already satisfies the lookahead. Every
            // max* rule except maxLength (which is anchored by ^...$ below) was a no-op.
            if ($max !== "") {
                $regex .= '(?!(?:.*' . $characterClass . '){' . ($max + 1) . '})';
            }
        }
        $regex .= '(.{' . $rules['minLength'] . ',' . $rules['maxLength'] . '})$/uD';

        // /u counts characters instead of bytes (a byte count lets a 7-character accented
        // password satisfy minLength 8). /D anchors $ at the very end (without it a trailing
        // newline is tolerated and slips past maxLength).
        return preg_match($regex, $password) === 1;
    }

    /**
     * Validates whether a given string is well-formed JSON.
     *
     * Any JSON value is accepted, not just objects and arrays: "0", "false", "null" and a bare
     * quoted string are all valid JSON documents and return TRUE. The empty string is NOT valid
     * JSON and returns FALSE.
     *
     * @param string|null $jsonString The string to test. Null or "" returns FALSE.
     * @return bool TRUE if the string parses as JSON, FALSE otherwise.
     *
     * @ref https://stackoverflow.com/questions/6041741/fastest-way-to-check-if-a-string-is-json-in-php
     */
    public static function validateJson(?string $jsonString): bool {
        // NOT empty(): empty("0") is true, and "0" is a valid JSON document.
        if ($jsonString === null || $jsonString === '') {
            return false;
        }

        @json_decode($jsonString);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Checks whether a given string is CANONICAL standard Base64.
     *
     * The check is a strict round-trip: the input is decoded and re-encoded, and the result must
     * be byte-identical to the input. Non-canonical but decodable input therefore returns FALSE
     * (wrong or missing padding, embedded whitespace/newlines, an alphabet that is not +/).
     *
     * @param string|null $input The string to verify. Null or "" returns FALSE.
     * @return bool TRUE only if the input is canonical standard Base64.
     */
    public static function isBase64Encoded(?string $input): bool {
        if (empty($input)) {
            return false;
        }

        return base64_encode(Parser::base64Decode($input)) === $input;
    }

    /**
     * Checks whether a given string is CANONICAL URL-safe Base64, in the dialect this library
     * produces — Parser::base64UrlEncode(), which maps "+/=" to "._-".
     *
     * Note this is NOT RFC 4648 base64url (which uses "-_" and strips padding): a token produced
     * by another library's base64url encoder will normally return FALSE here. It matches only
     * what Parser::base64UrlEncode() emits.
     *
     * The check is a strict round-trip: decode, re-encode, compare byte-for-byte. Anything that
     * fails to decode returns FALSE rather than throwing.
     *
     * @param string|null $input The string to verify. Null or "" returns FALSE.
     * @return bool TRUE only if the input is canonical URL-safe Base64 in this dialect.
     */
    public static function isBase64UrlEncoded(?string $input): bool {
        if (empty($input)) {
            return false;
        }

        // These were bare calls to base64_url_encode()/base64_url_decode(), which exist neither
        // in this package nor in PHP: every non-empty input raised a fatal Error (an Error, so
        // `catch (\Exception)` did not stop it). The real helpers live on Parser, and
        // base64UrlDecode() takes ONE argument.
        return Parser::base64UrlEncode(Parser::base64UrlDecode($input)) === $input;
    }

    /**
     * Validates whether a given string is well-formed XML.
     *
     * Checks WELL-FORMEDNESS only — it does not validate against any DTD or schema. HTML5
     * documents are deliberately rejected: a string containing "<!DOCTYPE html>" returns FALSE
     * even when it would otherwise parse (this method exists to tell XML from HTML).
     *
     * SECURITY: entity substitution is NOT enabled (LIBXML_NOENT is not passed), so an XXE
     * payload in $xmlContent is not expanded and no external entity is fetched by this method.
     * That property belongs to this method only — it says nothing about what a caller's own
     * parser will do with the same string afterwards.
     *
     * The libxml internal-error state is saved and restored, so calling this does not silently
     * change libxml error handling for the rest of the request.
     *
     * @param string|null $xmlContent The string to test. Null, "" or whitespace-only returns
     *                                FALSE.
     * @return bool TRUE if the string is well-formed XML. FALSE if it is not, and — because the
     *              check cannot be performed without them — FALSE if ext-simplexml or ext-libxml
     *              is not loaded.
     *
     * @ref https://stackoverflow.com/questions/4554233/how-check-if-a-string-is-a-valid-xml-with-out-displaying-a-warning-in-php
     */
    public static function validateXml(?string $xmlContent): bool {
        // The signature accepts null, so trim() must not be handed one (E_DEPRECATED on 8.1+).
        $xmlContent = trim($xmlContent ?? '');

        if (
            !extension_loaded('simplexml') ||
            !extension_loaded('libxml') ||
            empty($xmlContent) ||
            stripos($xmlContent, '<!DOCTYPE html>') !== false
        ) {
            return false;
        }

        $previousUseInternalErrors = libxml_use_internal_errors(true);
        simplexml_load_string($xmlContent);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseInternalErrors);

        return empty($errors);
    }

    /**
     * Checks whether a value is ABSENT, as opposed to merely falsy.
     *
     * This is deliberately NOT PHP's empty(). A value counts as empty here only when it is one
     * of: null, "", "\0", an empty array, or an object with no properties.
     *
     * Every other value is reported as PRESENT (FALSE) — including the falsy scalars 0, 0.0,
     * "0" and false. That is the whole point of the method: it is meant to back required-field
     * guards, and empty() would wrongly reject a legitimately submitted 0 or false. Note that
     * boolean false is present here, not just numeric zero; a submitted `false` is a value, not
     * an absence.
     *
     * Contrast isCompletelyEmpty(), the lenient sibling, which DOES report 0, "0" and false as
     * empty.
     *
     * @param mixed $value Value to check. Every type is accepted; no string cast is performed,
     *                     so arrays and objects are safe to pass.
     * @return bool TRUE only if the value is absent (per the list above); FALSE for every
     *              present value, falsy scalars included.
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
     * Checks whether a property/key exists on an object, or on an array (cast to an object).
     *
     * VISIBILITY IS IGNORED: this reports declared private and protected properties as existing,
     * because it is built on property_exists(). `hasProperty('secret', $obj)` returning TRUE
     * does NOT mean `$obj->secret` is readable from the caller's scope — that would raise an
     * Error. Use it to test for a property's existence, not its accessibility.
     *
     * For an array, the key is matched after a cast to object, so integer keys are matched by
     * their string form: hasProperty('0', [0 => 'a']) is TRUE.
     *
     * @param string $property Property/key name to look for.
     * @param array|object $target The array or object to inspect. An empty array returns FALSE.
     * @return bool TRUE if the property/key exists, FALSE otherwise.
     */
    public static function hasProperty(string $property, array|object $target): bool {
        if (empty($target)) {
            return false;
        }

        if (is_array($target)) {
            $target = (object) $target;
        }
        return property_exists($target, $property);
    }

    /**
     * Checks whether EVERY key of the given array is an integer key.
     *
     * All keys are inspected, not just the first. PHP normalizes canonical decimal integer
     * strings to int keys on insertion, so any key still typed as a string ("name", "01",
     * "1.5") makes this FALSE.
     *
     * This does NOT require the keys to be sequential or to start at 0 — [5 => 'a', 2 => 'b']
     * is TRUE. Use array_is_list() when list semantics (0..n-1, in order) are what you need.
     *
     * @param array $array Array to be checked.
     * @return bool TRUE if every key is an integer. FALSE for an EMPTY array: it has no keys to
     *              inspect, so it cannot be shown to be numerically keyed.
     */
    public static function isNumericArray(array $array): bool {
        if (empty($array)) return false;

        // Previously this read only the FIRST key (reset()+key()), so [0 => 'a', 'name' => 'b']
        // reported TRUE and the answer flipped with insertion order.
        foreach ($array as $key => $ignored) {
            if (!is_int($key)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Checks whether a value is "completely empty" — the LENIENT sibling of emptyExceptZero(),
     * for sloppy input where absence arrives as a placeholder string.
     *
     * TRUE for all of:
     *   - everything emptyExceptZero() calls absent (null, "", "\0", empty array/object);
     *   - boolean false;
     *   - numeric zero in any notation: 0, 0.0, "0", "0.0", "00", " 0 ";
     *   - these placeholders, ignoring case and ALL whitespace: "undefined", "null", "false",
     *     "{}", "[]", "n", "no", "tno".
     *
     * FALSE for every other value, including a NON-EMPTY array or object — such a value holds
     * content, so it is not empty. (Previously any non-empty array raised E_WARNING "Array to
     * string conversion" here and any object threw an Error, because the `mixed` value was cast
     * to string with no guard. An object with __toString is still evaluated by its string form.)
     *
     * Numeric zero is what separates this from emptyExceptZero(), which reports zero as PRESENT.
     * Pick deliberately: this method backs Parser::getBool() and Security's `asBoolean` sanitize
     * option, where a submitted "0" must become false.
     *
     * NOT a security control and not a type check — it is a leniency helper. Never use it to
     * decide whether input is safe.
     *
     * @param mixed $value The value to check. Every type is accepted and none of them throws.
     * @return bool TRUE if considered completely empty, FALSE otherwise.
     */
    public static function isCompletelyEmpty(mixed $value): bool {
        if (self::emptyExceptZero($value) || $value === false) {
            return true;
        }

        // A non-empty array/object/resource is content. Guard BEFORE any string cast: casting
        // an array warns, and casting an object without __toString throws.
        if (
            is_array($value) ||
            is_resource($value) ||
            (is_object($value) && !$value instanceof \Stringable)
        ) {
            return false;
        }

        $stringValue = (string) $value;

        // Numeric zero, whatever the notation. This has to test `!== false` explicitly:
        // filter_var() returns a falsy int(0)/float(0) for exactly the zero we are looking for,
        // so the old truthiness test could never fire and no zero was ever detected.
        $asFloat = filter_var($stringValue, FILTER_VALIDATE_FLOAT);
        if ($asFloat !== false && $asFloat === 0.0) {
            return true;
        }

        $normalized = Str::strToUpper(Str::removeExcessSpaces($stringValue, false));

        return in_array($normalized, self::COMPLETELY_EMPTY_SENTINELS, true);
    }

    /**
     * Checks whether a value's string form starts with a minus sign.
     *
     * This is a LEADING-SIGN test, not a numeric test: it does not check that the value is a
     * number, so isNegativeNumber("-abc") is TRUE and isNegativeNumber("1-2") is FALSE. All
     * whitespace is stripped before the test, so " - 5" is TRUE.
     *
     * @param mixed $value The value to check. Every type is accepted and none of them throws;
     *                     arrays and objects without __toString are simply FALSE (they have no
     *                     meaningful string form, and casting them used to raise E_WARNING /
     *                     throw an Error). Any value PHP considers empty is FALSE.
     * @return bool TRUE if the string form starts with "-", FALSE otherwise.
     *
     * @see https://stackoverflow.com/questions/15814592/how-do-i-include-negative-decimal-numbers-in-this-regular-expression
     */
    public static function isNegativeNumber(mixed $value): bool {
        if (empty($value)) {
            return false;
        }

        if (
            is_array($value) ||
            is_resource($value) ||
            (is_object($value) && !$value instanceof \Stringable)
        ) {
            return false;
        }

        return Str::subStr(Str::removeExcessSpaces((string) $value, false), 0, 1) === "-";
    }

    /**
     * Validates a Brazilian CPF number, including its two check digits.
     *
     * Punctuation and separators are ignored, so both "529.982.247-25" and "52998224725" are
     * accepted. After stripping non-digits the value must be EXACTLY 11 digits: a shorter
     * string is rejected, NOT zero-padded. (It used to be left-padded to 11, which made
     * validateCpf("191") return TRUE — "191" padded to "00000000191", a checksum-valid CPF.
     * That is a validator failing open on the very field it exists to protect.)
     *
     * CPFs whose digits are all the same ("111.111.111-11") are rejected: they satisfy the
     * checksum but are not valid CPFs.
     *
     * Validates STRUCTURE only — a true here means the number is well-formed, not that it was
     * ever issued to anyone or belongs to the person supplying it.
     *
     * @param string $cpf CPF number, masked or bare.
     * @return bool TRUE if the CPF is structurally valid, FALSE otherwise.
     *
     * @see https://www.geradorcpf.com/script-validar-cpf-php.htm
     */
    public static function validateCpf(string $cpf): bool {
        $cpf = Str::onlyNumbers($cpf);
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
     * Validates a Brazilian CNPJ number, including its two check digits.
     *
     * Punctuation and separators are ignored, so both "11.222.333/0001-81" and "11222333000181"
     * are accepted. (The computed digits used to be compared against the caller's RAW argument
     * while the digits were derived from the normalized one, so every correctly MASKED CNPJ —
     * the form humans actually type — was rejected, even though validateCpf accepted masks.)
     *
     * After stripping non-digits the value must be EXACTLY 14 digits. CNPJs whose digits are all
     * the same are rejected: "00000000000000" satisfies the checksum but is not a valid CNPJ,
     * and it used to return TRUE.
     *
     * Only the classic all-numeric CNPJ is supported. The alphanumeric CNPJ format is NOT
     * handled — its letters are stripped by normalization, so such a value is rejected.
     *
     * Validates STRUCTURE only — a true here means the number is well-formed, not that it is
     * registered or active.
     *
     * @param string $cnpj CNPJ number, masked or bare.
     * @return bool TRUE if the CNPJ is structurally valid, FALSE otherwise.
     *
     * @see https://www.todoespacoonline.com/w/2014/08/validar-cnpj-com-php/
     */
    public static function validateCnpj(string $cnpj): bool {
        $digits = Str::onlyNumbers($cnpj);
        if (Str::strLen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits)) {
            return false;
        }

        $base = Str::subStr($digits, 0, 12);
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

        return ($base . $secondDigit) === $digits;
    }

    /**
     * Checks whether a given string contains HTML/XML TAGS.
     *
     * Heuristic: the string is compared with its strip_tags() output; if they differ, a tag was
     * removed. It therefore detects TAGS only. HTML entities are not tags, so "&amp;" is FALSE,
     * and a bare comparison such as "5 < 6" is FALSE too.
     *
     * NOT a security check. A FALSE here does not mean the string is safe to render, and a TRUE
     * does not mean it is dangerous — use Security::xssClean() to sanitize output. Do not build
     * an XSS guard on this method.
     *
     * @param string|null $text The string to test. Null or "" returns FALSE.
     * @return bool TRUE if the string contains at least one tag, FALSE otherwise.
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
