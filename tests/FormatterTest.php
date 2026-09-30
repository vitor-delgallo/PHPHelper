<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Formatter;

final class FormatterTest extends TestCase {
    /**
     * Runs $fn with a capturing error handler installed and returns every PHP diagnostic
     * (E_WARNING / E_NOTICE / ...) it raised. Used to pin the contract that the documented
     * input shape produces NO diagnostics: several of these methods used to raise
     * "Undefined array key" or "A non-numeric value encountered", which under a framework
     * that rethrows warnings kills the request.
     *
     * @param callable $fn
     * @param mixed $result Receives $fn's return value.
     * @return string[] Messages raised, in order.
     */
    private function captureDiagnostics(callable $fn, mixed &$result = null): array {
        $raised = [];
        set_error_handler(static function (int $severity, string $message) use (&$raised): bool {
            $raised[] = $message;
            return true;
        }, E_ALL);

        try {
            $result = $fn();
        } finally {
            restore_error_handler();
        }

        return $raised;
    }

    // ---------------------------------------------------------------- formatNumber: happy path

    public function testFormatNumberReturnsPlainDecimalUnchangedWithDefaults(): void {
        self::assertSame('1234.56', Formatter::formatNumber('1234.56'));
        self::assertSame('7', Formatter::formatNumber('7'));
        self::assertSame('0', Formatter::formatNumber('0'));
    }

    public function testFormatNumberAppliesPtBrSeparatorsPrefixAndSuffix(): void {
        self::assertSame(
            'R$ 1.234.567,89',
            Formatter::formatNumber('1234567.891', '.', ',', '.', 'R$', '', 2)
        );
        self::assertSame(
            '50 %',
            Formatter::formatNumber('50', '.', '.', '', '', '%')
        );
    }

    public function testFormatNumberReadsCommaDecimalInputWhenToldTo(): void {
        self::assertSame('1.234,56', Formatter::formatNumber('1234,56', ',', ',', '.', '', '', 2));
        self::assertSame('12,5', Formatter::formatNumber('12,5', ',', ','));
    }

    // ------------------------------------------------------- formatNumber: documented boundaries

    public function testFormatNumberTruncatesDecimalsAndNeverRounds(): void {
        // Documented contract: decimals are cut, not rounded. 1.999 must NOT become 2.00.
        self::assertSame('1.99', Formatter::formatNumber('1.999', decimalPlaces: 2));
        self::assertSame('0.9', Formatter::formatNumber('0.99', decimalPlaces: 1));
    }

    public function testFormatNumberWithZeroDecimalPlacesDropsTheFractionEntirely(): void {
        self::assertSame('1234', Formatter::formatNumber('1234.99', decimalPlaces: 0));
    }

    public function testFormatNumberWithNullDecimalPlacesKeepsEveryDecimalTheInputHad(): void {
        self::assertSame('1234.5678', Formatter::formatNumber('1234.5678', decimalPlaces: null));
    }

    public function testFormatNumberThousandsSeparatorAppliesOnlyFromFourIntegerDigits(): void {
        self::assertSame('123.45', Formatter::formatNumber('123.45', '.', '.', ','));
        self::assertSame('1,234.5', Formatter::formatNumber('1234.5', '.', '.', ','));
    }

    public function testFormatNumberIgnoresThousandsSeparatorEqualToDecimalSeparator(): void {
        // Documented: a thousands separator equal to the decimal separator is dropped, because
        // the output would otherwise be unparseable.
        self::assertSame('1234,5', Formatter::formatNumber('1234.5', '.', ',', ','));
    }

    public function testFormatNumberOutputSeparatorFallsBackToDotWhenUnusable(): void {
        // Digits and signs are stripped out of $decimalSeparatorTo; nothing left => "." fallback.
        self::assertSame('1.5', Formatter::formatNumber('1.5', '.', '5'));
    }

    /**
     * Documented trap: $decimalSeparatorFrom doubles as the input whitelist, so a separator that
     * does not match the input's real one deletes that separator as noise and rescales the value
     * by a power of ten. Pinned because the silent 10x is exactly what a caller must be warned
     * about.
     */
    public function testFormatNumberInputSeparatorMismatchSilentlyRescalesTheValue(): void {
        self::assertSame('15', Formatter::formatNumber('1.5', ','));
    }

    /**
     * BEHAVIOR CHANGE (low). An UNUSABLE separator ("" or only digits/signs) is documented to
     * fall back to ".", but the fallback ran only AFTER the input had been filtered with the raw
     * value, so it never rescued anything: ('1.5', '') and ('1.5', '9') both gave '15'. The
     * fallback now happens first.
     */
    public function testFormatNumberUnusableInputSeparatorFallsBackToDotBeforeFiltering(): void {
        self::assertSame('1.5', Formatter::formatNumber('1.5', '9'));
        self::assertSame('1.5', Formatter::formatNumber('1.5', ''));
        self::assertSame('1.5', Formatter::formatNumber('1.5', '+-'));
    }

    /**
     * FINDING (medium): a FLOAT's string form always uses "." - the caller does not choose it -
     * yet it was filtered with $decimalSeparatorFrom, so pt-BR code passing a float from a native
     * PDO fetch alongside ',' got it silently rescaled: formatNumber(1.5, ',', ',') was '15'.
     */
    public function testFormatNumberReadsFloatInputWithADotWhateverTheInputSeparator(): void {
        self::assertSame('1,5', Formatter::formatNumber(1.5, ',', ','));
        self::assertSame('R$ 1.234,56', Formatter::formatNumber(1234.56, ',', ',', '.', 'R$', '', 2));
        self::assertSame('1234,5', Formatter::formatNumber('1234,5', ',', ','), 'strings still use the declared separator');
    }

