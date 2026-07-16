<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\DateTime;

/**
 * Contract tests for VD\PHPHelper\DateTime.
 *
 * Determinism rules followed here:
 *  - No assertion is made against a hardcoded "today". Anything clock-dependent is either
 *    asserted by shape/invariant, or cross-checked against PHP's own \DateTimeImmutable so the
 *    expectation tracks the clock instead of rotting.
 *  - Fixed dates (1999, 2024) are used everywhere the method allows it.
 *  - The class holds private static state ($defaultTimezone/$defaultFormat) and PHP holds a
 *    global default timezone; both are saved in setUp() and restored in tearDown() so tests
 *    cannot leak into each other or into other test classes in the same process.
 */
final class DateTimeTest extends TestCase {
    private ?string $savedTimezone = null;
    private ?string $savedFormat = null;
    private string $savedIniTimezone = 'UTC';

    protected function setUp(): void {
        $this->savedTimezone = self::readStatic('defaultTimezone');
        $this->savedFormat = self::readStatic('defaultFormat');
        $this->savedIniTimezone = date_default_timezone_get();

        // A known-good, DST-free baseline. Every test starts from the class's own fallbacks.
        date_default_timezone_set('UTC');
        self::writeStatic('defaultTimezone', null);
        self::writeStatic('defaultFormat', null);
    }

    protected function tearDown(): void {
        self::writeStatic('defaultTimezone', $this->savedTimezone);
        self::writeStatic('defaultFormat', $this->savedFormat);
        date_default_timezone_set($this->savedIniTimezone);
    }

    private static function readStatic(string $name): ?string {
        $property = new \ReflectionProperty(DateTime::class, $name);
        return $property->getValue();
    }

    private static function writeStatic(string $name, ?string $value): void {
        $property = new \ReflectionProperty(DateTime::class, $name);
        $property->setValue(null, $value);
    }

    private static function docblockOf(string $method): string {
        $doc = (new \ReflectionMethod(DateTime::class, $method))->getDocComment();
        self::assertIsString($doc, "Method {$method}() must carry a docblock stating its contract.");
        return $doc;
    }

    /**
     * Finds a timezone identifier whose CURRENT local hour is $hour. Offsets span UTC-12..UTC+14,
     * so every hour of the day is reachable from any instant; this lets the clock-reading methods
     * be driven to a chosen hour without a clock abstraction.
     */
    private static function timezoneWithCurrentHour(int $hour): string {
        foreach (\DateTimeZone::listIdentifiers() as $identifier) {
            $now = new \DateTimeImmutable('now', new \DateTimeZone($identifier));
            if ((int) $now->format('H') === $hour) {
                return $identifier;
            }
        }

        self::fail("No timezone currently sits at hour {$hour}.");
    }

    // ---------------------------------------------------------------- timezone defaults

    public function testGetDefaultTimezoneFallsBackToPhpDefaultWhenUnset(): void {
        $this->assertSame('UTC', DateTime::getDefaultTimezone());
    }

    public function testSetDefaultTimezoneAcceptsValidIdentifier(): void {
        DateTime::setDefaultTimezone('America/Sao_Paulo');
        $this->assertSame('America/Sao_Paulo', DateTime::getDefaultTimezone());
    }

    public function testSetDefaultTimezoneSilentlyIgnoresInvalidIdentifier(): void {
        DateTime::setDefaultTimezone('America/Sao_Paulo');
        DateTime::setDefaultTimezone('Not/AZone');

        // Documented contract: "sets ... if it is valid" — an invalid value is a no-op, not a throw
        // and not a reset.
        $this->assertSame('America/Sao_Paulo', DateTime::getDefaultTimezone());
    }

    public function testIsValidTimezoneAcceptsRealZonesAndRejectsGarbage(): void {
        $this->assertTrue(DateTime::isValidTimezone('America/Sao_Paulo'));
        $this->assertTrue(DateTime::isValidTimezone('UTC'));
        $this->assertFalse(DateTime::isValidTimezone('Not/AZone'));
        $this->assertFalse(DateTime::isValidTimezone(''));
    }

    // ---------------------------------------------------------------- format defaults

    public function testGetDefaultFormatFallsBackToYmd(): void {
        $this->assertSame('Y-m-d', DateTime::getDefaultFormat());
    }

    public function testSetDefaultFormatAcceptsFormatAndIgnoresEmptyString(): void {
        DateTime::setDefaultFormat('d/m/Y');
        $this->assertSame('d/m/Y', DateTime::getDefaultFormat());

        DateTime::setDefaultFormat('');
        $this->assertSame('d/m/Y', DateTime::getDefaultFormat());
    }

    // ---------------------------------------------------------------- validateDate

    public function testValidateDateAcceptsMatchingDate(): void {
        $this->assertTrue(DateTime::validateDate('2024-02-29', 'Y-m-d'));
        $this->assertTrue(DateTime::validateDate('31/01/2024', 'd/m/Y'));
    }

