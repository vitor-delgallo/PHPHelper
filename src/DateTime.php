<?php

namespace VD\PHPHelper;

class DateTime {
    /**
     * Stores the default timezone to be used in the application.
     *
     * @var string|null
     */
    private static ?string $defaultTimezone = null;

    /**
     * Stores the default datetime format used across the application.
     *
     * @var string|null
     */
    private static ?string $defaultFormat = null;

    /**
     * Unescaped format characters that give createFromFormat() a year. A format with none of them
     * is parsed against the leap year 2000 (see parseStrict()).
     */
    private const YEAR_TOKENS = 'YyXxU';

    /**
     * Returns the default timezone: the one set with setDefaultTimezone(), or — while none has
     * been set — PHP's CURRENT default (date_default_timezone_get()).
     *
     * The fallback is read on every call, never cached: a later date_default_timezone_set() is
     * followed until setDefaultTimezone() pins a value. (It used to be cached on first use, so a
     * bootstrap that touched this class before configuring PHP's timezone froze the wrong zone.)
     *
     * @return string
     */
    public static function getDefaultTimezone(): string {
        return self::$defaultTimezone ?? date_default_timezone_get();
    }

    /**
     * Sets the default timezone if it is valid.
     *
     * An invalid identifier is silently ignored: the previous default stays in place.
     *
     * @param string $defaultTimezone Timezone identifier (e.g., "America/Sao_Paulo")
     * @return void
     */
    public static function setDefaultTimezone(string $defaultTimezone): void {
        if (!self::isValidTimezone($defaultTimezone)) {
            return;
        }

        self::$defaultTimezone = $defaultTimezone;
    }

    /**
     * Checks if a given timezone string is valid.
     *
     * Accepts anything \DateTimeZone accepts: identifiers ("America/Sao_Paulo"), abbreviations
     * ("EST") and offsets ("+03:00"). Never throws — a NUL byte, which makes \DateTimeZone raise a
     * ValueError (an \Error, not an \Exception), is simply invalid.
     *
     * @param string $timezone Timezone identifier to validate
     * @return bool True if valid, false otherwise
     */
    public static function isValidTimezone(string $timezone): bool {
        return $timezone !== '' && self::createTimeZone($timezone) !== null;
    }

    /**
     * Returns the default datetime format. If it's not set, assigns a fallback value.
     *
     * @return string
     */
    public static function getDefaultFormat(): string {
        if (empty(self::$defaultFormat)) {
            self::setDefaultFormat("Y-m-d");
        }
        return self::$defaultFormat;
    }

    /**
     * Sets the default datetime format if it is valid.
     *
     * @param string $defaultFormat Date format (e.g., "Y-m-d")
     * @return void
     */
    public static function setDefaultFormat(string $defaultFormat): void {
        if (empty($defaultFormat)) {
            return;
        }

        self::$defaultFormat = $defaultFormat;
    }

    /**
     * Checks if the given date string is valid according to the specified format.
     *
     * The rules below are shared by EVERY method of this class that parses a date string:
     *
     *  - EXACT MATCH. The parsed date is re-formatted and compared with the input, so "2024-1-5"
     *    is not "Y-m-d" and overflow dates ("2024-02-30", "24:00:00") are rejected instead of being
     *    rolled over.
     *  - NOTHING IS TAKEN FROM THE CLOCK. Fields the format does not carry are fixed, not filled
     *    from "now" (PHP's default): time → 00:00:00, day → 1, month → January, and a format with
     *    no year at all is read in the leap year 2000, so "29/02" is a valid "d/m". The answer can
     *    therefore never change with the day it is asked on — "2024-02" used to be an invalid "Y-m"
     *    on the 30th and 31st of every month, because the current day was borrowed and overflowed.
     *  - LOCAL TIME. The string is read in PHP's default timezone (date_default_timezone_get()),
     *    unless it carries its own ('e', 'T', 'P', 'O', 'U'). A wall-clock time that does not
     *    exist there — the hour skipped at a DST start — is invalid. A date-only value on a day
     *    whose local midnight was skipped stays valid; its time becomes the first instant that
     *    exists (e.g. 01:00 on 2018-11-04 in America/Sao_Paulo).
     *  - The parse-only modifiers '!' and '|' are accepted; the wildcards '?', '*', '#' and '+'
     *    are not (the re-formatted value can never match the input).
     *
     * @param string|null $date The date string to validate
     * @param string|null $format The expected date format (Case null, use the class default)
     * @return bool
     *
     * @ref https://stackoverflow.com/questions/19271381/correctly-determine-if-date-string-is-a-valid-date-in-that-format
     */
    public static function validateDate(?string $date, ?string $format = null): bool {
        return self::parseStrict($date, self::resolveFormat($format)) !== null;
    }

