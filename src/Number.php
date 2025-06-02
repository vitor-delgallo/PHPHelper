<?php

namespace VD\PHPHelper;

class Number
{
    /**
     * Rounds a float using the specified rounding type and precision.
     *
     * @param float $value Value to be rounded
     * @param int $precision Number of decimal places
     * @param string $method Type of rounding: 'round', 'floor', or 'ceil'
     * @return float Rounded number
     *
     * @ref https://stackoverflow.com/questions/12277945/php-how-do-i-round-down-to-two-decimal-places
     */
    public static function roundDecimal(float $value, int $precision = 2, string $method = "round"): float {
        if (empty($value)) return 0;

        if (!in_array($method, ['round', 'floor', 'ceil'])) {
            $method = "round";
        }

        $factor = pow(10, $precision);
        return $method($value * $factor) / $factor;
    }

    /**
     * Generates a random decimal number between two values.
     *
     * @param float $min Minimum decimal value
     * @param float $max Maximum decimal value
     * @return float Random decimal number within the range
     *
     * @ref https://stackoverflow.com/questions/10419501/use-php-to-generate-random-decimal-beteween-two-decimals
     */
    public static function randomDecimal(float $min, float $max): float {
        if (!filter_var($min, FILTER_VALIDATE_FLOAT)) {
            $min = PHP_FLOAT_MIN;
        }
        if (!filter_var($max, FILTER_VALIDATE_FLOAT)) {
            $max = PHP_FLOAT_MAX;
        }

        $scale = pow(
            10,
            (float)max(
                strlen(substr(strrchr((string)$min, "."), 1)),
                strlen(substr(strrchr((string)$max, "."), 1))
            )
        );

        return rand($min * $scale, $max * $scale) / $scale;
    }

    /**
     * Checks if a given number is even.
     *
     * Returns true if the number is even or zero.
     *
     * @param int $number Number to be checked
     * @return bool True if the number is even, false otherwise
     */
    public static function isEven(int $number): bool {
        return $number % 2 === 0;
    }

}