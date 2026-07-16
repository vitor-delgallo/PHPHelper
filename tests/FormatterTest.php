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
     * by a power of ten. The "." fallback happens only AFTER the filter, so it cannot rescue it.
     * Pinned because the silent 10x is exactly what a caller must be warned about.
     */
    public function testFormatNumberInputSeparatorMismatchSilentlyRescalesTheValue(): void {
        self::assertSame('15', Formatter::formatNumber('1.5', ','));
        self::assertSame('15', Formatter::formatNumber('1.5', '9'));
        self::assertSame('15', Formatter::formatNumber('1.5', ''));
    }

    public function testFormatNumberKeepsTheNegativeSignByDefault(): void {
        self::assertSame('-50', Formatter::formatNumber('-50'));
        self::assertSame('R$ -1.234,56', Formatter::formatNumber('-1234.56', '.', ',', '.', 'R$', '', 2));
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
        // decimalPlaces must cap the FRACTION, not annihilate the integer part (used to give '0').
        self::assertSame('150000000000000000000', Formatter::formatNumber(1.5e20, decimalPlaces: 2));
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
        self::assertSame('0', Formatter::formatNumber('E5'));
        self::assertSame('1', Formatter::formatNumber('1E0'));
    }

    public function testFormatNumberFormatsScientificNotationWithThousandsSeparator(): void {
        self::assertSame('150.000.000.000.000.000.000', Formatter::formatNumber(1.5e20, '.', ',', '.'));
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
     * Documented PHP empty() trap: the string "0" counts as empty, so it comes back as null
     * rather than "0".
     */
    public function testUnformatCepTreatsTheStringZeroAsEmptyAndReturnsNull(): void {
        self::assertNull(Formatter::unformatCep('0'));
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
     * Documented PHP empty() trap: "0" is treated as empty and returns null.
     */
    public function testUnformatDocumentTreatsTheStringZeroAsEmptyAndReturnsNull(): void {
        self::assertNull(Formatter::unformatDocument('0'));
    }

    public function testUnformatDocumentReturnsEmptyStringWhenInputHasNoAlphanumerics(): void {
        self::assertSame('', Formatter::unformatDocument('--'));
    }
}