    /**
     * Function getCurrentFormattedDate.
     * Returns the current date formatted according to the specified format and time zone.
     *
     * @param null|string $format The desired date format (Case null, use the class default)
     * @param null|string $timeZone The time zone to use (Case null, use the class default)
     * @return string|null The formatted date, or null if $timeZone is not a valid timezone
     *
     * @ref http://php.net/manual/en/function.date.php
     */
    public static function getCurrentFormattedDate(?string $format = null, ?string $timeZone = null): ?string {
        $zone = self::resolveTimeZone($timeZone);
        if ($zone === null) {
            return null;
        }

        return (new \DateTimeImmutable('now', $zone))->format(self::resolveFormat($format));
    }

    /**
     * Converts a timestamp into a formatted date string.
     *
     * BREAKING (vs. earlier versions): the timestamp is rendered in $timeZone, i.e. in this class's
     * default timezone when null — like every other method here — and no longer always in PHP's
     * default. The two only differ once setDefaultTimezone() has been called. The Unix epoch (0)
     * is a real timestamp and is formatted; it used to return null.
     *
     * @param int|null $timestamp The Unix timestamp to convert. Negative values (pre-1970) work.
     * @param string|null $format The output date format (Case null, use the class default)
     * @param string|null $timeZone Timezone the timestamp is rendered in (Case null, use the class default)
     * @return string|null The formatted date; null for a null timestamp or an invalid $timeZone.
     */
    public static function convertTimestampToDate(?int $timestamp, ?string $format = null, ?string $timeZone = null): ?string {
        $zone = self::resolveTimeZone($timeZone);
        if ($timestamp === null || $zone === null) {
            return null;
        }

        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone($zone)->format(self::resolveFormat($format));
    }

    /**
     * Returns the weekday (in number) for a given date: 1 = Sunday ... 7 = Saturday.
     *
     * Parsing follows the rules documented on validateDate().
     *
     * @param string $date Date to evaluate
     * @param string|null $format Input date format (Case null, use the class default)
     * @return int|null Weekday (in number) or null if invalid
     *
     * @ref https://forum.imasters.com.br/topic/237012-descobrir-dia-da-semana/
     */
    public static function getWeekDay(string $date, ?string $format = null): ?int {
        $parsed = self::parseStrict($date, self::resolveFormat($format));
        return $parsed === null ? null : (int) $parsed->format('w') + 1;
    }

    /**
     * Returns the month (in number) for a given date (1-12).
     *
     * Parsing follows the rules documented on validateDate().
     *
     * @param string $date Date to evaluate
     * @param string|null $format Input date format (Case null, use the class default)
     * @return int|null Month (in number) or null if invalid
     */
    public static function getMonth(string $date, ?string $format = null): ?int {
        $parsed = self::parseStrict($date, self::resolveFormat($format));
        return $parsed === null ? null : (int) $parsed->format('n');
    }

    /**
     * Converts a valid date from one format to another.
     *
     * Parsing follows the rules documented on validateDate(): in particular a field the input
     * format lacks comes out FIXED, not as the current clock — convertDateToFormat('2024-01-31',
     * 'Y-m-d H:i:s', 'Y-m-d') is '2024-01-31 00:00:00' (it used to carry the time of the call).
     *
     * @param string $date The date to be converted
     * @param string $toFormat Desired output format
     * @param string|null $fromFormat Input format of the date (Case null, use the class default)
     * @return string Converted date string or empty string if invalid
     *
     * @ref https://secure.php.net/manual/en/datetime.createfromformat.php
     */
    public static function convertDateToFormat(
        string $date,
        string $toFormat,
        ?string $fromFormat = null
    ): string {
        $parsed = self::parseStrict($date, self::resolveFormat($fromFormat));
        if ($parsed === null || $toFormat === '') {
            return "";
        }

        return $parsed->format($toFormat);
    }

    /**
     * Function toDate.
     * Converts a date string to a DateTime object.
     *
     * Parsing follows the rules documented on validateDate(). Every call returns a NEW mutable
     * \DateTime, in PHP's default timezone unless the string carried its own.
     *
     * @param string $date Date string to convert
     * @param string|null $format Format of the date string (Case null, use the class default)
     * @return \DateTime|null DateTime object or null if invalid
     */
    public static function toDate(string $date, ?string $format = null): ?\DateTime {
        return self::parseStrict($date, self::resolveFormat($format));
    }


    /**
     * Function dateDiffDays.
     * Calculates the difference in CALENDAR days between two dates.
     *
     * Only the calendar dates count: the time of day (if the formats carry one) is ignored, and
     * the count is computed on a DST-free calendar, so a daylight-saving change between the two
     * dates can never make a day go missing. The result is absolute.
     *
     * @param string $startDate Initial date string
     * @param string $endDate Final date string
     * @param string|null $startFormat Format of the initial date (Case null, use the class default)
     * @param string|null $endFormat Format of the final date (Case null, use the class default)
     *
     * @return int|false Number of days between the two dates, or FALSE on failure
     *
     * @ref https://stackoverflow.com/questions/676824/how-to-calculate-the-difference-between-two-dates-using-php
     */
    public static function dateDiffDays(
        string $startDate,
        string $endDate,
        ?string $startFormat = null,
        ?string $endFormat = null
    ): int|false {
        $start = self::parseStrict($startDate, self::resolveFormat($startFormat));
        $end = self::parseStrict($endDate, self::resolveFormat($endFormat));
        if ($start === null || $end === null) {
            return false;
        }

        return abs(self::calendarDayNumber($end) - self::calendarDayNumber($start));
    }

