<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Str;

/**
 * Contract tests for VD\PHPHelper\Str.
 *
 * Tests named *...InsteadOf*, *...NotCharacterLength*, *...RatherThan* and similar pin a defect
 * that was fixed: each of them fails against the pre-fix code. The rest pin the documented
 * contract so it cannot drift back.
 */
final class StrTest extends TestCase {
    /** Output-buffering level on entry, restored in tearDown so a failed flushOutput test cannot leak. */
    private int $obLevel = 0;

    protected function setUp(): void {
        $this->obLevel = ob_get_level();
    }

    protected function tearDown(): void {
        while (ob_get_level() > $this->obLevel) {
            ob_end_clean();
        }
    }

    // ---------------------------------------------------------------- removeInvisibleCharacters

    public function testRemoveInvisibleCharactersReturnsEmptyStringForNullInsteadOfFatalTypeError(): void {
        // The docblock invites string|null and promises string. The old guard returned $str,
        // so null hit the ": string" return type and raised a TypeError - an Error, which a
        // consumer's catch (\Exception) does NOT catch.
        self::assertSame('', Str::removeInvisibleCharacters(null));
        self::assertSame('', Str::removeInvisibleCharacters(''));
    }

    public function testRemoveInvisibleCharactersReturnsZeroStringUnchanged(): void {
        self::assertSame('0', Str::removeInvisibleCharacters('0'));
    }

    public function testRemoveInvisibleCharactersStripsControlCharacters(): void {
        self::assertSame('Javascript', Str::removeInvisibleCharacters("Java\0script"));
        self::assertSame('ab', Str::removeInvisibleCharacters("a\x00\x01\x1Fb"));
        self::assertSame('ab', Str::removeInvisibleCharacters("a\x7Fb"));
    }

    public function testRemoveInvisibleCharactersPreservesNewlineCarriageReturnAndTab(): void {
        self::assertSame("a\nb\r\tc", Str::removeInvisibleCharacters("a\nb\r\tc"));
    }

    public function testRemoveInvisibleCharactersStripsUrlEncodedControlsOnlyWhenAsked(): void {
        self::assertSame('Java%00script', Str::removeInvisibleCharacters('Java%00script'));
        self::assertSame('Javascript', Str::removeInvisibleCharacters('Java%00script', true));
        self::assertSame('Javascript', Str::removeInvisibleCharacters('Java%1fscript', true));
    }

    public function testRemoveInvisibleCharactersRepeatsUntilNothingIsLeftToReassemble(): void {
        // One pass over '%%0000' leaves '%00', which is itself a match. The docblock promises the
        // stripping repeats until a pass removes nothing.
        self::assertSame('', Str::removeInvisibleCharacters('%%0000', true));
    }

    // ------------------------------------------------------------------------------ onlyNumbers

    public function testOnlyNumbersKeepsOnlyDigits(): void {
        self::assertSame('1250', Str::onlyNumbers('-12.50'));
        self::assertSame('123', Str::onlyNumbers('a1b2c3'));
        self::assertSame('', Str::onlyNumbers('abc'));
    }

    public function testOnlyNumbersReturnsEmptyStringForNull(): void {
        self::assertSame('', Str::onlyNumbers(null));
    }

    // ------------------------------------------------------------------------------- decodeText

    public function testDecodeTextDecodesUnicodeEscapeSequences(): void {
        self::assertSame('í', Str::decodeText('í'));
    }

    public function testDecodeTextReturnsNullForNull(): void {
        self::assertNull(Str::decodeText(null));
    }

    // --------------------------------------------------------------------------- containsString

    public function testContainsStringFindsSubstringAnywhere(): void {
        self::assertTrue(Str::containsString('hello world', 'lo wo'));
        self::assertFalse(Str::containsString('hello world', 'HELLO'));
        self::assertTrue(Str::containsString('hello world', 'HELLO', true));
    }

    public function testContainsStringReturnsFalseForBlankHaystackOrNeedle(): void {
        self::assertFalse(Str::containsString('hello', ''));
        self::assertFalse(Str::containsString('hello', null));
        self::assertFalse(Str::containsString('', 'hello'));
        self::assertFalse(Str::containsString(null, 'hello'));
    }

    // ---------------------------------------------------------------------------- replaceString

    public function testReplaceStringReplacesEveryOccurrence(): void {
        self::assertSame('b-b', Str::replaceString('a', 'b', 'a-a'));
    }

    public function testReplaceStringIgnoresCaseWhenAsked(): void {
        self::assertSame('x x', Str::replaceString('a', 'x', 'a A', true));
        self::assertSame('x A', Str::replaceString('a', 'x', 'a A'));
    }

    public function testReplaceStringAcceptsArraySearchAndReplace(): void {
        self::assertSame('1-2', Str::replaceString(['a', 'b'], ['1', '2'], 'a-b'));
        // Surplus searches are replaced with "" (str_replace semantics).
        self::assertSame('1-', Str::replaceString(['a', 'b'], ['1'], 'a-b'));
    }

