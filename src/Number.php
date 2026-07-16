<?php

namespace VD\PHPHelper;

use InvalidArgumentException;
use Random\RandomException;

class Number
{
    /**
     * Rounds a float to a number of decimal places, using round-half-up, floor or ceil.
     *
     * Scaling a float by 10^$precision reintroduces binary representation error: 8.2 * 100 is
     * 819.9999999999999, not 820. PHP's native round() compensates internally, floor()/ceil() do
     * not — so a naive floor(8.2 * 100) / 100 yields 8.19. This method therefore snaps the scaled
     * value to 9 decimal places BEFORE applying $method. The practical consequence, which callers
     * doing money/tax arithmetic must know: a value within ~1e-9 of a grid point is treated as
     * being ON it, so this floors the decimal number the caller wrote, not the binary double
     * actually stored. roundDecimal(8.2, 2, 'floor') is 8.2. A genuine 0.2999 still floors to 0.29.
     *
     * Zero is NOT special-cased: it takes the same path as every other value and comes back as a
     * float. IEEE-754 signed zero is preserved, so -0.0 returns -0.0 — as does any negative value
     * that rounds to zero, e.g. roundDecimal(-0.004) — which is === and == equal to 0.0 but casts
     * to the string "-0". Callers formatting money must normalise the sign themselves if "-0.00"
     * is unacceptable; this method will not guess.
     *
     * @param float $value Value to be rounded. Non-finite input passes through: NAN returns NAN,
     *                     INF returns INF.
     * @param int $precision Number of decimal places. Defaults to 2. May be negative, which rounds
     *                       to tens/hundreds/... — and, being $method-dependent like every other
     *                       precision, $precision = -2 turns 8250.0 into 8300.0 under the default
     *                       'round' (82.5 is a tie, and ties go up), but into 8200.0 under 'floor'
     *                       and 8300.0 under 'ceil'.
     *                       MUST satisfy -323 <= $precision <= 308, the exact range over which
     *                       10**$precision is a finite, non-zero double. Outside it the arithmetic
     *                       cannot be carried out at all (see the throws clause), so it is rejected
     *                       rather than answered wrongly.
     * @param string $method Rounding mode. Exactly one of 'round', 'floor' or 'ceil',
     *                       case-sensitive. Anything else — including the wrong-case 'Floor' and
     *                       the plausible-but-unsupported 'trunc' — is REJECTED.
     * @return float Rounded number.
     *
     * @throws InvalidArgumentException If $method is not exactly 'round', 'floor' or 'ceil'; or if
     *                       $precision is outside [-323, 308]. Previously $precision >= 309 made
     *                       10**$precision INF and returned NAN for finite input, and
     *                       $precision <= -324 made it 0.0 and raised DivisionByZeroError — an
     *                       \Error, which the `catch (\Exception)` a caller would reasonably write
     *                       around this method cannot catch.
     *
     * @ref https://stackoverflow.com/questions/12277945/php-how-do-i-round-down-to-two-decimal-places
     */
    public static function roundDecimal(float $value, int $precision = 2, string $method = "round"): float {
        // A $method the caller got wrong is a caller bug, not a request to round. Silently
        // substituting 'round' turned roundDecimal($x, 2, 'FLOOR') into a wrong number that looked
        // right — the worst possible failure for money arithmetic. Rejecting is also what
        // randomDecimal() below does with bad arguments, so the class is consistent.
        if (!in_array($method, ['round', 'floor', 'ceil'], true)) {
            throw new InvalidArgumentException(
                "roundDecimal(): \$method must be exactly 'round', 'floor' or 'ceil' (case-sensitive); got "
                . var_export($method, true) . '.'
            );
        }

        $factor = pow(10, $precision);

        // Validate the FACTOR rather than hardcode a bound: this is exactly the set of $precision
        // values the arithmetic below can carry out, with no magic number to drift out of date.
        // 10**309 is INF, so INF/INF handed back NAN for perfectly finite input; 10**-324 is 0.0,
        // so the final division raised DivisionByZeroError — an \Error, undocumented and outside
        // the \Exception hierarchy a caller would think to catch. Both are caller bugs; say so.
        if (!is_finite((float)$factor) || (float)$factor === 0.0) {
            throw new InvalidArgumentException(
                'roundDecimal(): $precision must be between -323 and 308 (10**$precision must be a '
                . 'finite, non-zero double); got ' . $precision . '.'
            );
        }

        // round(..., 9) neutralises the scaling error before floor/ceil see it; it is a no-op for
        // the 'round' branch, which already corrects internally.
        return $method(round($value * $factor, 9)) / $factor;
    }

