<?php

namespace VD\PHPHelper;

class System {
    /**
     * Returns the list of memory units in order from smallest to largest.
     *
     * @return array<string> List of memory units
     */
    public static function getMemoryUnitOrder(): array {
        return [
            'b',
            'kb',
            'mb',
            'gb',
            'tb',
            'pb',
            'eb',
            'zb',
            'yb',
        ];
    }

    /**
     * Converts a byte size into a human-readable memory unit (e.g., KB, MB, GB).
     *
     * @param int $bytes The size in bytes
     * @return string Human-readable memory size
     *
     * @see http://php.net/manual/en/function.memory-get-usage.php
     */
    public static function convertBytesToReadable(int $bytes): string {
        if ($bytes <= 0) {
            return '0 B';
        }

        $i = (int)floor(log($bytes, 1024));
        $value = round($bytes / pow(1024, $i), 2);

        return $value . ' ' . Str::strToUpper(self::getMemoryUnitOrder()[$i]);
    }

    /**
     * Converts a string representing a memory size (e.g., "2GB") into its equivalent in bytes.
     *
     * @param string|null $input The memory size string
     * @return int|false The size in bytes or false if the conversion failed
     */
    public static function convertMemoryToBytes(?string $input): int|false {
        if ($input === null) {
            return false;
        }

        $units = self::getMemoryUnitOrder();

        $parsed = [
            'num' => Str::onlyNumbers($input),
            'unit' => Str::strToLower(Str::onlyLetters($input))
        ];

        if (empty($parsed['num'])) {
            $parsed['num'] = 1;
        }

        $parsed['num'] = $parsed['num'] * 1;

        $index = array_search($parsed['unit'], $units);

        if ($index === 0) {
            return $parsed['num'];
        }

        if ($index === false && strlen($parsed['unit']) > 1) {
            $index = array_search(substr($parsed['unit'], 0, 1), $units);

            if ($index === 0) {
                return $parsed['num'];
            } elseif ($index === false) {
                return false;
            }
        }

        return $parsed['num'] * pow(1024, $index);
    }

    /**
     * Retrieves memory usage information from the operating system.
     * Works on both Windows and Linux systems.
     *
     * @return array{
     *     totalBytes: int,
     *     freeBytes: int,
     *     usageBytes: int,
     *     total: string,
     *     usage: string,
     *     free: string,
     *     freePercent: float
     * }|null Memory data or null if the data could not be retrieved
     *
     * @see https://www.php.net/manual/en/function.memory-get-usage.php
     */
    public static function getServerMemoryUsage(): ?array {
        $result = [
            'totalBytes' => null,
            'freeBytes' => null,
        ];

        if (stripos(PHP_OS, 'win') !== false) {
            // Windows
            @exec("wmic ComputerSystem get TotalPhysicalMemory", $outputTotal);
            @exec("wmic OS get FreePhysicalMemory", $outputFree);

            if ($outputTotal && $outputFree) {
                foreach ($outputTotal as $line) {
                    if ($line && preg_match("/^[0-9]+$/", $line)) {
                        $result['totalBytes'] = (int)$line;
                        break;
                    }
                }

                foreach ($outputFree as $line) {
                    if ($line && preg_match("/^[0-9]+$/", $line)) {
                        $result['freeBytes'] = (int)$line * 1024; // KiB to bytes
                        break;
                    }
                }
            }
        } else {
            // Linux
            if (is_readable('/proc/meminfo')) {
                $content = @file_get_contents('/proc/meminfo');

                if ($content !== false) {
                    $lines = preg_split('/\r\n|\r|\n/', $content);

                    foreach ($lines as $line) {
                        $parts = explode(':', $line);
                        if (count($parts) !== 2) continue;

                        $key = Str::removeExcessSpaces(Str::strToLower($parts[0]), false);
                        $value = Str::removeExcessSpaces(Str::strToLower($parts[1]), false);

                        if ($key === 'memtotal') {
                            $result['totalBytes'] = self::convertMemoryToBytes($value);
                        } elseif ($key === 'memfree') {
                            $result['freeBytes'] = self::convertMemoryToBytes($value);
                        }
                    }
                }
            }
        }

        if (is_null($result['totalBytes']) || is_null($result['freeBytes'])) {
            return null;
        }

        $result['usageBytes'] = $result['totalBytes'] - $result['freeBytes'];
        $result['total'] = self::convertBytesToReadable($result['totalBytes']);
        $result['usage'] = self::convertBytesToReadable($result['usageBytes']);
        $result['free'] = self::convertBytesToReadable($result['freeBytes']);
        $result['freePercent'] = Number::roundDecimal(
            (100 - ($result['freeBytes'] * 100 / $result['totalBytes'])),
            2,
            'floor'
        );

        return $result;
    }

