<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\DateTime;

/**
 * Contract tests for VD\PHPHelper\DateTime.
 *
 * Determinism rules followed here:
 *  - Every method that reads "now" accepts a reference instant ($now); the tests pass a fixed one.
 *    The few tests that exercise the real clock bracket it between two readings instead of
 *    comparing against a single "today", so they cannot race a second/day/DST boundary.
 *  - The class holds private static state ($defaultTimezone/$defaultFormat) and PHP holds a
 *    global default timezone; both are saved in setUp() and restored in tearDown(). Every test
 *    starts on UTC, and the DST tests switch PHP's default explicitly.
 */
final class DateTimeTest extends TestCase {
    private ?string $savedTimezone = null;
    private ?string $savedFormat = null;
    private string $savedIniTimezone = 'UTC';

    protected function setUp(): void {
        $this->savedTimezone = self::readStatic('defaultTimezone');
        $this->savedFormat = self::readStatic('defaultFormat');
        $this->savedIniTimezone = date_default_timezone_get();

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

    private static function at(string $isoInstant): \DateTimeImmutable {
        return new \DateTimeImmutable($isoInstant);
    }

    // ---------------------------------------------------------------- timezone defaults

    public function testGetDefaultTimezoneFallsBackToPhpDefaultWhenUnset(): void {
        $this->assertSame('UTC', DateTime::getDefaultTimezone());
    }

    /**
     * The fallback used to be CACHED on first use, so a bootstrap that touched this class before
     * calling date_default_timezone_set() froze the old zone for the rest of the process.
     */
    public function testUnsetDefaultTimezoneTracksLaterChangesToPhpDefaultUntilPinned(): void {
        $this->assertSame('UTC', DateTime::getDefaultTimezone());

        date_default_timezone_set('America/Sao_Paulo');
        $this->assertSame('America/Sao_Paulo', DateTime::getDefaultTimezone());

        DateTime::setDefaultTimezone('Asia/Tokyo');
        date_default_timezone_set('Europe/Lisbon');
        $this->assertSame('Asia/Tokyo', DateTime::getDefaultTimezone());
    }

    public function testSetDefaultTimezoneAcceptsValidIdentifier(): void {
        DateTime::setDefaultTimezone('America/Sao_Paulo');
        $this->assertSame('America/Sao_Paulo', DateTime::getDefaultTimezone());
    }

    public function testSetDefaultTimezoneSilentlyIgnoresInvalidIdentifier(): void {
        DateTime::setDefaultTimezone('America/Sao_Paulo');
        DateTime::setDefaultTimezone('Not/AZone');
        DateTime::setDefaultTimezone("UTC\0");

        $this->assertSame('America/Sao_Paulo', DateTime::getDefaultTimezone());
    }

    public function testIsValidTimezoneAcceptsRealZonesAndRejectsGarbage(): void {
        $this->assertTrue(DateTime::isValidTimezone('America/Sao_Paulo'));
        $this->assertTrue(DateTime::isValidTimezone('UTC'));
        $this->assertTrue(DateTime::isValidTimezone('+03:00'));
        $this->assertFalse(DateTime::isValidTimezone('Not/AZone'));
        $this->assertFalse(DateTime::isValidTimezone(''));
    }

    /** \DateTimeZone throws a ValueError (an \Error) on a NUL byte; that must not escape. */
    public function testIsValidTimezoneReturnsFalseForANulByteInsteadOfThrowingValueError(): void {
        $this->assertFalse(DateTime::isValidTimezone("UTC\0garbage"));
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
        $this->assertTrue(DateTime::validateDate('2024-01-31 23:59:59', 'Y-m-d H:i:s'));
        $this->assertTrue(DateTime::validateDate('2024-01-31 23:59:59.123456', 'Y-m-d H:i:s.u'));
        $this->assertTrue(DateTime::validateDate('0999-01-01', 'Y-m-d'));
    }

    public static function invalidDateProvider(): array {
        return [
            'Feb 29 of a common year' => ['2023-02-29', 'Y-m-d'],
            'Feb 30 rolls into March' => ['2024-02-30', 'Y-m-d'],
            'Apr 31'                  => ['2024-04-31', 'Y-m-d'],
            'month 13'                => ['2024-13-01', 'Y-m-d'],
            'month 00'                => ['2024-00-10', 'Y-m-d'],
            'day 00'                  => ['2024-01-00', 'Y-m-d'],
            'hour 24'                 => ['2024-01-01 24:00:00', 'Y-m-d H:i:s'],
            'minute 60'               => ['2024-01-01 23:60:00', 'Y-m-d H:i:s'],
            'unpadded digits'         => ['2024-1-5', 'Y-m-d'],
            'other format'            => ['2024-01-31', 'd/m/Y'],
            'leading space'           => [' 2024-01-31', 'Y-m-d'],
            'trailing data'           => ['2024-01-31x', 'Y-m-d'],
            'short microseconds'      => ['2024-01-01 00:00:00.5', 'Y-m-d H:i:s.u'],
            'NUL byte'                => ["2024-01-31\0", 'Y-m-d'],
            'NUL byte in format'      => ['2024-01-31', "Y-m-d\0"],
            'wildcard never matches'  => ['2024-01-31', 'Y-m-?'],
            'empty'                   => ['', 'Y-m-d'],
            'null'                    => [null, 'Y-m-d'],
        ];
    }

    /** NUL bytes used to raise a ValueError from createFromFormat(); now they are just invalid. */
    #[DataProvider('invalidDateProvider')]
    public function testValidateDateRejectsOverflowMismatchAndGarbage(?string $date, string $format): void {
        $this->assertFalse(DateTime::validateDate($date, $format));
    }

    public function testValidateDateUsesClassDefaultFormatWhenFormatIsNull(): void {
        DateTime::setDefaultFormat('d/m/Y');
        $this->assertTrue(DateTime::validateDate('31/01/2024'));
        $this->assertFalse(DateTime::validateDate('2024-01-31'));
    }

    /**
     * Regression: fields the format lacks were filled from the CURRENT clock, so "2024-02" as
     * 'Y-m' borrowed today's day — on the 30th/31st of any month that is Feb 30/31, which rolled
     * into March and failed the round-trip. The validator's answer depended on the calendar day.
     */
    public function testValidateDateDoesNotBorrowTheCurrentDayForAMissingField(): void {
        $this->assertTrue(DateTime::validateDate('2024-02', 'Y-m'));
        $this->assertTrue(DateTime::validateDate('2023-02', 'Y-m'));
        $this->assertSame('2024-02-01 00:00:00', DateTime::convertDateToFormat('2024-02', 'Y-m-d H:i:s', 'Y-m'));
    }

    /** A yearless format is read in a leap year, so a birthday on Feb 29 is a real "d/m". */
    public function testValidateDateAcceptsLeapDayInAYearlessFormat(): void {
        $this->assertTrue(DateTime::validateDate('29/02', 'd/m'));
        $this->assertFalse(DateTime::validateDate('30/02', 'd/m'));
        $this->assertSame(2, DateTime::getMonth('29/02', 'd/m'));
    }

    /** '!' and '|' are parse-only: format() prints them literally, so they used to always fail. */
    public function testValidateDateAcceptsTheParseOnlyResetModifiers(): void {
        $this->assertTrue(DateTime::validateDate('2024-01-01', '!Y-m-d'));
        $this->assertTrue(DateTime::validateDate('2024-01-01', 'Y-m-d|'));
        $this->assertTrue(DateTime::validateDate('15/07', '!d/m'), "the caller's '!' must not wipe the anchor year");
        $this->assertTrue(DateTime::validateDate('29/02', '!d/m'));
    }

    public function testValidateDateHonoursEscapedFormatCharacters(): void {
        $this->assertTrue(DateTime::validateDate('2024-01-01T10:00', 'Y-m-d\TH:i'));
        $this->assertFalse(DateTime::validateDate('2024-01-01X10:00', 'Y-m-d\TH:i'));
    }

    /** America/New_York skipped 02:00-02:59 on 2024-03-10: that wall-clock time does not exist. */
    public function testValidateDateRejectsAWallClockTimeSkippedByDst(): void {
        date_default_timezone_set('America/New_York');
        $this->assertFalse(DateTime::validateDate('2024-03-10 02:30:00', 'Y-m-d H:i:s'));
        $this->assertTrue(DateTime::validateDate('2024-03-10 03:30:00', 'Y-m-d H:i:s'));
        // The repeated hour at the DST end exists (twice), so it is valid.
        $this->assertTrue(DateTime::validateDate('2024-11-03 01:30:00', 'Y-m-d H:i:s'));
        // A string carrying its own offset is not a New York wall-clock time.
        $this->assertTrue(DateTime::validateDate('2024-03-10T02:30:00+00:00', 'Y-m-d\TH:i:sP'));

        date_default_timezone_set('UTC');
        $this->assertTrue(DateTime::validateDate('2024-03-10 02:30:00', 'Y-m-d H:i:s'));
    }

    /**
     * Brazil's historical DST started at MIDNIGHT (2018-11-04 00:00 -> 01:00 in São Paulo), so a
     * date-only value on that day parses to a midnight that never existed.
     */
    public function testValidateDateHandlesSaoPauloHistoricalMidnightDstGap(): void {
        date_default_timezone_set('America/Sao_Paulo');

        $this->assertTrue(DateTime::validateDate('2018-11-04', 'Y-m-d'));
        $this->assertSame(1, DateTime::getWeekDay('2018-11-04', 'Y-m-d'));
        $this->assertFalse(DateTime::validateDate('2018-11-04 00:30:00', 'Y-m-d H:i:s'));
        // Documented: the time of such a date is the first instant that exists.
        $this->assertSame('2018-11-04 01:00:00', DateTime::convertDateToFormat('04/11/2018', 'Y-m-d H:i:s', 'd/m/Y'));
    }

    // ---------------------------------------------------------------- getCurrentFormattedDate

    public function testGetCurrentFormattedDateReturnsTheCurrentInstant(): void {
        $before = time();
        $now = DateTime::getCurrentFormattedDate('U', 'UTC');
        $after = time();

        $this->assertIsString($now);
        $this->assertGreaterThanOrEqual($before, (int) $now);
        $this->assertLessThanOrEqual($after, (int) $now);
    }

    public function testGetCurrentFormattedDateUsesClassDefaultFormatAndTimezone(): void {
        DateTime::setDefaultFormat('P');
        DateTime::setDefaultTimezone('Pacific/Kiritimati'); // UTC+14, no DST

        $this->assertSame('+14:00', DateTime::getCurrentFormattedDate());
        $this->assertSame('-11:00', DateTime::getCurrentFormattedDate(null, 'Pacific/Midway'));
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
        $this->assertNull(DateTime::getCurrentFormattedDate('Y-m-d', "UTC\0"));
    }

    // ---------------------------------------------------------------- convertTimestampToDate

    public function testConvertTimestampToDateFormatsTimestamp(): void {
        $this->assertSame('2023-11-14', DateTime::convertTimestampToDate(1700000000, 'Y-m-d'));
        $this->assertSame('2023-11-14 22:13:20', DateTime::convertTimestampToDate(1700000000, 'Y-m-d H:i:s'));
        $this->assertSame('1969-12-31 23:59:59', DateTime::convertTimestampToDate(-1, 'Y-m-d H:i:s'));
    }

    /** Regression: a falsy guard made the Unix epoch itself unformattable (it returned null). */
    public function testConvertTimestampToDateFormatsTheEpoch(): void {
        $this->assertSame('1970-01-01 00:00:00', DateTime::convertTimestampToDate(0, 'Y-m-d H:i:s'));
    }

    public function testConvertTimestampToDateReturnsNullForNullTimestampOrInvalidTimezone(): void {
        $this->assertNull(DateTime::convertTimestampToDate(null, 'Y-m-d'));
        $this->assertNull(DateTime::convertTimestampToDate(0, 'Y-m-d', 'Not/AZone'));
    }

    public function testConvertTimestampToDateRendersInTheRequestedOrClassDefaultTimezone(): void {
        $this->assertSame('1969-12-31 21:00:00', DateTime::convertTimestampToDate(0, 'Y-m-d H:i:s', 'America/Sao_Paulo'));

        DateTime::setDefaultTimezone('Asia/Tokyo');
        $this->assertSame('1970-01-01 09:00:00', DateTime::convertTimestampToDate(0, 'Y-m-d H:i:s'));
    }

    // ---------------------------------------------------------------- getWeekDay / getMonth

    public function testGetWeekDayIsOneBasedStartingOnSunday(): void {
        $this->assertSame(1, DateTime::getWeekDay('2024-01-07', 'Y-m-d')); // Sunday
        $this->assertSame(2, DateTime::getWeekDay('2024-01-08', 'Y-m-d')); // Monday
        $this->assertSame(7, DateTime::getWeekDay('2024-01-13', 'Y-m-d')); // Saturday
        $this->assertSame(5, DateTime::getWeekDay('29/02/2024', 'd/m/Y')); // Thursday
    }

    public function testGetWeekDayReturnsNullForDateNotMatchingFormat(): void {
        $this->assertNull(DateTime::getWeekDay('07/01/2024', 'Y-m-d'));
        $this->assertNull(DateTime::getWeekDay('nonsense', 'Y-m-d'));
        $this->assertNull(DateTime::getWeekDay('2023-02-29', 'Y-m-d'));
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

    /**
     * Regression: the missing time was taken from the clock, so this returned the time of the call
     * ('2024-01-31 14:23:11') — a different string every second.
     */
    public function testConvertDateToFormatFillsAMissingTimeWithMidnightNotTheClock(): void {
        $this->assertSame('2024-01-31 00:00:00.000000', DateTime::convertDateToFormat('2024-01-31', 'Y-m-d H:i:s.u', 'Y-m-d'));
        $this->assertSame('2024-01-31 10:00:00', DateTime::convertDateToFormat('2024-01-31 10', 'Y-m-d H:i:s', 'Y-m-d H'));
    }

    public function testConvertDateToFormatReturnsEmptyStringOnInvalidInput(): void {
        $this->assertSame('', DateTime::convertDateToFormat('2024-01-31', 'Y-m-d', 'd/m/Y'));
        $this->assertSame('', DateTime::convertDateToFormat('2024-01-31', '', 'Y-m-d'));
        $this->assertSame('', DateTime::convertDateToFormat('2024-02-30', 'd/m/Y', 'Y-m-d'));
    }

    // ---------------------------------------------------------------- toDate

    public function testToDateBuildsDateTimeObject(): void {
        $date = DateTime::toDate('2024-01-31', 'Y-m-d');

        $this->assertInstanceOf(\DateTime::class, $date);
        $this->assertSame('2024-01-31 00:00:00', $date->format('Y-m-d H:i:s'));
        $this->assertSame('UTC', $date->getTimezone()->getName());
    }

    public function testToDateReturnsNullWhenDateDoesNotMatchFormat(): void {
        $this->assertNull(DateTime::toDate('31/01/2024', 'Y-m-d'));
        $this->assertNull(DateTime::toDate('2024-02-30', 'Y-m-d'));
    }

    /** The result is mutable, so every call must hand out an independent object. */
    public function testToDateReturnsAFreshObjectOnEveryCall(): void {
        $first = DateTime::toDate('2024-01-31', 'Y-m-d');
        $this->assertInstanceOf(\DateTime::class, $first);
        $first->modify('+1 day');

        $second = DateTime::toDate('2024-01-31', 'Y-m-d');
        $this->assertInstanceOf(\DateTime::class, $second);
        $this->assertSame('2024-01-31', $second->format('Y-m-d'));
    }

    // ---------------------------------------------------------------- dateDiffDays

    public function testDateDiffDaysCountsWholeDaysAndIsAbsolute(): void {
        $this->assertSame(30, DateTime::dateDiffDays('2024-01-01', '2024-01-31', 'Y-m-d', 'Y-m-d'));
        $this->assertSame(30, DateTime::dateDiffDays('2024-01-31', '2024-01-01', 'Y-m-d', 'Y-m-d'));
        $this->assertSame(0, DateTime::dateDiffDays('2024-01-01', '2024-01-01', 'Y-m-d', 'Y-m-d'));
        $this->assertSame(1, DateTime::dateDiffDays('2023-12-31', '2024-01-01', 'Y-m-d', 'Y-m-d'));
    }

    public function testDateDiffDaysCountsLeapDays(): void {
        $this->assertSame(29, DateTime::dateDiffDays('2024-02-01', '2024-03-01', 'Y-m-d', 'Y-m-d'));
        $this->assertSame(28, DateTime::dateDiffDays('2023-02-01', '2023-03-01', 'Y-m-d', 'Y-m-d'));
        $this->assertSame(366, DateTime::dateDiffDays('2024-01-01', '2025-01-01', 'Y-m-d', 'Y-m-d'));
    }

    public function testDateDiffDaysAcceptsDifferentFormatPerDate(): void {
        $this->assertSame(30, DateTime::dateDiffDays('01/01/2024', '2024-01-31', 'd/m/Y', 'Y-m-d'));
    }

    /** Calendar days: two minutes across midnight is one day; 23 hours within a day is zero. */
    public function testDateDiffDaysIgnoresTheTimeOfDay(): void {
        $this->assertSame(1, DateTime::dateDiffDays('2024-01-01 23:59', '2024-01-02 00:01', 'Y-m-d H:i', 'Y-m-d H:i'));
        $this->assertSame(0, DateTime::dateDiffDays('2024-01-01 00:00', '2024-01-01 23:00', 'Y-m-d H:i', 'Y-m-d H:i'));
    }

    public function testDateDiffDaysIsNotSkewedByDstTransitions(): void {
        date_default_timezone_set('America/New_York');
        $this->assertSame(2, DateTime::dateDiffDays('2024-03-09', '2024-03-11', 'Y-m-d', 'Y-m-d'));
        $this->assertSame(2, DateTime::dateDiffDays('2024-11-02', '2024-11-04', 'Y-m-d', 'Y-m-d'));

        date_default_timezone_set('America/Sao_Paulo');
        $this->assertSame(2, DateTime::dateDiffDays('2018-11-03', '2018-11-05', 'Y-m-d', 'Y-m-d'));
        $this->assertSame(1, DateTime::dateDiffDays('2018-11-04', '2018-11-05', 'Y-m-d', 'Y-m-d'));
    }

    public function testDateDiffDaysReturnsFalseOnInvalidDate(): void {
        $this->assertFalse(DateTime::dateDiffDays('nope', '2024-01-31', 'Y-m-d', 'Y-m-d'));
        $this->assertFalse(DateTime::dateDiffDays('2024-01-01', 'nope', 'Y-m-d', 'Y-m-d'));
    }

    // ---------------------------------------------------------------- dateFullTextPtBr

    public function testDateFullTextPtBrRendersFullFormForDistantYear(): void {
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
        $this->assertSame(
            'Quinta-feira, 15 de julho de 1999, às 09h',
            DateTime::dateFullTextPtBr('15/07/1999 9', 'd/m/Y G')
        );
        $this->assertStringNotContainsString('às', (string) DateTime::dateFullTextPtBr('1999-07-15', 'Y-m-d'));
        // An escaped 'H' is a literal, not an hour.
        $this->assertSame(
            'Quinta-feira, 15 de julho de 1999',
            DateTime::dateFullTextPtBr('1999-07-15H', 'Y-m-d\H')
        );
    }

    public function testDateFullTextPtBrReturnsNullWhenDateDoesNotMatchFormat(): void {
        $this->assertNull(DateTime::dateFullTextPtBr('nope', 'Y-m-d'));
        $this->assertNull(DateTime::dateFullTextPtBr('15/07/1999', 'Y-m-d'));
    }

    /** Regression: an invalid timezone raised a TypeError from the internal "now" split. */
    public function testDateFullTextPtBrReturnsNullForInvalidTimezone(): void {
        $this->assertNull(DateTime::dateFullTextPtBr('1999-07-15', 'Y-m-d', true, 'Not/AZone'));
    }

    /**
     * Every branch of the relative wording, against a fixed "now" of Saturday 2024-06-15 12:00.
     */
    #[DataProvider('relativeWordingProvider')]
    public function testDateFullTextPtBrRendersRelativeWording(string $date, string $expected): void {
        $this->assertSame(
            $expected,
            DateTime::dateFullTextPtBr($date, 'Y-m-d', true, 'UTC', self::at('2024-06-15T12:00:00Z'))
        );
    }

    public static function relativeWordingProvider(): array {
        return [
            'today'                => ['2024-06-15', 'Hoje, sábado'],
            'yesterday'            => ['2024-06-14', 'Ontem, sexta-feira'],
            'tomorrow'             => ['2024-06-16', 'Amanhã, domingo'],
            'day before yesterday' => ['2024-06-13', 'Anteontem, quinta-feira'],
            'day after tomorrow'   => ['2024-06-17', 'Dia 17 deste mês, segunda-feira'],
            'earlier this month'   => ['2024-06-01', 'Dia 01 deste mês, sábado'],
            'last month'           => ['2024-05-10', 'Sexta-feira, dia 10 do mês passado'],
            'earlier this year'    => ['2024-02-10', 'Sábado, 10 de fevereiro'],
            'later this year'      => ['2024-12-25', 'Quarta-feira, 25 de dezembro'],
            'last year'            => ['2023-07-15', 'Sábado, 15 de julho do ano passado'],
            'next year'            => ['2025-01-01', 'Quarta-feira, 01 de janeiro de 2025'],
        ];
    }

    /** "Ontem" is a calendar relation: it must hold across a month and a year boundary. */
    public function testDateFullTextPtBrRelativeWordingCrossesMonthAndYearBoundaries(): void {
        $this->assertSame(
            'Ontem, quinta-feira',
            DateTime::dateFullTextPtBr('2024-02-29', 'Y-m-d', true, 'UTC', self::at('2024-03-01T00:00:00Z'))
        );
        $this->assertSame(
            'Ontem, domingo',
            DateTime::dateFullTextPtBr('2023-12-31', 'Y-m-d', true, 'UTC', self::at('2024-01-01T08:00:00Z'))
        );
        $this->assertSame(
            'Amanhã, segunda-feira',
            DateTime::dateFullTextPtBr('2024-01-01', 'Y-m-d', true, 'UTC', self::at('2023-12-31T23:59:59Z'))
        );
    }

    /**
     * The timezone decides which calendar day "now" is: 01:00 UTC on the 15th is still 22:00 on
     * the 14th in São Paulo. A null timezone must follow the class default, not UTC and not a
     * hardcoded Brazilian zone.
     */
    public function testDateFullTextPtBrTimezoneDecidesWhichDayIsToday(): void {
        $now = self::at('2024-06-15T01:00:00Z');

        $this->assertSame('Hoje, sexta-feira', DateTime::dateFullTextPtBr('2024-06-14', 'Y-m-d', true, 'America/Sao_Paulo', $now));
        $this->assertSame('Ontem, sexta-feira', DateTime::dateFullTextPtBr('2024-06-14', 'Y-m-d', true, 'UTC', $now));

        DateTime::setDefaultTimezone('America/Sao_Paulo');
        $this->assertSame('Hoje, sexta-feira', DateTime::dateFullTextPtBr('2024-06-14', 'Y-m-d', true, null, $now));
    }

    /** A date carrying its own offset is converted into the requested timezone before wording. */
    public function testDateFullTextPtBrConvertsADateWithItsOwnOffsetIntoTheTimezone(): void {
        $this->assertSame(
            'Ontem, sexta-feira, às 21h e 30min',
            DateTime::dateFullTextPtBr(
                '2024-06-15T00:30:00+00:00',
                'Y-m-d\TH:i:sP',
                true,
                'America/Sao_Paulo',
                self::at('2024-06-15T15:00:00Z')
            )
        );
    }

    // ---------------------------------------------------------------- applyInterval

    public function testApplyIntervalAddsAndSubtracts(): void {
        $this->assertSame('2024-01-04', DateTime::applyInterval('P3D', '2024-01-01', true, 'Y-m-d', 'Y-m-d'));
        $this->assertSame('2023-12-29', DateTime::applyInterval('P3D', '2024-01-01', false, 'Y-m-d', 'Y-m-d'));
        $this->assertSame(
            '2024-01-01 02:30:00',
            DateTime::applyInterval('PT2H30M', '2024-01-01 00:00:00', true, 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
        $this->assertSame(
            '2025-03-11 02:30:00',
            DateTime::applyInterval('P1Y2M10DT2H30M', '2024-01-01 00:00:00', true, 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
    }

    public function testApplyIntervalIgnoresCaseAndWhitespaceInTheSpec(): void {
        $this->assertSame('2024-01-04', DateTime::applyInterval(' p3d ', '2024-01-01', true, 'Y-m-d', 'Y-m-d'));
        $this->assertSame('2024-01-15', DateTime::applyInterval('P2W', '2024-01-01', true, 'Y-m-d', 'Y-m-d'));
    }

    public function testApplyIntervalConvertsOutputFormat(): void {
        $this->assertSame('04/01/2024', DateTime::applyInterval('P3D', '01/01/2024', true, 'd/m/Y', 'd/m/Y'));
        $this->assertSame('04/01/2024', DateTime::applyInterval('P3D', '2024-01-01', true, 'Y-m-d', 'd/m/Y'));
        $this->assertSame('2024-01-02 00:00', DateTime::applyInterval('P1D', '2024-01-01', true, 'Y-m-d', 'Y-m-d H:i'));
    }

    /**
     * Regression: PHP's own arithmetic overflows a short month — 2024-01-31 + P1M is 2024-03-02 —
     * so "one month after January 31" skipped February entirely. The day is clamped instead.
     */
    #[DataProvider('monthEndProvider')]
    public function testApplyIntervalClampsTheDayAtMonthEnd(string $spec, string $base, bool $isAddition, string $expected): void {
        $this->assertSame($expected, DateTime::applyInterval($spec, $base, $isAddition, 'Y-m-d', 'Y-m-d'));
    }

    public static function monthEndProvider(): array {
        return [
            'Jan 31 + 1 month, leap year'   => ['P1M', '2024-01-31', true, '2024-02-29'],
            'Jan 31 + 1 month, common year' => ['P1M', '2023-01-31', true, '2023-02-28'],
            'Mar 31 - 1 month'              => ['P1M', '2024-03-31', false, '2024-02-29'],
            'May 31 + 1 month'              => ['P1M', '2024-05-31', true, '2024-06-30'],
            'Dec 31 + 2 months'             => ['P2M', '2024-12-31', true, '2025-02-28'],
            'Feb 29 + 1 year'               => ['P1Y', '2024-02-29', true, '2025-02-28'],
            'Feb 29 - 4 years'              => ['P4Y', '2024-02-29', false, '2020-02-29'],
            'Jan 15 - 13 months'            => ['P13M', '2024-01-15', false, '2022-12-15'],
            'months then days'              => ['P1M1D', '2024-01-31', true, '2024-03-01'],
            'mid-month is untouched'        => ['P1M', '2024-01-15', true, '2024-02-15'],
        ];
    }

    /**
     * The date is interpreted in $timezone: across the 2024-03-10 New York DST start, one hour
     * after 01:30 EST is 03:30 EDT, while the same wall clock in UTC is plain 02:30.
     */
    public function testApplyIntervalRunsTheArithmeticInTheRequestedTimezone(): void {
        $this->assertSame(
            '2024-03-10 03:30:00',
            DateTime::applyInterval('PT1H', '2024-03-10 01:30:00', true, 'Y-m-d H:i:s', 'Y-m-d H:i:s', 'America/New_York')
        );
        $this->assertSame(
            '2024-03-10 02:30:00',
            DateTime::applyInterval('PT1H', '2024-03-10 01:30:00', true, 'Y-m-d H:i:s', 'Y-m-d H:i:s', 'UTC')
        );
    }

    public function testApplyIntervalUsesTheCurrentInstantWhenBaseDateIsNull(): void {
        $utc = new \DateTimeZone('UTC');
        $before = (new \DateTimeImmutable('now', $utc))->modify('+1 day')->format('Y-m-d H:i');
        $result = DateTime::applyInterval('P1D', null, true, 'Y-m-d', 'Y-m-d H:i', 'UTC');
        $after = (new \DateTimeImmutable('now', $utc))->modify('+1 day')->format('Y-m-d H:i');

        // Bracketed by two readings, so a minute ticking over in between cannot fail it.
        $this->assertContains($result, [$before, $after]);
    }

    public function testApplyIntervalReturnsNullOnInvalidInput(): void {
        $this->assertNull(DateTime::applyInterval('', '2024-01-01', true, 'Y-m-d', 'Y-m-d'));
        $this->assertNull(DateTime::applyInterval('GARBAGE', '2024-01-01', true, 'Y-m-d', 'Y-m-d'));
        $this->assertNull(DateTime::applyInterval('P3D', '31/01/2024', true, 'Y-m-d', 'Y-m-d'));
        $this->assertNull(DateTime::applyInterval('P3D', '2024-02-30', true, 'Y-m-d', 'Y-m-d'));
    }

    /** Regression: a null base date with an invalid timezone escaped as a TypeError. */
    public function testApplyIntervalReturnsNullForInvalidTimezoneInsteadOfThrowing(): void {
        $this->assertNull(DateTime::applyInterval('P1D', null, true, 'Y-m-d', 'Y-m-d', 'Not/AZone'));
        $this->assertNull(DateTime::applyInterval('P1D', '2024-01-01', true, 'Y-m-d', 'Y-m-d', 'Not/AZone'));
    }

    // ---------------------------------------------------------------- getDateRangeList

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

    /** Date-only inputs start at midnight; the elements used to carry the time of the call. */
    public function testGetDateRangeListDoesNotLeakTheClockIntoTimeOfDay(): void {
        $this->assertSame(
            ['2024-01-01 00:00', '2024-01-01 12:00', '2024-01-02 00:00'],
            DateTime::getDateRangeList('2024-01-01', '2024-01-02', 'Y-m-d', 'Y-m-d', 'PT12H', 'Y-m-d H:i')
        );
    }

    /**
     * Regression: cumulative arithmetic went Jan 31 -> Mar 2 -> Apr 2 -> May 2, skipping February
     * and drifting off month-end for good. Each element is now start + N months, clamped.
     */
    public function testGetDateRangeListMonthlyRangeKeepsMonthEnds(): void {
        $this->assertSame(
            ['2024-01-31', '2024-02-29', '2024-03-31', '2024-04-30', '2024-05-31'],
            DateTime::getDateRangeList('2024-01-31', '2024-05-31', 'Y-m-d', 'Y-m-d', 'P1M', 'Y-m-d')
        );
    }

    /**
     * Regression: in America/Sao_Paulo, 2018-11-04 00:00 did not exist. Stepping +1 day from the
     * 3rd landed on 01:00 of the 4th, the next step on 01:00 of the 5th — one hour past the end —
     * so the end date was silently dropped.
     */
    public function testGetDateRangeListReachesTheEndDateAcrossAMidnightDstGap(): void {
        date_default_timezone_set('America/Sao_Paulo');

        $this->assertSame(
            ['2018-11-03 00:00', '2018-11-04 01:00', '2018-11-05 00:00'],
            DateTime::getDateRangeList('2018-11-03 00:00:00', '2018-11-05 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s', 'P1D', 'Y-m-d H:i')
        );
    }

    /**
     * The gap at the START: 2018-11-04 00:00 did not exist in America/Sao_Paulo, so the start
     * date was read as 01:00, every daily element inherited that hour, the last one fell past the
     * end date's midnight — and the end date was dropped (6 dates instead of 7). A calendar range
     * must not care what time it is: America/Santiago and America/Havana still shift at midnight.
     */
    public function testGetDateRangeListIncludesTheEndDateWhenTheStartSitsOnAMidnightDstGap(): void {
        $week = ['2018-11-04', '2018-11-05', '2018-11-06', '2018-11-07', '2018-11-08', '2018-11-09', '2018-11-10'];
        date_default_timezone_set('America/Sao_Paulo');
        $this->assertSame($week, DateTime::getDateRangeList('2018-11-04', '2018-11-10'));
        $this->assertSame($week, DateTime::getDateRangeList('2018-11-10', '2018-11-04'), 'reversed dates');

        date_default_timezone_set('America/Santiago');
        $this->assertSame(['2026-09-06', '2026-09-07', '2026-09-08'], DateTime::getDateRangeList('2026-09-06', '2026-09-08'));

        date_default_timezone_set('America/Havana');
        $this->assertSame(['2026-03-08', '2026-03-09', '2026-03-10'], DateTime::getDateRangeList('2026-03-08', '2026-03-10'));
    }

    /**
     * Calendar steps are computed on floating wall-clock values and rendered in the default zone:
     * a time or zone token in the output shows the local reading (01:00 -02:00 for the midnight
     * that did not exist), never the UTC the arithmetic borrowed.
     */
    public function testGetDateRangeListCalendarStepsRenderInTheDefaultTimezone(): void {
        date_default_timezone_set('America/Sao_Paulo');

        $this->assertSame(
            ['2018-11-03 00:00 -03:00', '2018-11-04 01:00 -02:00', '2018-11-05 00:00 -02:00'],
            DateTime::getDateRangeList('2018-11-03', '2018-11-05', 'Y-m-d', 'Y-m-d', 'P1D', 'Y-m-d H:i P')
        );
    }

    /** Hourly steps are elapsed hours: 02:00 did not exist in New York on 2024-03-10. */
    public function testGetDateRangeListHourlyStepsFollowElapsedTimeAcrossDst(): void {
        date_default_timezone_set('America/New_York');

        $this->assertSame(
            ['00:00', '01:00', '03:00', '04:00'],
            DateTime::getDateRangeList('2024-03-10 00:00', '2024-03-10 04:00', 'Y-m-d H:i', 'Y-m-d H:i', 'PT1H', 'H:i')
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
     * Named arguments make the documented parameter names part of the API: the docblock once
     * named a non-existent $inputFormat, and `inputFormat:` raises an \Error.
     */
    public function testCalculateAgeAcceptsTheDocumentedNamedArguments(): void {
        $this->assertSame(
            34,
            DateTime::calculateAge('1990-05-20', format: 'Y-m-d', timeZone: 'UTC', now: self::at('2024-06-15T12:00:00Z'))
        );
    }

    #[DataProvider('ageProvider')]
    public function testCalculateAgeCountsCompletedYears(string $birthDate, string $now, int $expected): void {
        $this->assertSame($expected, DateTime::calculateAge($birthDate, 'Y-m-d', 'UTC', self::at($now)));
    }

    public static function ageProvider(): array {
        return [
            'birthday today'                   => ['1990-06-15', '2024-06-15T12:00:00Z', 34],
            'birthday tomorrow'                => ['1990-06-16', '2024-06-15T12:00:00Z', 33],
            'birthday yesterday'               => ['1990-06-14', '2024-06-15T12:00:00Z', 34],
            'birthday later in the year'       => ['1990-12-31', '2024-06-15T12:00:00Z', 33],
            'born today'                       => ['2024-06-15', '2024-06-15T12:00:00Z', 0],
            'future birthdate'                 => ['2025-01-01', '2024-06-15T12:00:00Z', -1],
            'leap-day birth, Feb 28 common yr' => ['1996-02-29', '2023-02-28T12:00:00Z', 26],
            'leap-day birth, Mar 1 common yr'  => ['1996-02-29', '2023-03-01T12:00:00Z', 27],
            'leap-day birth, Feb 29 leap yr'   => ['1996-02-29', '2024-02-29T12:00:00Z', 28],
        ];
    }

    /** 01:00 UTC on the 15th is still the 14th in São Paulo: the birthday has not arrived there. */
    public function testCalculateAgeReadsTodayInTheRequestedTimezone(): void {
        $now = self::at('2024-06-15T01:00:00Z');

        $this->assertSame(34, DateTime::calculateAge('1990-06-15', 'Y-m-d', 'UTC', $now));
        $this->assertSame(33, DateTime::calculateAge('1990-06-15', 'Y-m-d', 'America/Sao_Paulo', $now));
    }

    /** BREAKING: null, not 0 — a real age of 0 and a garbage date must be distinguishable. */
    public function testCalculateAgeReturnsNullForDateNotMatchingFormatOrInvalidTimezone(): void {
        $this->assertNull(DateTime::calculateAge('20/05/1990', 'Y-m-d', 'UTC'));
        $this->assertNull(DateTime::calculateAge('nope', 'Y-m-d', 'UTC'));
        // Used to emit deprecations + warnings and then throw a TypeError.
        $this->assertNull(DateTime::calculateAge('1990-01-01', 'Y-m-d', 'Not/AZone'));
    }

    public function testCalculateAgeWithTheRealClockMatchesPhpsOwnYearDifference(): void {
        $utc = new \DateTimeZone('UTC');
        $before = (new \DateTimeImmutable('2000-01-01', $utc))->diff(new \DateTimeImmutable('today', $utc))->y;
        $age = DateTime::calculateAge('2000-01-01', 'Y-m-d', 'UTC');
        $after = (new \DateTimeImmutable('2000-01-01', $utc))->diff(new \DateTimeImmutable('today', $utc))->y;

        $this->assertContains($age, [$before, $after]);
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

        $this->assertIsArray($forward);
        $this->assertSame('04', $forward['years']);
        $this->assertSame($forward, $backward);
    }

    /**
     * Regression: date-only formats took their time from the clock, separately for each date, so
     * the sub-day units depended on how long the two parses took (and on a second ticking over).
     */
    public function testGetDateDifferenceOfDateOnlyValuesHasNoSubDayNoise(): void {
        $this->assertSame(
            [
                'years' => '00',
                'months' => '00',
                'days' => '01',
                'hours' => '00',
                'minutes' => '00',
                'seconds' => '00',
                'milliseconds' => '000',
            ],
            DateTime::getDateDifference('2024-01-01', '2024-01-02')
        );
    }

    public function testGetDateDifferenceDocumentsTheKeyItActuallyReturns(): void {
        $diff = DateTime::getDateDifference('2020-01-01 00:00:00', '2024-03-05 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s');

        $this->assertIsArray($diff);
        $this->assertArrayHasKey('milliseconds', $diff);
        $this->assertArrayNotHasKey('miliseconds', $diff);
    }

    /**
     * '%U' is not a \DateInterval specifier: PHP echoes it back verbatim, so the milliseconds
     * field used to be the literal string '%U'.
     */
    public function testGetDateDifferenceReturnsRealMillisecondsTruncatedAndZeroPadded(): void {
        $diff = DateTime::getDateDifference(
            '2024-01-01 00:00:00.000000',
            '2024-01-01 00:00:00.123999',
            'Y-m-d H:i:s.u',
            'Y-m-d H:i:s.u'
        );
        $this->assertIsArray($diff);
        $this->assertSame('123', $diff['milliseconds']);

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
     * The difference used to be multiplied by 1e6: past ~292.000 years (a "never expires"
     * PHP_INT_MAX timestamp, which the parser accepts) it overflowed into a float and intdiv()
     * threw a TypeError. It saturates at PHP_INT_MAX now, and stays exact below that.
     */
    public function testGetDateDifferenceInSecondsSaturatesInsteadOfOverflowing(): void {
        $this->assertSame(PHP_INT_MAX - 1700000000, DateTime::getDateDifferenceInSeconds('1700000000', (string) PHP_INT_MAX, 'U', 'U'));
        $this->assertSame(PHP_INT_MAX - 1700000000, DateTime::getDateDifferenceInSeconds((string) PHP_INT_MAX, '1700000000', 'U', 'U'));
        $this->assertSame(9300000000000, DateTime::getDateDifferenceInSeconds('0', '9300000000000', 'U', 'U'));
        $this->assertSame(PHP_INT_MAX, DateTime::getDateDifferenceInSeconds('-1', (string) PHP_INT_MAX, 'U', 'U'), 'a difference past PHP_INT_MAX saturates');
    }

    public function testGetDateDifferenceInSecondsIsExactAcrossYears(): void {
        $this->assertSame(
            365 * 86400,
            DateTime::getDateDifferenceInSeconds('2023-01-01 00:00:00', '2024-01-01 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
        // 2024 is a leap year: the old 365-day approximation was a day short here.
        $this->assertSame(
            366 * 86400,
            DateTime::getDateDifferenceInSeconds('2024-01-01 00:00:00', '2025-01-01 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
    }

    /**
     * BREAKING: January has 31 days. The old implementation used 30-day months, so this read
     * 2,592,000 — a full day short.
     */
    public function testGetDateDifferenceInSecondsIsExactAcrossMonths(): void {
        $this->assertSame(
            31 * 86400,
            DateTime::getDateDifferenceInSeconds('2024-01-01 00:00:00', '2024-02-01 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
        $this->assertSame(
            29 * 86400,
            DateTime::getDateDifferenceInSeconds('2024-02-01', '2024-03-01', 'Y-m-d', 'Y-m-d')
        );
    }

    public function testGetDateDifferenceInSecondsSumsTheSmallerUnitsAndIsAbsolute(): void {
        $this->assertSame(
            3600,
            DateTime::getDateDifferenceInSeconds('2024-01-01 00:00:00', '2024-01-01 01:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
        $this->assertSame(
            86400 + 3600 + 60 + 1,
            DateTime::getDateDifferenceInSeconds('2024-01-02 01:01:01', '2024-01-01 00:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
    }

    /** Local times across the New York DST start are 4 real hours apart, not 5. */
    public function testGetDateDifferenceInSecondsCountsElapsedTimeAcrossDst(): void {
        date_default_timezone_set('America/New_York');
        $this->assertSame(
            4 * 3600,
            DateTime::getDateDifferenceInSeconds('2024-03-10 00:00:00', '2024-03-10 05:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );

        date_default_timezone_set('UTC');
        $this->assertSame(
            5 * 3600,
            DateTime::getDateDifferenceInSeconds('2024-03-10 00:00:00', '2024-03-10 05:00:00', 'Y-m-d H:i:s', 'Y-m-d H:i:s')
        );
    }

    public function testGetDateDifferenceInSecondsHonoursOffsetsCarriedByTheStrings(): void {
        $this->assertSame(
            3 * 3600,
            DateTime::getDateDifferenceInSeconds('2024-01-01T00:00:00+00:00', '2024-01-01T00:00:00-03:00', 'Y-m-d\TH:i:sP', 'Y-m-d\TH:i:sP')
        );
    }

    public function testGetDateDifferenceInSecondsRoundsTheSubSecondRemainder(): void {
        $this->assertSame(
            1,
            DateTime::getDateDifferenceInSeconds('2024-01-01 00:00:00.000000', '2024-01-01 00:00:00.600000', 'Y-m-d H:i:s.u', 'Y-m-d H:i:s.u')
        );
        $this->assertSame(
            0,
            DateTime::getDateDifferenceInSeconds('2024-01-01 00:00:00.000000', '2024-01-01 00:00:00.400000', 'Y-m-d H:i:s.u', 'Y-m-d H:i:s.u')
        );
        // Borrowing across the second boundary: 0.9s -> 1.1s is 0.2s, not 1.2s.
        $this->assertSame(
            0,
            DateTime::getDateDifferenceInSeconds('2024-01-01 00:00:00.900000', '2024-01-01 00:00:01.100000', 'Y-m-d H:i:s.u', 'Y-m-d H:i:s.u')
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
        $this->assertSame(3600, DateTime::timeToSeconds(' 01:00:00 '));
    }

    public function testTimeToSecondsTreatsInputAsDurationNotCappedAtOneDay(): void {
        $this->assertSame(360000, DateTime::timeToSeconds('100:00:00'));
        $this->assertSame(5400, DateTime::timeToSeconds('00:90:00'));
    }

    public function testTimeToSecondsTruncatesFractionalSecondsTail(): void {
        $this->assertSame(90, DateTime::timeToSeconds('00:01:30.999'));
    }

    /** Regression: the '-' used to negate only the hours, so "-01:30:00" came out as -1800. */
    public function testTimeToSecondsLeadingMinusNegatesTheWholeDuration(): void {
        $this->assertSame(-5400, DateTime::timeToSeconds('-01:30:00'));
        $this->assertSame(-1, DateTime::timeToSeconds('-00:00:01'));
    }

    public static function invalidTimeProvider(): array {
        return [
            'null'                  => [null],
            'empty'                 => [''],
            'two components'        => ['10:30'],
            'four components'       => ['01:02:03:04'],
            'letters'               => ['ab:cd:ef'],
            'empty components'      => ['::'],
            'one bad component'     => ['01:xx:03'],
            'placeholder'           => ['hh:mm:ss'],
            'inner sign'            => ['01:-30:00'],
            'plus sign'             => ['+01:00:00'],
            'exponent'              => ['01:1e3:00'],
            'decimal minutes'       => ['01:1.5:00'],
            'hex'                   => ['0x1A:00:00'],
            'inner space'           => ['01: 2:03'],
            'bare dot tail'         => ['00:00:05.'],
            'overflows an int'      => ['99999999999999999999:00:00'],
            'trailing NUL'          => ["01:00:00\0"],
        ];
    }

    /**
     * is_numeric() used to let "1e3", "1.5" and " 2" through, and an int overflow escaped as a
     * TypeError from the int return type. All of these must give the documented 0.
     */
    #[DataProvider('invalidTimeProvider')]
    public function testTimeToSecondsReturnsZeroForInvalidInputWithoutThrowing(?string $time): void {
        $this->assertSame(0, DateTime::timeToSeconds($time));
    }

    // ---------------------------------------------------------------- secondsToTime

    public function testSecondsToTimeFormatsSeconds(): void {
        $this->assertSame('01:01:01', DateTime::secondsToTime(3661));
        $this->assertSame('01:01:01', DateTime::secondsToTime('3661'));
        $this->assertSame('01:01:01', DateTime::secondsToTime(' 3661 '));
        $this->assertSame('23:59:59', DateTime::secondsToTime(86399));
    }

    public function testSecondsToTimeReturnsZeroForNullBlankAndZero(): void {
        $this->assertSame('00:00:00', DateTime::secondsToTime(null));
        $this->assertSame('00:00:00', DateTime::secondsToTime(0));
        $this->assertSame('00:00:00', DateTime::secondsToTime('0'));
        $this->assertSame('00:00:00', DateTime::secondsToTime(''));
        $this->assertSame('00:00:00', DateTime::secondsToTime('   '));
    }

    /** Regression: this rendered a clock-of-day, so 25 hours came out as "01:00:00". */
    public function testSecondsToTimeDoesNotWrapAtTwentyFourHours(): void {
        $this->assertSame('24:00:00', DateTime::secondsToTime(86400));
        $this->assertSame('25:00:00', DateTime::secondsToTime(90000));
        $this->assertSame('100:00:00', DateTime::secondsToTime(360000));
    }

    /** Regression: every non-digit was stripped, so -5 became "00:00:05" and "12.5" 125 seconds. */
    public function testSecondsToTimeKeepsTheSignAndTruncatesDecimals(): void {
        $this->assertSame('-00:00:05', DateTime::secondsToTime(-5));
        $this->assertSame('-01:01:01', DateTime::secondsToTime('-3661'));
        $this->assertSame('00:00:12', DateTime::secondsToTime('12.5'));
        $this->assertSame('-00:00:12', DateTime::secondsToTime('-12.9'));
        $this->assertSame('00:16:40', DateTime::secondsToTime('1e3'));
    }

    public static function invalidSecondsProvider(): array {
        return [
            'letters'         => ['abc'],
            'unit suffix'     => ['1h30'],
            'hex'             => ['0x1A'],
            'too large'       => ['1e400'],
            'int min'         => [PHP_INT_MIN],
            'trailing NUL'    => ["5\0"],
        ];
    }

    #[DataProvider('invalidSecondsProvider')]
    public function testSecondsToTimeThrowsOnNonNumericOrOutOfRangeInput(int|string $seconds): void {
        $this->expectException(\InvalidArgumentException::class);
        DateTime::secondsToTime($seconds);
    }

    /** The two methods are now exact inverses, for durations over a day and negative ones too. */
    #[DataProvider('roundTripSecondsProvider')]
    public function testSecondsToTimeAndTimeToSecondsRoundTrip(int $seconds): void {
        $this->assertSame($seconds, DateTime::timeToSeconds(DateTime::secondsToTime($seconds)));
    }

    public static function roundTripSecondsProvider(): array {
        return [[0], [1], [59], [60], [3599], [3600], [86399], [86400], [90000], [360000], [-5], [-5400], [-90000]];
    }

    // ---------------------------------------------------------------- getGreetingPeriodCode

    #[DataProvider('greetingProvider')]
    public function testGetGreetingPeriodCodeHonoursTheDocumentedMinuteBoundaries(string $time, int $expected): void {
        $this->assertSame($expected, DateTime::getGreetingPeriodCode('UTC', self::at("2024-06-15T{$time}:00Z")));
    }

    /**
     * 12:00 exactly is still morning and 12:01 is afternoon: the code once read only the hour and
     * tested `<= 12`, so the whole 12:00-12:59 window was "Bom dia".
     */
    public static function greetingProvider(): array {
        return [
            'midnight'      => ['00:00', 1],
            'morning'       => ['09:00', 1],
            '11:59'         => ['11:59', 1],
            'noon exactly'  => ['12:00', 1],
            '12:01'         => ['12:01', 2],
            '12:59'         => ['12:59', 2],
            'afternoon'     => ['15:00', 2],
            '17:59'         => ['17:59', 2],
            '18:00'         => ['18:00', 3],
            '23:59'         => ['23:59', 3],
        ];
    }

    public function testGetGreetingPeriodCodeReadsTheTimeInTheRequestedTimezone(): void {
        $now = self::at('2024-06-15T15:00:00Z');

        $this->assertSame(2, DateTime::getGreetingPeriodCode('UTC', $now));
        $this->assertSame(1, DateTime::getGreetingPeriodCode('America/Sao_Paulo', $now)); // 12:00
        $this->assertSame(1, DateTime::getGreetingPeriodCode('Asia/Tokyo', $now));        // 00:00 on the 16th
        $this->assertSame(3, DateTime::getGreetingPeriodCode('Europe/Moscow', $now));     // 18:00
    }

    public function testGetGreetingPeriodCodeFallsBackToClassDefaultForInvalidTimezone(): void {
        DateTime::setDefaultTimezone('America/Sao_Paulo');
        $now = self::at('2024-06-15T23:00:00Z'); // 20:00 in São Paulo

        $this->assertSame(3, DateTime::getGreetingPeriodCode('Not/AZone', $now));
        $this->assertSame(3, DateTime::getGreetingPeriodCode(null, $now));
        $this->assertSame(3, DateTime::getGreetingPeriodCode("UTC\0", $now));
    }

    public function testGetGreetingPeriodCodeWithTheRealClockReturnsAValidCode(): void {
        $this->assertContains(DateTime::getGreetingPeriodCode('UTC'), [1, 2, 3]);
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

    /** Brazilian summer time of 2018/2019 made São Paulo UTC-2 in December 2018. */
    public function testConvertTimezoneAppliesHistoricalBrazilianDst(): void {
        $this->assertSame(
            '2018-12-01 14:00:00',
            DateTime::convertTimezone('2018-12-01 12:00:00', 'America/Sao_Paulo', 'UTC')
        );
    }

    public function testConvertTimezoneAppliesOutputFormat(): void {
        $this->assertSame(
            '01/01/2024 09:00',
            DateTime::convertTimezone('2024-01-01 12:00:00', 'UTC', 'America/Sao_Paulo', 'Y-m-d H:i:s', 'd/m/Y H:i')
        );
    }

    /**
     * Regression: without '!' createFromFormat() took the missing time from the clock, so the
     * converted calendar date depended on the hour the call ran (before/after 03:00 UTC here).
     */
    public function testConvertTimezoneOfADateOnlyValueIsDeterministic(): void {
        $this->assertSame('2023-12-31', DateTime::convertTimezone('2024-01-01', 'UTC', 'America/Sao_Paulo', 'Y-m-d'));
    }

    public function testConvertTimezoneRejectsAWallClockTimeSkippedByDst(): void {
        $this->assertNull(DateTime::convertTimezone('2024-03-10 02:30:00', 'America/New_York', 'UTC'));
        // The repeated hour at the DST end is read as its FIRST occurrence (EDT, UTC-4).
        $this->assertSame('2024-11-03 05:30:00', DateTime::convertTimezone('2024-11-03 01:30:00', 'America/New_York', 'UTC'));
    }

    public function testConvertTimezoneLetsAnOffsetInTheStringOverrideFromTimezone(): void {
        $this->assertSame(
            '2024-01-01 09:00:00 -03:00',
            DateTime::convertTimezone('2024-01-01 12:00:00 +00:00', 'Asia/Tokyo', 'America/Sao_Paulo', 'Y-m-d H:i:s P')
        );
    }

    public function testConvertTimezoneDefaultsToYmdHisAndIgnoresTheClassDefaultFormat(): void {
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
        $this->assertNull(DateTime::convertTimezone('2024-01-01 12:00:00', 'UTC', "UTC\0"));
        $this->assertNull(DateTime::convertTimezone('', 'UTC', 'America/Sao_Paulo'));
        $this->assertNull(DateTime::convertTimezone('2024-02-30 12:00:00', 'UTC', 'America/Sao_Paulo'));
        // Unpadded digits used to be accepted here while validateDate() rejected them.
        $this->assertNull(DateTime::convertTimezone('2024-1-5 01:02:03', 'UTC', 'UTC'));
    }

    // ---------------------------------------------------------------- class-wide doc contract

    /**
     * Named arguments make the documented parameter name part of the public API, so no @param may
     * name a parameter the signature does not have, and no parameter may go undocumented.
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

        $this->assertGreaterThan(20, $checked, 'Expected the whole public surface to be inspected.');
    }
}