    public function testFormatNumberKeepsTheNegativeSignByDefault(): void {
        self::assertSame('-50', Formatter::formatNumber('-50'));
        self::assertSame('R$ -1.234,56', Formatter::formatNumber('-1234.56', '.', ',', '.', 'R$', '', 2));
    }

    /**
     * FINDING (low): U+2212 MINUS SIGN was stripped by the ASCII input filter, so "−5" formatted
     * as "5" — a silent sign flip — while Validator::isNegativeNumber("−5") says it is negative.
     */
    public function testFormatNumberTreatsTheUnicodeMinusSignAsNegative(): void {
        self::assertSame('-5', Formatter::formatNumber("\u{2212}5"));
        self::assertSame('R$ -1.234,56', Formatter::formatNumber("\u{2212}1234,56", ',', ',', '.', 'R$', '', 2));
        self::assertSame('0.015', Formatter::formatNumber("1.5e\u{2212}2"), 'a U+2212 exponent sign is honoured too');
        self::assertSame('5', Formatter::formatNumber("\u{2212}5", allowNegative: false));
    }

    // ------------------------------------------------- formatNumber: lenient / "denied" behaviour

    /**
     * formatNumber is documented as TOTAL: it never throws, and anything without usable digits
     * degrades to "0" rather than being rejected.
     */
    public function testFormatNumberTreatsNullEmptyAndNonNumericInputAsZero(): void {
        self::assertSame('0', Formatter::formatNumber(null));
        self::assertSame('0', Formatter::formatNumber(''));
        self::assertSame('0', Formatter::formatNumber('abc'));
    }

    /**
     * Defect found while testing the documented "@return Formatted number string": an input made
     * only of signs/separators survived the input filter and fell through to return the leftover
     * character as if it were a number — formatNumber('---') was '-' (and 'R$ -' with a prefix),
     * while formatNumber('+') and formatNumber('.') returned an EMPTY STRING. None is a number.
     * The zero-degradation guard now checks for digits, not for emptiness.
     * Without the fix every assertion here fails.
     */
    public function testFormatNumberDegradesSignOrSeparatorOnlyInputToZeroInsteadOfReturningGarbage(): void {
        self::assertSame('0', Formatter::formatNumber('---'));
        self::assertSame('0', Formatter::formatNumber('-'));
        self::assertSame('0', Formatter::formatNumber('+'));
        self::assertSame('0', Formatter::formatNumber('.'));
        self::assertSame('R$ 0', Formatter::formatNumber('---', '.', '.', '', 'R$'));
    }

    public function testFormatNumberStripsNonNumericNoiseAroundTheDigits(): void {
        self::assertSame('1234.56', Formatter::formatNumber('R$ 1234.56 xyz'));
    }

    /**
     * FINDING (medium, allowNegative): the docblock said "whether negative values are allowed",
     * which reads as a validation gate. The code discards the sign and returns the ABSOLUTE
     * value — it does not reject anything. The doc now says exactly that; this test pins the
     * real behaviour so the two cannot drift apart again.
     */
    public function testFormatNumberWithAllowNegativeFalseReturnsAbsoluteValueAndDoesNotReject(): void {
        self::assertSame('50', Formatter::formatNumber('-50', allowNegative: false));
        self::assertSame('1234.56', Formatter::formatNumber('-1234.56', decimalPlaces: 2, allowNegative: false));
        // Positive input is untouched by the flag.
        self::assertSame('50', Formatter::formatNumber('50', allowNegative: false));
    }

    // ------------------------------------- formatNumber: scientific notation (FINDING, high sev)

    /**
     * FINDING (high, formatNumber): the exponent's sign was stripped by Str::onlyNumbers()
     * before it was read, so EVERY exponent was treated as negative and 1.5E+20 formatted as
     * '0.000000000000000000015' — wrong by 40 orders of magnitude, silently.
     * Without the fix this test fails on every assertion.
     */
    public function testFormatNumberExpandsPositiveExponentScientificNotation(): void {
        self::assertSame('150000000000000000000', Formatter::formatNumber('1.5E+20'));
        self::assertSame('100', Formatter::formatNumber('1E2'));
        self::assertSame('20000000000000000', Formatter::formatNumber('2.0E+16'));
        self::assertSame('5', Formatter::formatNumber('0.5E1'));
        self::assertSame('1.5', Formatter::formatNumber('0.0015E3'));
    }

    /**
     * FINDING (high, formatNumber): a caller cannot control when PHP switches to E notation —
     * any float of magnitude >= ~1e15 casts to a string in E notation. The documented signature
     * accepts float, so this is the path a caller reaches without asking for it.
     */
    public function testFormatNumberExpandsLargeFloatThatPhpRendersInScientificNotation(): void {
        self::assertSame('1.5E+20', (string) 1.5e20, 'precondition: PHP casts this float to E notation');

        self::assertSame('150000000000000000000', Formatter::formatNumber(1.5e20));
        self::assertSame('1000000000000000', Formatter::formatNumber(1.0e15));
        // decimalPlaces must shape the FRACTION, not annihilate the integer part (used to give
        // '0'); and it is now exact, so the two places are padded.
        self::assertSame('150000000000000000000.00', Formatter::formatNumber(1.5e20, decimalPlaces: 2));
    }

    /**
     * Negative exponents worked before the fix; this pins that the fix did not regress them.
     */
    public function testFormatNumberExpandsNegativeExponentScientificNotation(): void {
        self::assertSame('0.00000015', Formatter::formatNumber('1.5E-7'));
        self::assertSame('0.00000015', Formatter::formatNumber(1.5e-7));
        self::assertSame('0.00123456789', Formatter::formatNumber('1.23456789E-3'));
    }

