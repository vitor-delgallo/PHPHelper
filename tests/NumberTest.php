<?php

namespace VD\PHPHelper\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Number;

/**
 * Contract tests for VD\PHPHelper\Number.
 *
 * randomDecimal() is random, so nothing here asserts a literal draw. Every random assertion is an
 * invariant that must hold for EVERY draw (range, grid alignment) or a reachability claim over a
 * draw count large enough that a false negative is astronomically unlikely (a 2-value range missed
 * across 200 draws is ~2^-199).
 */
final class NumberTest extends TestCase
{
    /** Number of draws used for invariant sweeps over randomDecimal(). */
    private const DRAWS = 300;

    /**
     * Asserts a float sits on the 10^$decimals grid, tolerating binary representation error.
     */
    private static function assertOnGrid(float $value, int $decimals, string $message = ''): void
    {
        $scaled = $value * (10 ** $decimals);
        self::assertLessThan(
            1e-9,
            abs($scaled - round($scaled)),
            $message !== '' ? $message : sprintf('%s is not on the 10^-%d grid', var_export($value, true), $decimals)
        );
    }

    // ---------------------------------------------------------------- roundDecimal(): happy path

    public function testRoundDecimalRoundsHalfUpAtTheDefaultPrecisionOfTwo(): void
    {
        $this->assertSame(2.68, Number::roundDecimal(2.675));
        $this->assertSame(2.68, Number::roundDecimal(2.675, 2));
        $this->assertSame(2.68, Number::roundDecimal(2.675, 2, 'round'));
        $this->assertSame(1.24, Number::roundDecimal(1.235));
    }

    public function testRoundDecimalHonoursPrecision(): void
    {
        $this->assertSame(3.1, Number::roundDecimal(3.14159, 1));
        $this->assertSame(3.142, Number::roundDecimal(3.14159, 3));
        $this->assertSame(3.0, Number::roundDecimal(3.14159, 0));
    }

    // ------------------------------------------- roundDecimal(): FINDING (medium) — floor/ceil at
    // ------------------------------------------- N decimals lost a cent to representation error

    /**
     * Pins the medium finding: floor()ing money to cents must not silently lose a cent.
     *
     * Before the fix these all came back one cent LOW (8.2 -> 8.19) because `8.2 * 100` is
     * 819.9999999999999 and floor() — unlike PHP's native round() — has no fuzz correction.
     */
    public function testFloorToTwoDecimalsDoesNotLoseACentToRepresentationError(): void
    {
        $this->assertSame(8.2, Number::roundDecimal(8.2, 2, 'floor'));
        $this->assertSame(0.29, Number::roundDecimal(0.29, 2, 'floor'));
        $this->assertSame(2.3, Number::roundDecimal(2.3, 2, 'floor'));
        $this->assertSame(1.15, Number::roundDecimal(1.15, 2, 'floor'));
        $this->assertSame(4.35, Number::roundDecimal(4.35, 2, 'floor'));
    }

    /**
     * Pins the symmetric half of the medium finding: ceil() gained a cent (0.07 -> 0.08).
     */
    public function testCeilToTwoDecimalsDoesNotGainACentToRepresentationError(): void
    {
        $this->assertSame(0.07, Number::roundDecimal(0.07, 2, 'ceil'));
        $this->assertSame(8.2, Number::roundDecimal(8.2, 2, 'ceil'));
        $this->assertSame(2.3, Number::roundDecimal(2.3, 2, 'ceil'));
    }

    /**
     * Guards the OTHER side of the fuzz correction: it must not round away genuine digits.
     * A real 0.2999 is still floored to 0.29 — the correction only absorbs ~1e-9 of noise.
     */
    public function testFloorStillTruncatesGenuineSubPrecisionDigits(): void
    {
        $this->assertSame(0.29, Number::roundDecimal(0.2999, 2, 'floor'));
        $this->assertSame(0.99, Number::roundDecimal(0.999, 2, 'floor'));
        $this->assertSame(8.19, Number::roundDecimal(8.1987, 2, 'floor'));
        // 0.2001 is genuinely above 0.20, so ceil must climb to the next cent.
        $this->assertSame(0.21, Number::roundDecimal(0.2001, 2, 'ceil'));
    }

