# DateTime Helper

Class: `VD\PHPHelper\DateTime`
Source file: `src/DateTime.php`

Use for date/time validation, formatting, and calculations. The class keeps static state for the default timezone and format.

## Methods

| Method | Use |
| --- | --- |
| `getDefaultTimezone()` | Returns the default timezone; if none exists, uses the current PHP timezone. |
| `setDefaultTimezone(string $defaultTimezone)` | Sets the default timezone when it is valid. |
| `isValidTimezone(string $timezone)` | Checks whether a string is accepted by `DateTimeZone`. |
| `getDefaultFormat()` | Returns the default format; current fallback is `Y-m-d`. |
| `setDefaultFormat(string $defaultFormat)` | Sets the default date/time format. |
| `validateDate(?string $date, ?string $format = null)` | Validates whether a date matches the given or default format. |
| `getCurrentFormattedDate(?string $format = null, ?string $timeZone = null)` | Returns the current date formatted in the given timezone. |
| `convertTimestampToDate(?int $timestamp, ?string $format = null)` | Converts a Unix timestamp into a formatted string. |
| `getWeekDay(string $date, ?string $format = null)` | Returns the numeric weekday from 1 to 7. |
| `getMonth(string $date, ?string $format = null)` | Returns the numeric month from 1 to 12. |
| `convertDateToFormat(string $date, string $toFormat, ?string $fromFormat = null)` | Converts a date string between formats. |
| `toDate(string $date, ?string $format = null)` | Converts a string to `\DateTime` or returns null. |
| `dateDiffDays(string $startDate, string $endDate, ?string $startFormat = null, ?string $endFormat = null)` | Calculates the day difference between two dates. |
| `dateFullTextPtBr(string $date, ?string $fromFormat = null, bool $capitalizeFirst = true, ?string $timeZone = null)` | Generates a full written date in pt-BR. |
| `applyInterval(string $intervalSpec, ?string $baseDate = null, bool $isAddition = true, ?string $formatFrom = null, ?string $formatTo = null, ?string $timezone = null)` | Adds or subtracts a `DateInterval` interval from a base date. |
| `getDateRangeList(string $startDate, string $endDate, ?string $formatStart = null, ?string $formatEnd = null, string $intervalSpec = 'P1D', ?string $outputFormat = null)` | Generates a list of dates between start and end. |
| `calculateAge(string $birthDate, ?string $format = null, ?string $timeZone = null)` | Calculates age from a birth date. |
| `getDateDifference(string $startDate, string $endDate, ?string $startFormat = null, ?string $endFormat = null)` | Returns a detailed difference between dates. |
| `getDateDifferenceInSeconds(string $startDate, string $endDate, ?string $startFormat = null, ?string $endFormat = null)` | Returns the difference in seconds. |
| `timeToSeconds(?string $timeString)` | Converts a time string to seconds. |
| `secondsToTime(int|string|null $seconds)` | Converts seconds to a time string. |
| `getGreetingPeriodCode(?string $timeZone = null)` | Returns a day-period code for greetings. |
| `convertTimezone(string $date, string $fromTimezone, string $toTimezone, ?string $fromFormat = null, ?string $toFormat = null)` | Converts a date between timezones and formats. |

## Cautions

- `setDefaultTimezone` changes static class state; be careful in long flows or tests.
- `validateDate` and conversion methods depend on exact formats.
- For intervals, use `DateInterval`-compatible specifications such as `P1D`.
- Before creating date helpers in the project, search here first.
