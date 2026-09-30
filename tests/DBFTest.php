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
     *                      count the file cannot back), 'eof' => append the 0x1A marker,
     *                      'rawNames' => [fieldIndex => exact 11-byte name area],
     *                      'terminator' => the byte written after the field table (0x0D),
     *                      'deleted' => list of row indexes to flag '*'
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
        foreach ($fields as $index => [$name, $type, $len, $dec]) {
            // DBF names are NUL-padded to 11 bytes
            $out .= $opts['rawNames'][$index] ?? str_pad(substr($name, 0, 10), 11, "\0");
            $out .= $type;
            $out .= pack('V', $offset);
            $out .= pack('C', $len);
            $out .= pack('C', $dec);
            $out .= str_repeat("\0", 14);
            $offset += $len;
        }
        $out .= $opts['terminator'] ?? chr(13); // field-list terminator

        foreach ($rows as $rowIndex => $row) {
            $record = in_array($rowIndex, $opts['deleted'] ?? [], true) ? '*' : ' ';
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

    /**
     * Each buffer is the record exactly as on disk: RecordLength (14) bytes, the deleted flag and
     * then 13 bytes of field data. The reader used to seek one byte PAST the first flag, so every
     * buffer was this record's data glued to the NEXT record's flag (or the 0x1A EOF marker), and a
     * record's own deleted flag could not be found in its own buffer.
     */
    public function testScanModeExposesRawRecordBuffersVerbatim(): void {
        $path = $this->samplePath();

        $scan = DBF::DBFReadBasic($path, 'scan');

        $this->assertCount(2, $scan['raw']);
        $this->assertSame(' Alice     30 ', $scan['raw'][0]);
        $this->assertSame(' Bob       7  ', $scan['raw'][1]);
    }

    public function testScanRawBufferStartsWithTheRecordsOwnDeletedFlag(): void {
        $path = $this->makeDbf([['NAME', 'C', 10, 0]], [['Alice'], ['Bob'], ['Cy']], ['deleted' => [1]]);

        $raw = DBF::DBFReadBasic($path, 'scan')['raw'];

        $this->assertSame([' ', '*', ' '], array_map(static fn (string $buf): string => $buf[0], $raw));
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

    /**
     * The read-amplification DoS, second shape.
     *
     * testReadBasicStopsOnGarbageHeaderWithoutFieldTable covers the case where no field table parses
     * at all, so the record loop is skipped outright by the `$unpackString !== ''` guard and never
     * runs. This pins the OTHER shape: a VALID field table with a RecordCount forged to the value
     * from the original report (0x01010101 = 16,843,009) behind a file holding one real record. Here
     * the loop does run, and only the `break` on a short read bounds it to the bytes on disk.
     *
     * The existing forged-count test uses 5000, which is small enough to pass even if that `break`
     * were weakened to a `continue` — this one would hammer an exhausted handle 16.8M times instead.
     * The time bound is deliberately ~1600x the observed runtime (0.003s), so it cannot flake, but
     * a reintroduced amplification fails it loudly instead of hanging the suite.
     */
    public function testReadBasicDoesNotAmplifyReadsForForgedRecordCountBehindValidFieldTable(): void {
        $path = $this->makeDbf(
            [['NAME', 'C', 10, 0]],
            [['Alice']],
            ['recordCount' => 0x01010101]
        );

        $started = microtime(true);
        $all = DBF::DBFReadBasic($path, 'all');
        $elapsed = microtime(true) - $started;

        $this->assertSame(16843009, $all['header']['RecordCount'], 'the header is reported as it is on disk');
        $this->assertCount(1, $all['records'], 'only the record actually backed by bytes is returned');
        $this->assertSame(['NAME' => 'Alice'], $all['records'][0]);
        $this->assertLessThan(5.0, $elapsed, 'the record loop must be bounded by the file, not by RecordCount');
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

    // ---------------------------------------------------------------- DBFReadBasic: values are data

    /**
     * Replaces testReadBasicDecodesUnicodeEscapeSequencesInValues, which was tautological: its
     * fixture held no escape sequence at all, so it passed whether or not anything was decoded.
     *
     * FINDING (fixed): every value went through Parser::decodeTextArray(), which rewrites a literal
     * "\uXXXX" into a character. On raw DBF bytes that is corruption: this path came back as
     * "C:㫾\x.txt".
     */
    public function testReadBasicDoesNotRewriteLiteralBackslashUSequences(): void {
        $path = $this->makeDbf([['PATH', 'C', 20, 0], ['NOTE', 'C', 12, 0]], [['C:\\ucafe\\x.txt', '\\u00e9 raw']]);

        $record = DBF::DBFReadBasic($path, 'records')[0];

        $this->assertSame('C:\\ucafe\\x.txt', $record['PATH']);
        $this->assertSame('\\u00e9 raw', $record['NOTE']);
    }

    public function testReadBasicReturnsTheBytesUntouchedWhenNoEncodingIsGiven(): void {
        $cp850 = mb_convert_encoding('São Paulo', 'CP850', 'UTF-8');
        $path = $this->makeDbf([['CITY', 'C', 12, 0]], [[$cp850]]);

        $this->assertSame($cp850, DBF::DBFReadBasic($path, 'records')[0]['CITY']);
    }

    /** The actual encoding problem of legacy DBF files: text in a DOS/Windows code page. */
    public function testReadBasicConvertsFieldNamesAndValuesFromTheGivenCodePage(): void {
        $path = $this->makeDbf(
            [['CIDADE', 'C', 12, 0], [mb_convert_encoding('AÇÃO', 'CP850', 'UTF-8'), 'C', 8, 0]],
            [[mb_convert_encoding('São Paulo', 'CP850', 'UTF-8'), mb_convert_encoding('Ênfase', 'CP850', 'UTF-8')]]
        );

        $all = DBF::DBFReadBasic($path, 'all', 'CP850');

        $this->assertSame(['CIDADE', 'AÇÃO'], array_column($all['schema'], 'fieldname'));
        $this->assertSame(['CIDADE' => 'São Paulo', 'AÇÃO' => 'Ênfase'], $all['records'][0]);
    }

    public function testReadBasicConvertsFromWindows1252(): void {
        $path = $this->makeDbf([['NOME', 'C', 12, 0]], [[mb_convert_encoding('João Ñandú', 'CP1252', 'UTF-8')]]);

        $this->assertSame('João Ñandú', DBF::DBFReadBasic($path, 'records', 'CP1252')[0]['NOME']);
    }

    /** CP437 is the dBase default and mbstring does not know it: iconv is the fallback. */
    public function testReadBasicConvertsFromACodePageOnlyIconvKnows(): void {
        if (!function_exists('iconv') || @iconv('CP437', 'UTF-8', "\x82") !== 'é') {
            $this->markTestSkipped('iconv with CP437 support is not available');
        }

        $path = $this->makeDbf([['NAME', 'C', 10, 0]], [["Caf\x82"]]); // "Café" in CP437

        $this->assertSame('Café', DBF::DBFReadBasic($path, 'records', 'CP437')[0]['NAME']);
    }

    public function testReadBasicRejectsAnUnknownEncodingBeforeReadingAnything(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('unknown source encoding');

        DBF::DBFReadBasic($this->tmpDir . DIRECTORY_SEPARATOR . 'does-not-even-exist.dbf', 'records', 'NOT-A-CODEPAGE');
    }

    public function testReadBasicRejectsAnEmptyEncodingInsteadOfFallingBackToTheLocale(): void {
        $this->expectException(\InvalidArgumentException::class);

        DBF::DBFReadBasic($this->samplePath(), 'records', ' ');
    }

    /** Binary-safe read: CR, LF and the 0x1A EOF byte inside a value are data, not structure. */
    public function testReadBasicIsBinarySafeInsideValues(): void {
        $path = $this->makeDbf([['MEMO', 'C', 10, 0], ['N', 'N', 2, 0]], [["A\r\nB\x1AC", '7'], ['next', '8']]);

        $records = DBF::DBFReadBasic($path, 'records');

        $this->assertSame([['MEMO' => "A\r\nB\x1AC", 'N' => '7'], ['MEMO' => 'next', 'N' => '8']], $records);
    }

    // ---------------------------------------------------------------- DBFReadBasic: hostile field tables

    /**
     * FINDING (fixed): records were cut with an unpack() format assembled from the field NAMES
     * ("A10NAME/A3AGE/"). A name is data: "1ST" made the format "A101ST", i.e. a 101-byte field
     * named "ST", and a '/' split one field into two format codes — every later column misaligned
     * or unpack() warned and the read died.
     */
    public function testReadBasicKeepsFieldsAlignedWhateverCharactersTheFieldNamesHold(): void {
        $path = $this->makeDbf(
            [['1ST', 'C', 4, 0], ['A/B', 'C', 3, 0], ['X*', 'C', 2, 0], ['LAST', 'C', 5, 0]],
            [['abcd', 'efg', 'hi', 'jklmn'], ['1234', '567', '89', 'final']]
        );

        $records = DBF::DBFReadBasic($path, 'records');

        $this->assertSame(
            [
                ['1ST' => 'abcd', 'A/B' => 'efg', 'X*' => 'hi', 'LAST' => 'jklmn'],
                ['1ST' => '1234', 'A/B' => '567', 'X*' => '89', 'LAST' => 'final'],
            ],
            $records
        );
    }

    /** A name ends at its first NUL; some writers leave garbage, not NULs, in the padding. */
    public function testReadBasicCutsTheFieldNameAtItsFirstNul(): void {
        $path = $this->makeDbf(
            [['NAME', 'C', 6, 0]],
            [['Alice']],
            ['rawNames' => [0 => "NAME\0GARBAG"]]
        );

        $this->assertSame([['NAME' => 'Alice']], DBF::DBFReadBasic($path, 'records'));
        $this->assertSame('NAME', DBF::DBFReadBasic($path, 'schema')[0]['fieldname']);
    }

    /**
     * FINDING (fixed): the descriptor loop stopped only at a 0x0D byte or EOF. Without the
     * terminator it read the first record's bytes as a 33rd descriptor, added a phantom field
     * named after the data, and misaligned every record. The header length bounds the table.
     */
    public function testReadBasicBoundsTheFieldTableByTheHeaderLengthWhenTheTerminatorIsMissing(): void {
        $path = $this->makeDbf(
            [['NAME', 'C', 10, 0], ['AGE', 'N', 3, 0]],
            [['Alice', '30'], ['Bob', '7']],
            ['terminator' => ' ']
        );

        $all = DBF::DBFReadBasic($path, 'all');

        $this->assertSame(['NAME', 'AGE'], array_column($all['schema'], 'fieldname'));
        $this->assertSame([['NAME' => 'Alice', 'AGE' => '30'], ['NAME' => 'Bob', 'AGE' => '7']], $all['records']);
    }

    /** Duplicate names are malformed; the documented outcome is "the later field wins". */
    public function testReadBasicDuplicateFieldNamesCollapseOntoTheLaterField(): void {
        $path = $this->makeDbf([['CODE', 'C', 3, 0], ['CODE', 'C', 3, 0]], [['one', 'two']]);

        $all = DBF::DBFReadBasic($path, 'all');

        $this->assertCount(2, $all['schema'], 'the schema still reports both descriptors');
        $this->assertSame([['CODE' => 'two']], $all['records']);
    }

    /** RecordLength must cover the deleted flag plus every field, or no record can be cut. */
    public function testReadBasicReturnsNoRecordsWhenRecordLengthCannotHoldTheFields(): void {
        $path = $this->makeDbf([['NAME', 'C', 10, 0]], [['Alice']]);
        $bytes = file_get_contents($path);
        $bytes = substr_replace($bytes, pack('v', 10), 10, 2); // 10 < 1 + 10
        $path = $this->writeTmp('short-reclen.dbf', $bytes);

        $all = DBF::DBFReadBasic($path, 'all');

        $this->assertCount(1, $all['schema']);
        $this->assertSame([], $all['records']);
    }
}