    public function testFloorAndCeilWorkOnNegativeValues(): void
    {
        $this->assertSame(-8.2, Number::roundDecimal(-8.2, 2, 'floor'));
        $this->assertSame(-8.2, Number::roundDecimal(-8.2, 2, 'ceil'));
        // floor() moves away from zero for negatives, ceil() moves toward it.
        $this->assertSame(-0.3, Number::roundDecimal(-0.2999, 2, 'floor'));
        $this->assertSame(-0.29, Number::roundDecimal(-0.2999, 2, 'ceil'));
    }

    // ------------------------------------------------------ roundDecimal(): documented edge cases

    public function testRoundDecimalShortCircuitsZeroToPositiveZero(): void
    {
        $this->assertSame(0.0, Number::roundDecimal(0.0));
        $this->assertSame(0.0, Number::roundDecimal(-0.0, 2, 'floor'));
        $this->assertSame(0.0, Number::roundDecimal(0.0, 5, 'ceil'));
    }

    /**
     * The docblock promises this silent fallback explicitly, so it is a contract, not an accident:
     * an unknown $method — including a wrong-case 'FLOOR' — is replaced with 'round'.
     */
    public function testRoundDecimalSilentlyFallsBackToRoundForAnUnknownMethod(): void
    {
        // 'floor' would give 8.19; the fallback rounds instead.
        $this->assertSame(8.2, Number::roundDecimal(8.199, 2, 'FLOOR'));
        $this->assertSame(8.19, Number::roundDecimal(8.199, 2, 'floor'));

        $this->assertSame(8.2, Number::roundDecimal(8.199, 2, 'trunc'));
        $this->assertSame(8.2, Number::roundDecimal(8.199, 2, ''));
    }

    public function testRoundDecimalAcceptsNegativePrecisionToRoundToTensAndHundreds(): void
    {
        $this->assertSame(8200.0, Number::roundDecimal(8250.0, -2, 'floor'));
        $this->assertSame(8300.0, Number::roundDecimal(8250.0, -2, 'ceil'));
        $this->assertSame(8250.0, Number::roundDecimal(8253.0, -1, 'floor'));
    }

    public function testRoundDecimalPropagatesNonFiniteInput(): void
    {
        $this->assertNan(Number::roundDecimal(NAN));
        $this->assertInfinite(Number::roundDecimal(INF));
        $this->assertInfinite(Number::roundDecimal(-INF, 2, 'floor'));
    }

    // ---------------------------------- randomDecimal(): FINDING (high) — a 0.0 bound was fatal

    /**
     * Pins the high finding. `filter_var(0.0, FILTER_VALIDATE_FLOAT)` returns a FALSY float(0), so
     * the old `!filter_var(...)` guard treated the perfectly valid bound 0.0 as invalid and swapped
     * in PHP_FLOAT_MIN/PHP_FLOAT_MAX — sentinels rand() cannot accept. Before the fix this call
     * raised an uncaught `TypeError: rand(): Argument #2 ($max) must be of type int, float given`.
     */
    public function testRandomDecimalAcceptsZeroAsTheLowerBound(): void
    {
        for ($i = 0; $i < self::DRAWS; $i++) {
            $value = Number::randomDecimal(0.0, 100.0);
            $this->assertGreaterThanOrEqual(0.0, $value);
            $this->assertLessThanOrEqual(100.0, $value);
        }
    }

    /**
     * The $max = 0.0 half of the high finding: the old guard swapped 0.0 for PHP_FLOAT_MAX, whose
     * scaled form overflowed to INF and fatalled on every single call.
     */
    public function testRandomDecimalAcceptsZeroAsTheUpperBound(): void
    {
        for ($i = 0; $i < self::DRAWS; $i++) {
            $value = Number::randomDecimal(-5.5, 0.0);
            $this->assertGreaterThanOrEqual(-5.5, $value);
            $this->assertLessThanOrEqual(0.0, $value);
        }
    }