    /**
     * Defect found while fixing the audited exponent-sign bug: a NEGATIVE mantissa in E notation
     * left the "-" inside the digit string that was then zero-padded, producing the nonsense
     * "-0.000000000000000000-15", raising "A non-numeric value encountered" and returning '0'.
     * Both the value and the absence of the warning are pinned here.
     */
    public function testFormatNumberExpandsNegativeMantissaScientificNotationWithoutRaisingWarning(): void {
        $diagnostics = $this->captureDiagnostics(
            static fn (): string => Formatter::formatNumber(-1.5e20),
            $result
        );

        self::assertSame([], $diagnostics, 'E-notation formatting must not raise PHP diagnostics');
        self::assertSame('-150000000000000000000', $result);
        self::assertSame('-0.00000015', Formatter::formatNumber('-1.5E-7'));
    }

    public function testFormatNumberTreatsScientificNotationOfZeroAsZero(): void {
        self::assertSame('0', Formatter::formatNumber('0E5'));
        self::assertSame('0', Formatter::formatNumber('0.000E-5'));
        self::assertSame('1', Formatter::formatNumber('1E0'));
        self::assertSame('0.00', Formatter::formatNumber('-0E5', decimalPlaces: 2));
    }

    /**
     * BEHAVIOR CHANGE / FINDING (high). Every "e" used to survive the input filter, so the "e" of
     * an ordinary WORD switched the exponent path on and silently rescaled the value:
     * 'R$ 1234,56 reais' formatted as 123456 (100x) and 'Price: 12.50 each' as 0. An "e" is now an
     * exponent marker only between a digit (or the separator) and an optionally signed digit;
     * otherwise it is noise like any other letter. That also means the malformed 'E5' - an
     * exponent with no mantissa - is the noise "E" plus 5, i.e. 5 (it used to be 0).
     */
    public function testFormatNumberIgnoresTheLetterEOutsideExponentPosition(): void {
        self::assertSame('1.234,56', Formatter::formatNumber('R$ 1234,56 reais', ',', ',', '.'));
        self::assertSame('12.50', Formatter::formatNumber('Price: 12.50 each'));
        self::assertSame('7', Formatter::formatNumber('Rest 7'));
        self::assertSame('5', Formatter::formatNumber('E5'));
        self::assertSame('2', Formatter::formatNumber('2 e'), 'an E with no digit after it is not an exponent');
        // Real exponents in every accepted spelling still expand.
        self::assertSame('1500', Formatter::formatNumber('1.5e3'));
        self::assertSame('100000', Formatter::formatNumber('1.e5'));
        self::assertSame('0.015', Formatter::formatNumber('1.5E-2'));
    }

    /**
     * FINDING (high, DoS): the exponent was expanded with str_repeat() unbounded, so one short
     * string - '1e999999999', e.g. a form field run through Security's formatDecimal getter -
     * allocated ~1 GB and killed the request with a fatal "Allowed memory size exhausted".
     * |exponent| > 1000 now degrades to "0", like other unusable input. Every finite double's
     * exponent (-324..308) is well inside the bound.
     */
    public function testFormatNumberDoesNotExpandAnUnboundedExponent(): void {
        self::assertSame('0', Formatter::formatNumber('1e5000'));
        self::assertSame('0', Formatter::formatNumber('1e-5000'));
        self::assertSame('0', Formatter::formatNumber('1e99999999999999999999999'));
        self::assertSame('R$ 0,00', Formatter::formatNumber('9e999999999', '.', ',', '.', 'R$', '', 2));

        self::assertSame('1' . str_repeat('0', 1000), Formatter::formatNumber('1e1000'), 'the bound itself is inclusive');
        self::assertSame('4.9406564584125E-324', (string) 4.9e-324, 'precondition: default precision=14');
        self::assertSame(
            '0.' . str_repeat('0', 323) . '49406564584125',
            Formatter::formatNumber(4.9e-324),
            'the smallest subnormal still expands'
        );
    }

    /**
     * The mantissa of a STRING in E notation uses the caller's declared separator, like the rest
     * of the string (it used to be hard-wired to ".", so '1,5E3' with ',' read as 15E3 = 15000).
     */
    public function testFormatNumberReadsTheMantissaWithTheDeclaredSeparator(): void {
        self::assertSame('1500', Formatter::formatNumber('1,5E3', ','));
        self::assertSame('0,0015', Formatter::formatNumber('1,5E-3', ',', ','));
    }

    public function testFormatNumberFormatsScientificNotationWithThousandsSeparator(): void {
        self::assertSame('150.000.000.000.000.000.000', Formatter::formatNumber(1.5e20, '.', ',', '.'));
    }

    // ------------------------------------------------- formatNumber: pass-5 regression findings

    /**
     * @return array<string, array{string, string, string, string, int}>
     */
    public static function fixedDecimalPlacesCases(): array {
        return [
            // number, decimal-to, thousands-to, expected, places
            'short integer part, fraction padded' => ['123.5', ',', '.', '123,50', 2],
            'long integer part, fraction padded' => ['1234.5', ',', '.', '1.234,50', 2],
            'integer input, no thousands separator' => ['123', ',', '', '123,00', 2],
            'integer input, thousands separator' => ['1234', ',', '.', '1.234,00', 2],
            'longer fraction truncated' => ['99999.999', ',', '.', '99.999,99', 2],
            'zero places drops the fraction' => ['1234.99', ',', '.', '1.234', 0],
            'negative places behaves as zero' => ['12345.6', '.', ',', '12,345', -1],
        ];
    }

