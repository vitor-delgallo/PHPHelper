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

    /**
     * FINDING (medium): the URL-encoded patterns were case-sensitive, so the UPPERCASE spelling -
     * the canonical one per RFC 3986, and what rawurlencode() itself emits - walked straight
     * through: removeInvisibleCharacters('a%0Bb%1Fc', true) returned it unchanged.
     */
    public function testRemoveInvisibleCharactersStripsUppercaseUrlEncodedControls(): void {
        self::assertSame(rawurlencode("\x1F"), '%1F', 'precondition: PHP itself encodes in uppercase');

        self::assertSame('abc', Str::removeInvisibleCharacters('a%0Bb%1Fc', true));
        self::assertSame('Javascript', Str::removeInvisibleCharacters('Java%0Escript', true));
        self::assertSame('', Str::removeInvisibleCharacters('%%0B0B', true), 'the reassembly loop must hold for uppercase too');
    }

    public function testRemoveInvisibleCharactersStripsTheEncodedDelLikeTheRawOne(): void {
        // The raw set always included 127; its encoded form was missing from the URL set.
        self::assertSame('ab', Str::removeInvisibleCharacters("a\x7Fb"));
        self::assertSame('abc', Str::removeInvisibleCharacters('a%7Fb%7fc', true));
        self::assertSame('a%7Fb', Str::removeInvisibleCharacters('a%7Fb'), 'only when $urlEncoded is set');
    }

    public function testRemoveInvisibleCharactersKeepsUrlEncodedNewlineCrAndTab(): void {
        // %09, %0A and %0D are the encoded forms of the three characters the method preserves.
        self::assertSame('a%09b%0Ac%0dd', Str::removeInvisibleCharacters('a%09b%0Ac%0dd', true));
        // Printable encodings are not touched either.
        self::assertSame('%20%41%7E', Str::removeInvisibleCharacters('%20%41%7E', true));
    }

    public function testRemoveInvisibleCharactersIsByteSafeOnInvalidUtf8(): void {
        self::assertSame("\xFF\xFEok", Str::removeInvisibleCharacters("\xFF\x00\xFE\x01ok"));
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
        // This used to be decodeText('í') === 'í': the input held no escape sequence at all, so
        // the test passed against an identity function. The single quotes keep 'í' literal.
        self::assertSame('í', Str::decodeText('í'));
        self::assertSame('Ação', Str::decodeText('Ação'));
        self::assertSame('0', Str::decodeText('0'));
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
        // produce a string, so the return type is now string. (assertIsString alone was
        // tautological under a ": string" return type; pin the value instead.)
        self::assertSame('xyc', Str::replaceString(['a', 'b'], ['x', 'y'], 'abc'));
    }

    /**
     * FINDING (low): an array $replace with a string $search is accepted by the signature but
     * raised str_replace()'s TypeError - an \Error, invisible to catch (\Exception).
     */
    public function testReplaceStringRejectsAnArrayReplaceForAStringSearch(): void {
        $this->expectException(\InvalidArgumentException::class);
        Str::replaceString('a', ['x'], 'abc');
    }

    public function testReplaceStringCaseInsensitivityIsAsciiOnly(): void {
        // Documented: str_ireplace folds ASCII only.
        self::assertSame('Ç', Str::replaceString('ç', 'x', 'Ç', true));
        self::assertSame('xx', Str::replaceString('Ab', 'x', 'abAB', true));
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

    /**
     * FINDING (low): the first character was UPPER-cased, not title-cased. Since PHP 7.3
     * mb_strtoupper() applies full case mapping, so "ß" became "SS" and a sentence starting with
     * it came out as "SSa"; digraphs and ligatures were upper-cased whole.
     */
    public function testMbUcFirstTitleCasesRatherThanUpperCasesTheFirstCharacter(): void {
        self::assertSame('Ssa', Str::mbUcFirst('ßa'));
        self::assertSame('ǅa', Str::mbUcFirst('ǆa'), 'U+01C6 titlecases to U+01C5, not U+01C4');
        self::assertSame('Fix', Str::mbUcFirst('ﬁx'));
        self::assertSame('ÉCOLE', Str::mbUcFirst('éCOLE'), 'only the first character is touched');
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

    /**
     * @return array<string, array{int, int}>
     */
    public static function outOfRangeKeySizes(): array {
        return [
            'length 0' => [0, 5],
            'length -3' => [-3, 5],
            'length 128' => [128, 1],
            'length 999' => [999, 1],
            'count 0' => [5, 0],
            'count -1' => [5, -1],
        ];
    }

    /**
     * BEHAVIOR CHANGE / FINDING (medium). Out-of-range sizes used to fall back silently to 5.
     * For $segmentLength > 127 that was a silent SECURITY DOWNGRADE: generateUniqueKey(128, 1) -
     * a request for a 512-bit token - returned 5 hex characters, i.e. 20 bits. It now throws.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('outOfRangeKeySizes')]
    public function testGenerateUniqueKeyRejectsOutOfRangeSizesInsteadOfSilentlyShrinkingThem(int $length, int $count): void {
        $this->expectException(\InvalidArgumentException::class);
        Str::generateUniqueKey($length, $count);
    }

    public function testGenerateUniqueKeyAcceptsBothEndsOfTheSegmentLengthRange(): void {
        self::assertMatchesRegularExpression('/^[0-9a-f]$/', Str::generateUniqueKey(1, 1));
        self::assertMatchesRegularExpression('/^[0-9a-f]{127}$/', Str::generateUniqueKey(127, 1));
    }

    /**
     * FINDING (low): the id was padded and truncated with byte functions. 'ééé' (6 bytes) was not
     * padded to a 5-character segment at all, and truncating it to 2 "characters" cut the first
     * 'é' in half, leaving invalid UTF-8 in the key.
     */
    public function testGenerateUniqueKeyPadsAndTruncatesTheIdByCharacterNotByte(): void {
        $padded = explode('-', Str::generateUniqueKey(5, 1, '-', 'ééé'));
        self::assertSame('00ééé', $padded[1]);

        $truncated = explode('-', Str::generateUniqueKey(2, 1, '-', 'ééé', '', '', false));
        self::assertSame('éé', $truncated[1]);
        self::assertTrue(mb_check_encoding(implode('-', $truncated), 'UTF-8'));
    }

    public function testGenerateUniqueKeySupportsAnEmptySeparator(): void {
        self::assertMatchesRegularExpression('/^[0-9a-f]{25}$/', Str::generateUniqueKey(5, 5, ''));
    }

    /**
     * BEHAVIOR CHANGE (pass 2). Every segment used to be cut at a rand() offset out of ONE 128-char
     * whirlpool hash, so segments within a single key repeated verbatim — reproduced live as
     * 'PRE-6f972-6f972-fd013-3213e-c98ee'. Segments are now independent random_bytes() draws.
     *
     * The parameters are chosen so the two implementations are separated by an enormous margin
     * rather than by luck. At (32, 8) the old design had only 97 possible start offsets for 8
     * segments, so a birthday collision hit ~25% of keys; over 60 keys, seeing zero collisions had
     * probability ~0.75^60 ~ 3e-8, i.e. the old code failed this test essentially always. The new
     * code collides only if two independent 32-hex-char draws coincide: C(8,2) * 16^-32 per key,
     * which is ~1e-36 over all 60 keys. No flake in either direction.
     */
    public function testGenerateUniqueKeyDoesNotRepeatSegmentsWithinAKey(): void {
        for ($i = 0; $i < 60; $i++) {
            $segments = explode('-', Str::generateUniqueKey(32, 8));

            self::assertCount(8, $segments);
            self::assertSame(
                $segments,
                array_values(array_unique($segments)),
                'segments repeated inside one key: they are being cut from a shared hash, not drawn independently'
            );
        }
    }

    /**
     * The shared hash was 128 chars of whirlpool, so a key could never carry more than 128 distinct
     * characters' worth of material however many segments were asked for: at (16, 40) the old code
     * drew 40 segments from 113 offsets and duplicates were a certainty (pigeonhole gives at most
     * 113 distinct segments, and the birthday bound makes a repeat overwhelming long before that).
     * Independent draws have no such ceiling.
     */
    public function testGenerateUniqueKeyScalesBeyondTheOldHashLengthWithoutRepeating(): void {
        $segments = explode('-', Str::generateUniqueKey(16, 40));

        self::assertCount(40, $segments);
        self::assertCount(40, array_unique($segments), 'a 40-segment key must still have 40 distinct segments');
        foreach ($segments as $segment) {
            self::assertMatchesRegularExpression('/^[0-9a-f]{16}$/', $segment);
        }
    }

    /**
     * Odd $segmentLength exercises the hex truncation in randomHex(): ceil(n/2) bytes give one
     * character too many on odd lengths, and the surplus must be dropped, not leaked into the key.
     */
    public function testGenerateUniqueKeyHonoursOddSegmentLengths(): void {
        foreach ([1, 3, 7, 15, 127] as $length) {
            $segments = explode('-', Str::generateUniqueKey($length, 3));

            self::assertCount(3, $segments);
            foreach ($segments as $segment) {
                self::assertSame($length, strlen($segment), "segment length {$length} must be exact");
                self::assertMatchesRegularExpression('/^[0-9a-f]+$/', $segment);
            }
        }
    }

    /**
     * The keys themselves must not collide across calls. The old key's uniqueness rested entirely on
     * one hash of time + uniqid() + rand(); the new one rests on 100 bits of CSPRNG entropy at the
     * 5x5 default. 500 keys, no duplicates.
     */
    public function testGenerateUniqueKeyDoesNotCollideAcrossCalls(): void {
        $keys = [];
        for ($i = 0; $i < 500; $i++) {
            $keys[] = Str::generateUniqueKey();
        }

        self::assertCount(500, array_unique($keys));
    }

    /**
     * generateUniqueKey() used to be seeded by rand() through uniqid()'s prefix. Nothing in it may
     * depend on the seedable Mt19937 generator any more: re-seeding must not replay a key.
     */
    public function testGenerateUniqueKeyIgnoresTheMtRandSeed(): void {
        try {
            mt_srand(999);
            $first = Str::generateUniqueKey(16, 4);

            mt_srand(999);
            $second = Str::generateUniqueKey(16, 4);

            self::assertNotSame($first, $second, 'a seeded sequence replayed the key: generateUniqueKey is not CSPRNG-backed');
        } finally {
            mt_srand();
        }
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

    /**
     * BEHAVIOR CHANGE (low). generateGuid() used a three-way chain: com_create_guid() (Windows,
     * UPPERCASE), openssl, and a last-resort md5(uniqid(rand())) that was guessable and carried no
     * version/variant bits. It now always draws random_bytes() and emits a lowercase RFC 4122 v4
     * UUID. The /i-free regex pins the case; the fixed nibbles pin version 4 and variant 10xx on
     * every draw (a hash-shaped string would miss them 15/16 and 3/4 of the time respectively).
     */
    public function testGenerateGuidIsAlwaysALowercaseVersion4Rfc4122Uuid(): void {
        for ($i = 0; $i < 200; $i++) {
            self::assertMatchesRegularExpression(
                '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                Str::generateGuid()
            );
        }
    }

    /**
     * The seedable Mt19937 must play no part: re-seeding it must neither replay a GUID nor be
     * disturbed by one. (The removed fallback both drew from it and, before pass 3, reseeded it
     * from the clock.)
     */
    public function testGenerateGuidNeitherReadsNorDisturbsTheMtRandStream(): void {
        try {
            mt_srand(777);
            $firstGuid = Str::generateGuid();
            $first = mt_rand();

            mt_srand(777);
            $secondGuid = Str::generateGuid();
            $second = mt_rand();

            self::assertNotSame($firstGuid, $secondGuid, 'a seeded Mt19937 replayed the GUID');
            self::assertSame($first, $second, 'generateGuid() advanced or reseeded the global Mt19937');

            mt_srand(777);
            self::assertSame($first, mt_rand(), 'the stream must be exactly what the seed alone produces');
        } finally {
            mt_srand();
        }
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

    public function testRemoveExcessSpacesDoesNotTreatUnicodeSpacesAsWhitespace(): void {
        // Documented: ASCII whitespace only. U+00A0 survives, and so does every UTF-8 byte.
        self::assertSame("a\u{00A0}b", Str::removeExcessSpaces("a\u{00A0}b"));
        self::assertSame("à b", Str::removeExcessSpaces(" à \x0B\x0C b "));
    }

    /**
     * FINDING (high): the pattern used \s in byte mode, which PHP compiles against character
     * tables built for the CURRENT LC_CTYPE locale. Under a Latin-1-style locale \s also matches
     * byte 0xA0 - the second byte of "à" (C3 A0) - so removeExcessSpaces("à") returned the lone
     * byte C3: invalid UTF-8, produced by a method whose job is to tidy text. Brazilian apps set
     * such a locale routinely. The test runs only where the platform reproduces the hazard.
     */
    public function testRemoveExcessSpacesDoesNotSplitUtf8UnderALatin1Locale(): void {
        $previous = setlocale(LC_CTYPE, '0');
        try {
            if (setlocale(LC_CTYPE, 'Portuguese_Brazil.1252', 'pt_BR.ISO-8859-1', 'pt_BR.ISO8859-1', 'de_DE.ISO-8859-1', 'en_US.ISO-8859-1') === false) {
                self::markTestSkipped('No Latin-1 LC_CTYPE locale is installed.');
            }
            if (preg_match('/\s/', "\xA0") !== 1) {
                self::markTestSkipped('This platform\'s Latin-1 locale does not classify 0xA0 as a space.');
            }

            self::assertSame('à', Str::removeExcessSpaces('à'));
            self::assertSame('à b', Str::removeExcessSpaces(" à \t b "));
            self::assertSame('àb', Str::removeExcessSpaces('à b', false));
        } finally {
            setlocale(LC_CTYPE, $previous);
        }
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

    /**
     * FINDING (medium): every mb_* call inherited mb_internal_encoding(). With it set to
     * ISO-8859-1 (legacy apps, or a library that set it and never restored it), "Ç" (C3 87) was
     * lower-cased as the two Latin-1 characters "Ã‡" -> E3 87: invalid UTF-8. The class documents
     * UTF-8, so it now says so to mbstring on every call.
     */
    public function testMultibyteMethodsIgnoreMbInternalEncoding(): void {
        $previous = mb_internal_encoding();
        try {
            mb_internal_encoding('ISO-8859-1');

            self::assertSame('ç', Str::strToLower('Ç'));
            self::assertSame('AÇÃO', Str::strToUpper('ação'));
            self::assertSame(4, Str::strLen('ação'));
            self::assertSame('çã', Str::subStr('ação', 1, 2));
            self::assertSame(4, Str::strPos('ação!', '!'));
            self::assertSame(1, Str::strIPos('AÇÃO', 'ç'));
            self::assertSame('Ísis', Str::mbUcFirst('ísis'));
            self::assertSame([2], Str::findAllOccurrences('ação', 'ã'));
            self::assertSame(['çã'], Str::extractSubstringsBetween('açãoa', 'a', 'o'));
            self::assertSame('00ééé', explode('-', Str::generateUniqueKey(5, 1, '-', 'ééé'))[1]);
        } finally {
            mb_internal_encoding($previous);
        }
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

    public function testStrIPosFoldsUnicodeCase(): void {
        self::assertSame(1, Str::strIPos('AÇÃO', 'çã'));
        self::assertSame(1, Str::strIPos("x\u{212A}y", 'k'), 'KELVIN SIGN folds to k');
    }

    public function testStrPosAndStrIPosHonourPositiveAndNegativeOffsets(): void {
        self::assertSame(3, Str::strPos('abcabc', 'a', 1));
        self::assertSame(3, Str::strPos('abcabc', 'a', -3));
        self::assertFalse(Str::strPos('abcabc', 'c', 6), 'an offset equal to the length is valid and finds nothing');
        self::assertSame(3, Str::strIPos('ÁbcÁbc', 'á', 1));
    }

    public function testStrIPosThrowsValueErrorWhenTheOffsetIsOutsideTheString(): void {
        $this->expectException(\ValueError::class);
        Str::strIPos('abc', 'a', -4);
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

    /**
     * FINDING (medium): the walk read $input[$j] for j = 0..n-1, so any array that was not a
     * 0-based list - the direct output of array_unique() or array_filter() - raised
     * "Undefined array key" warnings and spliced nulls into the phrases (' a' came back).
     */
    public function testGetAdjacentCombinationsIgnoresKeysAndUsesIterationOrder(): void {
        $result = Str::getAdjacentCombinations([1 => 'a', 3 => 'b', 'x' => 'c']);

        self::assertSame(['a b c', 'a b', 'b c', 'a', 'b', 'c'], $result);
        self::assertSame($result, Str::getAdjacentCombinations(array_unique(['a', 'a', 'b', 'c'])));
    }

    public function testGetAdjacentCombinationsCastsScalarElementsToString(): void {
        // @return string[]: an int element used to come back as an int beside string phrases.
        self::assertSame(['1 2', '1', '2'], Str::getAdjacentCombinations([1, 2]));
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

    /**
     * FINDING (low): a null entry reached str_replace() unchanged, which PHP 8.1+ reports as a
     * deprecation on every call (and phpunit.xml's failOnDeprecation turns into a failure).
     */
    public function testRemoveSubstringsSkipsNullAndEmptyEntriesWithoutADeprecation(): void {
        self::assertSame('ac', Str::removeSubstrings('abc', [null, '', 'b']));
        self::assertSame('a3', Str::removeSubstrings('a123', [12]), 'scalars are cast to string');
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

    /**
     * FINDING (low): invalid UTF-8 in the character LIST is spliced into the /u pattern, and PCRE
     * reports a malformed pattern with an E_WARNING ("Compilation failed: UTF-8 error") BEFORE
     * the documented exception - under failOnWarning, or a framework promoting warnings, that was
     * a different failure than the one documented. The exception must now come alone.
     */
    public function testCharacterFiltersRejectAnInvalidUtf8ListWithoutAWarning(): void {
        foreach (['removeCharacters', 'keepOnlyCharacters'] as $method) {
            $warnings = [];
            set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
                $warnings[] = $message;
                return true;
            });
            try {
                Str::$method('abc', "a\xFF");
                self::fail("$method accepted an invalid UTF-8 list");
            } catch (\InvalidArgumentException) {
                // expected
            } finally {
                restore_error_handler();
            }

            self::assertSame([], $warnings, "$method raised a PHP diagnostic before throwing");
        }
    }

    public function testRemoveCharactersWorksOnCodePointsNotGraphemes(): void {
        // Documented: a decomposed accent is its own code point and survives removing the base.
        self::assertSame("\u{0301}", Str::removeCharacters("e\u{0301}", 'e'));
        self::assertSame('ab', Str::removeCharacters('a😀b', '😀'));
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
        // The old second assertion searched only the fixed prefix '<span class="tooltip_ativo"
        // title="', which can never contain the payload - it passed against unencoded output too.
        // Pin the whole string instead: exactly one attribute, and both quotes encoded.
        self::assertSame(
            '<span class="tooltip_ativo" title="cliente&quot; onmouseover=alert(1)">cliente&quot; onmouseover=alert(1)</span>',
            Str::truncateWithTooltip('cliente" onmouseover=alert(1)', 100)
        );
        self::assertSame(
            '<span class="tooltip_ativo" title="a&apos;b">a&apos;b</span>',
            Str::truncateWithTooltip("a'b", 100)
        );
    }

    /**
     * FINDING (medium): htmlspecialchars() without ENT_SUBSTITUTE returns "" for invalid UTF-8,
     * so ONE bad byte (a Latin-1 value from a legacy table) blanked the whole cell - the span
     * rendered with an empty title and an empty body, and nothing reported it.
     */
    public function testTruncateWithTooltipRendersInvalidUtf8AsReplacementCharactersInsteadOfBlanking(): void {
        self::assertSame(
            "<span class=\"tooltip_ativo\" title=\"abc\u{FFFD}def\">abc\u{FFFD}...</span>",
            Str::truncateWithTooltip("abc\xFFdef", 4)
        );
        self::assertSame(
            "<span class=\"tooltip_ativo\" title=\"S\u{FFFD}o\">S\u{FFFD}o</span>",
            Str::truncateWithTooltip("S\xE3o", 100),
            'a Latin-1 "São" must stay readable'
        );
    }

    public function testTruncateWithTooltipDoesNotStripTheAccentOffTheLastKeptLetter(): void {
        // Decomposed é = e + U+0301. Cutting between them used to render "cafe...".
        self::assertSame(
            "<span class=\"tooltip_ativo\" title=\"cafe\u{0301} au lait\">cafe\u{0301}...</span>",
            Str::truncateWithTooltip("cafe\u{0301} au lait", 4)
        );
    }

    public function testTruncateWithTooltipCountsEastAsianWideCharactersAsTwoColumns(): void {
        self::assertSame(
            '<span class="tooltip_ativo" title="日本語">日...</span>',
            Str::truncateWithTooltip('日本語', 3)
        );
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
        // The escape is decoded first (to é), and then dropped like any other accented letter.
        self::assertSame('cafBar', Str::toCamelCase('café bar'));
    }

    /**
     * FINDING (low): a multibyte preserve character was spliced into a BYTE-mode class, where "ç"
     * (C3 A7) means "byte C3 or byte A7". Every character sharing a lead byte kept half of itself:
     * toCamelCase('ação x', ['ç']) returned "aç\xC3OX" - invalid UTF-8.
     */
    public function testToCamelCasePreservesAMultibyteCharacterWithoutSplittingOthers(): void {
        $result = Str::toCamelCase('ação x_y', ['ç', '_']);

        self::assertSame('açOX_y', $result);
        self::assertTrue(mb_check_encoding($result, 'UTF-8'));
    }

    public function testToCamelCaseRejectsInvalidUtf8OnlyWhenAMultibytePreserveCharacterNeedsUtf8Mode(): void {
        // Byte mode (the default) cannot fail: invalid bytes are just non-word characters.
        self::assertSame('aB', Str::toCamelCase("a\xFF b"));

        $this->expectException(\InvalidArgumentException::class);
        Str::toCamelCase("a\xFF b", ['ç']);
    }

    public function testToCamelCaseOnlyTouchesTheFirstLetterOfEachWord(): void {
        // Documented: the rest of each word keeps its case.
        self::assertSame('hELLOWORLD', Str::toCamelCase('HELLO WORLD'));
        self::assertSame('fooBarBaz', Str::toCamelCase('fooBar baz'));
        self::assertSame('a1B2', Str::toCamelCase('a1 b2'));
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
        $start = microtime(true);
        Str::flushOutput('x', -5); // sleep(-5) would be a ValueError; the clamp keeps it at 0
        $elapsed = microtime(true) - $start;
        ob_end_clean();
        $flushed = (string) ob_get_clean();

        self::assertSame('x' . str_pad('', 4096) . "\n", $flushed);
        self::assertLessThan(1.0, $elapsed, 'a negative sleep must be clamped to 0, not to anything positive');
    }

    /**
     * The branch where NO buffer is active - flushOutput() opens its own and must close it again -
     * can never run inside PHPUnit, which always holds an output buffer. So it runs in a child PHP
     * process with output buffering off.
     */
    public function testFlushOutputOpensAndClosesItsOwnBufferWhenNoneIsActive(): void {
        if (!function_exists('proc_open')) {
            self::markTestSkipped('proc_open() is disabled, cannot spawn the child PHP process.');
        }

        $script = tempnam(sys_get_temp_dir(), 'strflush');
        self::assertIsString($script);
        file_put_contents($script, '<?php require ' . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ';'
            . '$before = ob_get_level();'
            . 'VD\PHPHelper\Str::flushOutput("hello");'
            . 'fwrite(STDERR, "levels:" . $before . ":" . ob_get_level());');

        try {
            $process = proc_open(
                [PHP_BINARY, '-d', 'output_buffering=0', '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', $script],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);
        } finally {
            @unlink($script);
        }

        self::assertSame(0, $exitCode, (string) $stderr);
        self::assertSame('levels:0:0', $stderr, 'the buffer flushOutput() opened must be closed again');
        self::assertSame('hello' . str_pad('', 4096) . "\n", $stdout);
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

    /**
     * BEHAVIOR CHANGE / FINDING (medium). This test used to PIN the defect: with \b, a word that
     * ends in a non-word character had its semantics inverted - 'c++' was NOT found standing
     * alone in 'a c++ b', but WAS "found" glued to a letter in 'c++b'. Lookarounds ask the actual
     * question: no word character on either side.
     */
    public function testContainsExactWordHandlesWordsThatStartOrEndWithANonWordCharacter(): void {
        self::assertTrue(Str::containsExactWord('a c++ b', 'c++'));
        self::assertTrue(Str::containsExactWord('c++', 'c++'));
        self::assertFalse(Str::containsExactWord('c++b', 'c++'));
        self::assertTrue(Str::containsExactWord('pay $5 now', '$5'));
        self::assertFalse(Str::containsExactWord('x$5', '$5'));
        self::assertTrue(Str::containsExactWord('see .NET, not NET', '.NET'));
    }

    /**
     * FINDING (medium): in byte mode only [A-Za-z0-9_] were word characters, so every accented
     * letter counted as a boundary: containsExactWord('ação', 'a') and ('pão', 'p') were TRUE.
     * In Portuguese text that made the method close to useless as a word gate.
     */
    public function testContainsExactWordTreatsAccentedLettersAsPartOfTheWord(): void {
        self::assertFalse(Str::containsExactWord('ação', 'a'));
        self::assertFalse(Str::containsExactWord('pão', 'p'));
        self::assertFalse(Str::containsExactWord('coração', 'cora'));
        self::assertTrue(Str::containsExactWord('a ação foi boa', 'ação'));
        self::assertTrue(Str::containsExactWord('(ação)', 'ação'));
        // Decomposed accents are combining marks, which also continue a word.
        self::assertFalse(Str::containsExactWord("cafe\u{0301}", 'cafe'));
        self::assertTrue(Str::containsExactWord('snake_case x', 'snake_case'));
        self::assertFalse(Str::containsExactWord('snake_case x', 'snake'), 'underscore is a word character');
    }

    public function testContainsExactWordFoldsUnicodeCaseWhenCaseInsensitive(): void {
        self::assertTrue(Str::containsExactWord('É AÇÃO!', 'ação', false));
        self::assertFalse(Str::containsExactWord('É AÇÃO!', 'ação'));
    }

    public function testContainsExactWordRejectsInvalidUtf8RatherThanAnsweringFalse(): void {
        // A gate that answered "not found" for a malformed input could be bypassed by one byte.
        $this->expectException(\InvalidArgumentException::class);
        Str::containsExactWord("blocked\xFF", 'blocked');
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
        self::assertSame('ǎ', Str::removeAccents('ǎ'), 'Latin Extended-B is out of scope');
        self::assertSame('2×3', Str::removeAccents('2×3'), 'Latin-1 symbols are not letters');
    }

    /**
     * FINDING (low): the table listed the "ö" key twice where "ø" belonged (a typo inherited from
     * the original snippet), so ø/Ø were never folded; ŉ/Ŋ/ŋ had their cases inverted; and Æ, Ð,
     * Þ (with lowercase forms) were missing from a method documented to cover Latin-1.
     */
    public function testRemoveAccentsFoldsTheLettersTheTableUsedToMissOrMiscase(): void {
        self::assertSame('oO', Str::removeAccents('øØ'));
        self::assertSame('nNn', Str::removeAccents('ŉŊŋ'));
        self::assertSame('AEaeDdTHth', Str::removeAccents('ÆæÐðÞþ'));
        self::assertSame('Soren AErosmith', Str::removeAccents('Søren Ærosmith'), 'Æ -> AE, like the existing Œ -> OE');
    }

    /**
     * FINDING (low): decomposed (NFD) text - what macOS hands over for file names - kept every
     * accent, because only precomposed code points were in the table.
     */
    public function testRemoveAccentsStripsCombiningDiacritics(): void {
        self::assertSame('Cafe', Str::removeAccents("Cafe\u{0301}"));
        self::assertSame('Acao', Str::removeAccents("Ac\u{0327}a\u{0303}o"));
        // Byte-matched, so invalid UTF-8 elsewhere cannot make the call fail.
        self::assertSame("\xFFa", Str::removeAccents("\xFFa\u{0300}"));
    }

    /**
     * Table integrity, checked against Unicode itself: every letter of U+00C0-U+017F must fold to
     * pure ASCII, to its canonical base letter where it has a decomposition, and must keep its
     * case. Each of the three table defects fixed above fails this independently.
     */
    public function testRemoveAccentsFoldsEveryLatin1AndLatinExtendedALetterToCaseCorrectAscii(): void {
        $hasNormalizer = class_exists(\Normalizer::class);

        for ($codePoint = 0xC0; $codePoint <= 0x17F; $codePoint++) {
            if ($codePoint === 0xD7 || $codePoint === 0xF7) {
                continue; // × and ÷ are not letters
            }
            $char = mb_chr($codePoint, 'UTF-8');
            $folded = Str::removeAccents($char);
            $label = sprintf('U+%04X %s -> %s', $codePoint, $char, $folded);

            self::assertMatchesRegularExpression('/^[A-Za-z]{1,2}$/', $folded, $label);

            $isLower = mb_strtolower($char, 'UTF-8') === $char && mb_strtoupper($char, 'UTF-8') !== $char;
            $isUpper = mb_strtoupper($char, 'UTF-8') === $char && mb_strtolower($char, 'UTF-8') !== $char;
            if ($isLower) {
                self::assertSame(strtolower($folded), $folded, "$label: a lowercase letter must fold to lowercase");
            } elseif ($isUpper) {
                self::assertSame(strtoupper($folded[0]), $folded[0], "$label: an uppercase letter must fold to uppercase");
            }

            if ($hasNormalizer) {
                $base = (string) \Normalizer::normalize($char, \Normalizer::FORM_D);
                if (strlen($base) > 0 && ord($base[0]) < 0x80 && $base !== $char) {
                    self::assertSame($base[0], $folded, "$label: must equal its canonical base letter");
                }
            }
        }
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

    /**
     * API CHANGE: $offset is now a real, documented start offset (it used to be an "internal,
     * leave at 0" recursion cursor), and the by-reference $results accumulator is gone.
     */
    public function testFindAllOccurrencesStartsAtTheGivenCharacterOffset(): void {
        self::assertSame([3], Str::findAllOccurrences('ábcábc', 'á', false, true, 1));
        self::assertSame([3], Str::findAllOccurrences('ábcábc', 'á', false, true, -3));
        self::assertSame([4], Str::findAllOccurrences('ÁbcÁbc', 'á', true, false, 2));
        self::assertSame([], Str::findAllOccurrences('abc', 'a', false, true, 3));
    }

    public function testFindAllOccurrencesRejectsAnOffsetOutsideTheHaystack(): void {
        $this->expectException(\ValueError::class);
        Str::findAllOccurrences('abc', 'a', false, true, 4);
    }

    public function testFindAllOccurrencesFoldsUnicodeCaseLikeStrIPos(): void {
        // U+212A KELVIN SIGN is 3 bytes and folds to the 1-byte "k": positions must still be
        // CHARACTER positions in the original string.
        self::assertSame([1, 3], Str::findAllOccurrences("x\u{212A}yk", 'K', false, false));
        self::assertSame([2, 4], Str::findAllOccurrences("x\u{212A}yk", 'K', true, false));
        self::assertSame([0, 4], Str::findAllOccurrences('AÇÃOação', 'açã', false, false));
    }

    /**
     * Differential test: the linear rewrite must agree with the obvious mb_strpos()/mb_stripos()
     * loop - the old algorithm, minus its recursion - on random multibyte haystacks, including
     * characters whose folded form has a different byte length.
     */
    public function testFindAllOccurrencesAgreesWithAReferenceImplementationOnRandomInput(): void {
        $alphabet = ['a', 'A', 'b', 'á', 'Á', 'ç', 'Ç', "\u{212A}", 'k', '😀', ' '];

        for ($round = 0; $round < 400; $round++) {
            $haystack = self::randomString($alphabet, random_int(0, 30));
            $needle = self::randomString($alphabet, random_int(1, 3));
            $caseSensitive = (bool) random_int(0, 1);
            $returnEnd = (bool) random_int(0, 1);
            $offset = random_int(0, mb_strlen($haystack, 'UTF-8'));

            $expected = [];
            $position = $offset;
            while (($found = $caseSensitive
                    ? mb_strpos($haystack, $needle, $position, 'UTF-8')
                    : mb_stripos($haystack, $needle, $position, 'UTF-8')) !== false) {
                $length = mb_strlen($needle, 'UTF-8');
                $expected[] = $returnEnd ? $found + $length : $found;
                $position = $found + $length;
            }
            if ($haystack === '') {
                $expected = [];
            }

            self::assertSame(
                $expected,
                Str::findAllOccurrences($haystack, $needle, $returnEnd, $caseSensitive, $offset),
                sprintf('haystack=%s needle=%s cs=%d end=%d offset=%d', json_encode($haystack), json_encode($needle), $caseSensitive, $returnEnd, $offset)
            );
        }
    }

    /**
     * FINDING (medium): the method recursed once per match AND re-scanned from the start of the
     * haystack on every call (a character offset makes mb_strpos() walk from byte 0), so it was
     * quadratic with one stack frame per match - measured at ~2 s for 40 000 matches and minutes
     * for 2 000 000. 200 000 matches now take a few milliseconds; the old code needed ~50 s.
     */
    public function testFindAllOccurrencesIsLinearAndIterative(): void {
        $start = microtime(true);
        $positions = Str::findAllOccurrences(str_repeat('á', 200000), 'á');
        $caseless = Str::findAllOccurrences(str_repeat('Á', 200000), 'á', false, false);
        $elapsed = microtime(true) - $start;

        self::assertCount(200000, $positions);
        self::assertSame(199999, $positions[199999]);
        self::assertCount(200000, $caseless);
        self::assertLessThan(5.0, $elapsed, 'findAllOccurrences() is no longer linear');
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

    public function testExtractSubstringsBetweenUsesTheSameDelimiterForBothEnds(): void {
        self::assertSame(['b', 'd'], Str::extractSubstringsBetween('a"b"c"d"', '"', '"'));
        self::assertSame(['x'], Str::extractSubstringsBetween('{{x}}{{', '{{', '}}'));
    }

    /**
     * Case-insensitive extraction must return the ORIGINAL text, even when folding changes byte
     * lengths (U+212A KELVIN SIGN, 3 bytes, folds to the 1-byte "k") ahead of the region.
     */
    public function testExtractSubstringsBetweenCaseInsensitiveKeepsTheOriginalText(): void {
        self::assertSame(
            ['áé', 'Ç'],
            Str::extractSubstringsBetween("\u{212A}áé\u{212A}xx[kÇk]", 'k', 'K', false)
        );
        self::assertSame(['Ação'], Str::extractSubstringsBetween('<B>Ação</b>', '<b>', '</B>', false));
    }

    /**
     * Differential test against the old mb_strpos()-based loop on random multibyte input.
     */
    public function testExtractSubstringsBetweenAgreesWithAReferenceImplementationOnRandomInput(): void {
        $alphabet = ['a', 'A', '[', ']', 'á', 'Á', "\u{212A}", 'k', 'K', '😀'];

        for ($round = 0; $round < 400; $round++) {
            $input = self::randomString($alphabet, random_int(1, 30));
            $start = self::randomString($alphabet, random_int(1, 2));
            $end = self::randomString($alphabet, random_int(1, 2));
            $caseSensitive = (bool) random_int(0, 1);

            $find = static fn (string $needle, int $from) => $caseSensitive
                ? mb_strpos($input, $needle, $from, 'UTF-8')
                : mb_stripos($input, $needle, $from, 'UTF-8');
            $expected = [];
            $offset = 0;
            while (($startPos = $find($start, $offset)) !== false) {
                $startPos += mb_strlen($start, 'UTF-8');
                $endPos = $find($end, $startPos);
                if ($endPos === false) {
                    break;
                }
                $expected[] = mb_substr($input, $startPos, $endPos - $startPos, 'UTF-8');
                $offset = $endPos + mb_strlen($end, 'UTF-8');
            }

            self::assertSame(
                $expected,
                Str::extractSubstringsBetween($input, $start, $end, $caseSensitive),
                sprintf('input=%s start=%s end=%s cs=%d', json_encode($input), json_encode($start), json_encode($end), $caseSensitive)
            );
        }
    }

    /**
     * FINDING (low): quadratic for the same reason as findAllOccurrences() (~3.5 s for 20 000
     * pairs). 100 000 pairs, case-insensitive, now take well under a second.
     */
    public function testExtractSubstringsBetweenIsLinear(): void {
        $start = microtime(true);
        $regions = Str::extractSubstringsBetween(str_repeat('[á]', 100000), '[', ']', false);
        $elapsed = microtime(true) - $start;

        self::assertCount(100000, $regions);
        self::assertSame(['á'], array_values(array_unique($regions)));
        self::assertLessThan(5.0, $elapsed, 'extractSubstringsBetween() is no longer linear');
    }

    /**
     * @param string[] $alphabet
     */
    private static function randomString(array $alphabet, int $length): string {
        $out = '';
        for ($i = 0; $i < $length; $i++) {
            $out .= $alphabet[random_int(0, count($alphabet) - 1)];
        }

        return $out;
    }
}
