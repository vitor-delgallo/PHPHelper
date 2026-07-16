<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\TestCase;
use VD\PHPHelper\DBF;

final class DBFTest extends TestCase {
    /** @var string Absolute path to this test's private temp directory. */
    private string $tmpDir;

    protected function setUp(): void {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbftest_' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir, 0777, true);
    }

    protected function tearDown(): void {
        $this->removeTree($this->tmpDir);
    }

    private function removeTree(string $path): void {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path)) {
            unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        rmdir($path);
    }

    /**
     * Builds a byte-exact dBase III file.
     *
     * @param array $fields Each: [string $name, string $type, int $len, int $dec]
     * @param array $rows   Each: list of values, positional to $fields
     * @param array $opts   'recordCount' => override the header's record count (to forge a
     *                      count the file cannot back), 'eof' => append the 0x1A marker
     * @return string Absolute path of the written file
     */
    private function makeDbf(array $fields, array $rows, array $opts = []): string {
        $headerLength = 32 + 32 * count($fields) + 1;
        $recordLength = 1; // deleted flag
        foreach ($fields as $field) {
            $recordLength += $field[2];
        }

        $header = pack('C', 0x03) . pack('CCC', 24, 1, 1); // version + last-update Y/M/D
        $header .= pack('V', $opts['recordCount'] ?? count($rows));
        $header .= pack('v', $headerLength);
        $header .= pack('v', $recordLength);
        $header .= str_repeat("\0", 20);

        $out = $header;
        $offset = 1;
        foreach ($fields as [$name, $type, $len, $dec]) {
            $out .= str_pad(substr($name, 0, 10), 11, "\0"); // DBF names are NUL-padded to 11 bytes
            $out .= $type;
            $out .= pack('V', $offset);
            $out .= pack('C', $len);
            $out .= pack('C', $dec);
            $out .= str_repeat("\0", 14);
            $offset += $len;
        }
        $out .= chr(13); // field-list terminator

        foreach ($rows as $row) {
            $record = ' '; // not deleted
            foreach ($fields as $i => $field) {
                $record .= str_pad(substr((string)$row[$i], 0, $field[2]), $field[2], ' ');
            }
            $out .= $record;
        }
        if ($opts['eof'] ?? true) {
            $out .= chr(26);
        }

        return $this->writeTmp('sample.dbf', $out);
    }

    private function writeTmp(string $name, string $contents): string {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    private function samplePath(): string {
        return $this->makeDbf(
            [['NAME', 'C', 10, 0], ['AGE', 'N', 3, 0]],
            [['Alice', '30'], ['Bob', '7']]
        );
    }

    // ---------------------------------------------------------------- DBFFormatPath

    public function testFormatPathReturnsResolvedAbsolutePathForExistingFile(): void {
        $path = $this->samplePath();

        $formatted = DBF::DBFFormatPath($path);

        $this->assertIsString($formatted);
        $this->assertTrue(is_file($formatted));
        $this->assertSame(realpath($path), $formatted);
    }

    public function testFormatPathAcceptsForwardSlashSeparators(): void {
        $path = $this->samplePath();

        $formatted = DBF::DBFFormatPath(str_replace('\\', '/', $path));

        $this->assertSame(realpath($path), $formatted);
    }

    public function testFormatPathReturnsNullForMissingFile(): void {
        $this->assertNull(DBF::DBFFormatPath($this->tmpDir . DIRECTORY_SEPARATOR . 'nope.dbf'));
    }

    public function testFormatPathReturnsNullForEmptyPath(): void {
        $this->assertNull(DBF::DBFFormatPath(''));
    }

    public function testFormatPathReturnsNullForDirectory(): void {
        $this->assertNull(DBF::DBFFormatPath($this->tmpDir));
    }

    /**
     * Pins the fix for the high finding: the docblock promised path formatting, the code ran a
     * recursive 0777 mkdir driven by the input path and hid it behind a null return.
     */
    public function testFormatPathCreatesNoDirectoriesForMissingNestedPath(): void {
        $ghost = $this->tmpDir . DIRECTORY_SEPARATOR . 'a' . DIRECTORY_SEPARATOR . 'b'
            . DIRECTORY_SEPARATOR . 'c' . DIRECTORY_SEPARATOR . 'report.dbf';

        $this->assertNull(DBF::DBFFormatPath($ghost));

        $this->assertDirectoryDoesNotExist($this->tmpDir . DIRECTORY_SEPARATOR . 'a');
        $this->assertSame(['.', '..'], scandir($this->tmpDir));
    }

    /** The same side effect reached through the public reader, which delegates to DBFFormatPath. */
    public function testReadBasicCreatesNoDirectoriesForMissingNestedPath(): void {
        $ghost = $this->tmpDir . DIRECTORY_SEPARATOR . 'x' . DIRECTORY_SEPARATOR . 'y'
            . DIRECTORY_SEPARATOR . 'data.dbf';

        $this->assertSame([], DBF::DBFReadBasic($ghost, 'all'));

        $this->assertDirectoryDoesNotExist($this->tmpDir . DIRECTORY_SEPARATOR . 'x');
    }

    // ---------------------------------------------------------------- DBFReadBasic: modes

    public function testReadBasicHeaderReturnsCountFirstRecordAndLength(): void {
        $header = DBF::DBFReadBasic($this->samplePath(), 'header');

        $this->assertSame(
            ['RecordCount' => 2, 'FirstRecord' => 32 + 64 + 1, 'RecordLength' => 1 + 10 + 3],
            $header
        );
    }

    public function testReadBasicSchemaReturnsTrimmedFieldDescriptors(): void {
        $schema = DBF::DBFReadBasic($this->samplePath(), 'schema');

        $this->assertCount(2, $schema);
        $this->assertSame(
            ['fieldname' => 'NAME', 'fieldtype' => 'C', 'offset' => 1, 'fieldlen' => 10, 'fielddec' => 0],
            $schema[0]
        );
        $this->assertSame(
            ['fieldname' => 'AGE', 'fieldtype' => 'N', 'offset' => 11, 'fieldlen' => 3, 'fielddec' => 0],
            $schema[1]
        );
    }

    public function testReadBasicRecordsReturnsTrimmedValues(): void {
        $records = DBF::DBFReadBasic($this->samplePath(), 'records');

        $this->assertSame(
            [
                0 => ['NAME' => 'Alice', 'AGE' => '30'],
                1 => ['NAME' => 'Bob', 'AGE' => '7'],
            ],
            $records
        );
    }

    /**
     * Pins the record-key fix: the raw DBF field name is NUL-padded to 11 bytes and was fed
     * untrimmed into the unpack format, so records came back keyed "NAME\0\0\0\0\0\0\0" while
     * 'schema' reported "NAME". A caller correlating the two wrote an undefined-key access.
     */
    public function testReadBasicRecordKeysAreExactlyTheSchemaFieldNames(): void {
        $path = $this->samplePath();

        $schema = DBF::DBFReadBasic($path, 'schema');
        $records = DBF::DBFReadBasic($path, 'records');

        $schemaNames = array_column($schema, 'fieldname');
        foreach ($records as $record) {
            $this->assertSame($schemaNames, array_keys($record));
            foreach (array_keys($record) as $key) {
                $this->assertStringNotContainsString("\0", $key);
                $this->assertSame(trim($key), $key);
            }
        }
    }

    public function testReadBasicDefaultsToRecordsWhenModeIsNull(): void {
        $path = $this->samplePath();

        $this->assertSame(DBF::DBFReadBasic($path, 'records'), DBF::DBFReadBasic($path));
    }

    public function testReadBasicFallsBackToRecordsForUnknownMode(): void {
        $path = $this->samplePath();

        $this->assertSame(DBF::DBFReadBasic($path, 'records'), DBF::DBFReadBasic($path, 'bogus-mode'));
        $this->assertSame(DBF::DBFReadBasic($path, 'records'), DBF::DBFReadBasic($path, ''));
    }

    public function testReadBasicStructureReturnsHeaderAndSchemaOnly(): void {
        $path = $this->samplePath();

        $structure = DBF::DBFReadBasic($path, 'structure');

        $this->assertSame(['header', 'schema'], array_keys($structure));
        $this->assertSame(DBF::DBFReadBasic($path, 'header'), $structure['header']);
        $this->assertSame(DBF::DBFReadBasic($path, 'schema'), $structure['schema']);
    }

    public function testReadBasicAllReturnsHeaderSchemaAndRecords(): void {
        $path = $this->samplePath();

        $all = DBF::DBFReadBasic($path, 'all');

        $this->assertSame(['header', 'schema', 'records'], array_keys($all));
        $this->assertSame(DBF::DBFReadBasic($path, 'header'), $all['header']);
        $this->assertSame(DBF::DBFReadBasic($path, 'schema'), $all['schema']);
        $this->assertSame(DBF::DBFReadBasic($path, 'records'), $all['records']);
    }

    // ---------------------------------------------------------------- DBFReadBasic: 'scan'

    /**
     * Pins the medium finding: 'scan' was documented as a peer read mode under a blanket
     * "@return array", but returned an empty array and echoed the header, every field and every
     * raw record to standard output as HTML.
     */
    public function testScanModeReturnsThePayloadInsteadOfEchoingIt(): void {
        $path = $this->samplePath();

        ob_start();
        $scan = DBF::DBFReadBasic($path, 'scan');
        $printed = ob_get_clean();

        $this->assertSame('', $printed, 'scan must not write anything to standard output');
        $this->assertNotSame([], $scan, 'scan must return its debug payload, not an empty array');
        $this->assertSame(['header', 'schema', 'records', 'raw'], array_keys($scan));
        $this->assertSame(DBF::DBFReadBasic($path, 'header'), $scan['header']);
        $this->assertSame(DBF::DBFReadBasic($path, 'schema'), $scan['schema']);
        $this->assertSame(DBF::DBFReadBasic($path, 'records'), $scan['records']);
    }

    public function testScanModeExposesRawRecordBuffersVerbatim(): void {
        $path = $this->samplePath();

        $scan = DBF::DBFReadBasic($path, 'scan');

        $this->assertCount(2, $scan['raw']);
        // Each buffer is RecordLength (14) bytes: 13 bytes of field data (10 + 3) plus the
        // following byte, which is the next record's deleted flag (or the 0x1A EOF marker).
        $this->assertSame('Alice     30  ', $scan['raw'][0]);
        $this->assertSame('Bob       7  ' . chr(26), $scan['raw'][1]);
        $this->assertSame('Alice     30 ', substr($scan['raw'][0], 0, 13), 'field data of record 0');
    }

    public function testNonScanModesDoNotExposeRawBuffers(): void {
        $path = $this->samplePath();

        foreach (['all', 'structure', 'header', 'schema', 'records'] as $mode) {
            $this->assertArrayNotHasKey('raw', DBF::DBFReadBasic($path, $mode), "mode {$mode}");
        }
    }

    public function testEveryModeWritesNothingToStandardOutput(): void {
        $path = $this->samplePath();

        foreach (['header', 'schema', 'records', 'structure', 'all', 'scan'] as $mode) {
            ob_start();
            DBF::DBFReadBasic($path, $mode);
            $this->assertSame('', ob_get_clean(), "mode {$mode} printed to output");
        }
    }

    // ---------------------------------------------------------------- DBFReadBasic: rejections

    public function testReadBasicReturnsEmptyArrayForMissingFile(): void {
        $this->assertSame([], DBF::DBFReadBasic($this->tmpDir . DIRECTORY_SEPARATOR . 'missing.dbf', 'all'));
    }

    public function testReadBasicReturnsEmptyArrayForEmptyPath(): void {
        $this->assertSame([], DBF::DBFReadBasic('', 'all'));
    }

    public function testReadBasicReturnsEmptyArrayForDirectory(): void {
        $this->assertSame([], DBF::DBFReadBasic($this->tmpDir, 'all'));
    }

    /**
     * A non-DBF file used to unpack to false, so 'all' returned ['header' => false, ...] under a
     * documented "@return array" — after a burst of PHP warnings.
     */
    public function testReadBasicReturnsEmptyArrayForFileTooShortToBeADbf(): void {
        // Bytes 4..11 hold the header fields, so anything under 12 bytes cannot be a DBF.
        foreach (['empty.dbf' => '', 'junk.dbf' => 'hello world', 'short.dbf' => str_repeat('x', 11)] as $name => $contents) {
            $path = $this->writeTmp($name, $contents);

            foreach (['header', 'all', 'records', 'scan'] as $mode) {
                $this->assertSame([], DBF::DBFReadBasic($path, $mode), "{$name} / {$mode}");
            }
        }
    }

    /**
     * A file long enough to clear the 12-byte floor still parses to a garbage header, but the
     * documented "@return array" must hold: header stays an int map and never degrades to false.
     */
    public function testReadBasicNeverReturnsFalseAsHeaderForLongerNonDbfFile(): void {
        $path = $this->writeTmp('junk.dbf', 'not a dbf at all, but longer than 12 bytes');

        $result = DBF::DBFReadBasic($path, 'all');

        $this->assertNotFalse($result['header']);
        $this->assertSame(['RecordCount', 'FirstRecord', 'RecordLength'], array_keys($result['header']));
        foreach ($result['header'] as $key => $value) {
            $this->assertIsInt($value, $key);
        }
        $this->assertSame([], $result['schema'], 'no valid field descriptor is recoverable');
        $this->assertSame([], $result['records']);
    }

    /**
     * A 32-byte garbage header parses to an enormous RecordCount with no field table behind it.
     * The reader must stop instead of looping RecordCount times over an exhausted handle.
     */
    public function testReadBasicStopsOnGarbageHeaderWithoutFieldTable(): void {
        $path = $this->writeTmp('garbage32.dbf', str_repeat("\x01", 32));

        $all = DBF::DBFReadBasic($path, 'all');

        $this->assertSame(0x01010101, $all['header']['RecordCount']);
        $this->assertSame([], $all['schema']);
        $this->assertSame([], $all['records'], 'no field table means no parseable records');
    }

    /** A RecordCount larger than the file can back returns the records that really exist. */
    public function testReadBasicReturnsOnlyTheRecordsTheFileActuallyHolds(): void {
        $path = $this->makeDbf(
            [['NAME', 'C', 10, 0], ['AGE', 'N', 3, 0]],
            [['Alice', '30']],
            ['recordCount' => 5000]
        );

        $all = DBF::DBFReadBasic($path, 'all');

        $this->assertSame(5000, $all['header']['RecordCount'], 'the header is reported as it is on disk');
        $this->assertCount(1, $all['records'], 'only the record actually present is returned');
        $this->assertSame(['NAME' => 'Alice', 'AGE' => '30'], $all['records'][0]);
    }

    /** The last record is readable even when the 0x1A end-of-file marker is absent. */
    public function testReadBasicReadsFinalRecordWithoutEofMarker(): void {
        $path = $this->makeDbf(
            [['NAME', 'C', 10, 0], ['AGE', 'N', 3, 0]],
            [['Alice', '30'], ['Bob', '7']],
            ['eof' => false]
        );

        $records = DBF::DBFReadBasic($path, 'records');

        $this->assertCount(2, $records);
        $this->assertSame(['NAME' => 'Bob', 'AGE' => '7'], $records[1]);
    }

    /** A field table cut mid-descriptor must not be trusted into the unpack format. */
    public function testReadBasicStopsOnTruncatedFieldDescriptor(): void {
        $complete = $this->makeDbf([['NAME', 'C', 10, 0]], [['Alice']]);
        $bytes = file_get_contents($complete);
        // Header (32) + 10 bytes of a 32-byte descriptor: not enough to read a field.
        $path = $this->writeTmp('cut.dbf', substr($bytes, 0, 42));

        $all = DBF::DBFReadBasic($path, 'all');

        $this->assertSame([], $all['schema']);
        $this->assertSame([], $all['records']);
        $this->assertSame(1, $all['header']['RecordCount']);
    }

    public function testReadBasicHandlesDbfWithZeroRecords(): void {
        $path = $this->makeDbf([['NAME', 'C', 10, 0]], []);

        $all = DBF::DBFReadBasic($path, 'all');

        $this->assertSame(0, $all['header']['RecordCount']);
        $this->assertCount(1, $all['schema']);
        $this->assertSame('NAME', $all['schema'][0]['fieldname']);
        $this->assertSame([], $all['records']);
        $this->assertSame([], DBF::DBFReadBasic($path, 'records'));
    }

    /** Records flagged deleted are returned like any other record, as the docblock now states. */
    public function testReadBasicReturnsDeletedRecordsToo(): void {
        $path = $this->makeDbf([['NAME', 'C', 10, 0]], [['Alice'], ['Bob']]);
        $bytes = file_get_contents($path);
        $firstRecordAt = 32 + 32 + 1;
        $bytes[$firstRecordAt] = '*'; // dBase deleted-record flag
        $path = $this->writeTmp('deleted.dbf', $bytes);

        $records = DBF::DBFReadBasic($path, 'records');

        $this->assertCount(2, $records, 'the deleted flag is not interpreted');
        $this->assertSame('Alice', $records[0]['NAME']);
    }

    public function testReadBasicDecodesUnicodeEscapeSequencesInValues(): void {
        $path = $this->makeDbf([['CITY', 'C', 12, 0]], [['São Paulo']]);

        $records = DBF::DBFReadBasic($path, 'records');

        $this->assertSame('São Paulo', $records[0]['CITY']);
    }
}