    /**
     * BEHAVIOR CHANGE / FINDING (medium). $decimalPlaces used to mean "at most" on one code path
     * and "exactly" on the other: with a thousands separator and an integer part of 4+ digits the
     * value went through number_format(), which PADS - so the same call gave 'R$ 123,5' and
     * 'R$ 1.234,50'. It is now always exact. A negative value also used to reach
     * number_format(), which (PHP 8.3+) ROUNDS to tens: '12345.6' at -1 places came back as
     * '12,350', breaking the never-rounds contract.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('fixedDecimalPlacesCases')]
    public function testFormatNumberDecimalPlacesIsExactOnEveryPath(string $number, string $decimalTo, string $thousandsTo, string $expected, int $places): void {
        self::assertSame($expected, Formatter::formatNumber($number, '.', $decimalTo, $thousandsTo, '', '', $places));
    }

    public function testFormatNumberWithNullDecimalPlacesAddsNoZeros(): void {
        self::assertSame('1.5', Formatter::formatNumber('1.5'));
        self::assertSame('1.50', Formatter::formatNumber('1.50'), 'but keeps the ones the input had');
        self::assertSame('1', Formatter::formatNumber('1.'));
    }

    /**
     * FINDING (medium): truncation of a small negative value displayed a signed zero -
     * formatNumber('-0.001', decimalPlaces: 2) was '-0.00', and a float -0.0 was '-0'. The sign
     * is now decided on the digits actually shown.
     */
    public function testFormatNumberNeverShowsASignedZero(): void {
        self::assertSame('0.00', Formatter::formatNumber('-0.001', decimalPlaces: 2));
        self::assertSame('0', Formatter::formatNumber(-0.0));
        self::assertSame('0.00', Formatter::formatNumber(-1.5e-7, decimalPlaces: 2));
        self::assertSame('R$ 0,00', Formatter::formatNumber('-0,004', ',', ',', '.', 'R$', '', 2));
        // A non-zero digit keeps the sign.
        self::assertSame('-0.001', Formatter::formatNumber('-0.001'));
        self::assertSame('-0.01', Formatter::formatNumber('-0.019', decimalPlaces: 2));
    }

    public function testFormatNumberNormalisesTheIntegerPart(): void {
        // '.5' used to be returned as '.5', and leading zeros were kept only when no thousands
        // separator was requested ('0001234.5' -> '0001234.5' but '1,234.5' with one).
        self::assertSame('0.5', Formatter::formatNumber('.5'));
        self::assertSame('-0.5', Formatter::formatNumber('-.5'));
        self::assertSame('7', Formatter::formatNumber('007'));
        self::assertSame('1234.5', Formatter::formatNumber('0001234.5'));
        self::assertSame('1,234.5', Formatter::formatNumber('0001234.5', '.', '.', ','));
    }

    /**
     * FINDING (medium): the thousands path went through number_format(), i.e. through a FLOAT, so
     * anything past ~15 significant digits was silently rewritten:
     * '12345678901234567890.12' came back as '12.345.678.901.234.567.168,00'.
     */
    public function testFormatNumberGroupsThousandsWithoutFloatPrecisionLoss(): void {
        self::assertSame(
            '12.345.678.901.234.567.890,12',
            Formatter::formatNumber('12345678901234567890.12', '.', ',', '.')
        );
        self::assertSame(
            '-9.007.199.254.740.993',
            Formatter::formatNumber('-9007199254740993', '.', ',', '.'),
            '2^53 + 1 is the first integer a double cannot hold'
        );
    }

    /**
     * FINDING (low): every character of $prefix and $suffix was stripped out of the separators, so
     * the prefix 'Total, R$' deleted the "," decimal separator (fallback ".") and the "." thousands
     * separator with it (equal to the fallback): 'Total, R$ 1234.5' instead of '1.234,5'.
     */
    public function testFormatNumberDoesNotLetThePrefixOrSuffixEatTheSeparators(): void {
        self::assertSame('Total, R$ 1.234,5', Formatter::formatNumber('1234.5', '.', ',', '.', 'Total, R$'));
        self::assertSame('1.234,5 p.p.', Formatter::formatNumber('1234,5', ',', ',', '.', '', 'p.p.'));
    }

    public function testFormatNumberInsertsTheThousandsSeparatorLiterally(): void {
        // Regex-special separators go in verbatim; digits are stripped out of any separator.
        self::assertSame('1$234$567', Formatter::formatNumber('1234567', '.', '.', '$1'));
        self::assertSame('1\\234\\567', Formatter::formatNumber('1234567', '.', '.', '\\'));
        self::assertSame('1${x}234${x}567', Formatter::formatNumber('1234567', '.', '.', '${x}'));
    }

    public function testFormatNumberSupportsMultibyteSeparators(): void {
        self::assertSame("1\u{202F}234\u{202F}567,5", Formatter::formatNumber('1234567.5', '.', ',', "\u{202F}"));
        self::assertSame('1,234,567٫5', Formatter::formatNumber('1234567.5', '.', '٫', ','));
        self::assertSame('1234567.5', Formatter::formatNumber('1234567٫5', '٫'));
    }

    /**
     * FINDING (medium): the input was filtered with Str::keepOnlyCharacters(), a /u regex, so ONE
     * invalid UTF-8 byte made the documented "never throws" formatter throw
     * InvalidArgumentException (and an invalid separator raised a PCRE warning first).
     */
    public function testFormatNumberNeverThrowsOnInvalidUtf8(): void {
        $diagnostics = $this->captureDiagnostics(static fn (): array => [
            Formatter::formatNumber("12\xFF.5"),
            Formatter::formatNumber("R\$\xA0 1.234,5", ',', ',', '.'),
            Formatter::formatNumber("12\xFF5", "\xFF", ','),
            Formatter::formatNumber('12.5', '.', "\xFF", "\xFE"),
        ], $results);

        self::assertSame([], $diagnostics);
        self::assertSame(['12.5', '1.234,5', '12,5', "12\xFF5"], $results);
    }