    public function testValidateDateRejectsRolloverAndMismatchAndEmpty(): void {
        // 2023 is not a leap year: createFromFormat would roll this to 2023-03-01.
        $this->assertFalse(DateTime::validateDate('2023-02-29', 'Y-m-d'));
        $this->assertFalse(DateTime::validateDate('2024-01-31', 'd/m/Y'));
        $this->assertFalse(DateTime::validateDate('', 'Y-m-d'));
        $this->assertFalse(DateTime::validateDate(null, 'Y-m-d'));
    }

    public function testValidateDateUsesClassDefaultFormatWhenFormatIsNull(): void {
        DateTime::setDefaultFormat('d/m/Y');
        $this->assertTrue(DateTime::validateDate('31/01/2024'));
        $this->assertFalse(DateTime::validateDate('2024-01-31'));
    }

    // ---------------------------------------------------------------- getCurrentFormattedDate

    public function testGetCurrentFormattedDateReturnsShapeOfRequestedFormat(): void {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/',
            (string) DateTime::getCurrentFormattedDate('Y-m-d H:i:s', 'UTC')
        );
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', (string) DateTime::getCurrentFormattedDate());
    }

    public function testGetCurrentFormattedDateHonoursRequestedTimezone(): void {
        // Kiritimati (+14) and Midway (-11) are 25h apart, so their calendar dates can never
        // coincide at any instant — a real proof that the timezone argument is applied.
        $this->assertNotSame(
            DateTime::getCurrentFormattedDate('Y-m-d', 'Pacific/Kiritimati'),
            DateTime::getCurrentFormattedDate('Y-m-d', 'Pacific/Midway')
        );
    }

    public function testGetCurrentFormattedDateReturnsNullForInvalidTimezone(): void {
        $this->assertNull(DateTime::getCurrentFormattedDate('Y-m-d', 'Not/AZone'));
    }

    // ---------------------------------------------------------------- convertTimestampToDate

    public function testConvertTimestampToDateFormatsTimestamp(): void {
        $this->assertSame('2023-11-14', DateTime::convertTimestampToDate(1700000000, 'Y-m-d'));
        $this->assertSame('2023-11-14 22:13:20', DateTime::convertTimestampToDate(1700000000, 'Y-m-d H:i:s'));
    }

    public function testConvertTimestampToDateReturnsNullForNullAndForTheEpochItself(): void {
        $this->assertNull(DateTime::convertTimestampToDate(null, 'Y-m-d'));

        // Documented quirk: the guard is a falsy check, so timestamp 0 is unreachable.
        $this->assertNull(DateTime::convertTimestampToDate(0, 'Y-m-d'));
    }

    // ---------------------------------------------------------------- getWeekDay / getMonth

    public function testGetWeekDayIsOneBasedStartingOnSunday(): void {
        $this->assertSame(1, DateTime::getWeekDay('2024-01-07', 'Y-m-d')); // Sunday
        $this->assertSame(2, DateTime::getWeekDay('2024-01-08', 'Y-m-d')); // Monday
        $this->assertSame(7, DateTime::getWeekDay('2024-01-13', 'Y-m-d')); // Saturday
    }

    public function testGetWeekDayReturnsNullForDateNotMatchingFormat(): void {
        $this->assertNull(DateTime::getWeekDay('07/01/2024', 'Y-m-d'));
        $this->assertNull(DateTime::getWeekDay('nonsense', 'Y-m-d'));
    }

    public function testGetMonthReturnsMonthNumber(): void {
        $this->assertSame(3, DateTime::getMonth('2024-03-05', 'Y-m-d'));
        $this->assertSame(12, DateTime::getMonth('31/12/2024', 'd/m/Y'));
    }

    public function testGetMonthReturnsNullForDateNotMatchingFormat(): void {
        $this->assertNull(DateTime::getMonth('2024-13-05', 'Y-m-d'));
    }

    // ---------------------------------------------------------------- convertDateToFormat

    public function testConvertDateToFormatConvertsBetweenFormats(): void {
        $this->assertSame('2024-01-31', DateTime::convertDateToFormat('31/01/2024', 'Y-m-d', 'd/m/Y'));
        $this->assertSame('31/01/2024', DateTime::convertDateToFormat('2024-01-31', 'd/m/Y', 'Y-m-d'));
    }

    public function testConvertDateToFormatReturnsEmptyStringOnInvalidInput(): void {
        $this->assertSame('', DateTime::convertDateToFormat('2024-01-31', 'Y-m-d', 'd/m/Y'));
        $this->assertSame('', DateTime::convertDateToFormat('2024-01-31', '', 'Y-m-d'));
    }

    // ---------------------------------------------------------------- toDate

    public function testToDateBuildsDateTimeObject(): void {
        $date = DateTime::toDate('2024-01-31', 'Y-m-d');

        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertSame('2024-01-31', $date->format('Y-m-d'));
    }

    public function testToDateReturnsNullWhenDateDoesNotMatchFormat(): void {
        $this->assertNull(DateTime::toDate('31/01/2024', 'Y-m-d'));
    }

    // ---------------------------------------------------------------- dateDiffDays

    public function testDateDiffDaysCountsWholeDaysAndIsAbsolute(): void {
        $this->assertSame(30, DateTime::dateDiffDays('2024-01-01', '2024-01-31', 'Y-m-d', 'Y-m-d'));
        $this->assertSame(30, DateTime::dateDiffDays('2024-01-31', '2024-01-01', 'Y-m-d', 'Y-m-d'));
        $this->assertSame(0, DateTime::dateDiffDays('2024-01-01', '2024-01-01', 'Y-m-d', 'Y-m-d'));
        // 2024 is a leap year: February has 29 days.
        $this->assertSame(29, DateTime::dateDiffDays('2024-02-01', '2024-03-01', 'Y-m-d', 'Y-m-d'));
    }

    public function testDateDiffDaysAcceptsDifferentFormatPerDate(): void {
        $this->assertSame(30, DateTime::dateDiffDays('01/01/2024', '2024-01-31', 'd/m/Y', 'Y-m-d'));
    }

    public function testDateDiffDaysReturnsFalseOnInvalidDate(): void {
        $this->assertFalse(DateTime::dateDiffDays('nope', '2024-01-31', 'Y-m-d', 'Y-m-d'));
        $this->assertFalse(DateTime::dateDiffDays('2024-01-01', 'nope', 'Y-m-d', 'Y-m-d'));
    }

    // ---------------------------------------------------------------- dateFullTextPtBr

    public function testDateFullTextPtBrRendersFullFormForDistantYear(): void {
        // A date whose wording cannot depend on "now": the year is neither this one nor the last.
        $this->assertSame(
            'Quinta-feira, 15 de julho de 1999',
            DateTime::dateFullTextPtBr('1999-07-15', 'Y-m-d')
        );
    }

    public function testDateFullTextPtBrCanSkipCapitalization(): void {
        $this->assertSame(
            'quinta-feira, 15 de julho de 1999',
            DateTime::dateFullTextPtBr('1999-07-15', 'Y-m-d', false)
        );
    }

    public function testDateFullTextPtBrAppendsTimeSuffixOnlyWhenFormatCarriesTime(): void {
        $this->assertSame(
            'Quinta-feira, 15 de julho de 1999, às 14h e 30min',
            DateTime::dateFullTextPtBr('15/07/1999 14:30', 'd/m/Y H:i')
        );
        $this->assertStringNotContainsString('às', (string) DateTime::dateFullTextPtBr('1999-07-15', 'Y-m-d'));
    }

    public function testDateFullTextPtBrReturnsNullWhenDateDoesNotMatchFormat(): void {
        $this->assertNull(DateTime::dateFullTextPtBr('nope', 'Y-m-d'));
        $this->assertNull(DateTime::dateFullTextPtBr('15/07/1999', 'Y-m-d'));
    }

    /**
     * The relative-wording branches are the reason this method exists, and they all read "now".
     * Driving them through a timezone that currently sits at ~noon puts the calendar-day boundary
     * ~12h away in both directions, so these assertions cannot race midnight.
     */
    #[DataProvider('relativeWordingProvider')]
    public function testDateFullTextPtBrRendersRelativeWording(string $intervalSpec, bool $isAddition, string $expectedPrefix): void {
        $noonZone = self::timezoneWithCurrentHour(12);
        $now = (string) DateTime::getCurrentFormattedDate('Y-m-d H:i:s', $noonZone);

        $date = $intervalSpec === ''
            ? $now
            : (string) DateTime::applyInterval($intervalSpec, $now, $isAddition, 'Y-m-d H:i:s', 'Y-m-d H:i:s');

        $this->assertStringStartsWith(
            $expectedPrefix,
            (string) DateTime::dateFullTextPtBr($date, 'Y-m-d H:i:s', true, $noonZone)
        );
    }

    public static function relativeWordingProvider(): array {
        return [
            'today' => ['', true, 'Hoje, '],
            'yesterday' => ['P1D', false, 'Ontem, '],
            'tomorrow' => ['P1D', true, 'Amanhã, '],
            'day before yesterday' => ['P2D', false, 'Anteontem, '],
        ];
    }

    public function testDateFullTextPtBrRendersOtherDayOfCurrentMonth(): void {
        $noonZone = self::timezoneWithCurrentHour(12);
        $now = (string) DateTime::getCurrentFormattedDate('Y-m-d H:i:s', $noonZone);
        $today = (int) substr($now, 8, 2);

        // Stay inside the current month but at least 3 days away, so the ontem/amanhã/anteontem
        // branches (which win first) cannot claim it. Every month has at least 28 days.
        $day = $today <= 25 ? $today + 3 : $today - 3;
        $date = substr($now, 0, 8) . str_pad((string) $day, 2, '0', STR_PAD_LEFT) . substr($now, 10);

        $this->assertStringContainsString(
            'deste mês',
            (string) DateTime::dateFullTextPtBr($date, 'Y-m-d H:i:s', true, $noonZone)
        );
    }

    public function testDateFullTextPtBrRendersLastYear(): void {
        $noonZone = self::timezoneWithCurrentHour(12);
        $lastYear = ((int) DateTime::getCurrentFormattedDate('Y', $noonZone)) - 1;

        $this->assertStringEndsWith(
            'do ano passado',
            (string) DateTime::dateFullTextPtBr("{$lastYear}-07-15", 'Y-m-d', true, $noonZone)
        );
    }

    /**
     * Pins audit finding 6: the docblock used to promise "default: America/Sao_Paulo" while the
     * code resolves the class default timezone (PHP's, i.e. UTC on most servers). A pt-BR caller
     * who trusted that line got "now" up to 3h off and the wrong relative wording.
     */
    public function testDateFullTextPtBrDocDoesNotClaimAHardcodedBrazilianTimezoneDefault(): void {
        $doc = self::docblockOf('dateFullTextPtBr');

        $this->assertDoesNotMatchRegularExpression(
            '#default:\s*America/Sao_Paulo#i',
            $doc,
            'The docblock must not claim a America/Sao_Paulo default that the code never applies.'
        );
        $this->assertMatchesRegularExpression(
            '/class default/i',
            $doc,
            'The docblock must state that a null timezone resolves to the class default.'
        );

        // And the behaviour the doc now describes: null follows the class default.
        DateTime::setDefaultTimezone('Pacific/Kiritimati');
        $this->assertSame('Pacific/Kiritimati', DateTime::getDefaultTimezone());
    }

    // ---------------------------------------------------------------- applyInterval

    public function testApplyIntervalAddsAndSubtracts(): void {
        $this->assertSame('2024-01-04', DateTime::applyInterval('P3D', '2024-01-01', true, 'Y-m-d', 'Y-m-d'));
        $this->assertSame('2023-12-29', DateTime::applyInterval('P3D', '2024-01-01', false, 'Y-m-d', 'Y-m-d'));
        $this->assertSame(
            '2024-01-01 02:30:00',
            DateTime::applyInterval('PT2H30M', '2024-01-01 00:00:00', true, 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
    }

    public function testApplyIntervalConvertsOutputFormat(): void {
        $this->assertSame('04/01/2024', DateTime::applyInterval('P3D', '01/01/2024', true, 'd/m/Y', 'd/m/Y'));
        $this->assertSame('04/01/2024', DateTime::applyInterval('P3D', '2024-01-01', true, 'Y-m-d', 'd/m/Y'));
    }

    public function testApplyIntervalUsesCurrentDateWhenBaseDateIsNull(): void {
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}$/',
            (string) DateTime::applyInterval('P1D', null, true, 'Y-m-d', 'Y-m-d', 'UTC')
        );
    }

    public function testApplyIntervalReturnsNullOnInvalidInput(): void {
        // Documented: "or null on failure" — never an exception escaping to the caller.
        $this->assertNull(DateTime::applyInterval('', '2024-01-01', true, 'Y-m-d', 'Y-m-d'));
        $this->assertNull(DateTime::applyInterval('GARBAGE', '2024-01-01', true, 'Y-m-d', 'Y-m-d'));
        $this->assertNull(DateTime::applyInterval('P3D', '31/01/2024', true, 'Y-m-d', 'Y-m-d'));
    }

    // ---------------------------------------------------------------- getDateRangeList

    /**
     * Pins audit finding 3: the internal toDate() calls omitted their format and fell back to the
     * class default ('Y-m-d'), while being handed a 'Y-m-d H:i:s' string built on the same line.
     * toDate() returned null and the loop dereferenced it, so this documented-@return-array call
     * used to die with `Error: Call to a member function format() on null` unless the consumer had
     * happened to call setDefaultFormat('Y-m-d H:i:s') first.
     */
    public function testGetDateRangeListReturnsArrayUnderTheDefaultClassFormat(): void {
        $this->assertSame(
            ['2024-01-01', '2024-01-02', '2024-01-03', '2024-01-04', '2024-01-05'],
            DateTime::getDateRangeList('2024-01-01', '2024-01-05', 'Y-m-d', 'Y-m-d', 'P1D', 'Y-m-d')
        );
    }

    public function testGetDateRangeListWorksWithNullFormatsFallingBackToClassDefault(): void {
        $this->assertSame(
            ['2024-01-01', '2024-01-02', '2024-01-03'],
            DateTime::getDateRangeList('2024-01-01', '2024-01-03')
        );
    }

    /**
     * Pins audit finding 8: reversed dates were swapped but their formats were not, so the
     * swapped-in date was parsed with the other date's format. It failed to parse, toDate()
     * returned null, and the loop fatally dereferenced it.
     */
    public function testGetDateRangeListSwapsFormatsTogetherWithReversedDates(): void {
        $this->assertSame(
            ['2024-01-29', '2024-01-30', '2024-01-31'],
            DateTime::getDateRangeList('31/01/2024', '2024-01-29 00:00:00', 'd/m/Y', 'Y-m-d H:i:s', 'P1D', 'Y-m-d')
        );
    }

    public function testGetDateRangeListIncludesEndOnlyWhenTheIntervalLandsOnIt(): void {
        $this->assertSame(
            ['2024-01-01', '2024-01-03', '2024-01-05'],
            DateTime::getDateRangeList('2024-01-01', '2024-01-05', 'Y-m-d', 'Y-m-d', 'P2D', 'Y-m-d')
        );
        $this->assertSame(
            ['2024-01-01', '2024-01-03'],
            DateTime::getDateRangeList('2024-01-01', '2024-01-04', 'Y-m-d', 'Y-m-d', 'P2D', 'Y-m-d')
        );
    }

    public function testGetDateRangeListReturnsSingleElementWhenBothDatesAreEqual(): void {
        $this->assertSame(
            ['2024-01-01'],
            DateTime::getDateRangeList('2024-01-01', '2024-01-01', 'Y-m-d', 'Y-m-d', 'P1D', 'Y-m-d')
        );
    }

    public function testGetDateRangeListReturnsEmptyArrayOnInvalidDate(): void {
        $this->assertSame([], DateTime::getDateRangeList('nope', '2024-01-05', 'Y-m-d', 'Y-m-d', 'P1D', 'Y-m-d'));
        $this->assertSame([], DateTime::getDateRangeList('2024-01-01', 'nope', 'Y-m-d', 'Y-m-d', 'P1D', 'Y-m-d'));
    }

    public function testGetDateRangeListRejectsUnparseableIntervalSpec(): void {
        $this->expectException(\InvalidArgumentException::class);
        DateTime::getDateRangeList('2024-01-01', '2024-01-05', 'Y-m-d', 'Y-m-d', 'GARBAGE', 'Y-m-d');
    }

    public function testGetDateRangeListRejectsZeroLengthIntervalInsteadOfHanging(): void {
        // 'PT0S' parses fine but never advances the cursor: the loop would spin forever.
        $this->expectException(\InvalidArgumentException::class);
        DateTime::getDateRangeList('2024-01-01', '2024-01-05', 'Y-m-d', 'Y-m-d', 'PT0S', 'Y-m-d');
    }

    // ---------------------------------------------------------------- calculateAge

    /**
     * Pins audit finding 4 for real callers: the docblock named the parameter $inputFormat, which
     * does not exist. PHP 8 named arguments make the documented name load-bearing, and the wrong
     * one raises an \Error that `catch (\Exception)` does not catch.
     */
    public function testCalculateAgeAcceptsTheDocumentedFormatNamedArgument(): void {
        $age = DateTime::calculateAge('1990-05-20', format: 'Y-m-d', timeZone: 'UTC');
        $this->assertSame($this->expectedAgeOf('1990-05-20'), $age);
    }

    public function testCalculateAgeMatchesPhpsOwnYearDiffForFixedBirthdates(): void {
        foreach (['1990-05-20', '2000-01-01', '1996-02-29', '1975-12-31'] as $birthDate) {
            $this->assertSame(
                $this->expectedAgeOf($birthDate),
                DateTime::calculateAge($birthDate, 'Y-m-d', 'UTC'),
                "Age for {$birthDate} must match PHP's own year difference."
            );
        }
    }

    public function testCalculateAgeIsNotYetIncrementedBeforeTheBirthdayInTheSameYear(): void {
        // Built relative to "today" so it never rots: a birthday one day from now, 30 years back,
        // must read 29 — the "birthday hasn't occurred yet this year" branch.
        $tomorrow = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $birth = $tomorrow->modify('+1 day')->modify('-30 years');

        // Skip the ambiguous case where +1 day crosses into the next year (Dec 31): then the
        // birthday month/day is January, which is not "later this year" for a December today.
        if ($birth->format('Y') === $tomorrow->modify('-30 years')->format('Y')) {
            $this->assertSame(29, DateTime::calculateAge($birth->format('Y-m-d'), 'Y-m-d', 'UTC'));
        } else {
            $this->assertSame(30, DateTime::calculateAge($birth->format('Y-m-d'), 'Y-m-d', 'UTC'));
        }
    }

    public function testCalculateAgeReturnsZeroForDateNotMatchingFormat(): void {
        // Documented trap: 0 is also a legitimate age, so this is indistinguishable from a baby.
        $this->assertSame(0, DateTime::calculateAge('20/05/1990', 'Y-m-d', 'UTC'));
        $this->assertSame(0, DateTime::calculateAge('nope', 'Y-m-d', 'UTC'));
    }

    private function expectedAgeOf(string $birthDate): int {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $birth = \DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $birthDate . ' 00:00:00', new \DateTimeZone('UTC'));
        self::assertInstanceOf(\DateTimeImmutable::class, $birth);

        return $birth->diff($today)->y;
    }

    // ---------------------------------------------------------------- getDateDifference

    public function testGetDateDifferenceBreaksDownByCalendarUnits(): void {
        $diff = DateTime::getDateDifference(
            '2020-01-01 10:00:00.250000',
            '2024-03-05 12:30:45.750000',
            'Y-m-d H:i:s.u',
            'Y-m-d H:i:s.u'
        );

        $this->assertSame(
            [
                'years' => '04',
                'months' => '02',
                'days' => '04',
                'hours' => '02',
                'minutes' => '30',
                'seconds' => '45',
                'milliseconds' => '500',
            ],
            $diff
        );
    }

    public function testGetDateDifferenceIsAbsoluteRegardlessOfArgumentOrder(): void {
        $forward = DateTime::getDateDifference('2020-01-01 00:00:00', '2024-03-05 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s');
        $backward = DateTime::getDateDifference('2024-03-05 00:00:00', '2020-01-01 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s');

        $this->assertSame($forward, $backward);
    }

    /**
     * Pins audit finding 1: the documented @return shape spelled the key 'miliseconds' (one L),
     * which the code has never returned. A caller copying the doc hit an undefined array key —
     * an E_WARNING, or a thrown ErrorException under any framework that promotes warnings.
     */
    public function testGetDateDifferenceDocumentsTheKeyItActuallyReturns(): void {
        $diff = DateTime::getDateDifference('2020-01-01 00:00:00', '2024-03-05 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s');

        $this->assertIsArray($diff);
        $this->assertArrayHasKey('milliseconds', $diff);
        $this->assertArrayNotHasKey('miliseconds', $diff);

        $doc = self::docblockOf('getDateDifference');
        $this->assertMatchesRegularExpression('/\bmilliseconds:\s*string/', $doc);
        $this->assertDoesNotMatchRegularExpression('/\bmiliseconds\b/', $doc, "The doc must not spell the key with one L.");
    }

    /**
     * Pins audit finding 5: the code formatted the interval with '%U', which is not a
     * \DateInterval specifier. PHP echoes unknown specifiers back verbatim, so the documented
     * milliseconds field used to be the literal two-character string '%U' — (int) '%U' is 0
     * forever, and UIs rendered "%U ms".
     */
    public function testGetDateDifferenceReturnsRealMillisecondsNotTheLiteralSpecifier(): void {
        $diff = DateTime::getDateDifference(
            '2024-01-01 00:00:00.000000',
            '2024-01-01 00:00:00.123456',
            'Y-m-d H:i:s.u',
            'Y-m-d H:i:s.u'
        );

        $this->assertIsArray($diff);
        $this->assertNotSame('%U', $diff['milliseconds']);
        // Truncated from microseconds, zero-padded to 3 digits, as documented.
        $this->assertSame('123', $diff['milliseconds']);
        $this->assertSame(123, (int) $diff['milliseconds']);
    }

    public function testGetDateDifferenceZeroPadsMillisecondsBelowOneHundred(): void {
        $diff = DateTime::getDateDifference(
            '2024-01-01 00:00:00.000000',
            '2024-01-01 00:00:00.007000',
            'Y-m-d H:i:s.u',
            'Y-m-d H:i:s.u'
        );

        $this->assertIsArray($diff);
        $this->assertSame('007', $diff['milliseconds']);
    }

    public function testGetDateDifferenceReturnsFalseOnInvalidDate(): void {
        $this->assertFalse(DateTime::getDateDifference('nope', '2024-03-05 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s'));
        $this->assertFalse(DateTime::getDateDifference('2020-01-01 00:00:00', 'nope', 'Y-m-d H:i:s', 'Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------- getDateDifferenceInSeconds

    /**
     * Pins audit finding 2: the code multiplied years by 365 * 30 * 86400 — 10,950 days per year —
     * while the docblock promised 365-day years. Every span crossing a year boundary came back
     * exactly 30x too large, so a 1-year-old credential read as ~30 years old to any TTL guard.
     */
    public function testGetDateDifferenceInSecondsUsesThreeHundredSixtyFiveDayYearsAsDocumented(): void {
        $this->assertSame(
            365 * 86400,
            DateTime::getDateDifferenceInSeconds('2023-01-01 00:00:00', '2024-01-01 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
        $this->assertSame(
            2 * 365 * 86400,
            DateTime::getDateDifferenceInSeconds('2022-01-01 00:00:00', '2024-01-01 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
    }

    public function testGetDateDifferenceInSecondsSumsTheSmallerUnits(): void {
        $this->assertSame(
            3600,
            DateTime::getDateDifferenceInSeconds('2024-01-01 00:00:00', '2024-01-01 01:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
        $this->assertSame(
            86400 + 3600 + 60 + 1,
            DateTime::getDateDifferenceInSeconds('2024-01-01 00:00:00', '2024-01-02 01:01:01', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
        // 30-day months, per the documented approximation (January really has 31 days).
        $this->assertSame(
            30 * 86400,
            DateTime::getDateDifferenceInSeconds('2024-01-01 00:00:00', '2024-02-01 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
    }

    /**
     * The sub-second rounding branch was dead code while getDateDifference returned '%U'
     * ('%U' >= 500 is a false string comparison). With real milliseconds it finally fires.
     */
    public function testGetDateDifferenceInSecondsRoundsTheSubSecondRemainder(): void {
        $this->assertSame(
            1,
            DateTime::getDateDifferenceInSeconds(
                '2024-01-01 00:00:00.000000',
                '2024-01-01 00:00:00.600000',
                'Y-m-d H:i:s.u',
                'Y-m-d H:i:s.u'
            )
        );
        $this->assertSame(
            0,
            DateTime::getDateDifferenceInSeconds(
                '2024-01-01 00:00:00.000000',
                '2024-01-01 00:00:00.400000',
                'Y-m-d H:i:s.u',
                'Y-m-d H:i:s.u'
            )
        );
    }

    public function testGetDateDifferenceInSecondsReturnsPhpIntMaxOnInvalidDate(): void {
        // Documented: an invalid date reads as "infinitely old", never as "brand new".
        $this->assertSame(
            PHP_INT_MAX,
            DateTime::getDateDifferenceInSeconds('nope', '2024-01-01 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
    }

    // ---------------------------------------------------------------- timeToSeconds

    public function testTimeToSecondsConvertsClockString(): void {
        $this->assertSame(3723, DateTime::timeToSeconds('01:02:03'));
        $this->assertSame(0, DateTime::timeToSeconds('00:00:00'));
        $this->assertSame(86399, DateTime::timeToSeconds('23:59:59'));
    }

    public function testTimeToSecondsTreatsInputAsDurationNotCappedAtOneDay(): void {
        $this->assertSame(360000, DateTime::timeToSeconds('100:00:00'));
        $this->assertSame(5400, DateTime::timeToSeconds('00:90:00'));
    }

    public function testTimeToSecondsTruncatesFractionalSecondsTail(): void {
        $this->assertSame(90, DateTime::timeToSeconds('00:01:30.999'));
    }

    public function testTimeToSecondsReturnsZeroForNullEmptyAndWrongComponentCount(): void {
        $this->assertSame(0, DateTime::timeToSeconds(null));
        $this->assertSame(0, DateTime::timeToSeconds(''));
        $this->assertSame(0, DateTime::timeToSeconds('10:30'));
        $this->assertSame(0, DateTime::timeToSeconds('01:02:03:04'));
    }

    /**
     * Pins a defect found beyond the audit list: the docblock promises "0 if the format is
     * invalid", but the arithmetic ran on raw string parts, and in PHP 8 a non-numeric string
     * operand raises a TypeError. Callers who trusted the documented 0 took a fatal instead.
     */
    public function testTimeToSecondsReturnsZeroForNonNumericComponentsInsteadOfThrowing(): void {
        $this->assertSame(0, DateTime::timeToSeconds('ab:cd:ef'));
        $this->assertSame(0, DateTime::timeToSeconds('::'));
        $this->assertSame(0, DateTime::timeToSeconds('01:xx:03'));
        $this->assertSame(0, DateTime::timeToSeconds('hh:mm:ss'));
    }

    // ---------------------------------------------------------------- secondsToTime

    public function testSecondsToTimeFormatsSeconds(): void {
        $this->assertSame('01:01:01', DateTime::secondsToTime(3661));
        $this->assertSame('01:01:01', DateTime::secondsToTime('3661'));
        $this->assertSame('23:59:59', DateTime::secondsToTime(86399));
    }

    public function testSecondsToTimeReturnsZeroClockForEmptyAndDigitlessInput(): void {
        $this->assertSame('00:00:00', DateTime::secondsToTime(null));
        $this->assertSame('00:00:00', DateTime::secondsToTime(0));
        $this->assertSame('00:00:00', DateTime::secondsToTime(''));
        $this->assertSame('00:00:00', DateTime::secondsToTime('abc'));
    }

    public function testSecondsToTimeWrapsAtTwentyFourHoursAsDocumented(): void {
        // Documented limitation: this is a clock-of-day rendering, not a duration renderer, so it
        // is NOT a total inverse of timeToSeconds(). Pinned so the doc cannot drift from it.
        $this->assertSame('01:00:00', DateTime::secondsToTime(90000));
        $this->assertSame(90000, DateTime::timeToSeconds('25:00:00'));
    }

    public function testSecondsToTimeSilentlyStripsSignAndDecimalPoint(): void {
        // Documented coercion trap: non-digits are stripped before use.
        $this->assertSame('00:00:05', DateTime::secondsToTime(-5));
        $this->assertSame('00:02:05', DateTime::secondsToTime('12.5'));
    }

    // ---------------------------------------------------------------- getGreetingPeriodCode

    public function testGetGreetingPeriodCodeBucketsMorningAfternoonAndEvening(): void {
        $this->assertSame(1, DateTime::getGreetingPeriodCode(self::timezoneWithCurrentHour(9)));
        $this->assertSame(1, DateTime::getGreetingPeriodCode(self::timezoneWithCurrentHour(0)));
        $this->assertSame(2, DateTime::getGreetingPeriodCode(self::timezoneWithCurrentHour(15)));
        $this->assertSame(2, DateTime::getGreetingPeriodCode(self::timezoneWithCurrentHour(17)));
        $this->assertSame(3, DateTime::getGreetingPeriodCode(self::timezoneWithCurrentHour(18)));
        $this->assertSame(3, DateTime::getGreetingPeriodCode(self::timezoneWithCurrentHour(23)));
    }

    /**
     * Pins audit finding 7: the documented table gives minute-precise boundaries (morning ends at
     * 12:00, afternoon starts at 12:01), but the code read only the hour and tested `<= 12`, so
     * the whole 12:00-12:59 window returned 1. Every user got "Bom dia" for an hour after the doc
     * promised "Boa tarde".
     *
     * The expectation is derived from the documented rule against the noon timezone's real minute,
     * so it holds at any run instant: at 12:00 exactly the answer is still 1, from 12:01 it is 2.
     */
    public function testGetGreetingPeriodCodeHonoursTheDocumentedNoonBoundaryToTheMinute(): void {
        $noonZone = self::timezoneWithCurrentHour(12);
        $minute = (int) (new \DateTimeImmutable('now', new \DateTimeZone($noonZone)))->format('i');

        $expected = $minute === 0 ? 1 : 2;

        $this->assertSame(
            $expected,
            DateTime::getGreetingPeriodCode($noonZone),
            "At 12:{$minute} in {$noonZone} the documented mapping requires code {$expected}."
        );
    }

    public function testGetGreetingPeriodCodeFallsBackToClassDefaultForInvalidTimezone(): void {
        DateTime::setDefaultTimezone(self::timezoneWithCurrentHour(20));

        // Documented: an invalid identifier resolves to the class default rather than throwing or
        // silently reporting morning.
        $this->assertSame(3, DateTime::getGreetingPeriodCode('Not/AZone'));
        $this->assertSame(3, DateTime::getGreetingPeriodCode(null));
    }

    // ---------------------------------------------------------------- convertTimezone

    public function testConvertTimezoneShiftsTheClock(): void {
        // Brazil has had no DST since 2019, so this is a stable UTC-3.
        $this->assertSame(
            '2024-01-01 09:00:00',
            DateTime::convertTimezone('2024-01-01 12:00:00', 'UTC', 'America/Sao_Paulo')
        );
        $this->assertSame(
            '2024-01-01 12:00:00',
            DateTime::convertTimezone('2024-01-01 09:00:00', 'America/Sao_Paulo', 'UTC')
        );
    }

    public function testConvertTimezoneAppliesOutputFormat(): void {
        $this->assertSame(
            '01/01/2024 09:00',
            DateTime::convertTimezone('2024-01-01 12:00:00', 'UTC', 'America/Sao_Paulo', 'Y-m-d H:i:s', 'd/m/Y H:i')
        );
    }

    public function testConvertTimezoneDefaultsToYmdHisAndIgnoresTheClassDefaultFormat(): void {
        // Documented exception in this class: setDefaultFormat() does NOT reach this method.
        DateTime::setDefaultFormat('d/m/Y');

        $this->assertSame(
            '2024-01-01 09:00:00',
            DateTime::convertTimezone('2024-01-01 12:00:00', 'UTC', 'America/Sao_Paulo')
        );
        $this->assertNull(DateTime::convertTimezone('01/01/2024', 'UTC', 'America/Sao_Paulo'));
    }

    public function testConvertTimezoneReturnsNullOnInvalidInput(): void {
        $this->assertNull(DateTime::convertTimezone('2024-01-01', 'UTC', 'America/Sao_Paulo'));
        $this->assertNull(DateTime::convertTimezone('2024-01-01 12:00:00', 'Not/AZone', 'UTC'));
        $this->assertNull(DateTime::convertTimezone('2024-01-01 12:00:00', 'UTC', 'Not/AZone'));
        $this->assertNull(DateTime::convertTimezone('', 'UTC', 'America/Sao_Paulo'));
    }

    // ---------------------------------------------------------------- class-wide doc contract

    /**
     * Pins audit finding 4 at the source, and guards every other method against the same rot:
     * calculateAge's docblock documented a $inputFormat parameter that does not exist, so
     * `calculateAge('...', inputFormat: 'Y-m-d')` — the natural way to reach $timeZone by name —
     * raised `Error: Unknown named parameter`. Named arguments make the documented name part of
     * the public API, so no @param may name a parameter the signature does not have.
     */
    public function testEveryDocumentedParameterNameExistsInItsSignature(): void {
        $class = new \ReflectionClass(DateTime::class);
        $checked = 0;

        foreach ($class->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $doc = $method->getDocComment();
            if ($doc === false) {
                continue;
            }

            $actual = array_map(static fn(\ReflectionParameter $p): string => $p->getName(), $method->getParameters());
            $documented = [];
            if (preg_match_all('/@param\s+\S+\s+\$(\w+)/', $doc, $matches) > 0) {
                $documented = $matches[1];
            }

            $this->assertSame(
                [],
                array_values(array_diff($documented, $actual)),
                "{$method->getName()}() documents @param names that do not exist in its signature."
            );
            $this->assertSame(
                [],
                array_values(array_diff($actual, $documented)),
                "{$method->getName()}() has parameters with no @param entry."
            );
            $checked++;
        }

        $this->assertGreaterThan(15, $checked, 'Expected the whole public surface to be inspected.');
    }
}