    /**
     * Generates a random decimal number between two values, inclusive of both bounds.
     *
     * The draw happens on a fixed grid of 10^$decimals steps. Every returned value satisfies
     * $min <= $value <= $max. That is enforced, not merely intended: both grid endpoints are
     * checked against the original bounds with the same division the return performs, so a bound
     * that the internal rounding tolerance would otherwise snap past yields either an in-range
     * value or an InvalidArgumentException — never an out-of-range draw.
     *
     * The value is derived from a single integer drawn uniformly over the WHOLE scaled range
     * [$min * 10^$decimals, $max * 10^$decimals] and then divided back down, so every grid point
     * in [$min, $max] is equally likely — including both bounds.
     *
     * The integer is drawn with random_int(), i.e. from the platform CSPRNG, so the result is not
     * predictable from previously observed output and is not biased by the modulo/scaling skew
     * rand() exhibits on wide ranges. Note that the return is still a float on a coarse grid:
     * for a secret, prefer random_bytes()/Str::generateUniqueKey() over quantised arithmetic.
     *
     * @param float $min Minimum value, inclusive. Must be finite and <= $max. 0.0 is valid.
     * @param float $max Maximum value, inclusive. Must be finite and >= $min. 0.0 is valid.
     * @param int|null $decimals Number of decimal places to draw at, >= 0. When null (default) it
     *                           is DERIVED from how many decimal digits $min and $max have once
     *                           cast to string, taking the larger of the two. This default is
     *                           surprising and is preserved only for convenience: whole-valued
     *                           bounds stringify without a '.' at all — (string)1.0 === "1" — so
     *                           randomDecimal(1.0, 10.0) derives 0 decimals and returns WHOLE
     *                           NUMBERS only, and randomDecimal(0.5, 100.0) derives 1 decimal and
     *                           is quantised to 0.1 steps. Pass $decimals explicitly whenever the
     *                           granularity matters: randomDecimal(1.0, 10.0, 2) draws on 0.01
     *                           steps.
     * @return float Random value on the 10^$decimals grid within [$min, $max].
     *
     * @throws InvalidArgumentException If $min or $max is NAN or INF; if $min > $max; if $decimals
     *                                  is negative; if the bounds scaled by 10^$decimals fall
     *                                  outside the integer range random_int() accepts; or if no
     *                                  value on the requested grid exists within [$min, $max] (e.g.
     *                                  randomDecimal(0.24, 0.25, 1) — there is no 1-decimal value
     *                                  in that interval).
     * @throws RandomException If the platform CSPRNG cannot produce randomness. This is not
     *                         caught and downgraded to a weaker generator: a caller asking for a
     *                         random number gets one or gets an exception, never a guessable
     *                         fallback.
     *
     * @ref https://stackoverflow.com/questions/10419501/use-php-to-generate-random-decimal-beteween-two-decimals
     */
    public static function randomDecimal(float $min, float $max, ?int $decimals = null): float {
        // Both parameters are already typed `float`, so the only non-numeric values that can reach
        // here are NAN and INF. They are rejected rather than replaced with a sentinel: the old
        // PHP_FLOAT_MIN/PHP_FLOAT_MAX fallbacks could never survive the random_int() call below,
        // which needs ints inside PHP_INT range.
        if (!is_finite($min) || !is_finite($max)) {
            throw new InvalidArgumentException('randomDecimal(): $min and $max must be finite; NAN and INF are rejected.');
        }
        if ($min > $max) {
            throw new InvalidArgumentException('randomDecimal(): $min must be less than or equal to $max.');
        }

        if ($decimals === null) {
            $decimals = max(self::decimalDigitsOf($min), self::decimalDigitsOf($max));
        } elseif ($decimals < 0) {
            throw new InvalidArgumentException('randomDecimal(): $decimals must be greater than or equal to 0.');
        }

        $scale = 10 ** $decimals;

        // Correct the scaling error (0.29 * 100 is 28.999999999999996) before quantising, then
        // round the low bound UP and the high bound DOWN so the grid never escapes [$min, $max].
        $lowScaled = ceil(round($min * $scale, 9));
        $highScaled = floor(round($max * $scale, 9));

        if (!is_finite($lowScaled) || !is_finite($highScaled)
            || $lowScaled < (float)PHP_INT_MIN || $highScaled >= (float)PHP_INT_MAX) {
            throw new InvalidArgumentException('randomDecimal(): [$min, $max] scaled by 10^' . $decimals . ' exceeds the integer range random_int() accepts; lower $decimals or narrow the range.');
        }

        $low = (int)$lowScaled;
        $high = (int)$highScaled;

        // The round(..., 9) above is a TOLERANCE, and a tolerance cuts both ways: it rescues
        // 0.29 * 100 = 28.999999999999996 into the grid point 29 the caller meant, but it also
        // snaps a bound that sits just INSIDE an integer to the far side of it, handing back a
        // value outside [$min, $max] and breaking the inclusive-range contract this method leads
        // with. randomDecimal(2.0000000001, 2.0000000001, 0) returned 2.0 — below $min — and
        // randomDecimal(0.0, 2.9999999999, 0) could return 3.0 — above $max.
        //
        // So verify the endpoints against the ORIGINAL bounds, using the very same division the
        // return statement performs, and pull each one in by a single grid step if the snap
        // overshot. One step always suffices: round($x, 9) moves $x by at most 5e-10 scaled units,
        // so the snapped endpoint is off the true one by at most 1. Correcting by exactly one step
        // (rather than looping) keeps this bounded — $high - $low can be ~1e18, and a loop over
        // that is a hang, not a fix. Anything still outside afterwards means the interval holds no
        // grid point at all, which is the same "no value exists" condition reported below.
        if ($low <= $high && $low / $scale < $min) {
            $low++;
        }
        if ($low <= $high && $high / $scale > $max) {
            $high--;
        }

        if ($low > $high || $low / $scale < $min || $high / $scale > $max) {
            throw new InvalidArgumentException('randomDecimal(): no value with ' . $decimals . ' decimal place(s) exists within [' . $min . ', ' . $max . '].');
        }

        // random_int() draws uniformly over the inclusive range with rejection sampling, so no grid
        // point is favoured however wide the range is — unlike rand(), which both skews on wide
        // ranges and lets an observer reconstruct the Mt19937 state and predict later draws.
        return random_int($low, $high) / $scale;
    }