    /**
     * Documented: INF and NAN have no digits, so they degrade to "0" like any other unusable input.
     */
    public function testFormatNumberFormatsNonFiniteFloatsAsZero(): void {
        self::assertSame('0', Formatter::formatNumber(INF));
        self::assertSame('0', Formatter::formatNumber(-INF));
        self::assertSame('0.00', Formatter::formatNumber(NAN, decimalPlaces: 2));
    }

    /**
     * Documented: float input goes through PHP's (locale-independent) string cast, which keeps 14
     * significant digits by default. Pinned so the precision trade-off stays visible.
     */
    public function testFormatNumberRendersFloatsThroughTheStringCast(): void {
        self::assertSame('0.3', Formatter::formatNumber(0.1 + 0.2));
        self::assertSame('123456789012350000', Formatter::formatNumber(123456789012345678.0));
        self::assertSame('123456789012345678', Formatter::formatNumber('123456789012345678'), 'a string keeps every digit');
    }

    public function testFormatNumberAcceptsIntegers(): void {
        self::assertSame('1.234.567', Formatter::formatNumber(1234567, '.', ',', '.'));
        self::assertSame('-42', Formatter::formatNumber(-42));
        self::assertSame((string) PHP_INT_MIN, Formatter::formatNumber(PHP_INT_MIN));
    }

    public function testFormatNumberKeepsOnlyTheDigitsUpToASecondDecimalSeparator(): void {
        // Documented lenient behaviour for malformed input.
        self::assertSame('1.234', Formatter::formatNumber('1.234.567'));
    }

