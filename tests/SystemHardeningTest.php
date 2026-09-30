<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\System;

/**
 * Regression tests for the System findings of the fifth review pass. The OS probe is exercised
 * through its pure parsing/assembly steps, so these run identically on every host — including
 * the ones where getServerMemoryUsage() itself can only return null (no wmic, no /proc).
 */
final class SystemHardeningTest extends TestCase {
    private string $originalMemoryLimit;

    protected function setUp(): void {
        $this->originalMemoryLimit = (string) ini_get('memory_limit');
        System::timer(null, 'clear');
    }

    protected function tearDown(): void {
        ini_set('memory_limit', $this->originalMemoryLimit);
        System::timer(null, 'clear');
        mt_srand();
    }

    private static function invokePrivate(string $method, mixed ...$args): mixed {
        return (new \ReflectionMethod(System::class, $method))->invoke(null, ...$args);
    }

    // ---------------------------------------------------------------------
    // convertBytesToReadable
    // ---------------------------------------------------------------------

    public static function boundaryProvider(): array {
        return [
            'one byte under 1 MB' => [1048575, '1 MB'],
            'one byte under 1 GB' => [1073741823, '1 GB'],
            'one byte under 1 TB' => [1099511627775, '1 TB'],
            'exact PB' => [1024 ** 5, '1 PB'],
            'exact EB' => [1024 ** 6, '1 EB'],
            'just under the rounding edge' => [1023 * 1024 + 1018, '1023.99 KB'],
            'rounds up to the next unit' => [1023 * 1024 + 1019, '1 MB'],
        ];
    }

    /**
     * The unit was chosen from log() BEFORE rounding, so 1048575 bytes read "1024 KB" — a value no
     * one writes — instead of "1 MB".
     */
    #[DataProvider('boundaryProvider')]
    public function testConvertBytesToReadableNeverShows1024OfAUnit(int $bytes, string $expected): void {
        $this->assertSame($expected, System::convertBytesToReadable($bytes));
    }

    // ---------------------------------------------------------------------
    // convertMemoryToBytes
    // ---------------------------------------------------------------------

    /**
     * A whole number went through a float, which cannot represent every integer above 2^53:
     * "9007199254740993" came back as ...992 — a partially-wrong number from a method that promises
     * "check === false and the value is trustworthy".
     */
    public function testConvertMemoryToBytesIsExactForLargeWholeNumbers(): void {
        $this->assertSame(9007199254740993, System::convertMemoryToBytes('9007199254740993'));
        $this->assertSame(PHP_INT_MAX - 1, System::convertMemoryToBytes((string) (PHP_INT_MAX - 1)));
        $this->assertSame(8796093022207 * 1024, System::convertMemoryToBytes('8796093022207k'));
    }

    public function testConvertMemoryToBytesStillRefusesWhatCannotBeAnInt(): void {
        $this->assertFalse(System::convertMemoryToBytes((string) PHP_INT_MAX));
        $this->assertFalse(System::convertMemoryToBytes('9223372036854775808'));
        $this->assertFalse(System::convertMemoryToBytes('8e'));
        $this->assertFalse(System::convertMemoryToBytes('1zb'));
    }

    public function testConvertMemoryToBytesHandlesZeroAndLeadingZeros(): void {
        $this->assertSame(0, System::convertMemoryToBytes('0zb'));
        $this->assertSame(0, System::convertMemoryToBytes('000'));
        $this->assertSame(7 * 1024, System::convertMemoryToBytes('007k'));
    }

    public function testConvertMemoryToBytesRejectsATrailingNewline(): void {
        // "\s*$" happily matched a trailing newline; "\z" is explicit about where input ends.
        $this->assertSame(512 * 1024 * 1024, System::convertMemoryToBytes("512M\n"), 'Trailing whitespace is documented as allowed.');
        $this->assertFalse(System::convertMemoryToBytes("512M\nX"));
    }

    // ---------------------------------------------------------------------
    // getServerMemoryUsage building blocks
    // ---------------------------------------------------------------------

    /**
     * stripos(PHP_OS, 'win') matched "Darwin" (and "CYGWIN_NT"): on macOS the wmic branch ran
     * `wmic … 2>NUL` through /bin/sh, which created a file named "NUL" in the working directory on
     * every call and never produced a number.
     */
    public function testOnlyWindowsTakesTheWmicProbe(): void {
        $this->assertTrue(self::invokePrivate('usesWindowsMemoryProbe', 'Windows'));
        foreach (['Darwin', 'Linux', 'BSD', 'Solaris', 'Unknown'] as $family) {
            $this->assertFalse(self::invokePrivate('usesWindowsMemoryProbe', $family), $family);
        }
    }

    /**
     * MemFree excludes the page cache, which Linux fills with idle RAM: on a long-running server it
     * is a small fraction of what can be allocated, and getMemoryUsage() caps freeBytes by it —
     * SQL batch sizing then shrank to a crawl. MemAvailable is the kernel's own "allocatable" figure.
     */
    public function testParseMeminfoPrefersMemAvailableOverMemFree(): void {
        $meminfo = "MemTotal:       16384000 kB\nMemFree:          512000 kB\nMemAvailable:   12288000 kB\nBuffers:          1024 kB\n";

        $this->assertSame(
            ['totalBytes' => 16384000 * 1024, 'freeBytes' => 12288000 * 1024],
            self::invokePrivate('parseMeminfo', $meminfo)
        );
    }

    public function testParseMeminfoFallsBackToMemFreeOnOldKernels(): void {
        $meminfo = "MemTotal:       16384000 kB\r\nMemFree:          512000 kB\r\n";

        $this->assertSame(
            ['totalBytes' => 16384000 * 1024, 'freeBytes' => 512000 * 1024],
            self::invokePrivate('parseMeminfo', $meminfo)
        );
    }