    /**
     * Counts the decimal places in a float's string form, compensating for exponent notation.
     *
     * PHP stringifies small/large magnitudes as "1.0E-20", where naively counting the characters
     * after the '.' gives a meaningless answer. The exponent is subtracted so the count reflects
     * the actual position of the last significant decimal place.
     *
     * The count is bounded by the `precision` ini setting, which governs float-to-string casting;
     * it is NOT the mathematically exact decimal expansion of the double.
     *
     * @param float $value Finite value to inspect.
     * @return int Number of decimal places, >= 0. Whole-valued floats yield 0.
     */
    private static function decimalDigitsOf(float $value): int {
        $text = (string)$value;

        $exponent = 0;
        $exponentPos = stripos($text, 'E');
        if ($exponentPos !== false) {
            $exponent = (int)substr($text, $exponentPos + 1);
            $text = substr($text, 0, $exponentPos);
        }

        $dotPos = strpos($text, '.');
        $fractionDigits = $dotPos === false ? 0 : strlen($text) - $dotPos - 1;

        return max(0, $fractionDigits - $exponent);
    }

    /**
     * Checks if a given integer is even.
     *
     * Works for negative numbers (-4 is even, -3 is not) and for zero, which is even.
     *
     * @param int $number Number to be checked.
     * @return bool True if the number is divisible by 2, false otherwise. Never throws.
     */
    public static function isEven(int $number): bool {
        return $number % 2 === 0;
    }

}