    /**
     * The quieter half of the high finding: randomDecimal(0.0, 1.0) did not fatal, it emitted
     * `Deprecated: Implicit conversion from float 2.2250738585072014E-290 to int loses precision`
     * on EVERY call — which a framework that promotes notices to exceptions turns into a throw the
     * caller never saw documented. phpunit.xml sets failOnDeprecation=true, so this test fails if
     * the deprecation ever comes back.
     */
    public function testRandomDecimalWithAZeroBoundEmitsNoDeprecation(): void
    {
        for ($i = 0; $i < self::DRAWS; $i++) {
            $value = Number::randomDecimal(0.0, 1.0);
            $this->assertContains($value, [0.0, 1.0]);
        }
    }

    public function testRandomDecimalWithIdenticalBoundsReturnsThatBound(): void
    {
        $this->assertSame(0.0, Number::randomDecimal(0.0, 0.0));
        $this->assertSame(2.5, Number::randomDecimal(2.5, 2.5));
    }

    // ------------------------- randomDecimal(): FINDING (medium) — precision derived from strings

    /**
     * Pins the documented (and genuinely surprising) default: with $decimals = null the precision
     * is derived from the string form of the bounds, and (string)1.0 === "1" has no decimal digits
     * at all — so whole-valued bounds draw WHOLE NUMBERS. The docblock now says this outright
     * instead of promising a "random decimal number"; this test is what keeps the doc honest.
     */
    public function testRandomDecimalDerivesZeroDecimalsFromWholeBoundsAndReturnsIntegers(): void
    {
        for ($i = 0; $i < self::DRAWS; $i++) {
            $value = Number::randomDecimal(1.0, 10.0);
            $this->assertSame(floor($value), $value, 'derived precision must yield whole numbers');
            $this->assertGreaterThanOrEqual(1.0, $value);
            $this->assertLessThanOrEqual(10.0, $value);
        }
    }

    /**
     * The documented quantisation: "0.5" contributes one decimal digit, "100" contributes none, so
     * the draw lands on 0.1 steps.
     */
    public function testRandomDecimalDerivesPrecisionFromTheMoreDetailedBound(): void
    {
        for ($i = 0; $i < self::DRAWS; $i++) {
            $value = Number::randomDecimal(0.5, 100.0);
            self::assertOnGrid($value, 1);
            $this->assertGreaterThanOrEqual(0.5, $value);
            $this->assertLessThanOrEqual(100.0, $value);
        }
    }

    /**
     * Pins the fix for the medium finding: $decimals lets a caller ask for the granularity they
     * actually want instead of silently inheriting a 10-value integer distribution. Before the fix
     * this parameter did not exist.
     */
    public function testRandomDecimalExplicitDecimalsMakesFractionalValuesReachable(): void
    {
        $sawFraction = false;

        for ($i = 0; $i < self::DRAWS; $i++) {
            $value = Number::randomDecimal(1.0, 10.0, 2);
            self::assertOnGrid($value, 2);
            $this->assertGreaterThanOrEqual(1.0, $value);
            $this->assertLessThanOrEqual(10.0, $value);
            if ($value !== floor($value)) {
                $sawFraction = true;
            }
        }

        // 901 grid points, only 10 of them whole: missing every fraction in 300 draws is ~1e-885.
        $this->assertTrue($sawFraction, 'explicit $decimals must make fractional values reachable');
    }

    public function testRandomDecimalReachesBothInclusiveBounds(): void
    {
        $seen = [];
        for ($i = 0; $i < 200; $i++) {
            $seen[(string)Number::randomDecimal(1.0, 2.0)] = true;
        }

        // Derived precision is 0, so the range is exactly {1, 2}; both must be reachable.
        $this->assertArrayHasKey('1', $seen);
        $this->assertArrayHasKey('2', $seen);
        $this->assertCount(2, $seen);
    }