    public function testParseMeminfoLeavesMissingOrGarbledFiguresNull(): void {
        $this->assertSame(['totalBytes' => null, 'freeBytes' => null], self::invokePrivate('parseMeminfo', ''));
        $this->assertSame(
            ['totalBytes' => null, 'freeBytes' => 1024],
            self::invokePrivate('parseMeminfo', "MemTotal: lots kB\nMemFree: 1 kB\n")
        );
    }

    public function testParseWmicOutputReadsBytesAndKibibytes(): void {
        $this->assertSame(
            ['totalBytes' => 17034477568, 'freeBytes' => 8388608 * 1024],
            self::invokePrivate(
                'parseWmicOutput',
                ['TotalPhysicalMemory', '17034477568', ''],
                ['FreePhysicalMemory', '8388608  ', '']
            )
        );
        $this->assertSame(
            ['totalBytes' => null, 'freeBytes' => null],
            self::invokePrivate('parseWmicOutput', ["'wmic' is not recognized"], [])
        );
    }

    public function testBuildServerMemoryUsageComputesTheFreeShare(): void {
        $result = self::invokePrivate('buildServerMemoryUsage', 8 * 1024 ** 3, 2 * 1024 ** 3);

        $this->assertSame(
            ['totalBytes', 'freeBytes', 'usageBytes', 'total', 'usage', 'free', 'freePercent'],
            array_keys($result)
        );
        $this->assertSame(6 * 1024 ** 3, $result['usageBytes']);
        $this->assertSame('8 GB', $result['total']);
        $this->assertSame('6 GB', $result['usage']);
        $this->assertSame('2 GB', $result['free']);
        $this->assertSame(25.0, $result['freePercent'], 'freePercent is the FREE share, not the used one.');
    }

    public function testBuildServerMemoryUsageNeverReportsMoreFreeThanTotal(): void {
        $result = self::invokePrivate('buildServerMemoryUsage', 1000, 1500);

        $this->assertSame(1000, $result['freeBytes']);
        $this->assertSame(0, $result['usageBytes'], 'usage must never go negative.');
        $this->assertSame(100.0, $result['freePercent']);
    }

    public function testBuildServerMemoryUsageReturnsNullForUnusableFigures(): void {
        $this->assertNull(self::invokePrivate('buildServerMemoryUsage', null, 1));
        $this->assertNull(self::invokePrivate('buildServerMemoryUsage', 1, null));
        $this->assertNull(self::invokePrivate('buildServerMemoryUsage', 0, 0));
        $this->assertNull(self::invokePrivate('buildServerMemoryUsage', 10, -1));
    }

    // ---------------------------------------------------------------------
    // getMemoryUsage
    // ---------------------------------------------------------------------

    /**
     * memory_limit accepts every form PHP's own quantity parser does. A hex limit was unparseable
     * here and reported as NO limit — the physical total, or "unknown" — so SQL batch sizing ran
     * against the whole machine while PHP would fatal at 512 MB.
     */
    public function testGetMemoryUsageUnderstandsAHexMemoryLimit(): void {
        if (ini_set('memory_limit', '0x20000000') === false) {
            $this->markTestSkipped('This PHP build rejects a hexadecimal memory_limit.');
        }

        $result = System::getMemoryUsage();

        $this->assertSame(536870912, $result['totalBytes']);
        $this->assertSame('512 MB', $result['total']);
    }

    // ---------------------------------------------------------------------
    // makeSeed
    // ---------------------------------------------------------------------

    private static function sequenceFor(?string $seed): array {
        System::makeSeed($seed);
        return [rand(), rand(), rand()];
    }

    private static function sequenceForInt(int $seed): array {
        srand($seed);
        return [rand(), rand(), rand()];
    }

    /**
     * is_numeric() + (int) collapsed distinct seeds: "4.2" and "4.7" (both 4), "1e3" and "1000".
     */
    public function testMakeSeedKeepsDistinctNonIntegerStringsDistinct(): void {
        $this->assertNotSame(self::sequenceFor('4.2'), self::sequenceFor('4.7'));
        $this->assertNotSame(self::sequenceFor('1e3'), self::sequenceFor('1000'));
        $this->assertSame(self::sequenceForInt(crc32('4.2')), self::sequenceFor('4.2'));
    }

    public function testMakeSeedTakesCanonicalIntegerStringsNumerically(): void {
        $this->assertSame(self::sequenceForInt(42), self::sequenceFor('42'));
        $this->assertSame(self::sequenceForInt(42), self::sequenceFor('0042'));
        $this->assertSame(self::sequenceForInt(-7), self::sequenceFor('-7'));
        $this->assertSame(self::sequenceForInt(0), self::sequenceFor('-0'));
    }

    public function testMakeSeedHashesAnOutOfRangeIntegerInsteadOfSaturatingIt(): void {
        $this->assertNotSame(self::sequenceFor('99999999999999999999'), self::sequenceFor('99999999999999999998'));
    }

    // ---------------------------------------------------------------------
    // timer
    // ---------------------------------------------------------------------

    /**
     * empty() treated the name "0" as "no name": timer('0') was refused and timer('0', 'clear')
     * discarded EVERY timer.
     */
    public function testTimerAcceptsZeroAsAName(): void {
        System::timer('keep');
        $this->assertSame('0', System::timer('0')['timer']);
        $this->assertSame('0', System::timer('0', 'get')['timer']);
        $this->assertSame(['keep', '0'], array_column(System::timer(null, 'get'), 'timer'));

        System::timer('0', 'clear');

        $this->assertNull(System::timer('0', 'get'));
        $this->assertNotNull(System::timer('keep', 'get'), 'Clearing "0" must not clear every timer.');
    }
}