    public function testReplaceStringReturnsEmptyStringForBlankSubject(): void {
        self::assertSame('', Str::replaceString('a', 'b', null));
        self::assertSame('', Str::replaceString('a', 'b', ''));
    }

    public function testReplaceStringReturnsSubjectUnchangedForBlankSearch(): void {
        self::assertSame('a-a', Str::replaceString(null, 'b', 'a-a'));
        self::assertSame('a-a', Str::replaceString('', 'b', 'a-a'));
    }

    public function testReplaceStringTreatsNullReplaceAsEmptyString(): void {
        self::assertSame('--', Str::replaceString('a', null, '-a-'));
    }

    public function testReplaceStringAlwaysReturnsAStringNeverAnArray(): void {
        // The docblock used to advertise "@return string|array"; a string subject can only ever
        // produce a string, so the return type is now string.
        self::assertIsString(Str::replaceString('a', 'b', 'a'));
    }

    public function testReplaceStringRejectsAnArraySubject(): void {
        // The docblock used to describe $subject as "The string or array being searched", which
        // invited this call. It is, and always was, a TypeError.
        $this->expectException(\TypeError::class);
        /** @phpstan-ignore-next-line - deliberately passing the type the docblock used to promise */
        Str::replaceString('a', 'b', ['x']);
    }

    // -------------------------------------------------------------------------------- mbUcFirst

    public function testMbUcFirstCapitalizesFirstMultibyteCharacter(): void {
        self::assertSame('Ísis', Str::mbUcFirst('ísis'));
        self::assertSame('Hello world', Str::mbUcFirst('hello world'));
    }

    public function testMbUcFirstReturnsNullForNullAndEmptyForEmpty(): void {
        self::assertNull(Str::mbUcFirst(null));
        self::assertSame('', Str::mbUcFirst(''));
    }

    // ----------------------------------------------------------- removeStringPrefix and Suffix

    public function testRemoveStringPrefixRemovesTheZeroPrefixRatherThanIgnoringIt(): void {
        // empty('0') is true, so the old guard bailed out for every "0" prefix.
        self::assertSame('abc', Str::removeStringPrefix('0abc', '0'));
    }

    public function testRemoveStringPrefixRemovesOnlyOneOccurrenceAndOnlyAtTheStart(): void {
        self::assertSame('ab', Str::removeStringPrefix('aab', 'a'));
        self::assertSame('abc', Str::removeStringPrefix('abc', 'bc'));
    }

    public function testRemoveStringPrefixReturnsInputForBlankArguments(): void {
        self::assertNull(Str::removeStringPrefix(null, 'a'));
        self::assertSame('abc', Str::removeStringPrefix('abc', null));
        self::assertSame('abc', Str::removeStringPrefix('abc', ''));
    }

    public function testRemoveStringSuffixRemovesTheZeroSuffixRatherThanIgnoringIt(): void {
        self::assertSame('abc', Str::removeStringSuffix('abc0', '0'));
    }

    public function testRemoveStringSuffixRemovesOnlyOneOccurrenceAndOnlyAtTheEnd(): void {
        self::assertSame('ab', Str::removeStringSuffix('abb', 'b'));
        self::assertSame('abc', Str::removeStringSuffix('abc', 'ab'));
    }

    public function testRemoveStringSuffixReturnsInputForBlankArguments(): void {
        self::assertNull(Str::removeStringSuffix(null, 'a'));
        self::assertSame('abc', Str::removeStringSuffix('abc', null));
        self::assertSame('abc', Str::removeStringSuffix('abc', ''));
    }

    // ------------------------------------------------------------------------ generateUniqueKey