    /**
     * The bounds are inclusive and the grid must never escape them, even when the bounds do not
     * scale to exact integers (0.29 * 100 is 28.999999999999996).
     */
    public function testRandomDecimalNeverLeavesTheRequestedRange(): void
    {
        foreach ([[0.29, 2.3], [-1.5, 1.5], [0.07, 0.09], [-10.25, -10.2]] as [$min, $max]) {
            for ($i = 0; $i < self::DRAWS; $i++) {
                $value = Number::randomDecimal($min, $max);
                $this->assertGreaterThanOrEqual($min, $value, "draw escaped below $min");
                $this->assertLessThanOrEqual($max, $value, "draw escaped above $max");
            }
        }
    }

    /**
     * Bounds small enough to stringify in exponent notation ("1.0E-20") must still draw in range.
     * Counting the characters after the '.' naively gives 5 for "1.0E-20", which collapses the grid
     * to whole numbers and returns 0.0 — outside the requested range.
     */
    public function testRandomDecimalHandlesBoundsInExponentNotation(): void
    {
        for ($i = 0; $i < self::DRAWS; $i++) {
            $value = Number::randomDecimal(1.0E-20, 1.0E-19);
            $this->assertGreaterThanOrEqual(1.0E-20, $value);
            $this->assertLessThanOrEqual(1.0E-19, $value);
        }
    }

    // ------------------------------------------------------- randomDecimal(): denied/error paths

    public function testRandomDecimalRejectsNanLowerBound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be finite');
        Number::randomDecimal(NAN, 1.0);
    }

    public function testRandomDecimalRejectsInfiniteUpperBound(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('must be finite');
        Number::randomDecimal(1.0, INF);
    }

    /**
     * rand() silently SWAPS reversed bounds, which would quietly hand back a value from a range the
     * caller never asked for. Reversed bounds are a caller bug and are rejected.
     */
    public function testRandomDecimalRejectsReversedBounds(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$min must be less than or equal to $max');
        Number::randomDecimal(5.0, 1.0);
    }

    public function testRandomDecimalRejectsNegativeDecimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('$decimals must be greater than or equal to 0');
        Number::randomDecimal(1.0, 2.0, -1);
    }

    /**
     * There is no 1-decimal value inside [0.24, 0.25]. Rather than round a bound outward and return
     * something outside the range, the method refuses.
     */
    public function testRandomDecimalRejectsAGridWithNoValueInsideTheRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no value with 1 decimal place(s) exists');
        Number::randomDecimal(0.24, 0.25, 1);
    }

    public function testRandomDecimalRejectsDecimalsThatOverflowTheIntegerGrid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the integer range');
        Number::randomDecimal(1.0, 2.0, 400);
    }

    public function testRandomDecimalRejectsBoundsThatOverflowTheIntegerGrid(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('exceeds the integer range');
        Number::randomDecimal(1.0E25, 2.0E25, 3);
    }

    // ------------------------------------------------------------------------------- isEven()

    public function testIsEvenIdentifiesEvenNumbers(): void
    {
        $this->assertTrue(Number::isEven(2));
        $this->assertTrue(Number::isEven(4));
        $this->assertTrue(Number::isEven(100));
    }

    public function testIsEvenIdentifiesOddNumbers(): void
    {
        $this->assertFalse(Number::isEven(1));
        $this->assertFalse(Number::isEven(3));
        $this->assertFalse(Number::isEven(99));
    }

    public function testIsEvenTreatsZeroAsEven(): void
    {
        $this->assertTrue(Number::isEven(0));
    }

    /**
     * -3 % 2 is -1 in PHP, not 1, so a `% 2 === 1` implementation would call -3 even. The strict
     * `% 2 === 0` check is what keeps negatives correct.
     */
    public function testIsEvenHandlesNegativeNumbers(): void
    {
        $this->assertTrue(Number::isEven(-2));
        $this->assertTrue(Number::isEven(-4));
        $this->assertFalse(Number::isEven(-1));
        $this->assertFalse(Number::isEven(-3));
    }

    public function testIsEvenHandlesIntegerBoundaries(): void
    {
        $this->assertFalse(Number::isEven(PHP_INT_MAX));
        $this->assertTrue(Number::isEven(PHP_INT_MIN));
    }
}
