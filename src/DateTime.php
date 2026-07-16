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
     * Returns the default timezone. If it's not set, assigns a fallback value.
     *
     * @return string
     */
    public static function getDefaultTimezone(): string {
        if (empty(self::$defaultTimezone)) {
            self::setDefaultTimezone(date_default_timezone_get());
        }
        return self::$defaultTimezone;
    }

    /**
     * Sets the default timezone if it is valid.
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
     * @param string $timezone Timezone identifier to validate
     * @return bool True if valid, false otherwise
     */
    public static function isValidTimezone(string $timezone): bool {
        if (empty($timezone)) {
            return false;
        }

        try {
            new \DateTimeZone($timezone);
            return true;
        } catch (\Exception $e) {
            return false;
        }
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
     * @param string|null $date The date string to validate
     * @param string|null $format The expected date format
     * @return bool
     *
     * @ref https://stackoverflow.com/questions/19271381/correctly-determine-if-date-string-is-a-valid-date-in-that-format
     */
    public static function validateDate(?string $date, ?string $format = null): bool {
        if (empty($date)) return FALSE;

        if(empty($format)) {
            $format = self::getDefaultFormat();
        }
        $d = \DateTime::createFromFormat($format, $date);
        return $d && $d->format($format) === $date;
    }

    /**
     * Function getCurrentFormattedDate.
     * Returns the current date formatted according to the specified format and time zone.
     *
     * @param null|string $format The desired date format
     * @param null|string $timeZone The time zone to use
     * @return string|null
     *
     * @ref http://php.net/manual/en/function.date.php
     */
    public static function getCurrentFormattedDate(?string $format = null, ?string $timeZone = null): ?string {
        if(empty($format)) {
            $format = self::getDefaultFormat();
        }
        if(empty($timeZone)) {
            $timeZone = self::getDefaultTimezone();
        }

        $dt = null;
        try{
            $dt = new \DateTime('NOW', new \DateTimeZone($timeZone));
        } catch (\Exception $ignored) {}

        return !empty($dt) ? $dt->format($format) : null;
    }

    /**
     * Converts a timestamp into a formatted date string.
     *
     * The timestamp is rendered in PHP's current default timezone (date_default_timezone_get()),
     * NOT in this class's default timezone — setDefaultTimezone() has no effect here.
     *
     * @param int|null $timestamp The Unix timestamp to convert. Negative values (pre-1970) work.
     * @param string|null $format The output date format (Case null, use the class default)
     * @return string|null The formatted date, or null for a null timestamp — and also for
     *                     timestamp 0, since the guard is a falsy check: the Unix epoch itself
     *                     cannot be formatted through this method.
     */
    public static function convertTimestampToDate(?int $timestamp, ?string $format = null): ?string {
        if(empty($format)) {
            $format = self::getDefaultFormat();
        }
        if(
            empty($timestamp) ||
            empty($ret = date($format, $timestamp))
        ) {
            return null;
        }

        return $ret;
    }

    /**
     * Returns the weekday (in number) for a given date (1-7).
     *
     * @param string $date Date to evaluate
     * @param string|null $format Input date format
     * @return int|null Weekday (in number) or null if invalid
     *
     * @ref https://forum.imasters.com.br/topic/237012-descobrir-dia-da-semana/
     */
    public static function getWeekDay(string $date, ?string $format = null): ?int {
        if(empty($format)) {
            $format = self::getDefaultFormat();
        }
        if (!self::validateDate($date, $format)) {
            return null;
        }

        return (int) \DateTime::createFromFormat($format, $date)->format('w') + 1;
    }

    /**
     * Returns the month (in number) for a given date (1-12).
     *
     * @param string $date Date to evaluate
     * @param string|null $format Input date format
     * @return int|null Month (in number) or null if invalid
     */
    public static function getMonth(string $date, ?string $format = null): ?int {
        if(empty($format)) {
            $format = self::getDefaultFormat();
        }
        if (!self::validateDate($date, $format)) {
            return null;
        }

        return (int) \DateTime::createFromFormat($format, $date)->format('m');
    }

    /**
     * Converts a valid date from one format to another.
     *
     * @param string $date The date to be converted
     * @param string $toFormat Desired output format
     * @param string|null $fromFormat Input format of the date
     * @return string Converted date string or empty string if invalid
     *
     * @ref https://secure.php.net/manual/en/datetime.createfromformat.php
     */
    public static function convertDateToFormat(
        string $date,
        string $toFormat,
        ?string $fromFormat = null
    ): string {
        if(empty($fromFormat)) {
            $fromFormat = self::getDefaultFormat();
        }
        if (!self::validateDate($date, $fromFormat) || empty($toFormat)) {
            return "";
        }

        return \DateTime::createFromFormat($fromFormat, $date)->format($toFormat);
    }

    /**
     * Function toDate.
     * Converts a date string to a DateTime object.
     *
     * @param string $date Date string to convert
     * @param string|null $format Format of the date string
     * @return DateTime|null DateTime object or null if invalid
     */
    public static function toDate(string $date, ?string $format = null): ?\DateTime {
        if(empty($format)) {
            $format = self::getDefaultFormat();
        }
        if (!self::validateDate($date, $format)) {
            return null;
        }

        return \DateTime::createFromFormat($format, $date);
    }


    /**
     * Function dateDiffDays.
     * Calculates the difference in days between two dates.
     *
     * @param string $startDate Initial date string
     * @param string $endDate Final date string
     * @param string|null $startFormat Format of the initial date
     * @param string|null $endFormat Format of the final date
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
        if(empty($startFormat)) {
            $startFormat = self::getDefaultFormat();
        }
        if(empty($endFormat)) {
            $endFormat = self::getDefaultFormat();
        }
        $startDate = self::convertDateToFormat($startDate, 'Y-m-d', $startFormat);
        $endDate   = self::convertDateToFormat($endDate, 'Y-m-d', $endFormat);

        if (
            !self::validateDate($startDate, 'Y-m-d') ||
            !self::validateDate($endDate, 'Y-m-d')
        ) return false;

        $start = self::toDate($startDate, 'Y-m-d');
        $end   = self::toDate($endDate, 'Y-m-d');
        $diff = $end->diff($start)->format('%a');

        return abs((int) $diff);
    }

    /**
     * Converts a formatted date string to a long-form textual date in Brazilian Portuguese.
     *
     * The wording is relative to "now" ("hoje", "ontem", "amanhã", "do mês passado", ...), so the
     * timezone below is what decides which calendar day "now" falls on — it is NOT hardcoded to
     * Brazil. Pass 'America/Sao_Paulo' explicitly (or set it once via setDefaultTimezone()) if the
     * output must follow Brazilian local time; otherwise a server on UTC will call a 21:00 BRT
     * event "ontem".
     *
     * @param string $date Date string to be converted
     * @param string|null $fromFormat Input date format (Case null, use the class default)
     * @param bool $capitalizeFirst Whether to capitalize the first letter of the result
     * @param string|null $timeZone Timezone the "now" comparison runs in
     *                              (Case null, use the class default — see setDefaultTimezone(),
     *                              which itself falls back to date_default_timezone_get())
     *
     * @return string|null Formatted date string, or null if $date does not match $fromFormat
     */
    public static function dateFullTextPtBr(
        string $date,
        ?string $fromFormat = null,
        bool $capitalizeFirst = true,
        ?string $timeZone = null
    ): ?string {
        if(empty($fromFormat)) {
            $fromFormat = self::getDefaultFormat();
        }
        if(empty($timeZone)) {
            $timeZone = self::getDefaultTimezone();
        }
        if (!self::validateDate($date, $fromFormat)) {
            return null;
        }

        $splitDateToObject = function (string $dateFormatted) {
            $parts = explode(' ', $dateFormatted);
            $datePart = explode('-', $parts[0]);
            $timePart = explode(':', $parts[1]);

            return (object)[
                'day'        => str_pad($datePart[2], 2, "0", STR_PAD_LEFT),
                'month'      => str_pad($datePart[1], 2, "0", STR_PAD_LEFT),
                'year'       => str_pad($datePart[0], 4, "0", STR_PAD_LEFT),
                'hour'       => str_pad($timePart[0], 2, "0", STR_PAD_LEFT),
                'minute'     => str_pad($timePart[1], 2, "0", STR_PAD_LEFT),
                'second'     => str_pad($timePart[2], 2, "0", STR_PAD_LEFT),
                'weekDay'    => match (self::getWeekDay($parts[0], 'Y-m-d')) {
                    1 => 'domingo',
                    2 => 'segunda-feira',
                    3 => 'terça-feira',
                    4 => 'quarta-feira',
                    5 => 'quinta-feira',
                    6 => 'sexta-feira',
                    7 => 'sábado',
                    default => '',
                },
                'monthName'  => match (self::getMonth($parts[0], 'Y-m-d')) {
                    1 => 'janeiro',
                    2 => 'fevereiro',
                    3 => 'março',
                    4 => 'abril',
                    5 => 'maio',
                    6 => 'junho',
                    7 => 'julho',
                    8 => 'agosto',
                    9 => 'setembro',
                    10 => 'outubro',
                    11 => 'novembro',
                    12 => 'dezembro',
                    default => '',
                },
            ];
        };

        $date = self::convertDateToFormat($date, 'Y-m-d H:i:s', $fromFormat);
        $dateObj = $splitDateToObject($date);

        $now = self::getCurrentFormattedDate('Y-m-d H:i:s', $timeZone);
        $nowObj = $splitDateToObject($now);
        $splitDateToObject = null;

        $timeSuffix = "";
        if (Str::containsString($fromFormat, "H")) {
            $timeSuffix = ", às {$dateObj->hour}h";
            if (Str::containsString($fromFormat, "i")) {
                $timeSuffix .= " e {$dateObj->minute}min";
            }
        }

        $result = null;
        if (self::dateDiffDays($date, $now, 'Y-m-d H:i:s', 'Y-m-d H:i:s') === 1) {
            if (self::toDate($now, 'Y-m-d H:i:s') > self::toDate($date, 'Y-m-d H:i:s')) {
                $result = "ontem, {$dateObj->weekDay}";
            } else {
                $result = "amanhã, {$dateObj->weekDay}";
            }
        } elseif (self::dateDiffDays($date, $now, 'Y-m-d H:i:s', 'Y-m-d H:i:s') === 2) {
            if (self::toDate($now, 'Y-m-d H:i:s') > self::toDate($date, 'Y-m-d H:i:s')) {
                $result = "anteontem, {$dateObj->weekDay}";
            }
        }

        if (empty($result)) {
            if ($dateObj->year == $nowObj->year) {
                if ($dateObj->month == $nowObj->month) {
                    if ($dateObj->day == $nowObj->day) {
                        $result = "hoje, {$dateObj->weekDay}";
                    } else {
                        $result = "dia {$dateObj->day} deste mês, {$dateObj->weekDay}";
                    }
                } elseif ($dateObj->month == ($nowObj->month - 1)) {
                    $result = "{$dateObj->weekDay}, dia {$dateObj->day} do mês passado";
                } else {
                    $result = "{$dateObj->weekDay}, {$dateObj->day} de {$dateObj->monthName}";
                }
            } elseif ($dateObj->year == ($nowObj->year - 1)) {
                $result = "{$dateObj->weekDay}, {$dateObj->day} de {$dateObj->monthName} do ano passado";
            } else {
                $result = "{$dateObj->weekDay}, {$dateObj->day} de {$dateObj->monthName} de {$dateObj->year}";
            }
        }

        $result .= $timeSuffix;

        return $capitalizeFirst ? Str::mbUcFirst($result) : $result;
    }

    /**
     * Adjusts a date based on a specified interval, supporting addition or subtraction of time periods.
     *
     * @param string $intervalSpec Interval period for date modification (ISO 8601 format)
     *                              Prefix with 'P' for date components, 'T' for time, 'PT' for time only.
     *                              Examples:
     *                              - 'P3D' = 3 days
     *                              - 'PT2H30M' = 2 hours 30 minutes
     *                              - 'P1Y2M10DT2H30M' = 1 year, 2 months, 10 days, 2 hours, 30 minutes
     *
     * @param string|null $baseDate Date to modify; if null, uses the current datetime
     * @param bool $isAddition Whether to add (true) or subtract (false) the interval from the date
     * @param string|null $formatFrom Format of the input date (Case null, use the class default)
     * @param string|null $formatTo Format of the output date (Case null, use the class default)
     * @param string|null $timezone Timezone to use if date is null (Case null, use the class default)
     *
     * @return string|null The modified date as a string, or null on failure
     *
     * @ref https://www.php.net/manual/en/dateinterval.construct.php
     */
    public static function applyInterval(
        string $intervalSpec, ?string $baseDate = null, bool $isAddition = true,
        ?string $formatFrom = null, ?string $formatTo = null, ?string $timezone = null
    ): ?string {
        if(empty($formatFrom)) {
            $formatFrom = self::getDefaultFormat();
        }
        if(empty($formatTo)) {
            $formatTo = self::getDefaultFormat();
        }
        if(empty($timezone)) {
            $timezone = self::getDefaultTimezone();
        }

        try {
            if (empty($intervalSpec)) {
                throw new \Exception("Interval specification is required.");
            }

            if (empty($baseDate)) {
                $baseDate = self::getCurrentFormattedDate($formatFrom, $timezone);
            } elseif (!self::validateDate($baseDate, $formatFrom)) {
                throw new \Exception("Invalid date format.");
            }

            $dateObj = self::toDate($baseDate, $formatFrom);
            $intervalSpec = Str::removeExcessSpaces(Str::strToUpper($intervalSpec), false);

            $interval = new \DateInterval($intervalSpec);
            if ($isAddition) {
                $dateObj->add($interval);
            } else {
                $dateObj->sub($interval);
            }

            return $dateObj->format($formatTo);
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Generates an array of date strings between two dates at a given interval.
     *
     * The range is inclusive on both ends: the start date is always the first element, and the end
     * date is only included when the interval lands on it exactly. If the dates arrive reversed
     * they are swapped (together with their formats), so the result is always ascending.
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
        if(empty($formatStart)) {
            $formatStart = self::getDefaultFormat();
        }
        if(empty($formatEnd)) {
            $formatEnd = self::getDefaultFormat();
        }
        if(empty($outputFormat)) {
            $outputFormat = self::getDefaultFormat();
        }

        $dates = [];
        if (!self::validateDate($startDate, $formatStart) || !self::validateDate($endDate, $formatEnd)) {
            return $dates;
        }
        if (self::toDate($startDate, $formatStart) > self::toDate($endDate, $formatEnd)) {
            // The formats travel with their own date, otherwise the swapped-in date would be
            // parsed with the other one's format and fail to parse at all.
            [$startDate, $endDate, $formatStart, $formatEnd] =
                [$endDate, $startDate, $formatEnd, $formatStart];
        }

        // 'Y-m-d H:i:s' is the intermediate format produced right here, so it must also be the
        // format toDate() parses with — leaving it out makes toDate() fall back to the class
        // default format and return null for anything but 'Y-m-d H:i:s'.
        $current = self::toDate(self::convertDateToFormat($startDate, 'Y-m-d H:i:s', $formatStart), 'Y-m-d H:i:s');
        $end = self::toDate(self::convertDateToFormat($endDate, 'Y-m-d H:i:s', $formatEnd), 'Y-m-d H:i:s');
        if ($current === null || $end === null) {
            return $dates;
        }

        while ($current <= $end) {
            $dates[] = $current->format($outputFormat);

            $nextDate = self::applyInterval(
                $intervalSpec,
                $current->format('Y-m-d H:i:s'),
                true,
                'Y-m-d H:i:s',
                'Y-m-d H:i:s'
            );
            $next = $nextDate === null ? null : self::toDate($nextDate, 'Y-m-d H:i:s');
            if ($next === null || $next <= $current) {
                throw new \InvalidArgumentException(
                    "Interval specification '{$intervalSpec}' is invalid or does not advance the date."
                );
            }

            $current = $next;
        }

        return $dates;
    }

    /**
     * Calculates the age, in whole years, based on a birthdate string.
     *
     * @param string $birthDate The birth date to calculate from, in $format
     * @param string|null $format The format of the input date (Case null, use the class default).
     *                            NOTE: this is the parameter's real name — use `format:` for named
     *                            arguments, not `inputFormat:`.
     * @param string|null $timeZone The timezone the "today" comparison runs in
     *                              (Case null, use the class default)
     *
     * @return int The calculated age. Returns 0 — NOT null and NOT an exception — when $birthDate
     *             does not match $format, which is indistinguishable from a genuine age of 0:
     *             validate with validateDate() first if that difference matters. A future
     *             birthdate yields a negative number.
     *
     * @see https://www.paulocollares.com.br/programacao/5-funcoes-uteis-em-php/
     */
    public static function calculateAge(
        string $birthDate,
        ?string $format = null,
        ?string $timeZone = null
    ): int {
        if(empty($format)) {
            $format = self::getDefaultFormat();
        }
        if(empty($timeZone)) {
            $timeZone = self::getDefaultTimezone();
        }

        if (!self::validateDate($birthDate, $format)) {
            return 0;
        }

        $birthDate = self::convertDateToFormat($birthDate, 'Y-m-d', $format);
        $today = self::getCurrentFormattedDate('Y-m-d', $timeZone);

        [$birthYear, $birthMonth, $birthDay] = explode('-', $birthDate);
        [$todayYear, $todayMonth, $todayDay] = explode('-', $today);

        $age = $todayYear - $birthYear;

        if (
            (int) $birthMonth > (int) $todayMonth ||
            ((int) $birthMonth === (int) $todayMonth && (int) $birthDay > (int) $todayDay)
        ) {
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
     * WARNING: a $format without time fields (the default 'Y-m-d', say) makes createFromFormat()
     * fill the missing time from the current clock, so the sub-day units reflect "now", not zero.
     * Pass formats that carry the precision you intend to compare.
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
        if(empty($startFormat)) {
            $startFormat = self::getDefaultFormat();
        }
        if(empty($endFormat)) {
            $endFormat = self::getDefaultFormat();
        }

        $startDate = self::convertDateToFormat($startDate, 'Y-m-d H:i:s.u', $startFormat);
        $endDate   = self::convertDateToFormat($endDate, 'Y-m-d H:i:s.u', $endFormat);

        if (
            !self::validateDate($startDate, 'Y-m-d H:i:s.u') ||
            !self::validateDate($endDate, 'Y-m-d H:i:s.u')
        ) {
            return false;
        }

        $start = self::toDate($startDate, 'Y-m-d H:i:s.u');
        $end   = self::toDate($endDate, 'Y-m-d H:i:s.u');

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
     * Returns the difference between two dates in seconds (approximate, uses 30-day months and
     * 365-day years).
     *
     * APPROXIMATE is literal: the year/month components of the calendar difference are converted
     * with fixed 365-day years and 30-day months, so leap days and 28/31-day months are not
     * accounted for. Any span crossing a month or year boundary is therefore off by hours to days.
     * Do NOT use this for billing, expiry, or any exact-seconds guard — for an exact figure,
     * subtract two timestamps. The sub-second remainder is rounded to the nearest second.
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
        if(empty($startFormat)) {
            $startFormat = self::getDefaultFormat();
        }
        if(empty($endFormat)) {
            $endFormat = self::getDefaultFormat();
        }

        $diff = self::getDateDifference($startDate, $endDate, $startFormat, $endFormat);
        if (empty($diff)) {
            return PHP_INT_MAX;
        }

        $seconds = 0;
        $seconds += (!empty($diff['milliseconds']) && $diff['milliseconds'] >= 500 ? 1 : 0);
        $seconds += (!empty($diff['seconds']) ? ((int) $diff['seconds']) : 0);
        $seconds += (!empty($diff['minutes']) ? ((int) $diff['minutes']) * 60 : 0);
        $seconds += (!empty($diff['hours']) ? ((int) $diff['hours']) * 3600 : 0);
        $seconds += (!empty($diff['days']) ? ((int) $diff['days']) * 86400 : 0);
        $seconds += (!empty($diff['months']) ? ((int) $diff['months']) * 30 * 86400 : 0);
        // 365 days, per the documented contract. The stray '* 30' that used to sit here (copied
        // from the months line above) made every year 10,950 days: results were 30x too large.
        $seconds += (!empty($diff['years']) ? ((int) $diff['years']) * 365 * 86400 : 0);

        return $seconds;
    }

    /**
     * Converts a time string (HH:mm:ss) into its total equivalent in seconds.
     *
     * This reads a DURATION, not a clock time: components are not range-checked, so "100:00:00"
     * is a valid 360000 and "00:90:00" is 5400. A fractional-seconds tail is truncated
     * ("00:01:30.999" → 90). A leading '-' on a component makes that component subtract.
     *
     * @param string|null $timeString Time string in format "HH:mm:ss". Exactly three
     *                                colon-separated numeric components are required.
     * @return int Total seconds, or 0 if the format is invalid — null, empty, not three
     *             components, or any non-numeric component ("ab:cd:ef"). Never throws.
     *
     * @link https://stackoverflow.com/questions/2451165/function-for-converting-time-to-number-of-seconds
     */
    public static function timeToSeconds(?string $timeString): int {
        if ($timeString === null || $timeString === "") {
            return 0;
        }

        $parts = explode(':', $timeString);
        if(count($parts) !== 3) {
            return 0;
        }

        $parts[2] = substr($parts[2], 0, 2);

        // Guard the arithmetic below: in PHP 8 a non-numeric string operand raises a TypeError,
        // which would escape past the documented "0 if the format is invalid" contract.
        foreach ($parts as $part) {
            if (!is_numeric($part)) {
                return 0;
            }
        }

        return ((int) $parts[0] * 3600) + ((int) $parts[1] * 60) + ((int) $parts[2]);
    }

    /**
     * Converts a total number of seconds into a formatted time string (HH:mm:ss).
     *
     * This is a clock-of-day rendering, NOT a full duration renderer, and it is therefore not a
     * total inverse of timeToSeconds(): the hour field WRAPS at 24h (90000 → "01:00:00", not
     * "25:00:00"). Do not use it to display durations that may reach a day.
     *
     * The input is coerced by stripping every non-digit before use, so a sign or a decimal point
     * is silently swallowed: -5 → "00:00:05" and "12.5" → 125 seconds → "00:02:05". Pass a
     * non-negative integer count.
     *
     * @param int|string|null $seconds Number of seconds to convert (may be numeric string)
     * @return string Time string in format "HH:mm:ss"; "00:00:00" for null, empty, zero, or an
     *                input with no digits at all
     */
    public static function secondsToTime(int|string|null $seconds): string {
        $seconds = Str::onlyNumbers($seconds);
        if (empty($seconds)) {
            return "00:00:00";
        }

        return gmdate("H:i:s", (int) $seconds);
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
     * @return int Greeting period code (1 = morning, 2 = afternoon, 3 = evening)
     */
    public static function getGreetingPeriodCode(?string $timeZone = null): int {
        if (empty($timeZone) || !self::isValidTimezone($timeZone)) {
            $timeZone = self::getDefaultTimezone();
        }

        // Minutes are load-bearing: reading only "H" and testing `<= 12` swallowed the whole
        // 12:00-12:59 window into morning, an hour after the documented 12:01 boundary.
        $currentTime = (string) self::getCurrentFormattedDate("H:i", $timeZone);
        [$currentHour, $currentMinute] = array_map('intval', explode(':', $currentTime));

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
     * @return string|null The converted date, or null if $date does not parse cleanly under
     *                     $fromFormat (parse warnings count as failure) or if either timezone
     *                     identifier is invalid. Never throws.
     */
    public static function convertTimezone(
        string $date,
        string $fromTimezone,
        string $toTimezone,
        ?string $fromFormat = null,
        ?string $toFormat = null
    ): ?string {
        if (empty($fromFormat)) {
            $fromFormat = 'Y-m-d H:i:s';
        }
        if (empty($toFormat)) {
            $toFormat = $fromFormat;
        }

        if (
            empty($date) ||
            !self::isValidTimezone($fromTimezone) ||
            !self::isValidTimezone($toTimezone)
        ) {
            return null;
        }

        try {
            $dateObj = \DateTime::createFromFormat(
                $fromFormat,
                $date,
                new \DateTimeZone($fromTimezone)
            );

            $errors = \DateTime::getLastErrors();
            if (
                $dateObj === false ||
                (!empty($errors['warning_count'])) ||
                (!empty($errors['error_count']))
            ) {
                return null;
            }

            $dateObj->setTimezone(new \DateTimeZone($toTimezone));
            return $dateObj->format($toFormat);
        } catch (\Throwable $e) {
            return null;
        }
    }
}