    public function testGenerateUniqueKeyProducesExactlySegmentCountRandomSegments(): void {
        // The old loop spent iteration 0 on an empty $prefix, so the default returned 4 segments
        // (20 hex chars) for a documented $segmentCount of 5 (25 hex chars).
        $segments = explode('-', Str::generateUniqueKey(5, 5));

        self::assertCount(5, $segments);
        foreach ($segments as $segment) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{5}$/', $segment);
        }
    }

    public function testGenerateUniqueKeyNeverDropsTheUniqueIdAtASmallSegmentCount(): void {
        // Verified pre-fix: generateUniqueKey(5, 2, '-', 'ID42') returned a bare 'fc760' - the ID
        // was silently gone, taking with it the uniqueness the caller asked for.
        $key = Str::generateUniqueKey(5, 2, '-', 'ID42');
        $parts = explode('-', $key);

        self::assertContains('0ID42', $parts, "uniqueId must be embedded, got: {$key}");
        self::assertCount(3, $parts, 'two random segments plus the id');
    }

    public function testGenerateUniqueKeyKeepsBothTheUniqueIdAndTheSuffix(): void {
        // Verified pre-fix: (5, 3, '-', 'ID42', '', 'SUF') returned 'cb0a3-0ID42' - the suffix lost
        // its slot to the id.
        $parts = explode('-', Str::generateUniqueKey(5, 3, '-', 'ID42', '', 'SUF'));

        self::assertContains('0ID42', $parts);
        self::assertSame('SUF', end($parts));
        self::assertCount(5, $parts, 'three random segments plus the id plus the suffix');
    }

    public function testGenerateUniqueKeyEmbedsTheZeroUniqueIdRatherThanDroppingIt(): void {
        // '0' is a legitimate id; the old !empty() guard threw it away.
        $parts = explode('-', Str::generateUniqueKey(5, 5, '-', '0'));

        self::assertContains('00000', $parts, 'id "0" is left-padded to the segment length');
        self::assertCount(6, $parts);
    }

    public function testGenerateUniqueKeyPlacesPrefixFirstAndSuffixLastWithoutConsumingSegments(): void {
        $parts = explode('-', Str::generateUniqueKey(5, 5, '-', '', 'PRE', 'SUF'));

        self::assertSame('PRE', $parts[0]);
        self::assertSame('SUF', end($parts));
        self::assertCount(7, $parts, 'prefix + 5 random segments + suffix');
    }

    public function testGenerateUniqueKeyTruncatesLongIdOnlyWhenIgnoreLengthOnIdIsFalse(): void {
        self::assertContains('ABCDEFGH', explode('-', Str::generateUniqueKey(5, 3, '-', 'ABCDEFGH')));
        self::assertContains('ABCDE', explode('-', Str::generateUniqueKey(5, 3, '-', 'ABCDEFGH', '', '', false)));
    }

    public function testGenerateUniqueKeyFallsBackToFiveForOutOfRangeSizes(): void {
        foreach ([0, -1] as $invalidCount) {
            self::assertCount(5, explode('-', Str::generateUniqueKey(5, $invalidCount)));
        }

        // A $segmentLength >= the 128-char whirlpool hash (and any <= 0) falls back to 5.
        foreach ([0, -3, 128, 999] as $invalidLength) {
            $segments = explode('-', Str::generateUniqueKey($invalidLength, 2));
            self::assertSame(5, strlen($segments[0]), "segmentLength {$invalidLength} must fall back to 5");
        }
    }

    public function testGenerateUniqueKeySupportsAnEmptySeparator(): void {
        self::assertMatchesRegularExpression('/^[0-9a-f]{25}$/', Str::generateUniqueKey(5, 5, ''));
    }

    // ----------------------------------------------------------------------------- generateGuid

    public function testGenerateGuidReturnsABareGuidByDefault(): void {
        $guid = Str::generateGuid();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $guid
        );
        self::assertSame(36, strlen($guid));
    }

    public function testGenerateGuidWrapsInCurlyBracesWhenTrimIsFalse(): void {
        // Verified pre-fix: on every host without com_create_guid (i.e. all Linux/macOS), the
        // openssl branch ignored $trim entirely and returned an unbraced GUID.
        $guid = Str::generateGuid(false);

        self::assertMatchesRegularExpression(
            '/^\{[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\}$/i',
            $guid
        );
        self::assertSame(38, strlen($guid));
    }

    public function testGenerateGuidReturnsADifferentValueOnEachCall(): void {
        self::assertNotSame(Str::generateGuid(), Str::generateGuid());
    }

    // ----------------------------------------------------------------------- removeExcessSpaces

    public function testRemoveExcessSpacesCollapsesRunsOfSpacesIntoASingleSpace(): void {
        // Verified pre-fix: the "+" bound only to the <br> alternative, so the whitespace group
        // matched ONE character and N spaces were replaced by N spaces - a no-op normalizer.
        self::assertSame('Hello World', Str::removeExcessSpaces('Hello    World'));
        self::assertSame('Joao Silva', Str::removeExcessSpaces('Joao  Silva'));
    }

    public function testRemoveExcessSpacesCollapsesMixedWhitespaceAndBrTagsIntoOneSpace(): void {
        self::assertSame('a b', Str::removeExcessSpaces("a \n\t <br> b"));
        self::assertSame('a b', Str::removeExcessSpaces('a <br /> b'));
        self::assertSame('a b', Str::removeExcessSpaces("a\r\n\tb"));
    }

    public function testRemoveExcessSpacesRemovesEveryRunWhenKeepSingleSpaceIsFalse(): void {
        self::assertSame('HelloWorld', Str::removeExcessSpaces('Hello    World', false));
        self::assertSame('ab', Str::removeExcessSpaces("a \n <br> b", false));
    }

    public function testRemoveExcessSpacesTrimsBothEnds(): void {
        self::assertSame('a b', Str::removeExcessSpaces('   a  b   '));
    }

    public function testRemoveExcessSpacesKeepsTheZeroStringRatherThanBlankingIt(): void {
        // empty('0') is true, so the old guard returned '' for the legitimate content "0".
        self::assertSame('0', Str::removeExcessSpaces('0'));
    }

    public function testRemoveExcessSpacesCastsScalarsAndStringables(): void {
        self::assertSame('42', Str::removeExcessSpaces(42));
        self::assertSame('1.5', Str::removeExcessSpaces(1.5));

        $stringable = new class implements \Stringable {
            public function __toString(): string {
                return 'a    b';
            }
        };
        self::assertSame('a b', Str::removeExcessSpaces($stringable));
    }

    public function testRemoveExcessSpacesReturnsEmptyStringForNonStringableInput(): void {
        self::assertSame('', Str::removeExcessSpaces(null));
        self::assertSame('', Str::removeExcessSpaces(''));
        self::assertSame('', Str::removeExcessSpaces([]));
        self::assertSame('', Str::removeExcessSpaces(['a', 'b']));
        self::assertSame('', Str::removeExcessSpaces(new \stdClass()));
    }

    // ----------------------------------------------------------- strToUpper / strToLower / trim

    public function testStrToUpperHandlesMultibyteAndNull(): void {
        self::assertSame('AÇÃO', Str::strToUpper('ação'));
        self::assertNull(Str::strToUpper(null));
    }

    public function testStrToLowerHandlesMultibyteAndNull(): void {
        self::assertSame('ação', Str::strToLower('AÇÃO'));
        self::assertNull(Str::strToLower(null));
    }

    public function testTrimReturnsNullForNullAndNeverNormalizesItToEmptyString(): void {
        // The prose used to promise "Returns empty string if input is null", which the @return
        // tag, the signature and the code all contradicted. Callers rely on null to tell
        // "absent" apart from "blank", so the prose was the half that had to go.
        self::assertNull(Str::trim(null));
        self::assertNotSame('', Str::trim(null));
    }

    public function testTrimStripsWhitespaceFromBothEnds(): void {
        self::assertSame('a b', Str::trim("  \t a b \n "));
        self::assertSame('', Str::trim('   '));
    }

    // -------------------------------------------------------------- strLen / subStr / positions

    public function testStrLenCountsCharactersNotBytes(): void {
        self::assertSame(3, Str::strLen('ção'));
        self::assertSame(0, Str::strLen(''));
    }

    public function testStrLenReturnsZeroForNull(): void {
        self::assertSame(0, Str::strLen(null));
    }

    public function testSubStrExtractsByCharacterAndSupportsNegativeOffsets(): void {
        self::assertSame('çã', Str::subStr('ação', 1, 2));
        self::assertSame('ção', Str::subStr('ação', 1));
        self::assertSame('ão', Str::subStr('ação', -2));
    }

    public function testSubStrReturnsNullForNull(): void {
        self::assertNull(Str::subStr(null));
    }

    public function testStrPosReturnsCharacterPositionOrFalse(): void {
        self::assertSame(1, Str::strPos('ábc', 'b'));
        self::assertFalse(Str::strPos('abc', 'z'));
        self::assertFalse(Str::strPos('abc', 'A'));
    }

    public function testStrPosReturnsFalseForNull(): void {
        self::assertFalse(Str::strPos(null, 'a'));
    }

    public function testStrPosThrowsValueErrorWhenTheOffsetIsOutsideTheString(): void {
        $this->expectException(\ValueError::class);
        Str::strPos('abc', 'a', 10);
    }

    public function testStrIPosIgnoresCase(): void {
        self::assertSame(0, Str::strIPos('ABC', 'a'));
        self::assertSame(1, Str::strIPos('ábC', 'B'));
        self::assertFalse(Str::strIPos(null, 'a'));
    }

    // ----------------------------------------------------------------- getAdjacentCombinations

    public function testGetAdjacentCombinationsGeneratesEveryContiguousRun(): void {
        $result = Str::getAdjacentCombinations(['a', 'b', 'c']);

        self::assertCount(6, $result);
        foreach (['a b c', 'a b', 'b c', 'a', 'b', 'c'] as $expected) {
            self::assertContains($expected, $result);
        }
        self::assertNotContains('a c', $result, 'non-adjacent elements must not be combined');
    }

    public function testGetAdjacentCombinationsSortsByWordCountDescendingNotCharacterLength(): void {
        // The docblock claimed "sorted by length descending". The code sorts by word count, and a
        // caller doing greedy longest-match replacement on that promise silently mis-matches.
        $result = Str::getAdjacentCombinations(['aaaaaaaaaaaa', 'b', 'c']);

        self::assertLessThan(
            array_search('aaaaaaaaaaaa', $result, true),
            array_search('b c', $result, true),
            "'b c' (3 chars, 2 words) must precede 'aaaaaaaaaaaa' (12 chars, 1 word)"
        );

        $wordCounts = array_map(static fn(string $s): int => count(explode(' ', $s)), $result);
        $sorted = $wordCounts;
        rsort($sorted);
        self::assertSame($sorted, $wordCounts, 'word counts must be non-increasing');
    }

    public function testGetAdjacentCombinationsPopulatesAndReusesTheCache(): void {
        $cache = [];
        $input = ['a', 'b'];
        $first = Str::getAdjacentCombinations($input, $cache);

        self::assertArrayHasKey(md5(serialize($input)), $cache);
        self::assertSame($first, Str::getAdjacentCombinations($input, $cache));
    }

    public function testGetAdjacentCombinationsReturnsACachedEntryVerbatimWithoutRevalidating(): void {
        // Documented: the cache is never invalidated. Pinned so nobody "fixes" it by accident.
        $input = ['a', 'b'];
        $cache = [md5(serialize($input)) => ['poisoned']];

        self::assertSame(['poisoned'], Str::getAdjacentCombinations($input, $cache));
    }

    public function testGetAdjacentCombinationsReturnsEmptyArrayForEmptyInput(): void {
        self::assertSame([], Str::getAdjacentCombinations([]));
    }

    // ------------------------------------------------------------- truncateAtFirstOccurrence

    public function testTruncateAtFirstOccurrenceCutsAtTheFirstMatch(): void {
        self::assertSame('a', Str::truncateAtFirstOccurrence('a-b-c', '-'));
        self::assertSame('', Str::truncateAtFirstOccurrence('-abc', '-'));
    }

    public function testTruncateAtFirstOccurrenceReturnsTheOriginalWhenNotFound(): void {
        self::assertSame('abc', Str::truncateAtFirstOccurrence('abc', 'z'));
    }

    public function testTruncateAtFirstOccurrenceReturnsInputForBlankArguments(): void {
        self::assertNull(Str::truncateAtFirstOccurrence(null, '-'));
        self::assertSame('abc', Str::truncateAtFirstOccurrence('abc', null));
        self::assertSame('abc', Str::truncateAtFirstOccurrence('abc', ''));
    }

    // -------------------------------------------------------------------------- removeSubstrings

    public function testRemoveSubstringsRemovesEveryListedSubstring(): void {
        self::assertSame('ac', Str::removeSubstrings('abc', ['b']));
        self::assertSame('c', Str::removeSubstrings('abcab', ['ab']));
    }

    public function testRemoveSubstringsReturnsInputForAnEmptyList(): void {
        self::assertSame('abc', Str::removeSubstrings('abc', []));
    }

    public function testRemoveSubstringsAppliesRemovalsInOrderSoOrderMatters(): void {
        // Removing 'b' first creates a new 'ac' for the second entry to remove.
        self::assertSame('', Str::removeSubstrings('abc', ['b', 'ac']));
        self::assertSame('ac', Str::removeSubstrings('abc', ['ac', 'b']));
    }

    // -------------------------------------------------------------------------- removeCharacters

    public function testRemoveCharactersWithAnEmptyListReturnsInputInsteadOfFatalTypeError(): void {
        // Verified pre-fix: sprintf built the pattern '/[]/u' - an unterminated character class.
        // preg_replace warned and returned null, and ": string" turned that into a TypeError that
        // catch (\Exception) does not catch. A config-driven strip-list that is empty for one
        // tenant fataled that tenant's request.
        self::assertSame('abc', Str::removeCharacters('abc', ''));
    }

    public function testRemoveCharactersRemovesEachListedCharacter(): void {
        self::assertSame('bd', Str::removeCharacters('abcd', 'ac'));
        self::assertSame('aão', Str::removeCharacters('ação', 'ç'));
    }

    public function testRemoveCharactersTreatsTheListAsLiteralCharactersNotARegexRange(): void {
        // 'a-c' is the set {a, -, c}, never the range a..c.
        self::assertSame('b', Str::removeCharacters('abc-', 'a-c'));
        self::assertSame('ab', Str::removeCharacters('a.b', '.'));
    }

    public function testRemoveCharactersReturnsEmptyStringForNullInputAndInputForNullList(): void {
        self::assertSame('', Str::removeCharacters(null, 'a'));
        self::assertSame('', Str::removeCharacters(null, null));
        self::assertSame('abc', Str::removeCharacters('abc', null));
    }

    public function testRemoveCharactersThrowsCatchableExceptionOnInvalidUtf8(): void {
        // Without the explicit guard, PCRE returns null here and the string return type raises a
        // TypeError - an Error, invisible to catch (\Exception).
        $this->expectException(\InvalidArgumentException::class);
        Str::removeCharacters("\xFF", 'a');
    }

    // ------------------------------------------------------------------------ keepOnlyCharacters

    public function testKeepOnlyCharactersKeepsOnlyTheAllowedOnes(): void {
        self::assertSame('ac', Str::keepOnlyCharacters('abcd', 'ac'));
        self::assertSame('ç', Str::keepOnlyCharacters('ação', 'ç'));
    }

    public function testKeepOnlyCharactersTreatsTheAllowListAsLiteralCharacters(): void {
        self::assertSame('ac-', Str::keepOnlyCharacters('abc-', 'a-c'));
    }

    public function testKeepOnlyCharactersReturnsEmptyStringWhenNothingIsAllowed(): void {
        // An empty allowlist can only deny - it must never pass input through unfiltered.
        self::assertSame('', Str::keepOnlyCharacters('abc', ''));
        self::assertSame('', Str::keepOnlyCharacters('abc', null));
        self::assertSame('', Str::keepOnlyCharacters(null, 'a'));
    }

    public function testKeepOnlyCharactersThrowsCatchableExceptionOnInvalidUtf8(): void {
        $this->expectException(\InvalidArgumentException::class);
        Str::keepOnlyCharacters("\xFF", 'a');
    }

    // ------------------------------------------------------- onlyLettersAndNumbers / onlyLetters

    public function testOnlyLettersAndNumbersStripsEverythingElse(): void {
        self::assertSame('a1B2', Str::onlyLettersAndNumbers('a1!B 2-'));
        self::assertSame('ao', Str::onlyLettersAndNumbers('ação'), 'accented letters are not a-z');
    }

    public function testOnlyLettersAndNumbersReturnsEmptyStringForNull(): void {
        self::assertSame('', Str::onlyLettersAndNumbers(null));
    }

    public function testOnlyLettersStripsDigitsAndSymbols(): void {
        self::assertSame('aB', Str::onlyLetters('a1!B 2-'));
    }

    public function testOnlyLettersReturnsEmptyStringForNull(): void {
        self::assertSame('', Str::onlyLetters(null));
    }

    // ----------------------------------------------------------------------- truncateWithTooltip

    public function testTruncateWithTooltipWrapsShortTextWithoutASuffix(): void {
        self::assertSame(
            '<span class="tooltip_ativo" title="hello">hello</span>',
            Str::truncateWithTooltip('hello', 100)
        );
    }

    public function testTruncateWithTooltipTruncatesAndAppendsTheSuffix(): void {
        self::assertSame(
            '<span class="tooltip_ativo" title="hello">hel...</span>',
            Str::truncateWithTooltip('hello', 3)
        );
    }

    public function testTruncateWithTooltipRendersTheZeroStringRatherThanBlankingIt(): void {
        // empty('0') is true, so a "0" cell - a count, a balance - rendered as nothing at all.
        self::assertSame(
            '<span class="tooltip_ativo" title="0">0</span>',
            Str::truncateWithTooltip('0')
        );
    }

    public function testTruncateWithTooltipEncodesAnAttributeBreakoutPayload(): void {
        $html = Str::truncateWithTooltip('cliente" onmouseover=alert(1)', 100);

        self::assertStringContainsString('title="cliente&quot; onmouseover=alert(1)"', $html);
        self::assertStringNotContainsString('onmouseover=alert(1)"', substr($html, 0, strpos($html, 'title="') + 7));
    }

    public function testTruncateWithTooltipStripsTagsFromTheText(): void {
        self::assertSame(
            '<span class="tooltip_ativo" title="hi">hi</span>',
            Str::truncateWithTooltip('<b>hi</b>', 100)
        );
    }

    public function testTruncateWithTooltipEncodesTheSuffixSoItCannotInjectMarkup(): void {
        $html = Str::truncateWithTooltip('hello', 3, '<i>');

        self::assertStringContainsString('hel&lt;i&gt;</span>', $html);
        self::assertStringNotContainsString('<i>', $html);
    }

    public function testTruncateWithTooltipHandlesMultibyteTextByDisplayWidth(): void {
        self::assertSame(
            '<span class="tooltip_ativo" title="ação">aç...</span>',
            Str::truncateWithTooltip('ação', 2)
        );
    }

    public function testTruncateWithTooltipReturnsEmptyStringForBlankTextOrNonPositiveLength(): void {
        self::assertSame('', Str::truncateWithTooltip(null));
        self::assertSame('', Str::truncateWithTooltip(''));
        self::assertSame('', Str::truncateWithTooltip('hello', 0));
        self::assertSame('', Str::truncateWithTooltip('hello', -1));
    }

    public function testTruncateWithTooltipAcceptsANullSuffix(): void {
        self::assertSame(
            '<span class="tooltip_ativo" title="hello">hel</span>',
            Str::truncateWithTooltip('hello', 3, null)
        );
    }

    // ------------------------------------------------------------------------------ toCamelCase

    public function testToCamelCaseConvertsSeparatorRunsToCamelCase(): void {
        self::assertSame('helloWorldFoo', Str::toCamelCase('hello world-foo'));
        self::assertSame('helloWorld', Str::toCamelCase('  hello   world  '));
    }

    public function testToCamelCasePreserveListAddsCharactersAndNotWords(): void {
        // Documented reality: the entries are spliced into a character class, so an ordinary word
        // is a silent no-op ("bar" adds b, a, r - all already word characters)...
        self::assertSame('fooBarBaz', Str::toCamelCase('foo bar baz', ['bar']));

        // ...and a word containing a symbol preserves that SYMBOL globally, not that word.
        self::assertSame('order_idTotal', Str::toCamelCase('order_id total', ['order_id']));
        self::assertSame('a_bOther', Str::toCamelCase('a_b other', ['order_id']));
    }

    public function testToCamelCaseEscapesPreserveCharactersSoAMetacharacterCannotCorruptThePattern(): void {
        // Verified pre-fix: a lone backslash produced '/[^a-z0-9\]+/i', an unterminated character
        // class. preg_replace warned, returned null, and the whole string came back as ''.
        self::assertSame('a\bC', Str::toCamelCase('a\\b c', ['\\']));
        self::assertSame('a]bC', Str::toCamelCase('a]b c', [']']));
    }

    public function testToCamelCaseKeepsTheZeroStringRatherThanBlankingIt(): void {
        self::assertSame('0', Str::toCamelCase('0'));
    }

    public function testToCamelCaseReturnsEmptyStringForNullAndEmpty(): void {
        self::assertSame('', Str::toCamelCase(null));
        self::assertSame('', Str::toCamelCase(''));
    }

    public function testToCamelCaseDecodesUnicodeEscapesAndDropsAccentedLettersAsWordBreaks(): void {
        // Documented gotcha: accented letters are not a-z0-9, so they break words and vanish.
        self::assertSame('cafBar', Str::toCamelCase('café bar'));
    }

    // ------------------------------------------------------------------------------ flushOutput

    public function testFlushOutputEmitsTheMessageWithPaddingAndLeavesTheCallersBufferOpen(): void {
        // Pre-fix, ob_end_flush() ran unconditionally: a helper that only borrowed the buffer
        // popped the CALLER's level, releasing output they were still holding.
        $sink = ob_start();
        self::assertTrue($sink);
        ob_start(); // stands in for a buffer the caller already had open
        $levelWithCallerBuffer = ob_get_level();

        Str::flushOutput('hello');

        $levelAfter = ob_get_level();
        ob_end_clean();
        $flushed = (string) ob_get_clean();

        self::assertSame($levelWithCallerBuffer, $levelAfter, "flushOutput must not close the caller's buffer");
        self::assertStringStartsWith('hello', $flushed);
        self::assertSame('hello' . str_pad('', 4096) . "\n", $flushed);
    }

    public function testFlushOutputClampsNegativeSleepInsteadOfRejectingIt(): void {
        ob_start();
        ob_start();
        Str::flushOutput('x', -5); // sleep(-5) would be a ValueError; the clamp keeps it at 0
        ob_end_clean();
        $flushed = (string) ob_get_clean();

        self::assertStringStartsWith('x', $flushed);
    }

    // ------------------------------------------------------------------------ containsExactWord

    public function testContainsExactWordReturnsFalseForABlankWordInsteadOfMatchingEverything(): void {
        // Verified pre-fix: both returned TRUE. Wired to an allowlist or a policy check, a blank
        // configured term silently authorized every input - a fail-OPEN default the docblock
        // ("false otherwise") gave no hint of.
        self::assertFalse(Str::containsExactWord('hello world', ''));
        self::assertFalse(Str::containsExactWord('hello world', null));
    }

    public function testContainsExactWordMatchesOnlyStandaloneWords(): void {
        self::assertTrue(Str::containsExactWord('hello world', 'world'));
        self::assertFalse(Str::containsExactWord('helloworld', 'world'));
        self::assertFalse(Str::containsExactWord('hello worlds', 'world'));
    }

    public function testContainsExactWordHonorsCaseSensitivity(): void {
        self::assertFalse(Str::containsExactWord('Hello World', 'hello'));
        self::assertTrue(Str::containsExactWord('Hello World', 'hello', false));
    }

    public function testContainsExactWordReturnsFalseForBlankText(): void {
        self::assertFalse(Str::containsExactWord('', 'word'));
        self::assertFalse(Str::containsExactWord(null, 'word'));
    }

    public function testContainsExactWordEscapesTheWordSoItCannotInjectAPattern(): void {
        self::assertFalse(Str::containsExactWord('hello world', 'w.rld'));
    }

    public function testContainsExactWordCannotMatchAWordEndingInANonWordCharacter(): void {
        // Documented caveat: \b needs a word/non-word transition, and "+" is not a word character.
        self::assertFalse(Str::containsExactWord('a c++ b', 'c++'));
    }

    // ----------------------------------------------------------------------------- removeAccents

    public function testRemoveAccentsFoldsLatinAccentsToAscii(): void {
        self::assertSame('Ola Cao', Str::removeAccents('Olá Ção'));
        self::assertSame('aeiou', Str::removeAccents('áéíóú'));
        self::assertSame('OEuvre', Str::removeAccents('Œuvre'), 'Latin Extended-A expands to two letters');
    }

    public function testRemoveAccentsReturnsAsciiInputUnchanged(): void {
        self::assertSame('abc', Str::removeAccents('abc'));
        self::assertSame('', Str::removeAccents(''));
    }

    public function testRemoveAccentsLeavesCharactersOutsideTheCoveredBlocksUntouched(): void {
        // Documented limit: it is not a general ASCII transliterator.
        self::assertSame('Ж', Str::removeAccents('Ж'));
        self::assertSame('Æ', Str::removeAccents('Æ'));
    }

    // ------------------------------------------------------------------------ findAllOccurrences

    public function testFindAllOccurrencesFindsTheDigitZeroNeedleRatherThanReturningNothing(): void {
        // empty('0') is true, so searching for the digit 0 always returned [].
        self::assertSame([1, 2], Str::findAllOccurrences('1002', '0'));
    }

    public function testFindAllOccurrencesReturnsEveryStartPosition(): void {
        self::assertSame([1, 4], Str::findAllOccurrences('abcabc', 'bc'));
        self::assertSame([], Str::findAllOccurrences('abc', 'z'));
    }

    public function testFindAllOccurrencesReturnsEndPositionsWhenAsked(): void {
        self::assertSame([3, 6], Str::findAllOccurrences('abcabc', 'bc', true));
    }

    public function testFindAllOccurrencesDoesNotReportOverlappingMatches(): void {
        self::assertSame([0, 2], Str::findAllOccurrences('aaaa', 'aa'));
    }

    public function testFindAllOccurrencesIgnoresCaseWhenAsked(): void {
        self::assertSame([], Str::findAllOccurrences('ABCaBC', 'bc'));
        self::assertSame([1, 4], Str::findAllOccurrences('ABCaBC', 'bc', false, false));
    }

    public function testFindAllOccurrencesReturnsCharacterPositionsNotBytePositions(): void {
        self::assertSame([1, 4], Str::findAllOccurrences('ábcábc', 'bc'));
    }

    public function testFindAllOccurrencesReturnsEmptyArrayForBlankArguments(): void {
        self::assertSame([], Str::findAllOccurrences('', 'a'));
        self::assertSame([], Str::findAllOccurrences('abc', ''));
    }

    // -------------------------------------------------------------------- extractSubstringsBetween

    public function testExtractSubstringsBetweenHonorsTheZeroDelimiterRatherThanReturningNothing(): void {
        self::assertSame(['b'], Str::extractSubstringsBetween('a0b0c', '0', '0'));
    }

    public function testExtractSubstringsBetweenReturnsEveryRegion(): void {
        self::assertSame(['a', 'b'], Str::extractSubstringsBetween('[a][b]', '[', ']'));
        self::assertSame(['ção'], Str::extractSubstringsBetween('x[ção]y', '[', ']'));
    }

    public function testExtractSubstringsBetweenCanIncludeTheDelimiters(): void {
        self::assertSame(['[a]', '[b]'], Str::extractSubstringsBetween('[a][b]', '[', ']', true, true));
    }

    public function testExtractSubstringsBetweenIgnoresCaseWhenAsked(): void {
        self::assertSame([], Str::extractSubstringsBetween('XaY', 'x', 'y'));
        self::assertSame(['a'], Str::extractSubstringsBetween('XaY', 'x', 'y', false));
        // Documented: the delimiters are re-attached AS PASSED, not as matched.
        self::assertSame(['xay'], Str::extractSubstringsBetween('XaY', 'x', 'y', false, true));
    }

    public function testExtractSubstringsBetweenReturnsAnEmptyStringForAnEmptyRegion(): void {
        self::assertSame([''], Str::extractSubstringsBetween('[]', '[', ']'));
    }

    public function testExtractSubstringsBetweenStopsWhenTheEndDelimiterIsMissing(): void {
        self::assertSame([], Str::extractSubstringsBetween('[a', '[', ']'));
        self::assertSame(['a'], Str::extractSubstringsBetween('[a][b', '[', ']'));
    }

    public function testExtractSubstringsBetweenReturnsEmptyArrayForBlankArguments(): void {
        self::assertSame([], Str::extractSubstringsBetween(null, '[', ']'));
        self::assertSame([], Str::extractSubstringsBetween('[a]', '', ']'));
        self::assertSame([], Str::extractSubstringsBetween('[a]', '[', null));
    }
}
