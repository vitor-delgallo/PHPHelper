<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Number;
use VD\PHPHelper\System;

final class SystemTest extends TestCase {
    private ?string $originalMemoryLimit = null;

    /** @var list<string> Absolute paths of temp files to remove in tearDown. */
    private array $tempFiles = [];

    protected function setUp(): void {
        $this->originalMemoryLimit = (string) ini_get('memory_limit');

        // timer() keeps a process-wide static store: isolate every test from the previous one.
        System::timer(null, 'clear');
    }

    protected function tearDown(): void {
        if ($this->originalMemoryLimit !== null) {
            ini_set('memory_limit', $this->originalMemoryLimit);
        }

        System::timer(null, 'clear');

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    // ---------------------------------------------------------------------
    // getMemoryUnitOrder()
    // ---------------------------------------------------------------------

    public function testGetMemoryUnitOrderReturnsBinaryUnitsFromSmallestToLargest(): void {
        $this->assertSame(
            ['b', 'kb', 'mb', 'gb', 'tb', 'pb', 'eb', 'zb', 'yb'],
            System::getMemoryUnitOrder()
        );
    }

    public function testGetMemoryUnitOrderFirstLettersAreUniqueSoShorthandIsUnambiguous(): void {
        $firstLetters = array_map(
            static fn (string $unit): string => $unit[0],
            System::getMemoryUnitOrder()
        );

        $this->assertSame($firstLetters, array_values(array_unique($firstLetters)));
    }

    // ---------------------------------------------------------------------
    // convertBytesToReadable()
    // ---------------------------------------------------------------------

    public static function readableBytesProvider(): array {
        return [
            'one byte'          => [1, '1 B'],
            'just under 1 KB'   => [1023, '1023 B'],
            'exactly 1 KB'      => [1024, '1 KB'],
            'one and a half KB' => [1536, '1.5 KB'],
            'exactly 1 MB'      => [1048576, '1 MB'],
            'exactly 1 GB'      => [1073741824, '1 GB'],
            'exactly 1 TB'      => [1099511627776, '1 TB'],
        ];
    }

    #[DataProvider('readableBytesProvider')]
    public function testConvertBytesToReadableFormatsEachMagnitude(int $bytes, string $expected): void {
        $this->assertSame($expected, System::convertBytesToReadable($bytes));
    }

    public function testConvertBytesToReadableReturnsZeroBytesForZeroAndNegative(): void {
        $this->assertSame('0 B', System::convertBytesToReadable(0));
        $this->assertSame('0 B', System::convertBytesToReadable(-1));
        $this->assertSame('0 B', System::convertBytesToReadable(PHP_INT_MIN));
    }

    public function testConvertBytesToReadableHandlesPhpIntMaxWithoutOverrunningTheUnitList(): void {
        // log(PHP_INT_MAX, 1024) is ~6.3, so the largest reachable unit index is 'eb'.
        $this->assertSame('8 EB', System::convertBytesToReadable(PHP_INT_MAX));
    }

    // ---------------------------------------------------------------------
    // convertMemoryToBytes()
    // ---------------------------------------------------------------------

    /**
     * Pins the documented contract that PHP's own shorthand ("512M", the form ini_get() returns for
     * memory_limit/upload_max_filesize) is understood. Before the fix these silently returned the
     * bare number — convertMemoryToBytes('512M') === 512 — so an upload guard rejected everything
     * over 512 BYTES while the documented `=== false` failure check happily passed.
     */
    public static function phpShorthandUnitProvider(): array {
        return [
            '512M' => ['512M', 536870912],
            '1G'   => ['1G', 1073741824],
            '128K' => ['128K', 131072],
            '2T'   => ['2T', 2199023255552],
            '8b'   => ['8b', 8],
            '4k'   => ['4k', 4096],
        ];
    }

    #[DataProvider('phpShorthandUnitProvider')]
    public function testConvertMemoryToBytesParsesPhpSingleLetterShorthand(string $input, int $expected): void {
        $this->assertSame($expected, System::convertMemoryToBytes($input));
    }

    public static function multiLetterUnitProvider(): array {
        return [
            '2GB'  => ['2GB', 2147483648],
            '512MB' => ['512MB', 536870912],
            '1KB'  => ['1KB', 1024],
            '3TB'  => ['3TB', 3298534883328],
            '10B'  => ['10B', 10],
        ];
    }

    #[DataProvider('multiLetterUnitProvider')]
    public function testConvertMemoryToBytesParsesMultiLetterUnits(string $input, int $expected): void {
        $this->assertSame($expected, System::convertMemoryToBytes($input));
    }

    public function testConvertMemoryToBytesTreatsBareNumberAsByteCount(): void {
        $this->assertSame(512, System::convertMemoryToBytes('512'));
        $this->assertSame(0, System::convertMemoryToBytes('0'));
    }

    public function testConvertMemoryToBytesIsCaseInsensitiveAndToleratesSurroundingWhitespace(): void {
        $this->assertSame(2147483648, System::convertMemoryToBytes('2gb'));
        $this->assertSame(2147483648, System::convertMemoryToBytes('2Gb'));
        $this->assertSame(2147483648, System::convertMemoryToBytes(' 2 GB '));
        // The /proc/meminfo form the library feeds itself.
        $this->assertSame(16777216, System::convertMemoryToBytes('16384 kb'));
    }

    public function testConvertMemoryToBytesFloorsFractionalResults(): void {
        $this->assertSame(1610612736, System::convertMemoryToBytes('1.5gb'));
        $this->assertSame(1536, System::convertMemoryToBytes('1.5kb'));
        $this->assertSame(1, System::convertMemoryToBytes('1.5b'));
    }

    /**
     * The docblock promises "false if the conversion failed". Every one of these used to return a
     * plausible int instead: '5x' -> 5, '-1' -> 1, '' -> 1.
     */
    public static function unparseableMemoryProvider(): array {
        return [
            'null'                 => [null],
            'empty string'         => [''],
            'whitespace only'      => ['   '],
            'unit without number'  => ['gb'],
            'unknown single unit'  => ['5x'],
            'unknown multi unit'   => ['5xy'],
            'iec unit'             => ['5kib'],
            'negative'             => ['-1'],
            'negative with unit'   => ['-512M'],
            'no digits at all'     => ['abc'],
            'two numbers'          => ['1 2 gb'],
            'trailing garbage'     => ['512M!'],
            'malformed decimal'    => ['1.5.2gb'],
        ];
    }

    #[DataProvider('unparseableMemoryProvider')]
    public function testConvertMemoryToBytesReturnsFalseForUnparseableInput(?string $input): void {
        $this->assertFalse(System::convertMemoryToBytes($input));
    }

    public function testConvertMemoryToBytesRefusesResultsThatCannotBeRepresentedAsInt(): void {
        // (int) on a float at/above 2**63 is undefined behaviour; false is the documented answer.
        $this->assertFalse(System::convertMemoryToBytes('9yb'));
        $this->assertFalse(System::convertMemoryToBytes('1zb'));
        // ...but a large value that still fits must convert.
        $this->assertSame(1152921504606846976, System::convertMemoryToBytes('1eb'));
    }

    public function testConvertMemoryToBytesNeverReturnsFloatOrNull(): void {
        $result = System::convertMemoryToBytes('1.5gb');

        $this->assertIsInt($result);
        $this->assertNotNull($result);
    }

    // ---------------------------------------------------------------------
    // isMemoryGreaterThan()
    // ---------------------------------------------------------------------

    /**
     * Before the fix this method called apenasNumeros()/apenasLetras()/my_strtolower(), which are
     * defined nowhere in the library: EVERY call with two non-empty arguments was an uncaught
     * \Error, so the documented `@return bool` could never be produced. This test would fatal.
     */
    public function testIsMemoryGreaterThanReturnsBoolInsteadOfFatalingOnUndefinedFunctions(): void {
        $this->assertTrue(System::isMemoryGreaterThan('512MB', '256MB'));
        $this->assertFalse(System::isMemoryGreaterThan('256MB', '512MB'));
    }

    /**
     * The old algorithm compared unit RANK first and only compared numbers within the same unit,
     * so "1GB" beat "2048MB" purely for being expressed in a bigger unit.
     */
    public function testIsMemoryGreaterThanComparesRealSizeAcrossDifferentUnits(): void {
        $this->assertFalse(System::isMemoryGreaterThan('1GB', '2048MB'));
        $this->assertTrue(System::isMemoryGreaterThan('2048MB', '1GB'));
        $this->assertTrue(System::isMemoryGreaterThan('1025MB', '1GB'));
        $this->assertFalse(System::isMemoryGreaterThan('1023MB', '1GB'));
    }

    public function testIsMemoryGreaterThanAcceptsPhpShorthandOnBothSides(): void {
        $this->assertTrue(System::isMemoryGreaterThan('512M', '256M'));
        $this->assertTrue(System::isMemoryGreaterThan('1G', '512MB'));
        $this->assertFalse(System::isMemoryGreaterThan('512M', '1G'));
    }

    public function testIsMemoryGreaterThanIsStrictSoEqualValuesAreNotGreater(): void {
        $this->assertFalse(System::isMemoryGreaterThan('512MB', '512MB'));
        // Same size, different notation.
        $this->assertFalse(System::isMemoryGreaterThan('1GB', '1024MB'));
        $this->assertFalse(System::isMemoryGreaterThan('1024MB', '1GB'));
    }

    public function testIsMemoryGreaterThanTreatsUnusableAAsNotGreater(): void {
        $this->assertFalse(System::isMemoryGreaterThan(null, '256MB'));
        $this->assertFalse(System::isMemoryGreaterThan('', '256MB'));
        $this->assertFalse(System::isMemoryGreaterThan('garbage', '256MB'));
        $this->assertFalse(System::isMemoryGreaterThan('5x', '256MB'));
        // Unusable A wins over unusable B: fail closed.
        $this->assertFalse(System::isMemoryGreaterThan(null, null));
    }

    public function testIsMemoryGreaterThanTreatsUnusableBAsNoCeiling(): void {
        $this->assertTrue(System::isMemoryGreaterThan('512MB', null));
        $this->assertTrue(System::isMemoryGreaterThan('512MB', ''));
        $this->assertTrue(System::isMemoryGreaterThan('512MB', 'garbage'));
    }

    // ---------------------------------------------------------------------
    // getServerMemoryUsage()
    // ---------------------------------------------------------------------

    public function testGetServerMemoryUsageReturnsNullOrAFullyConsistentArray(): void {
        $result = System::getServerMemoryUsage();

        if ($result === null) {
            // Documented and routine: no wmic (Windows 11 24H2+), unreadable /proc/meminfo, etc.
            $this->assertNull($result);
            return;
        }

        $this->assertSame(
            ['totalBytes', 'freeBytes', 'usageBytes', 'total', 'usage', 'free', 'freePercent'],
            array_keys($result)
        );
        $this->assertIsInt($result['totalBytes']);
        $this->assertIsInt($result['freeBytes']);
        $this->assertIsInt($result['usageBytes']);
        $this->assertIsString($result['total']);
        $this->assertIsString($result['usage']);
        $this->assertIsString($result['free']);
        $this->assertIsFloat($result['freePercent']);

        $this->assertGreaterThan(0, $result['totalBytes'], 'a non-null result must have a real total');
        $this->assertSame($result['totalBytes'] - $result['freeBytes'], $result['usageBytes']);
    }

    /**
     * Pins the field name against the formula: freePercent is free/total*100. The old code stored
     * 100 - free/total*100, i.e. the USED share, under a key named freePercent.
     */
    public function testGetServerMemoryUsageFreePercentIsTheFreeShareNotTheUsedShare(): void {
        $result = System::getServerMemoryUsage();

        if ($result === null) {
            $this->markTestSkipped('The OS did not expose physical memory numbers on this host.');
        }

        $expectedFreeShare = Number::roundDecimal(
            $result['freeBytes'] * 100 / $result['totalBytes'],
            2,
            'floor'
        );

        $this->assertSame($expectedFreeShare, $result['freePercent']);
        $this->assertGreaterThanOrEqual(0.0, $result['freePercent']);
        $this->assertLessThanOrEqual(100.0, $result['freePercent']);
    }

    // ---------------------------------------------------------------------
    // getMemoryUsage()
    // ---------------------------------------------------------------------

    /**
     * memory_limit=512M must be reported as 536870912 bytes. The old parser read '512M' as 512, so
     * this documented "total memory" reported a 512-BYTE machine on an utterly ordinary host.
     */
    public function testGetMemoryUsageReportsTheMemoryLimitAsTotalInBytes(): void {
        ini_set('memory_limit', '512M');

        $result = System::getMemoryUsage();

        $this->assertSame(536870912, $result['totalBytes']);
        $this->assertSame('512 MB', $result['total']);
    }

    public function testGetMemoryUsageReturnsTheDocumentedShape(): void {
        ini_set('memory_limit', '512M');

        $result = System::getMemoryUsage();

        $this->assertSame(
            ['usageBytes', 'usage', 'totalBytes', 'total', 'freeBytes', 'free', 'freePercent'],
            array_keys($result)
        );
        $this->assertIsInt($result['usageBytes']);
        $this->assertIsInt($result['totalBytes']);
        $this->assertIsInt($result['freeBytes']);
        $this->assertIsString($result['usage']);
        $this->assertIsString($result['total']);
        $this->assertIsString($result['free']);
        $this->assertIsFloat($result['freePercent']);

        $this->assertGreaterThan(0, $result['usageBytes'], 'the running process uses real memory');
        $this->assertGreaterThanOrEqual(0, $result['freeBytes'], 'free memory is never negative');
    }

    /**
     * usageBytes is exactly memory_get_usage(true) and owes NOTHING to the OS probe. That is not an
     * incidental detail: SQL::prepareInsertOrUpdateMySQL() leans on it to read process usage inside
     * its batch loop with a bare memory_get_usage(true) instead of calling this method once per row
     * and paying for a wmic/proc probe it would discard. If usageBytes ever starts meaning something
     * else — the machine's used RAM, say — that substitution becomes silently wrong, and this test
     * is what says so.
     */
    public function testGetMemoryUsageUsageBytesIsTheProcessFigureFromMemoryGetUsageTrue(): void {
        ini_set('memory_limit', '512M');

        $before = memory_get_usage(true);
        $result = System::getMemoryUsage();
        $after = memory_get_usage(true);

        $this->assertGreaterThanOrEqual($before, $result['usageBytes']);
        $this->assertLessThanOrEqual($after, $result['usageBytes']);
    }

    /**
     * The same invariant where it actually bites: with the OS probe unavailable, usageBytes must
     * still be the real process figure rather than 0 or a guess.
     */
    public function testGetMemoryUsageUsageBytesStaysRealWhenTheOsProbeIsUnavailable(): void {
        $output = $this->runInProcessWithoutOsMemoryProbe('512M');

        $this->assertNull($output['server'], 'the OS probe must be unavailable for this test to mean anything');
        $this->assertArrayNotHasKey('error', $output, $output['error'] ?? '');
        $this->assertGreaterThan(0, $output['usage']['usageBytes']);
        $this->assertLessThan(536870912, $output['usage']['usageBytes']);
    }

    public function testGetMemoryUsageFreePercentIsTheFreeShareNotTheUsedShare(): void {
        ini_set('memory_limit', '512M');

        $result = System::getMemoryUsage();

        $expectedFreeShare = Number::roundDecimal(
            $result['freeBytes'] * 100 / $result['totalBytes'],
            2,
            'floor'
        );

        $this->assertSame($expectedFreeShare, $result['freePercent']);
        $this->assertGreaterThanOrEqual(0.0, $result['freePercent']);
        $this->assertLessThanOrEqual(100.0, $result['freePercent']);
    }

    public function testGetMemoryUsageCapsFreeMemoryAtWhatThePhysicalMachineHasLeft(): void {
        // A limit no dev box can honour: free memory must be capped by the OS figure, never by it.
        // (PHP's own memory_limit parser only accepts K/M/G multipliers, hence 64G and not 1P.)
        ini_set('memory_limit', '64G');

        $result = System::getMemoryUsage();
        $server = System::getServerMemoryUsage();

        $this->assertSame(68719476736, $result['totalBytes']);

        if ($server === null) {
            $this->assertSame(
                $result['totalBytes'] - $result['usageBytes'],
                $result['freeBytes'],
                'without OS numbers, free memory is the remaining headroom under the limit'
            );
            return;
        }

        $this->assertLessThanOrEqual(
            $server['totalBytes'],
            $result['freeBytes'],
            'cannot have more free memory than the box physically has'
        );
    }

    public function testGetMemoryUsageFallsBackToThePhysicalTotalWhenMemoryLimitIsUnlimited(): void {
        ini_set('memory_limit', '-1');

        $result = System::getMemoryUsage();
        $server = System::getServerMemoryUsage();

        if ($server === null) {
            // Nothing knowable: documented as 0/0/0.0 meaning "unknown".
            $this->assertSame(0, $result['totalBytes']);
            $this->assertSame(0, $result['freeBytes']);
            $this->assertSame(0.0, $result['freePercent']);
            $this->assertSame('0 B', $result['total']);
            return;
        }

        $this->assertSame($server['totalBytes'], $result['totalBytes']);
        $this->assertGreaterThan(0, $result['totalBytes']);
    }

    /**
     * The finding that mattered most: getServerMemoryUsage() returning null is ROUTINE (no wmic on
     * Windows 11 24H2+, unreadable /proc/meminfo in containers), and getMemoryUsage() never checked
     * for it — `null['freeBytes']` warned, min(int, null) produced null, and convertBytesToReadable
     * then threw a TypeError. The docblock promised a plain array with no @throws.
     *
     * Runs in a child process where the OS probe is guaranteed to fail, under an error handler that
     * turns any unsuppressed diagnostic into an exception (the framework behaviour that turns this
     * bug into a dead request).
     */
    public function testGetMemoryUsageReturnsTheDocumentedArrayWhenTheOsProbeIsUnavailable(): void {
        $output = $this->runInProcessWithoutOsMemoryProbe('512M');

        $this->assertNull($output['server'], 'the OS probe must be unavailable for this test to mean anything');
        $this->assertArrayNotHasKey('error', $output, $output['error'] ?? '');

        $usage = $output['usage'];
        $this->assertSame(
            ['usageBytes', 'usage', 'totalBytes', 'total', 'freeBytes', 'free', 'freePercent'],
            array_keys($usage)
        );
        $this->assertSame(536870912, $usage['totalBytes']);
        $this->assertSame('512 MB', $usage['total']);

        // With no OS cap, free is exactly the headroom under the limit, so the free share is high.
        // The old inverted formula would report the used share here: under 1.
        $this->assertSame($usage['totalBytes'] - $usage['usageBytes'], $usage['freeBytes']);
        $this->assertGreaterThan(90.0, $usage['freePercent']);
    }

    /**
     * Degradation contract: no PHP limit AND no OS numbers means the total is genuinely unknowable.
     * Documented as 0 (not a guess, not a crash), with freePercent 0.0 meaning "unknown".
     */
    public function testGetMemoryUsageReportsUnknownTotalAsZeroWhenUnlimitedAndOsProbeUnavailable(): void {
        $output = $this->runInProcessWithoutOsMemoryProbe('-1');

        $this->assertNull($output['server']);
        $this->assertArrayNotHasKey('error', $output, $output['error'] ?? '');

        $usage = $output['usage'];
        $this->assertSame(0, $usage['totalBytes']);
        $this->assertSame(0, $usage['freeBytes']);
        $this->assertSame(0.0, $usage['freePercent'], 'freePercent must not divide by a zero total');
        $this->assertSame('0 B', $usage['total']);
        $this->assertSame('0 B', $usage['free']);
        // Process usage stays real and knowable even when the machine total is not.
        $this->assertGreaterThan(0, $usage['usageBytes']);
    }

    // ---------------------------------------------------------------------
    // makeSeed()
    // ---------------------------------------------------------------------

    /**
     * The signature says ?string and the summary says "based on a string", but the value went
     * straight to srand(), which takes an int: srand('build-42') is an uncaught TypeError. This
     * test would fatal (TypeError extends \Error, so callers could not even catch it).
     */
    public function testMakeSeedAcceptsANonNumericStringAndMakesTheSequenceReproducible(): void {
        System::makeSeed('build-42');
        $first = [rand(), rand(), rand()];

        System::makeSeed('build-42');
        $second = [rand(), rand(), rand()];

        $this->assertSame($first, $second);
    }

    public function testMakeSeedWithDifferentStringSeedsProducesDifferentSequences(): void {
        System::makeSeed('tenant-a');
        $a = [rand(), rand(), rand()];

        System::makeSeed('tenant-b');
        $b = [rand(), rand(), rand()];

        $this->assertNotSame($a, $b);
    }

    public function testMakeSeedWithNumericStringSeedsThatExactInteger(): void {
        System::makeSeed('42');
        $viaHelper = [rand(), rand()];

        srand(42);
        $viaSrand = [rand(), rand()];

        $this->assertSame($viaSrand, $viaHelper);
    }

    /**
     * The doc named exactly one condition for the random fallback ($seed === null), but the code
     * branched on empty(), so the perfectly ordinary seed "0" was silently swapped for a random
     * one — a "reproducible" fixture that quietly was not.
     */
    public function testMakeSeedHonoursTheZeroStringSeedInsteadOfRandomisingIt(): void {
        System::makeSeed('0');
        $viaHelper = [rand(), rand()];

        srand(0);
        $viaSrand = [rand(), rand()];

        $this->assertSame($viaSrand, $viaHelper);
    }

    public function testMakeSeedHonoursTheEmptyStringSeedInsteadOfRandomisingIt(): void {
        // Documented: crc32('') is 0, so '' seeds srand(0) — deterministic, like any other string.
        System::makeSeed('');
        $viaHelper = [rand(), rand()];

        srand(crc32(''));
        $viaSrand = [rand(), rand()];

        $this->assertSame($viaSrand, $viaHelper);
    }

    public function testMakeSeedHashesNonNumericStringsWithCrc32AsDocumented(): void {
        System::makeSeed('order-7');
        $viaHelper = [rand(), rand()];

        srand(crc32('order-7'));
        $viaSrand = [rand(), rand()];

        $this->assertSame($viaSrand, $viaHelper);
    }

    public function testMakeSeedWithNullSeedsFromTimeAndKeepsRandUsable(): void {
        System::makeSeed(null);

        $value = rand(1, 10);
        $this->assertGreaterThanOrEqual(1, $value);
        $this->assertLessThanOrEqual(10, $value);

        // Default argument must behave exactly like an explicit null.
        System::makeSeed();
        $value = rand(1, 10);
        $this->assertGreaterThanOrEqual(1, $value);
        $this->assertLessThanOrEqual(10, $value);
    }

    // ---------------------------------------------------------------------
    // timer()
    // ---------------------------------------------------------------------

    public function testTimerInitReturnsTheTimerNameAndItsStartTimestamp(): void {
        $result = System::timer('request');

        $this->assertSame(['timer', 'started_at'], array_keys($result));
        $this->assertSame('request', $result['timer']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/',
            $result['started_at']
        );
    }

    /**
     * The docblock advertised "@return string|array|null ... the timestamp string", which the
     * ": ?array" signature makes impossible. A caller who trusted the doc and echoed the result got
     * "Array" plus an "Array to string conversion" warning.
     */
    public function testTimerNeverReturnsAStringOnAnyPath(): void {
        $this->assertIsArray(System::timer('a', 'init'));
        $this->assertIsArray(System::timer('a', 'reset'));
        $this->assertIsArray(System::timer('a', 'get'));
        $this->assertIsArray(System::timer(null, 'get'));
        $this->assertNull(System::timer('unknown-timer', 'get'));
        $this->assertNull(System::timer('a', 'clear'));
    }

    public function testTimerGetReturnsElapsedTimeForANamedTimer(): void {
        $init = System::timer('job');
        usleep(2000);

        $result = System::timer('job', 'get');

        $this->assertSame(
            ['timer', 'started_at', 'now', 'elapsed_ms', 'elapsed_seconds'],
            array_keys($result)
        );
        $this->assertSame('job', $result['timer']);
        $this->assertSame($init['started_at'], $result['started_at'], 'get must not restart the timer');
        $this->assertIsFloat($result['elapsed_ms']);
        $this->assertIsFloat($result['elapsed_seconds']);
        $this->assertGreaterThan(0.0, $result['elapsed_ms']);
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}\.\d{6}$/',
            $result['now']
        );
    }

    public function testTimerGetIsRepeatableAndDoesNotStopTheTimer(): void {
        System::timer('job');

        $first = System::timer('job', 'get');
        usleep(2000);
        $second = System::timer('job', 'get');

        $this->assertGreaterThan($first['elapsed_ms'], $second['elapsed_ms']);
    }

    public function testTimerGetWithoutANameReturnsEveryLiveTimer(): void {
        System::timer('alpha');
        System::timer('beta');

        $all = System::timer(null, 'get');

        $this->assertCount(2, $all);
        $this->assertSame(['alpha', 'beta'], array_column($all, 'timer'));
        $this->assertSame([0, 1], array_keys($all), 'must be a list, not a name-keyed map');
    }

    public function testTimerGetWithoutANameReturnsAnEmptyListWhenNoTimersExist(): void {
        $this->assertSame([], System::timer(null, 'get'));
        $this->assertSame([], System::timer('', 'get'));
    }

    public function testTimerGetReturnsNullForATimerThatWasNeverStarted(): void {
        $this->assertNull(System::timer('never-started', 'get'));
    }

    public function testTimerResetRestartsAnExistingTimer(): void {
        $init = System::timer('job', 'init');
        usleep(2000);

        $reset = System::timer('job', 'reset');

        $this->assertSame(['timer', 'started_at'], array_keys($reset));
        $this->assertNotSame($init['started_at'], $reset['started_at']);
        $this->assertSame($reset['started_at'], System::timer('job', 'get')['started_at']);
    }

    /**
     * "clear" by name used to assign an EMPTY ARRAY instead of removing the entry, so the timer
     * still passed isset() in "get" and then read a missing 'started_ns' — an undefined-key warning
     * plus null arithmetic. This test fails on that warning (failOnWarning is on).
     */
    public function testTimerClearByNameRemovesTheTimerWithoutLeavingABrokenEntry(): void {
        System::timer('alpha');
        System::timer('beta');

        $this->assertNull(System::timer('alpha', 'clear'));

        $this->assertNull(System::timer('alpha', 'get'), 'a cleared timer must be gone, not empty');
        $this->assertNotNull(System::timer('beta', 'get'), 'clearing one timer must not touch another');

        // The get-all loop must not trip over a leftover entry either.
        $all = System::timer(null, 'get');
        $this->assertSame(['beta'], array_column($all, 'timer'));
    }

    /**
     * The clear-all branch was unreachable: the empty-name guard returned null before the switch for
     * every type except "get", so timer(null, 'clear') silently did nothing.
     */
    public function testTimerClearWithoutANameDiscardsEveryTimer(): void {
        System::timer('alpha');
        System::timer('beta');
        $this->assertCount(2, System::timer(null, 'get'));

        $this->assertNull(System::timer(null, 'clear'));

        $this->assertSame([], System::timer(null, 'get'));
        $this->assertNull(System::timer('alpha', 'get'));
        $this->assertNull(System::timer('beta', 'get'));
    }

    public function testTimerIsCaseInsensitiveOnType(): void {
        $this->assertIsArray(System::timer('job', 'INIT'));
        $this->assertIsArray(System::timer('job', 'GeT'));
        $this->assertNull(System::timer('job', 'CLEAR'));
        $this->assertNull(System::timer('job', 'get'));
    }

    public function testTimerRequiresANameToInitOrReset(): void {
        $this->assertNull(System::timer(null, 'init'));
        $this->assertNull(System::timer('', 'init'));
        $this->assertNull(System::timer(null, 'reset'));

        // Nothing was created by those rejected calls.
        $this->assertSame([], System::timer(null, 'get'));
    }

    public function testTimerReturnsNullForAnEmptyOrUnknownType(): void {
        $this->assertNull(System::timer('job', ''));
        $this->assertNull(System::timer('job', 'stop'));
        $this->assertNull(System::timer('job', 'destroy'));

        // An unknown type is a no-op, not a silent init.
        $this->assertNull(System::timer('job', 'get'));
    }

    // ---------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------

    /**
     * Runs getServerMemoryUsage()/getMemoryUsage() in a child PHP process configured so the OS
     * memory probe CANNOT work (exec disabled for the Windows wmic branch, open_basedir hiding
     * /proc/meminfo for the Linux branch), with a strict error handler that promotes any
     * unsuppressed diagnostic to an exception.
     *
     * @param string $memoryLimit Value for the child's memory_limit ini setting.
     * @return array{server: array|null, usage: array, error?: string} Decoded child output.
     */
    private function runInProcessWithoutOsMemoryProbe(string $memoryLimit): array {
        if (!function_exists('exec')) {
            $this->markTestSkipped('exec() is disabled, cannot spawn the child PHP process.');
        }

        $autoload = dirname(__DIR__) . '/vendor/autoload.php';
        $script = sys_get_temp_dir() . '/phphelper_system_' . bin2hex(random_bytes(8)) . '.php';
        $this->tempFiles[] = $script;

        $code = <<<'PHP'
<?php
set_error_handler(static function (int $no, string $str, string $file = '', int $line = 0): bool {
    // Respect the @ operator, exactly as a framework error handler does.
    if (!(error_reporting() & $no)) {
        return false;
    }
    throw new \ErrorException($str, 0, $no, $file, $line);
});

require %s;

try {
    $server = \VD\PHPHelper\System::getServerMemoryUsage();
    $usage = \VD\PHPHelper\System::getMemoryUsage();
    // JSON_PRESERVE_ZERO_FRACTION: without it json_encode(0.0) emits "0" and the parent would
    // decode an int, hiding whether freePercent really is the documented float.
    echo json_encode(['server' => $server, 'usage' => $usage], JSON_PRESERVE_ZERO_FRACTION);
} catch (\Throwable $e) {
    echo json_encode(['server' => null, 'usage' => [], 'error' => get_class($e) . ': ' . $e->getMessage()]);
}
PHP;
        file_put_contents($script, sprintf($code, var_export($autoload, true)));

        $command = escapeshellarg(PHP_BINARY)
            . ' -d ' . escapeshellarg('memory_limit=' . $memoryLimit)
            . ' -d ' . escapeshellarg('disable_functions=exec,shell_exec,passthru,system,popen,proc_open');

        if (PHP_OS_FAMILY !== 'Windows') {
            $command .= ' -d ' . escapeshellarg(
                'open_basedir=' . dirname(__DIR__) . PATH_SEPARATOR . sys_get_temp_dir()
            );
        }

        $command .= ' ' . escapeshellarg($script);

        $lines = [];
        $exitCode = 0;
        exec($command, $lines, $exitCode);
        $raw = implode("\n", $lines);

        $this->assertSame(0, $exitCode, "Child PHP process failed. Output:\n" . $raw);

        $decoded = json_decode($raw, true);
        $this->assertIsArray($decoded, "Child PHP process produced no usable JSON. Output:\n" . $raw);

        return $decoded;
    }
}
