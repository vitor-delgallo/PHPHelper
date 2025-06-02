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
     * @param int|null $timestamp The timestamp to convert
     * @param string|null $format The output date format
     * @return string|null
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
     * @param string $date Date string to be converted
     * @param string|null $fromFormat Input date format
     * @param bool $capitalizeFirst Whether to capitalize the first letter of the result
     * @param string|null $timeZone Timezone used for comparison (default: America/Sao_Paulo)
     *
     * @return string|null Formatted date string or null if invalid
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
     * @param string $startDate      Starting date
     * @param string $endDate        Ending date
     * @param string|null $formatStart    Format of the starting date
     * @param string|null $formatEnd      Format of the ending date
     * @param string $intervalSpec   Interval specification in ISO 8601 (e.g. 'P1D' for one day)
     * @param string|null $outputFormat   Output format for the resulting dates
     *
     * @return array Array of formatted date strings
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
            [$startDate, $endDate] = [$endDate, $startDate];
        }

        $current = self::toDate(self::convertDateToFormat($startDate, 'Y-m-d H:i:s', $formatStart));
        $end = self::toDate(self::convertDateToFormat($endDate, 'Y-m-d H:i:s', $formatEnd));
        while ($current <= $end) {
            $dates[] = $current->format($outputFormat);
            $current = self::toDate(
                self::applyInterval(
                    $intervalSpec,
                    $current->format('Y-m-d H:i:s'),
                    true,
                    'Y-m-d H:i:s',
                    'Y-m-d H:i:s'
                )
            );
        }

        return $dates;
    }

    /**
     * Calculates the age based on a birthdate string.
     *
     * @param string $birthDate The birth date to calculate from
     * @param string|null $inputFormat The format of the input date
     * @param string|null $timeZone The timezone to use for "today" comparison
     * @return int The calculated age
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
     * @param string $startDate Start date string
     * @param string $endDate End date string
     * @param string|null $startFormat Format of the start date string
     * @param string|null $endFormat Format of the end date string
     *
     * @return array{
     *     years: string,
     *     months: string,
     *     days: string,
     *     hours: string,
     *     minutes: string,
     *     seconds: string,
     *     miliseconds: string
     * }|false Returns false if dates are invalid
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

        $formatted = $end->diff($start)->format('%Y-%M-%D-%H-%I-%S-%U');
        [$years, $months, $days, $hours, $minutes, $seconds, $milliseconds] = explode('-', $formatted);

        return [
            'years' => $years,
            'months' => $months,
            'days' => $days,
            'hours' => $hours,
            'minutes' => $minutes,
            'seconds' => $seconds,
            'milliseconds' => $milliseconds,
        ];
    }

    /**
     * Returns the difference between two dates in seconds (approximate, uses 30-day months and 365-day years).
     *
     * @param string $startDate Start date string
     * @param string $endDate End date string
     * @param string|null $startFormat Format of the start date string
     * @param string|null $endFormat Format of the end date string
     *
     * @return int Total difference in seconds (or PHP_INT_MAX on error)
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
        $seconds += (!empty($diff['years']) ? ((int) $diff['years']) * 365 * 30 * 86400 : 0);

        return $seconds;
    }

    /**
     * Converts a time string (HH:mm:ss) into its total equivalent in seconds.
     *
     * @param string|null $timeString Time string in format "HH:mm:ss"
     * @return int Total seconds, or 0 if the format is invalid
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
        return ($parts[0] * 3600) + ($parts[1] * 60) + ($parts[2] * 1);
    }

    /**
     * Converts a total number of seconds into a formatted time string (HH:mm:ss).
     *
     * @param int|string|null $seconds Number of seconds to convert (may be numeric string)
     * @return string Time string in format "HH:mm:ss"
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
     * Mapping:
     *  - 1 => Morning (Bom dia)      → 00:00 to 12:00
     *  - 2 => Afternoon (Boa tarde)  → 12:01 to 17:59
     *  - 3 => Evening (Boa noite)    → 18:00 and onward
     *
     * @param string|null $timeZone Timezone identifier
     * @return int Greeting period code (1 = morning, 2 = afternoon, 3 = evening)
     */
    public static function getGreetingPeriodCode(?string $timeZone = null): int {
        if(empty($timeZone)) {
            $timeZone = self::getDefaultTimezone();
        }
        $currentHour = (int) self::getCurrentFormattedDate("H", $timeZone);

        if ($currentHour >= 0 && $currentHour <= 12) {
            return 1; // Morning
        } elseif ($currentHour > 12 && $currentHour < 18) {
            return 2; // Afternoon
        }

        return 3; // Evening
    }
}