    /**
     * Returns detailed information about the current memory usage by PHP.
     * Includes usage in bytes and human-readable format, total and free memory, and free memory percentage.
     *
     * @return array{
     *     usageBytes: int,
     *     usage: string,
     *     totalBytes: int,
     *     total: string,
     *     freeBytes: int,
     *     free: string,
     *     freePercent: float
     * }
     *
     * @see http://php.net/manual/en/function.memory-get-usage.php
     */
    public static function getMemoryUsage(): array {
        $result = [];

        $serverMemory = self::getServerMemoryUsage(); //VERIFICAR

        $result['usageBytes'] = memory_get_usage(true);
        $result['usage'] = self::convertBytesToReadable($result['usageBytes']);

        $memoryLimit = ini_get('memory_limit');

        if (!Validator::isNegativeNumber($memoryLimit)) {
            $result['totalBytes'] = self::convertMemoryToBytes($memoryLimit);
            $result['freeBytes'] = min(($result['totalBytes'] - $result['usageBytes']), $serverMemory['freeBytes']);
            if ($result['freeBytes'] < 0) {
                $result['freeBytes'] = 0;
            }
        } else {
            $result['totalBytes'] = $serverMemory['totalBytes'];
            $result['freeBytes'] = $serverMemory['freeBytes'];
        }

        $result['total'] = self::convertBytesToReadable($result['totalBytes']);
        $result['usage'] = self::convertBytesToReadable($result['usageBytes']);
        $result['free'] = self::convertBytesToReadable($result['freeBytes']);

        $result['freePercent'] = Number::roundDecimal(
            (100 - ($result['freeBytes'] * 100 / $result['totalBytes'])),
            2,
            'floor'
        );

        return $result;
    }

    /**
     * Compares two memory size strings and returns true if memory A is greater than memory B.
     *
     * @param string|null $memoryA Memory value to compare (e.g., "512MB")
     * @param string|null $memoryB Memory value to compare (e.g., "256MB")
     * @return bool True if A > B, otherwise false
     */
    public static function isMemoryGreaterThan(?string $memoryA, ?string $memoryB): bool
    {
        if (empty($memoryA)) return false;
        if (empty($memoryB)) return true;

        $validUnits = self::getMemoryUnitOrder(); // Uses same units as getMemoryUnits()

        $memoryAParsed = [
            'num' => apenasNumeros($memoryA), //VERIFICAR
            'unit' => my_strtolower(apenasLetras($memoryA)) //VERIFICAR
        ];
        if (empty($memoryAParsed['num'])) {
            $memoryAParsed['num'] = 1;
        }
        $memoryAParsed['num'] *= 1;

        if (!in_array($memoryAParsed['unit'], $validUnits)) {
            return false;
        }

        $memoryBParsed = [
            'num' => apenasNumeros($memoryB), //VERIFICAR
            'unit' => my_strtolower(apenasLetras($memoryB)) //VERIFICAR
        ];
        if (empty($memoryBParsed['num'])) {
            $memoryBParsed['num'] = 1;
        }
        $memoryBParsed['num'] *= 1;

        if (!in_array($memoryBParsed['unit'], $validUnits)) {
            return true;
        }

        // Same unit: compare numerically
        if (
            $memoryAParsed['unit'] === $memoryBParsed['unit'] &&
            $memoryAParsed['num'] > $memoryBParsed['num']
        ) {
            return true;
        }

        // Different units: compare unit scale
        foreach ($validUnits as $unit) {
            if ($memoryAParsed['unit'] === $unit) {
                return false;
            } elseif ($memoryBParsed['unit'] === $unit) {
                return true;
            }
        }

        return false;
    }

    /**
     * Generates a seed for random functions based on a string or the current time in microseconds.
     *
     * @param string|null $seed Optional custom seed value. If null, uses microtime and a random number.
     * @return void
     *
     * @see https://www.php.net/manual/en/function.srand.php
     */
    public static function makeSeed(?string $seed = null): void {
        if (empty($seed)) {
            list($microseconds, $seconds) = explode(' ', microtime());
            $seed = (int) (
                ( ((int) $seconds) + ((int) $microseconds) * 1000000 ) +
                rand(0, 99999)
            );
        }

        srand($seed);
    }

    /**
     * Controls named timers by initializing, resetting, or retrieving timestamps.
     *
     * This function uses a static array to store named timers (as formatted timestamps).
     *
     * - "init" or "reset": initializes or resets the timer with the current date/time.
     * - "get": retrieves the timestamp of a specific timer or all timers.
     *
     * @param string|null $timerName The name of the timer to control. Required unless type is "get".
     * @param string $type Type of control: "init", "reset", or "get". Defaults to "init".
     *
     * @return string|array|null Returns the timestamp string, array of all timers, or null if not applicable.
     */
    public static function timer(?string $timerName, string $type = "init"): string|array|null {
        if (empty($type)) {
            return null;
        }

        $type = Str::strToLower($type);
        if (empty($timerName) && $type !== "get") {
            return null;
        }

        static $timers = [];
        switch ($type) {
            case "init":
            case "reset":
                $timers[$timerName] = DateTime::getCurrentFormattedDate("Y-m-d H:i:s");
                break;

            case "get":
                if (empty($timerName)) {
                    return $timers;
                }
                if (isset($timers[$timerName])) {
                    return $timers[$timerName];
                }
                break;
        }

        return null;
    }
}