    // --------------------------------------------- buildNestedArray (FINDING, high sev) ---------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function flatRows(): array {
        return [
            ['id' => 1, 'idFather' => null, 'name' => 'root'],
            ['id' => 2, 'idFather' => 1, 'name' => 'child'],
            ['id' => 3, 'idFather' => 2, 'name' => 'grandchild'],
            ['id' => 4, 'idFather' => null, 'name' => 'second root'],
        ];
    }

    /**
     * FINDING (high, buildNestedArray): the recursive call passed its arguments in the wrong
     * positional order, so $parentId received the literal string 'children' and $parentField
     * received the id VALUE. Result: an "Undefined array key" warning per root element and a
     * silently FLAT return — the method could never do the one thing it documents.
     * Without the fix this test fails: children are missing at every depth.
     */
    public function testBuildNestedArrayNestsChildrenAtEveryDepth(): void {
        $rows = $this->flatRows();

        $tree = Formatter::buildNestedArray($rows);

        self::assertCount(2, $tree);
        self::assertSame('root', $tree[0]['name']);
        self::assertSame('second root', $tree[1]['name']);

        self::assertArrayHasKey('children', $tree[0]);
        self::assertCount(1, $tree[0]['children']);
        self::assertSame('child', $tree[0]['children'][0]['name']);

        self::assertArrayHasKey('children', $tree[0]['children'][0]);
        self::assertSame('grandchild', $tree[0]['children'][0]['children'][0]['name']);
    }

    /**
     * FINDING (high, buildNestedArray): pins that the documented input shape raises NO PHP
     * diagnostic. Before the fix this produced "Undefined array key 1" for every root, which
     * escapes as an ErrorException under Laravel/Symfony and kills the request.
     */
    public function testBuildNestedArrayRaisesNoWarningForDocumentedInput(): void {
        $rows = $this->flatRows();

        $diagnostics = $this->captureDiagnostics(
            static fn (): array => Formatter::buildNestedArray($rows),
            $tree
        );

        self::assertSame([], $diagnostics);
        self::assertNotEmpty($tree);
    }

    /**
     * Documented: $items is consumed. Everything placed in the tree is removed from it, so what
     * remains is exactly the orphans — the only way a caller can detect dropped rows.
     */
    public function testBuildNestedArrayConsumesItemsAndLeavesOnlyOrphans(): void {
        $rows = $this->flatRows();
        $rows[] = ['id' => 9, 'idFather' => 777, 'name' => 'orphan'];

        $tree = Formatter::buildNestedArray($rows);

        self::assertCount(2, $tree, 'the orphan must not surface as a root');
        self::assertCount(1, $rows, '$items must keep only the orphan');
        self::assertSame('orphan', array_values($rows)[0]['name']);
    }

    /**
     * Documented: a leaf does not carry an empty children key, so isset() is the correct probe.
     */
    public function testBuildNestedArrayDoesNotSetChildrenKeyOnLeaves(): void {
        $rows = $this->flatRows();

        $tree = Formatter::buildNestedArray($rows);

        self::assertArrayNotHasKey('children', $tree[1], 'a childless root must have no children key');
        self::assertArrayNotHasKey('children', $tree[0]['children'][0]['children'][0]);
    }

    public function testBuildNestedArrayReturnsEmptyArrayForEmptyInput(): void {
        $rows = [];

        self::assertSame([], Formatter::buildNestedArray($rows));
    }

    /**
     * Documented gotcha: matching is strict (===). Ids as int and parent refs as string nest
     * nothing. Pinned so nobody "fixes" the strictness without updating the docblock.
     */
    public function testBuildNestedArrayStrictMatchingMeansMixedIdTypesDoNotNest(): void {
        $rows = [
            ['id' => 1, 'idFather' => null, 'name' => 'root'],
            ['id' => 2, 'idFather' => '1', 'name' => 'string parent ref'],
        ];

        $tree = Formatter::buildNestedArray($rows);

        self::assertCount(1, $tree);
        self::assertArrayNotHasKey('children', $tree[0]);
        self::assertCount(1, $rows, 'the type-mismatched row stays behind as an orphan');
    }

    public function testBuildNestedArrayHonoursCustomFieldNames(): void {
        $rows = [
            ['uid' => 'a', 'parent' => null, 'name' => 'root'],
            ['uid' => 'b', 'parent' => 'a', 'name' => 'child'],
        ];

        $tree = Formatter::buildNestedArray($rows, 'parent', 'uid', 'kids');

        self::assertSame('child', $tree[0]['kids'][0]['name']);
        self::assertArrayNotHasKey('children', $tree[0]);
    }

    /**
     * @return array<string, array{array<int, array<string, mixed>>, int|string|null, int}>
     */
    public static function selfReferentialRows(): array {
        return [
            'root with null id' => [[['id' => null, 'idFather' => null, 'name' => 'unsaved']], null, 1],
            'self-parented row' => [[['id' => 5, 'idFather' => 5, 'name' => 'self']], 5, 1],
            'two-node cycle' => [[['id' => 1, 'idFather' => 2, 'name' => 'a'], ['id' => 2, 'idFather' => 1, 'name' => 'b']], 1, 2],
        ];
    }

    /**
     * FINDING (high): the element was removed from $items only AFTER recursing into its children,
     * so an element that was its own ancestor was picked up again by the recursion, forever: a
     * single row {id: null, idFather: null} - an unsaved row, a LEFT JOIN miss - exhausted memory
     * and killed the request. Each element is now consumed before descending, so it is placed at
     * most once and every call terminates.
     *
     * Run in a separate process so a regression costs one failed test, not the whole suite.
     *
     * @param array<int, array<string, mixed>> $rows
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('selfReferentialRows')]
    #[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
    public function testBuildNestedArrayTerminatesOnSelfReferentialRows(array $rows, int|string|null $parentId, int $expectedNodes): void {
        ini_set('memory_limit', '64M');

        $tree = Formatter::buildNestedArray($rows, 'idFather', 'id', 'children', $parentId);

        $count = 0;
        array_walk_recursive($tree, static function ($value, $key) use (&$count): void {
            if ($key === 'name') {
                $count++;
            }
        });
        self::assertSame($expectedNodes, $count, 'every element placed exactly once');
        self::assertSame([], $rows, 'nothing left over: every element was placed');
    }

    public function testBuildNestedArrayCutsACycleAtTheStartingNode(): void {
        $rows = [['id' => 1, 'idFather' => 2], ['id' => 2, 'idFather' => 1]];

        $tree = Formatter::buildNestedArray($rows, 'idFather', 'id', 'children', 1);

        self::assertSame([['id' => 2, 'idFather' => 1, 'children' => [['id' => 1, 'idFather' => 2]]]], $tree);
    }

    public function testBuildNestedArrayLeavesAnUnreachableCycleAsOrphans(): void {
        $rows = [
            ['id' => 1, 'idFather' => null],
            ['id' => 2, 'idFather' => 3],
            ['id' => 3, 'idFather' => 2],
        ];

        $tree = Formatter::buildNestedArray($rows);

        self::assertSame([['id' => 1, 'idFather' => null]], $tree);
        self::assertSame([1, 2], array_keys($rows), 'the detached cycle stays in $items');
    }

    public function testBuildNestedArrayBuildsFromAnExplicitParentId(): void {
        $rows = $this->flatRows();

        $tree = Formatter::buildNestedArray($rows, 'idFather', 'id', 'children', 1);

        self::assertCount(1, $tree);
        self::assertSame('child', $tree[0]['name']);
        self::assertSame('grandchild', $tree[0]['children'][0]['name']);
    }

    // ---------------------------------------------------------------------- cleanEmptyTree ------

    public function testCleanEmptyTreeRemovesLeavesWhoseRequiredFieldIsEmpty(): void {
        $tree = [
            ['id' => 1, 'val' => 'keep'],
            ['id' => 2, 'val' => ''],
        ];

        $filtered = Formatter::cleanEmptyTree($tree, 'children', 'val');

        self::assertCount(1, $filtered);
        self::assertSame('keep', array_values($filtered)[0]['val']);
    }

    public function testCleanEmptyTreeRemovesBranchWhoseSubtreeFiltersToEmpty(): void {
        $tree = [
            ['id' => 1, 'val' => '', 'children' => [['id' => 2, 'val' => '']]],
            ['id' => 3, 'val' => 'keep'],
        ];

        $filtered = Formatter::cleanEmptyTree($tree, 'children', 'val');

        self::assertCount(1, $filtered);
        self::assertSame(3, array_values($filtered)[0]['id']);
    }

    public function testCleanEmptyTreeKeepsBranchWhoseSubtreeSurvives(): void {
        $tree = [
            ['id' => 1, 'val' => '', 'children' => [['id' => 2, 'val' => 'keep'], ['id' => 3, 'val' => '']]],
        ];

        $filtered = Formatter::cleanEmptyTree($tree, 'children', 'val');

        self::assertCount(1, $filtered);
        self::assertCount(1, $filtered[0]['children']);
        self::assertSame('keep', array_values($filtered[0]['children'])[0]['val']);
    }

    /**
     * Documented asymmetry: an element that HAS the children key is judged only by its subtree,
     * never by its own required field — so an empty children array drops an otherwise valid node.
     */
    public function testCleanEmptyTreeDropsNodeWithEmptyChildrenArrayEvenWhenRequiredFieldIsFilled(): void {
        $tree = [
            ['id' => 1, 'val' => 'filled', 'children' => []],
        ];

        self::assertSame([], Formatter::cleanEmptyTree($tree, 'children', 'val'));
        self::assertSame([], Formatter::cleanEmptyTree($tree));
    }

    public function testCleanEmptyTreeWithoutRequiredFieldKeepsEveryLeaf(): void {
        $tree = [
            ['id' => 1, 'val' => ''],
            ['id' => 2, 'val' => 'x'],
        ];

        self::assertSame($tree, Formatter::cleanEmptyTree($tree));
    }

    /**
     * Documented: keys are preserved and never reindexed, so a filtered list json_encodes as an
     * OBJECT. Callers serialising to a client that expects an array must array_values() first.
     */
    public function testCleanEmptyTreePreservesOriginalKeysAndDoesNotReindex(): void {
        $tree = [
            ['id' => 1, 'val' => ''],
            ['id' => 2, 'val' => 'keep'],
        ];

        $filtered = Formatter::cleanEmptyTree($tree, 'children', 'val');

        self::assertSame([1], array_keys($filtered), 'key 0 is removed and key 1 keeps its index');
        self::assertSame('{"1":{"id":2,"val":"keep"}}', json_encode($filtered));
    }

    public function testCleanEmptyTreeReturnsEmptyArrayUnchanged(): void {
        self::assertSame([], Formatter::cleanEmptyTree([]));
    }

    /**
     * FINDING (medium): a children key holding NULL - what a query or json_decode() produces for
     * "no children" - was handed straight to the array-typed recursive call: TypeError, i.e. an
     * \Error that catch (\Exception) does not see. NULL now counts as an empty children array,
     * exactly like [], so the node is dropped.
     */
    public function testCleanEmptyTreeTreatsNonArrayChildrenAsEmpty(): void {
        $tree = [
            ['id' => 1, 'val' => 'x', 'children' => null],
            ['id' => 2, 'val' => 'x', 'children' => 'garbage'],
            ['id' => 3, 'val' => 'x'],
        ];

        self::assertSame([2 => ['id' => 3, 'val' => 'x']], Formatter::cleanEmptyTree($tree, 'children', 'val'));
    }

    /**
     * FINDING (low): the leaf check indexed every element as an array, so an object element
     * raised "Cannot use object of type stdClass as array" (an \Error) and a scalar element made
     * Validator::hasProperty() throw a TypeError.
     */
    public function testCleanEmptyTreeJudgesObjectAndScalarElementsAsLeaves(): void {
        $keep = (object) ['val' => 'x'];
        $tree = [(object) ['val' => ''], $keep, 'scalar'];

        self::assertSame([1 => $keep], Formatter::cleanEmptyTree($tree, 'children', 'val'));
        self::assertSame($tree, Formatter::cleanEmptyTree($tree), 'without a required field, every leaf stays');
    }

    public function testCleanEmptyTreeHonoursTheFieldNameZero(): void {
        // empty('0') is true, so the field name "0" (a list-shaped row) silently disabled the check.
        $tree = [['', 'a'], ['x', 'b']];

        self::assertSame([1 => ['x', 'b']], Formatter::cleanEmptyTree($tree, 'children', '0'));
    }

    public function testCleanEmptyTreeFiltersRecursivelyAtEveryDepth(): void {
        $tree = [
            ['id' => 1, 'children' => [
                ['id' => 2, 'children' => [['id' => 3, 'val' => ''], ['id' => 4, 'val' => 'y']]],
                ['id' => 5, 'children' => [['id' => 6, 'val' => '']]],
            ]],
        ];

        $filtered = Formatter::cleanEmptyTree($tree, 'children', 'val');

        self::assertSame([0 => ['id' => 1, 'children' => [
            0 => ['id' => 2, 'children' => [1 => ['id' => 4, 'val' => 'y']]],
        ]]], $filtered);
    }

    // ------------------------------------------------------------------ CPF / CNPJ / CEP masks --

    public function testFormatCnpjMasksFourteenCharacters(): void {
        self::assertSame('12.345.678/9012-34', Formatter::formatCnpj('12345678901234'));
        self::assertSame('12.345.678/9012-34', Formatter::formatCnpj('12.345.678/9012-34'));
    }

    public function testFormatCnpjPadsShortInputOnTheLeft(): void {
        self::assertSame('00.000.000/0001-23', Formatter::formatCnpj('123'));
    }

    /**
     * Documented: input longer than 14 is SILENTLY truncated — the tail is dropped with no error.
     */
    public function testFormatCnpjSilentlyTruncatesInputLongerThanFourteen(): void {
        self::assertSame('12.345.678/9012-34', Formatter::formatCnpj('123456789012345678'));
    }

    /**
     * Documented: presentation only. Letters are not rejected and check digits are not verified.
     */
    public function testFormatCnpjDoesNotValidateAndKeepsLetters(): void {
        self::assertSame('00.000.000/000A-BC', Formatter::formatCnpj('ABC'));
        self::assertSame('11.111.111/1111-11', Formatter::formatCnpj('11111111111111'));
    }

    public function testFormatCnpjReturnsEmptyStringForNullAndEmptyInput(): void {
        self::assertSame('', Formatter::formatCnpj(null));
        self::assertSame('', Formatter::formatCnpj(''));
    }

    /**
     * Alphanumeric CNPJs (issued since July 2026) use UPPERCASE letters; lowercase input is
     * normalised instead of being displayed as a CNPJ that does not exist.
     */
    public function testFormatCnpjUppercasesTheLettersOfAnAlphanumericCnpj(): void {
        self::assertSame('12.ABC.345/01DE-35', Formatter::formatCnpj('12.abc.345/01de-35'));
        self::assertSame('12.ABC.345/01DE-35', Formatter::formatCpfOrCnpj('12abc34501de35'));
    }

    /**
     * FINDING (low): a value with no letter or digit - a CHAR(n) column of spaces, "-", "../" -
     * was zero-padded into a FABRICATED document number ("000.000.000-00", "00000-000") that
     * looks real on screen. It now yields "".
     */
    public function testMasksDoNotFabricateAllZeroDocumentsFromBlankInput(): void {
        self::assertSame('', Formatter::formatCpf('---'));
        self::assertSame('', Formatter::formatCpf('           '));
        self::assertSame('', Formatter::formatCnpj('  ./-  '));
        self::assertSame('', Formatter::formatCpfOrCnpj('...'));
        self::assertSame('', Formatter::formatCep('        '));
        // A real zero is still content.
        self::assertSame('000.000.000-00', Formatter::formatCpf('0'));
        self::assertSame('00000-000', Formatter::formatCep('0'));
    }

    public function testMasksDropAccentedLettersAndOtherNonAsciiCharacters(): void {
        self::assertSame('123.456.789-01', Formatter::formatCpf("123.456.789-01\u{00A0}é"));
        self::assertSame('01310-100', Formatter::formatCep('01310–100'), 'an en dash is punctuation too');
    }

    public function testFormatCpfMasksElevenCharacters(): void {
        self::assertSame('123.456.789-01', Formatter::formatCpf('12345678901'));
        self::assertSame('123.456.789-01', Formatter::formatCpf('123.456.789-01'));
    }

    public function testFormatCpfPadsShortInputOnTheLeft(): void {
        self::assertSame('000.000.000-12', Formatter::formatCpf('12'));
    }

    public function testFormatCpfSilentlyTruncatesInputLongerThanEleven(): void {
        self::assertSame('123.456.789-01', Formatter::formatCpf('123456789012345'));
    }

    public function testFormatCpfReturnsEmptyStringForNullAndEmptyInput(): void {
        self::assertSame('', Formatter::formatCpf(null));
        self::assertSame('', Formatter::formatCpf(''));
    }

    /**
     * Documented: length alone decides, so a 12-digit value is masked as a padded CNPJ rather
     * than reported as invalid.
     */
    public function testFormatCpfOrCnpjChoosesTheMaskByLengthAlone(): void {
        self::assertSame('123.456.789-01', Formatter::formatCpfOrCnpj('12345678901'));
        self::assertSame('00.123.456/7890-12', Formatter::formatCpfOrCnpj('123456789012'));
        self::assertSame('12.345.678/9012-34', Formatter::formatCpfOrCnpj('12345678901234'));
    }

    public function testFormatCpfOrCnpjReturnsEmptyStringForNullAndEmptyInput(): void {
        self::assertSame('', Formatter::formatCpfOrCnpj(null));
        self::assertSame('', Formatter::formatCpfOrCnpj(''));
    }

    public function testFormatCepMasksAndPadsToEightDigits(): void {
        self::assertSame('12345-678', Formatter::formatCep('12345678'));
        self::assertSame('00000-123', Formatter::formatCep('123'));
    }

    public function testFormatCepSilentlyTruncatesInputLongerThanEight(): void {
        self::assertSame('12345-678', Formatter::formatCep('123456789'));
    }

    /**
     * Documented: unlike the CPF/CNPJ helpers, formatCep returns the empty input UNCHANGED and
     * keeps its type.
     */
    public function testFormatCepReturnsEmptyInputUnchangedKeepingItsType(): void {
        self::assertNull(Formatter::formatCep(null));
        self::assertSame('', Formatter::formatCep(''));
    }

    public function testUnformatCepReturnsOnlyDigits(): void {
        self::assertSame('12345678', Formatter::unformatCep('12345-678'));
    }

    public function testUnformatCepReturnsNullForEmptyInput(): void {
        self::assertNull(Formatter::unformatCep(null));
        self::assertNull(Formatter::unformatCep(''));
    }

    /**
     * BEHAVIOR CHANGE (low). This test used to PIN the empty('0') trap - unformatCep('0') was
     * null - the same "0 is not content" defect earlier passes fixed across Str. "0" is content.
     */
    public function testUnformatCepKeepsTheStringZero(): void {
        self::assertSame('0', Formatter::unformatCep('0'));
        self::assertSame('00', Formatter::unformatCep('0-0'));
    }

    public function testUnformatCepReturnsEmptyStringWhenInputHasNoDigits(): void {
        self::assertSame('', Formatter::unformatCep('abc'));
    }

    public function testUnformatDocumentKeepsLettersAndDigits(): void {
        self::assertSame('12345678000199', Formatter::unformatDocument('12.345.678/0001-99'));
        self::assertSame('12345678X', Formatter::unformatDocument('12.345.678-X'));
    }

    public function testUnformatDocumentReturnsNullForEmptyInput(): void {
        self::assertNull(Formatter::unformatDocument(null));
        self::assertNull(Formatter::unformatDocument(''));
    }

    /**
     * BEHAVIOR CHANGE (low): see testUnformatCepKeepsTheStringZero().
     */
    public function testUnformatDocumentKeepsTheStringZero(): void {
        self::assertSame('0', Formatter::unformatDocument('0'));
    }

    public function testUnformatDocumentReturnsEmptyStringWhenInputHasNoAlphanumerics(): void {
        self::assertSame('', Formatter::unformatDocument('--'));
    }
}
