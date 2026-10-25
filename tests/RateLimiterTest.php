<?php

namespace VD\PHPHelper\Tests;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Keyring;
use VD\PHPHelper\RateLimiter;

/**
 * Contract tests for VD\PHPHelper\RateLimiter: counting in fixed windows, the read-only check,
 * keyed file names, failing open, the throttled sweep, and the address grouping helpers.
 */
final class RateLimiterTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vdrate-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_dir($this->dir)) {
            foreach (scandir($this->dir) ?: [] as $name) {
                if ($name !== '.' && $name !== '..') {
                    @unlink($this->dir . DIRECTORY_SEPARATOR . $name);
                }
            }
            @rmdir($this->dir);
        } elseif (is_file($this->dir)) {
            @unlink($this->dir);
        }
    }

    /** @return list<string> The limiter's counter files in the directory. */
    private function counters(): array
    {
        return array_values(array_filter(
            scandir($this->dir) ?: [],
            static fn (string $name): bool => preg_match('/^[0-9a-f]{64}\.json$/', $name) === 1
        ));
    }

    // ---------------------------------------------------------------- counting

    public function testHitIsOverFromTheHitAfterTheLimit(): void
    {
        $limiter = new RateLimiter($this->dir, 'site-secret');

        $over = [];
        for ($i = 1; $i <= 5; $i++) {
            $over[] = $limiter->hit('form:ip:203.0.113.9', 3, 3600);
        }

        self::assertSame([false, false, false, true, true], $over);
    }

    public function testBucketsAreCountedApart(): void
    {
        $limiter = new RateLimiter($this->dir, 'site-secret');

        self::assertFalse($limiter->hit('a', 1, 60));
        self::assertFalse($limiter->hit('b', 1, 60));
        self::assertTrue($limiter->hit('a', 1, 60));
    }

    public function testTheWindowStartsOverAfterItEnds(): void
    {
        $limiter = new RateLimiter($this->dir, 'site-secret');
        $start = 1_800_000_000;

        self::assertFalse($limiter->hit('b', 1, 60, $start));
        self::assertTrue($limiter->hit('b', 1, 60, $start + 59), 'still the same window');
        self::assertFalse($limiter->hit('b', 1, 60, $start + 60 + 59), 'a new window, counted from its own first hit');
        self::assertTrue($limiter->hit('b', 1, 60, $start + 60 + 60));
    }

    public function testALimitOfZeroRefusesEveryHit(): void
    {
        $limiter = new RateLimiter($this->dir);

        self::assertTrue($limiter->hit('closed', 0, 60));
        self::assertTrue($limiter->isOver('never-hit', 0, 60));
    }

    // ---------------------------------------------------------------- isOver

    public function testIsOverOnlyReadsAndTellsWhetherTheNextHitWouldBeOver(): void
    {
        $limiter = new RateLimiter($this->dir, 'site-secret');
        $now = 1_800_000_000;

        self::assertFalse($limiter->isOver('net:203.0.113.0/24', 2, 86400, $now));
        self::assertFalse(is_dir($this->dir) && $this->counters() !== [], 'asking creates nothing');

        $limiter->hit('net:203.0.113.0/24', 2, 86400, $now);
        self::assertFalse($limiter->isOver('net:203.0.113.0/24', 2, 86400, $now));
        $limiter->hit('net:203.0.113.0/24', 2, 86400, $now);
        self::assertTrue($limiter->isOver('net:203.0.113.0/24', 2, 86400, $now + 10));
        self::assertTrue($limiter->isOver('net:203.0.113.0/24', 2, 86400, $now + 20), 'asking twice counts nothing');
        self::assertFalse($limiter->isOver('net:203.0.113.0/24', 2, 86400, $now + 86400), 'the window is over');
    }

    // ---------------------------------------------------------------- files

    /**
     * A plain hash of "ip:203.0.113.9" is reversed by hashing the whole IPv4 space; the names are
     * an HMAC under a subkey of the site's secret, so neither the bucket nor its plain hash is on
     * disk, and another secret gives other names.
     */
    public function testFileNamesAreKeyedAndCarryNoBucketText(): void
    {
        $bucket = 'form:ip:203.0.113.9';
        (new RateLimiter($this->dir, 'secret-one'))->hit($bucket, 5, 60);
        (new RateLimiter($this->dir, 'secret-two'))->hit($bucket, 5, 60);
        (new RateLimiter($this->dir))->hit($bucket, 5, 60);

        $names = $this->counters();
        self::assertCount(3, $names, 'one file per key');
        foreach ($names as $name) {
            self::assertStringNotContainsString('203.0.113.9', $name);
            self::assertNotSame(hash('sha256', $bucket) . '.json', $name);
            self::assertStringNotContainsString('203.0.113.9', (string) file_get_contents($this->dir . DIRECTORY_SEPARATOR . $name));
        }
    }

    public function testAKeyringCountsUnderItsCurrentKey(): void
    {
        $key = random_bytes(32);

        (new RateLimiter($this->dir, new Keyring($key)))->hit('k', 1, 60);

        self::assertTrue((new RateLimiter($this->dir, $key))->hit('k', 1, 60), 'same key, same counter');
    }

    public function testACorruptCounterStartsOver(): void
    {
        $limiter = new RateLimiter($this->dir, 'site-secret');
        $limiter->hit('c', 1, 60);
        $file = $this->dir . DIRECTORY_SEPARATOR . $this->counters()[0];

        file_put_contents($file, '{"start":"yesterday","count":-4}');

        self::assertFalse($limiter->hit('c', 1, 60));
        self::assertTrue($limiter->hit('c', 1, 60));
    }

    /** A full disk must not take a form down: when nothing can be written, the hit is allowed. */
    public function testItFailsOpenWhenTheDirectoryCannotBeCreated(): void
    {
        file_put_contents($this->dir, 'a file where the directory should be');
        $limiter = new RateLimiter($this->dir, 'site-secret');

        for ($i = 0; $i < 3; $i++) {
            self::assertFalse($limiter->hit('x', 1, 60));
        }
        self::assertFalse($limiter->isOver('x', 1, 60));
    }

    public function testVarDumpShowsNoKey(): void
    {
        $limiter = new RateLimiter($this->dir, 'site-secret-value');

        self::assertSame(['directory' => $this->dir, 'keyed' => true], $limiter->__debugInfo());
        self::assertStringNotContainsString('site-secret-value', print_r($limiter, true));
    }

    // ---------------------------------------------------------------- sweep

    public function testTheSweepDeletesOldCountersAndNothingElse(): void
    {
        $limiter = new RateLimiter($this->dir, 'site-secret', 3600, 600);
        $limiter->hit('old', 5, 60);
        $old = $this->dir . DIRECTORY_SEPARATOR . $this->counters()[0];
        touch($old, time() - 7200);
        $foreign = $this->dir . DIRECTORY_SEPARATOR . 'notes.json';
        file_put_contents($foreign, '{}');
        touch($foreign, time() - 7200);
        touch($this->dir . DIRECTORY_SEPARATOR . 'sweep.marker', time() - 601);

        $limiter->hit('fresh', 5, 60);

        self::assertFileDoesNotExist($old);
        self::assertFileExists($foreign, "not the limiter's file");
        self::assertCount(1, $this->counters(), 'the fresh counter stays');
    }

    /** Sweeping lists the whole directory, so it runs at most once per $sweepEverySeconds. */
    public function testTheSweepWaitsForItsInterval(): void
    {
        $limiter = new RateLimiter($this->dir, 'site-secret', 3600, 600);
        $limiter->hit('old', 5, 60);
        $old = $this->dir . DIRECTORY_SEPARATOR . $this->counters()[0];
        touch($old, time() - 7200);
        touch($this->dir . DIRECTORY_SEPARATOR . 'sweep.marker', time() - 30);

        $limiter->hit('fresh', 5, 60);

        self::assertFileExists($old, 'the last sweep was 30 s ago');
    }

    // ---------------------------------------------------------------- arguments

    /** @return array<string, array{0: callable(RateLimiter): mixed}> */
    public static function invalidCalls(): array
    {
        return [
            'empty bucket'            => [static fn (RateLimiter $l) => $l->hit('', 1, 60)],
            'negative limit'          => [static fn (RateLimiter $l) => $l->hit('b', -1, 60)],
            'zero window'             => [static fn (RateLimiter $l) => $l->hit('b', 1, 0)],
            'window over the max age' => [static fn (RateLimiter $l) => $l->hit('b', 1, 3601)],
            'isOver, long window'     => [static fn (RateLimiter $l) => $l->isOver('b', 1, 3601)],
        ];
    }

    /** A window longer than the sweep age would see its counter deleted while it runs. */
    #[DataProvider('invalidCalls')]
    public function testInvalidArgumentsAreRefused(callable $call): void
    {
        $this->expectException(InvalidArgumentException::class);

        $call(new RateLimiter($this->dir, 'site-secret', 3600));
    }

    public function testTheConstructorRefusesAnEmptyDirectoryAndZeroIntervals(): void
    {
        foreach ([['', 86400, 600], [$this->dir, 0, 600], [$this->dir, 86400, 0]] as [$dir, $maxAge, $every]) {
            try {
                new RateLimiter($dir, null, $maxAge, $every);
                self::fail('accepted ' . json_encode([$dir, $maxAge, $every]));
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    // ---------------------------------------------------------------- address grouping

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function addresses(): array
    {
        return [
            'ipv4'            => ['203.0.113.9', '203.0.113.9', '203.0.113.0/24'],
            'ipv6'            => ['2001:db8:1:2:3:4:5:6', '2001:db8:1:2::/64', '2001:db8:1::/48'],
            'ipv6, uppercase' => ['2001:DB8:1:2::9', '2001:db8:1:2::/64', '2001:db8:1::/48'],
            'mapped ipv4'     => ['::ffff:203.0.113.9', '203.0.113.9', '203.0.113.0/24'],
            'not an address'  => ['unknown', 'unknown', 'unknown'],
        ];
    }

    #[DataProvider('addresses')]
    public function testIpBucketAndIpNetwork(string $ip, string $bucket, string $network): void
    {
        self::assertSame($bucket, RateLimiter::ipBucket($ip));
        self::assertSame($network, RateLimiter::ipNetwork($ip));
    }

    /** Two addresses of one subscriber line share a bucket; of one customer, a network. */
    public function testAddressesOfOneLineShareTheirBucket(): void
    {
        self::assertSame(RateLimiter::ipBucket('2001:db8:1:2::1'), RateLimiter::ipBucket('2001:db8:1:2:ffff::2'));
        self::assertNotSame(RateLimiter::ipBucket('2001:db8:1:2::1'), RateLimiter::ipBucket('2001:db8:1:3::1'));
        self::assertSame(RateLimiter::ipNetwork('2001:db8:1:2::1'), RateLimiter::ipNetwork('2001:db8:1:3::1'));
    }
}