    /**
     * Converts a formatted date string to a long-form textual date in Brazilian Portuguese.
     *
     * The wording is relative to "now" ("hoje", "ontem", "amanhã", "anteontem", "do mês passado",
     * ...), and $timeZone is what decides which calendar day "now" falls on — it is NOT hardcoded
     * to Brazil. Pass 'America/Sao_Paulo' explicitly (or set it once via setDefaultTimezone()) if
     * the output must follow Brazilian local time; otherwise a server on UTC will call a 21:00 BRT
     * event "ontem".
     *
     * $date is read as a wall-clock time IN $timeZone. If $fromFormat carries its own offset
     * ('P', 'O', 'e', 'T', 'U'), the date is first converted into $timeZone.
     *
     * The time suffix (", às 14h e 30min") is appended only when $fromFormat carries an hour
     * (H, G, h, g or U); the minutes only when it also carries 'i' (or U).
     *
     * @param string $date Date string to be converted
     * @param string|null $fromFormat Input date format (Case null, use the class default)
     * @param bool $capitalizeFirst Whether to capitalize the first letter of the result
     * @param string|null $timeZone Timezone the "now" comparison runs in
     *                              (Case null, use the class default — see setDefaultTimezone(),
     *                              which itself falls back to date_default_timezone_get())
     * @param \DateTimeInterface|null $now The instant to treat as "now" (Case null, the real
     *                                     clock). Converted into $timeZone before use.
     *
     * @return string|null Formatted date string, or null if $date does not match $fromFormat or
     *                     $timeZone is not a valid timezone
     */
    public static function dateFullTextPtBr(
        string $date,
        ?string $fromFormat = null,
        bool $capitalizeFirst = true,
        ?string $timeZone = null,
        ?\DateTimeInterface $now = null
    ): ?string {
        $fromFormat = self::resolveFormat($fromFormat);
        $zone = self::resolveTimeZone($timeZone);
        if ($zone === null) {
            return null;
        }

        $parsed = self::parseStrict($date, $fromFormat, $zone);
        if ($parsed === null) {
            return null;
        }

        $target = \DateTimeImmutable::createFromMutable($parsed)->setTimezone($zone);
        $reference = self::referenceNow($now, $zone);

        $weekDays = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado'];
        $monthNames = [
            1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho',
            'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro',
        ];

        $weekDay = $weekDays[(int) $target->format('w')];
        $day = $target->format('d');
        $monthName = $monthNames[(int) $target->format('n')];
        $year = (int) $target->format('Y');
        $month = (int) $target->format('n');
        $referenceYear = (int) $reference->format('Y');
        $referenceMonth = (int) $reference->format('n');

        $timeSuffix = "";
        if (self::formatHasToken($fromFormat, 'GHghU')) {
            $timeSuffix = ", às {$target->format('H')}h";
            if (self::formatHasToken($fromFormat, 'iU')) {
                $timeSuffix .= " e {$target->format('i')}min";
            }
        }

        $daysFromNow = self::calendarDayNumber($target) - self::calendarDayNumber($reference);

        if ($daysFromNow === 0) {
            $result = "hoje, {$weekDay}";
        } elseif ($daysFromNow === -1) {
            $result = "ontem, {$weekDay}";
        } elseif ($daysFromNow === 1) {
            $result = "amanhã, {$weekDay}";
        } elseif ($daysFromNow === -2) {
            $result = "anteontem, {$weekDay}";
        } elseif ($year === $referenceYear) {
            if ($month === $referenceMonth) {
                $result = "dia {$day} deste mês, {$weekDay}";
            } elseif ($month === $referenceMonth - 1) {
                $result = "{$weekDay}, dia {$day} do mês passado";
            } else {
                $result = "{$weekDay}, {$day} de {$monthName}";
            }
        } elseif ($year === $referenceYear - 1) {
            $result = "{$weekDay}, {$day} de {$monthName} do ano passado";
        } else {
            $result = "{$weekDay}, {$day} de {$monthName} de {$target->format('Y')}";
        }

        $result .= $timeSuffix;

        return $capitalizeFirst
            ? mb_strtoupper(mb_substr($result, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($result, 1, null, 'UTF-8')
            : $result;
    }

    /**
     * Adjusts a date based on a specified interval, supporting addition or subtraction of time periods.
     *
     * MONTH ARITHMETIC CLAMPS instead of overflowing (BREAKING vs. PHP's own \DateTime::add()):
     * the year/month part of the interval is applied first and the day is clamped to the length
     * of the target month, then the day/time part is applied. So 2024-01-31 + P1M is 2024-02-29
     * (PHP says 2024-03-02), 2024-03-31 - P1M is 2024-02-29, and 2024-02-29 + P1Y is 2025-02-28.
     *
     * The date is interpreted — and the arithmetic done — in $timezone, so across a DST change a
     * time interval ('PT1H') is elapsed time while a date interval ('P1D') keeps the wall clock.
     *
     * @param string $intervalSpec Interval period for date modification (ISO 8601 format)
     *                              Prefix with 'P' for date components, 'T' for time, 'PT' for time only.
     *                              Case and whitespace are ignored.
     *                              Examples:
     *                              - 'P3D' = 3 days
     *                              - 'PT2H30M' = 2 hours 30 minutes
     *                              - 'P1Y2M10DT2H30M' = 1 year, 2 months, 10 days, 2 hours, 30 minutes
     *
     * @param string|null $baseDate Date to modify, in $formatFrom (parsed per the rules on
     *                              validateDate()); if null or "", the CURRENT INSTANT in
     *                              $timezone is used as-is ($formatFrom is then irrelevant)
     * @param bool $isAddition Whether to add (true) or subtract (false) the interval from the date
     * @param string|null $formatFrom Format of the input date (Case null, use the class default)
     * @param string|null $formatTo Format of the output date (Case null, use the class default)
     * @param string|null $timezone Timezone the date is interpreted in and "now" is read in
     *                              (Case null, use the class default)
     *
     * @return string|null The modified date as a string, or null on failure (invalid interval,
     *                     date or timezone). Never throws.
     *
     * @ref https://www.php.net/manual/en/dateinterval.construct.php
     */
    public static function applyInterval(
        string $intervalSpec, ?string $baseDate = null, bool $isAddition = true,
        ?string $formatFrom = null, ?string $formatTo = null, ?string $timezone = null
    ): ?string {
        $zone = self::resolveTimeZone($timezone);
        $interval = self::parseInterval($intervalSpec);
        if ($zone === null || $interval === null) {
            return null;
        }

        if ($baseDate === null || $baseDate === '') {
            $date = new \DateTime('now', $zone);
        } else {
            $date = self::parseStrict($baseDate, self::resolveFormat($formatFrom), $zone);
            if ($date === null) {
                return null;
            }
        }

        return self::shiftDate($date, $interval, $isAddition ? 1 : -1)->format(self::resolveFormat($formatTo));
    }

    /**
     * Generates an array of date strings between two dates at a given interval.
     *
     * The range is inclusive on both ends: the start date is always the first element, and the end
     * date is only included when the interval lands on it exactly. If the dates arrive reversed
     * they are swapped (together with their formats), so the result is always ascending.
     *
     * Element N is computed as start + N x interval — not by adding the interval to the previous
     * element — with the month clamping of applyInterval(). A monthly range from Jan 31 therefore
     * yields Jan 31, Feb 29, Mar 31, Apr 30... (cumulative PHP arithmetic yielded Jan 31, Mar 2,
     * Apr 2: February skipped, every later month drifted). It also keeps a DST gap from
     * accumulating: a daily range from a midnight that did not exist locally still lands on the
     * end date instead of stepping one hour past it and dropping it.
     *
     * Parsing follows the rules on validateDate(); the arithmetic runs in PHP's default timezone.
     *
     * NOTE: the list is built in memory. Its size is (end - start) / interval, so do not let an
     * untrusted caller pick a wide range together with a tiny interval.
     *
     * @param string $startDate      Starting date, in $formatStart
     * @param string $endDate        Ending date, in $formatEnd
     * @param string|null $formatStart    Format of the starting date (Case null, use the class default)
     * @param string|null $formatEnd      Format of the ending date (Case null, use the class default)
     * @param string $intervalSpec   Interval specification in ISO 8601 (e.g. 'P1D' for one day).
     *                               Must describe a strictly positive duration — see @throws.
     * @param string|null $outputFormat   Output format for the resulting dates (Case null, use the class default)
     *
     * @return array<int, string> Formatted date strings, ascending. Empty array when $startDate does
     *                            not match $formatStart or $endDate does not match $formatEnd.
     *
     * @throws \InvalidArgumentException If $intervalSpec is not a parseable ISO 8601 interval, or
     *                                   describes a zero-length duration (e.g. 'PT0S') — either case
     *                                   would otherwise never advance the cursor and hang the loop.
     */
    public static function getDateRangeList(
        string $startDate,
        string $endDate,
        ?string $formatStart = null,
        ?string $formatEnd = null,
        string $intervalSpec = 'P1D',
        ?string $outputFormat = null
    ): array {
        $outputFormat = self::resolveFormat($outputFormat);

        $start = self::parseStrict($startDate, self::resolveFormat($formatStart));
        $end = self::parseStrict($endDate, self::resolveFormat($formatEnd));
        if ($start === null || $end === null) {
            return [];
        }
        if ($start > $end) {
            [$start, $end] = [$end, $start];
        }

        $interval = self::parseInterval($intervalSpec);
        if ($interval === null || self::shiftDate($start, $interval, 1) <= $start) {
            throw new \InvalidArgumentException(
                "Interval specification '{$intervalSpec}' is invalid or does not advance the date."
            );
        }

        $dates = [];
        $current = $start;
        for ($step = 1; $current <= $end; $step++) {
            $dates[] = $current->format($outputFormat);

            $next = self::shiftDate($start, $interval, $step);
            if ($next <= $current) {
                // Cannot happen for a positive interval; guards the loop against ever spinning.
                throw new \InvalidArgumentException(
                    "Interval specification '{$intervalSpec}' does not advance the date."
                );
            }
            $current = $next;
        }

        return $dates;
    }

    /**
     * Calculates the age, in whole years, based on a birthdate string.
     *
     * The birthday is reached on the same month/day; someone born on Feb 29 turns a year older on
     * Mar 1 in non-leap years (the Brazilian Civil Code rule, art. 132 §3).
     *
     * @param string $birthDate The birth date to calculate from, in $format
     * @param string|null $format The format of the input date (Case null, use the class default).
     *                            NOTE: this is the parameter's real name — use `format:` for named
     *                            arguments, not `inputFormat:`.
     * @param string|null $timeZone The timezone the "today" comparison runs in
     *                              (Case null, use the class default)
     * @param \DateTimeInterface|null $now The instant to treat as "now" (Case null, the real
     *                                     clock). Converted into $timeZone before use.
     *
     * @return int|null The calculated age; a future birthdate yields a negative number.
     *                  NULL when $birthDate does not match $format or $timeZone is invalid
     *                  (BREAKING: it used to return 0, indistinguishable from a real age of 0).
     *
     * @see https://www.paulocollares.com.br/programacao/5-funcoes-uteis-em-php/
     */
    public static function calculateAge(
        string $birthDate,
        ?string $format = null,
        ?string $timeZone = null,
        ?\DateTimeInterface $now = null
    ): ?int {
        $zone = self::resolveTimeZone($timeZone);
        if ($zone === null) {
            return null;
        }

        $birth = self::parseStrict($birthDate, self::resolveFormat($format), $zone);
        if ($birth === null) {
            return null;
        }
        $birth->setTimezone($zone);
        $today = self::referenceNow($now, $zone);

        $age = (int) $today->format('Y') - (int) $birth->format('Y');
        if ((int) $today->format('md') < (int) $birth->format('md')) {
            // Birthday hasn't occurred yet this year
            $age--;
        }

        return $age;
    }

    /**
     * Returns the difference between two dates broken down by units.
     *
     * The breakdown is calendar-decomposed (\DateInterval), NOT a flat count: 'days' is the days
     * left over after whole years and months, so it never exceeds 30. The result is absolute —
     * the order of the two dates does not change it.
     *
     * Every value is a zero-padded numeric STRING ('04', not 4), because that is what
     * \DateInterval::format() emits. Cast before doing arithmetic on them.
     *
     * Parsing follows the rules on validateDate(): a format without time fields reads as midnight
     * (not as the current clock, which made two date-only values differ by however long the two
     * parses took). Both dates are read in PHP's default timezone, so across a DST change the
     * sub-day units follow PHP's \DateTime::diff() (elapsed time).
     *
     * @param string $startDate Start date string, in $startFormat
     * @param string $endDate End date string, in $endFormat
     * @param string|null $startFormat Format of the start date string (Case null, use the class default)
     * @param string|null $endFormat Format of the end date string (Case null, use the class default)
     *
     * @return array{
     *     years: string,
     *     months: string,
     *     days: string,
     *     hours: string,
     *     minutes: string,
     *     seconds: string,
     *     milliseconds: string
     * }|false Returns false if either date does not match its format. Note the key is
     *         'milliseconds' (two L's); it holds the sub-second remainder as a 3-digit string
     *         ('500'), truncated — not rounded — from the interval's microseconds.
     *
     * @see https://stackoverflow.com/questions/676824/how-to-calculate-the-difference-between-two-dates-using-php
     */
    public static function getDateDifference(
        string $startDate,
        string $endDate,
        ?string $startFormat = null,
        ?string $endFormat = null
    ): array|false {
        $start = self::parseStrict($startDate, self::resolveFormat($startFormat));
        $end = self::parseStrict($endDate, self::resolveFormat($endFormat));
        if ($start === null || $end === null) {
            return false;
        }

        // %F = microseconds, 6 digits. (%U is NOT a \DateInterval specifier: PHP echoes unknown
        // specifiers back verbatim, so it used to hand callers the literal string '%U'.)
        $formatted = $end->diff($start)->format('%Y-%M-%D-%H-%I-%S-%F');
        [$years, $months, $days, $hours, $minutes, $seconds, $microseconds] = explode('-', $formatted);

        return [
            'years' => $years,
            'months' => $months,
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds,
            'milliseconds' => str_pad((string) intdiv((int) $microseconds, 1000), 3, '0', STR_PAD_LEFT),
        ];
    }

    /**
     * Returns the EXACT elapsed time between two dates, in whole seconds.
     *
     * BREAKING: this used to be an approximation that converted the calendar breakdown with
     * 30-day months and 365-day years (2024-01-01 → 2024-02-01 came out as 30 days). It is now the
     * real difference between the two instants, so leap days, 28/31-day months and — for dates in
     * a DST timezone — the skipped or repeated hour are all accounted for.
     *
     * Both dates are parsed per the rules on validateDate(), in PHP's default timezone unless the
     * string carries its own offset. The sub-second remainder is rounded half-up.
     *
     * @param string $startDate Start date string, in $startFormat
     * @param string $endDate End date string, in $endFormat
     * @param string|null $startFormat Format of the start date string (Case null, use the class default)
     * @param string|null $endFormat Format of the end date string (Case null, use the class default)
     *
     * @return int Total difference in seconds, always >= 0 (the difference is absolute).
     *             Returns PHP_INT_MAX — not 0, not false — when either date does not match its
     *             format, so an unchecked comparison like `getDateDifferenceInSeconds(...) > $ttl`
     *             reads an invalid date as "infinitely old" rather than "brand new".
     */
    public static function getDateDifferenceInSeconds(
        string $startDate,
        string $endDate,
        ?string $startFormat = null,
        ?string $endFormat = null
    ): int {
        $start = self::parseStrict($startDate, self::resolveFormat($startFormat));
        $end = self::parseStrict($endDate, self::resolveFormat($endFormat));
        if ($start === null || $end === null) {
            return PHP_INT_MAX;
        }

        // Microsecond resolution fits comfortably in 64 bits for any year \DateTime can hold.
        $microseconds = abs(
            ($end->getTimestamp() - $start->getTimestamp()) * 1_000_000
            + ((int) $end->format('u') - (int) $start->format('u'))
        );

        return intdiv($microseconds + 500_000, 1_000_000);
    }

    /**
     * Converts a duration string (HH:mm:ss) into its total equivalent in seconds.
     *
     * This reads a DURATION, not a clock time: components are not range-checked, so "100:00:00"
     * is a valid 360000 and "00:90:00" is 5400. A fractional-seconds tail is truncated
     * ("00:01:30.999" → 90). Surrounding whitespace is ignored.
     *
     * A single leading '-' negates the WHOLE duration: "-01:30:00" is -5400, the exact inverse of
     * secondsToTime(-5400). (It used to negate only the hours, giving -1800.) A sign on any other
     * component is invalid.
     *
     * @param string|null $timeString Time string in format "HH:mm:ss". Exactly three
     *                                colon-separated components of ASCII digits are required.
     * @return int Total seconds, or 0 if the format is invalid — null, empty, not three
     *             components, anything but digits in a component ("ab:cd:ef", "01:1e3:00",
     *             "01:1.5:00"), or a value too large for an int. Never throws.
     *
     * @link https://stackoverflow.com/questions/2451165/function-for-converting-time-to-number-of-seconds
     */
    public static function timeToSeconds(?string $timeString): int {
        if ($timeString === null) {
            return 0;
        }

        // Every component is a plain digit run: is_numeric() used to let "1e3", "1.5" and " 1"
        // through, and a 3-digit seconds field was silently cut to its first two digits.
        if (preg_match('/^(-?)(\d+):(\d+):(\d+)(?:\.\d+)?$/D', trim($timeString, " \t\n\r\v\f"), $matches) !== 1) {
            return 0;
        }

        [, $sign, $hours, $minutes, $seconds] = $matches;

        // Bounded so the sum below stays an int (3.6e15 + 6e15 + 1e16 < PHP_INT_MAX); anything
        // larger would silently become a float and break the int return type.
        if (
            strlen(ltrim($hours, '0')) > 12 ||
            strlen(ltrim($minutes, '0')) > 14 ||
            strlen(ltrim($seconds, '0')) > 16
        ) {
            return 0;
        }

        $total = ((int) $hours * 3600) + ((int) $minutes * 60) + (int) $seconds;

        return $sign === '-' ? -$total : $total;
    }

    /**
     * Converts a number of seconds into a DURATION string ("HH:mm:ss"), the inverse of
     * timeToSeconds().
     *
     * BREAKING (vs. earlier versions, which rendered a clock-of-day and stripped every non-digit):
     *  - hours do NOT wrap at 24: 90000 → "25:00:00", 360000 → "100:00:00";
     *  - a negative value keeps its sign: -5 → "-00:00:05";
     *  - a decimal string is truncated toward zero, not digit-mashed: "12.5" → "00:00:12"
     *    (it used to become 125 seconds);
     *  - a string that is not numeric throws instead of silently rendering as zero.
     *
     * @param int|string|null $seconds Number of seconds to convert (may be a numeric string,
     *                                 surrounding whitespace ignored)
     * @return string Duration string, hours zero-padded to at least 2 digits; "00:00:00" for
     *                null or a blank string
     *
     * @throws \InvalidArgumentException If $seconds is a non-numeric string, or a value outside
     *                                   the int range.
     */
    public static function secondsToTime(int|string|null $seconds): string {
        if ($seconds === null) {
            return "00:00:00";
        }

        if (is_string($seconds)) {
            $trimmed = trim($seconds, " \t\n\r\v\f");
            if ($trimmed === '') {
                return "00:00:00";
            }

            $asInt = filter_var($trimmed, FILTER_VALIDATE_INT);
            if ($asInt !== false) {
                $seconds = $asInt;
            } elseif (is_numeric($trimmed) && abs((float) $trimmed) < PHP_INT_MAX) {
                $seconds = (int) (float) $trimmed;
            } else {
                throw new \InvalidArgumentException(
                    'DateTime::secondsToTime(): "' . $seconds . '" is not a number of seconds within the int range.'
                );
            }
        }

        if ($seconds === PHP_INT_MIN) {
            // -PHP_INT_MIN does not fit in an int.
            throw new \InvalidArgumentException('DateTime::secondsToTime(): PHP_INT_MIN is outside the supported range.');
        }

        $absolute = abs($seconds);

        return ($seconds < 0 ? '-' : '') . sprintf(
            '%02d:%02d:%02d',
            intdiv($absolute, 3600),
            intdiv($absolute % 3600, 60),
            $absolute % 60
        );
    }

    /**
     * Returns a numeric code representing the current greeting period based on time of day.
     *
     * Mapping (boundaries are inclusive, minute-precise):
     *  - 1 => Morning (Bom dia)      → 00:00 to 12:00
     *  - 2 => Afternoon (Boa tarde)  → 12:01 to 17:59
     *  - 3 => Evening (Boa noite)    → 18:00 and onward
     *
     * Noon exactly (12:00) is still morning; 12:01 is already afternoon.
     *
     * @param string|null $timeZone Timezone identifier the current time is read in.
     *                              (Case null, use the class default). An identifier that is not
     *                              valid also falls back to the class default rather than throwing.
     * @param \DateTimeInterface|null $now The instant to classify (Case null, the real clock).
     *                                     Converted into the timezone above before use.
     * @return int Greeting period code (1 = morning, 2 = afternoon, 3 = evening)
     */
    public static function getGreetingPeriodCode(?string $timeZone = null, ?\DateTimeInterface $now = null): int {
        $zone = self::resolveTimeZone($timeZone) ?? self::resolveTimeZone(null);

        // Minutes are load-bearing: reading only "H" and testing `<= 12` swallowed the whole
        // 12:00-12:59 window into morning, an hour after the documented 12:01 boundary.
        $moment = self::referenceNow($now, $zone);
        $currentHour = (int) $moment->format('G');
        $currentMinute = (int) $moment->format('i');

        if ($currentHour < 12 || ($currentHour === 12 && $currentMinute === 0)) {
            return 1; // Morning: 00:00 - 12:00
        } elseif ($currentHour < 18) {
            return 2; // Afternoon: 12:01 - 17:59
        }

        return 3; // Evening: 18:00 onward
    }

    /**
     * Converts a date string from one timezone to another, with optional format conversion.
     *
     * Parsing follows the rules on validateDate() — exact match, no field taken from the clock —
     * except that the naive string is read in $fromTimezone. A wall-clock time that does not exist
     * there (skipped by a DST start) is rejected; one that exists twice (repeated at a DST end) is
     * read as its FIRST occurrence. A string carrying its own offset ('P', 'O', 'e', 'T', 'U')
     * overrides $fromTimezone.
     *
     * @param string $date Date string to be converted, in $fromFormat
     * @param string $fromTimezone Timezone of the input date
     * @param string $toTimezone Timezone of the output date
     * @param string|null $fromFormat Format of the input date. Case null, defaults to
     *                                'Y-m-d H:i:s' — NOT the class default format. This method is
     *                                the exception in this class: setDefaultFormat() is ignored
     *                                here, so pass the format explicitly if the input is not
     *                                'Y-m-d H:i:s'.
     * @param string|null $toFormat Format of the output date (Case null, reuses $fromFormat)
     *
     * @return string|null The converted date, or null if $date does not match $fromFormat or if
     *                     either timezone identifier is invalid. Never throws.
     */
    public static function convertTimezone(
        string $date,
        string $fromTimezone,
        string $toTimezone,
        ?string $fromFormat = null,
        ?string $toFormat = null
    ): ?string {
        if ($fromFormat === null || $fromFormat === '') {
            $fromFormat = 'Y-m-d H:i:s';
        }
        if ($toFormat === null || $toFormat === '') {
            $toFormat = $fromFormat;
        }

        $fromZone = $fromTimezone === '' ? null : self::createTimeZone($fromTimezone);
        $toZone = $toTimezone === '' ? null : self::createTimeZone($toTimezone);
        if ($fromZone === null || $toZone === null) {
            return null;
        }

        $parsed = self::parseStrict($date, $fromFormat, $fromZone);
        if ($parsed === null) {
            return null;
        }

        return $parsed->setTimezone($toZone)->format($toFormat);
    }

    /**
     * The single parser behind every public method: see validateDate() for the rules.
     *
     * @param string|null $date The date string
     * @param string $format The createFromFormat() format
     * @param \DateTimeZone|null $timeZone Zone a naive string is read in (null: PHP's default)
     * @return \DateTime|null A NEW object, or null if $date does not match $format exactly
     */
    private static function parseStrict(?string $date, string $format, ?\DateTimeZone $timeZone = null): ?\DateTime {
        // createFromFormat() throws a ValueError (an \Error) on a NUL byte instead of failing.
        if ($date === null || $date === '' || $format === '' || str_contains($date, "\0") || str_contains($format, "\0")) {
            return null;
        }

        // The leading '!' resets every field the format does not set to the epoch instead of to
        // "now", which makes the caller's own '!'/'|' redundant — and a caller's '!' must go: it
        // would also wipe the anchor year below. A yearless format is anchored on 2000, a leap
        // year, so "29/02" can exist.
        $format = self::withoutParseModifiers($format);
        $hasYear = self::formatHasToken($format, self::YEAR_TOKENS);
        $parsed = \DateTime::createFromFormat(
            '!' . ($hasYear ? '' : 'Y-') . $format,
            ($hasYear ? '' : '2000-') . $date,
            $timeZone
        );
        if ($parsed === false) {
            return null;
        }

        $errors = \DateTime::getLastErrors();
        if ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return null;
        }

        return $parsed->format($format) === $date ? $parsed : null;
    }

    /**
     * Whether $format contains any of $tokens as an unescaped format character.
     */
    private static function formatHasToken(string $format, string $tokens): bool {
        $length = strlen($format);
        for ($i = 0; $i < $length; $i++) {
            if ($format[$i] === '\\') {
                $i++;
                continue;
            }
            if (str_contains($tokens, $format[$i])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Drops the unescaped parse-only modifiers '!' and '|', which format() would print literally.
     */
    private static function withoutParseModifiers(string $format): string {
        $result = '';
        $length = strlen($format);
        for ($i = 0; $i < $length; $i++) {
            if ($format[$i] === '\\') {
                $result .= substr($format, $i, 2);
                $i++;
                continue;
            }
            if ($format[$i] !== '!' && $format[$i] !== '|') {
                $result .= $format[$i];
            }
        }

        return $result;
    }

    private static function resolveFormat(?string $format): string {
        return ($format === null || $format === '') ? self::getDefaultFormat() : $format;
    }

    /**
     * @return \DateTimeZone|null The zone for $timeZone (the class default when null or ""), or
     *                            null if it is not a valid timezone.
     */
    private static function resolveTimeZone(?string $timeZone): ?\DateTimeZone {
        return self::createTimeZone(($timeZone === null || $timeZone === '') ? self::getDefaultTimezone() : $timeZone);
    }

    private static function createTimeZone(string $timeZone): ?\DateTimeZone {
        try {
            return new \DateTimeZone($timeZone);
        } catch (\Throwable) {
            // \Exception for an unknown zone, ValueError for a NUL byte.
            return null;
        }
    }

    private static function referenceNow(?\DateTimeInterface $now, \DateTimeZone $zone): \DateTimeImmutable {
        return $now === null
            ? new \DateTimeImmutable('now', $zone)
            : \DateTimeImmutable::createFromInterface($now)->setTimezone($zone);
    }

    /**
     * Days since a fixed origin for the CALENDAR date $date shows (its own wall clock), computed
     * on UTC so no DST transition can add or remove a day.
     */
    private static function calendarDayNumber(\DateTimeInterface $date): int {
        $utcMidnight = (new \DateTimeImmutable('@0'))->setDate(
            (int) $date->format('Y'),
            (int) $date->format('n'),
            (int) $date->format('j')
        );

        return intdiv($utcMidnight->getTimestamp(), 86400);
    }

    /**
     * Parses an ISO 8601 interval spec, ignoring case and whitespace.
     */
    private static function parseInterval(string $intervalSpec): ?\DateInterval {
        $intervalSpec = strtoupper(preg_replace('/\s+/', '', $intervalSpec));
        if ($intervalSpec === '') {
            return null;
        }

        try {
            return new \DateInterval($intervalSpec);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Returns a new date: $date moved by $interval x $factor ($factor may be negative).
     *
     * Years/months are applied first, with the day CLAMPED to the target month's length; the
     * day/time part is applied afterwards with PHP's own add()/sub().
     */
    private static function shiftDate(\DateTime $date, \DateInterval $interval, int $factor): \DateTime {
        $result = clone $date;

        $months = ($interval->y * 12 + $interval->m) * $factor;
        if ($months !== 0) {
            $monthIndex = (int) $result->format('Y') * 12 + (int) $result->format('n') - 1 + $months;
            $year = intdiv($monthIndex, 12) - ($monthIndex % 12 < 0 ? 1 : 0);
            $month = $monthIndex - $year * 12 + 1;

            $isLeap = ($year % 4 === 0 && $year % 100 !== 0) || $year % 400 === 0;
            $daysInMonth = match ($month) {
                2 => $isLeap ? 29 : 28,
                4, 6, 9, 11 => 30,
                default => 31,
            };

            $result->setDate($year, $month, min((int) $result->format('j'), $daysInMonth));
        }

        $steps = abs($factor);
        $rest = new \DateInterval('PT0S');
        $rest->d = $interval->d * $steps;
        $rest->h = $interval->h * $steps;
        $rest->i = $interval->i * $steps;
        $rest->s = $interval->s * $steps;

        return $factor < 0 ? $result->sub($rest) : $result->add($rest);
    }
}
