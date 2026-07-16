<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Spreadsheet;

/**
 * Contract tests for VD\PHPHelper\Spreadsheet.
 *
 * Every fixture is a REAL workbook written to a temp file with phpoffice/phpspreadsheet and read
 * back through excelToArray(), so the assertions pin the actual round-trip and not a mock.
 */
final class SpreadsheetTest extends TestCase {
    /** @var string[] Absolute paths created by a test; removed in tearDown(). */
    private array $tempFiles = [];

    protected function tearDown(): void {
        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    /**
     * Reserves a unique path inside the system temp dir and registers it for cleanup.
     */
    private function tempPath(string $extension = 'xlsx'): string {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR
            . 'vdph_spreadsheet_' . bin2hex(random_bytes(8)) . '.' . $extension;
        $this->tempFiles[] = $path;

        return $path;
    }

    /**
     * Writes a real .xlsx.
     *
     * @param array<string, array<int, array<int, mixed>>> $sheets Sheet title => rows of cell values.
     * @param int $activeSheetIndex Index of the sheet the workbook opens on.
     * @return string Absolute path to the written file.
     */
    private function makeXlsx(array $sheets, int $activeSheetIndex = 0): string {
        $book = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $book->removeSheetByIndex(0);

        $index = 0;
        foreach ($sheets as $title => $rows) {
            $sheet = $book->createSheet($index);
            $sheet->setTitle((string)$title);
            $sheet->fromArray($rows, null, 'A1', true);
            $index++;
        }

        $book->setActiveSheetIndex($activeSheetIndex);

        $path = $this->tempPath();
        $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($book);
        $writer->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    // ---------------------------------------------------------------- happy paths

    public function testWithHeaderReturnsZeroIndexedRowsKeyedByHeaderCells(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', 'Age'],
            ['Ann', 30],
            ['Bob', 41],
        ]]);

        self::assertSame(
            [
                ['Name' => 'Ann', 'Age' => '30'],
                ['Name' => 'Bob', 'Age' => '41'],
            ],
            Spreadsheet::excelToArray($path)
        );
    }

    public function testWithoutHeaderReturnsRowsKeyedByColumnLetterAndIncludesRowOne(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', 'Age'],
            ['Ann', 30],
        ]]);

        self::assertSame(
            [
                ['A' => 'Name', 'B' => 'Age'],
                ['A' => 'Ann', 'B' => '30'],
            ],
            Spreadsheet::excelToArray($path, false)
        );
    }

    public function testEmptyCellInsideARowIsPreservedAsNull(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', 'Middle', 'Age'],
            ['Ann', null, 30],
        ]]);

        $rows = Spreadsheet::excelToArray($path);

        self::assertCount(1, $rows);
        self::assertArrayHasKey('Middle', $rows[0]);
        self::assertNull($rows[0]['Middle']);
    }

    public function testNamedSheetIsReadInsteadOfTheActiveSheet(): void {
        $path = $this->makeXlsx(
            [
                'First'  => [['Name'], ['from-first']],
                'Second' => [['Name'], ['from-second']],
            ],
            activeSheetIndex: 0
        );

        self::assertSame(
            [['Name' => 'from-second']],
            Spreadsheet::excelToArray($path, true, 'Second')
        );
    }

    // ------------------------------------------- documented boundaries of the return shape

    public function testEmptyArrayIsReturnedOnlyForASheetThatYieldsNoRows(): void {
        // A real, readable, well-formed workbook whose sheet has no data at all: the ONE case the
        // documented contract allows [] for.
        $path = $this->makeXlsx(['Blank' => []]);

        self::assertSame([], Spreadsheet::excelToArray($path));
        self::assertSame([], Spreadsheet::excelToArray($path, false));
    }

    // -------------------------------------------- finding: $withHeader silently lost columns
    //
    // The three tests below replace two pass-1 tests that pinned the OLD, lossy behavior
    // (testDuplicatedHeadersCollapseAndTheRightmostColumnWins,
    // testBlankHeaderCellBecomesTheEmptyStringKey). That behavior is now a deliberate throw, so the
    // assertions were rewritten to pin the NEW contract rather than deleted.

    /**
     * Was: two 'Name' columns collapsed into one key and the RIGHTMOST silently won, so a bulk
     * import read a value from a column it never asked for and nothing reported the other. The
     * ambiguity is unresolvable inside the reader, so it is handed to the caller.
     */
    public function testDuplicatedHeadersThrowInsteadOfCollapsingOntoOneKey(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', 'Name'],
            ['left', 'right'],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate header "Name"');

        Spreadsheet::excelToArray($path);
    }

    /**
     * Header names are trimmed, so 'Name' and 'Name ' are the same column name. Untrimmed they were
     * two distinct keys and the caller reading $row['Name'] silently never saw the second column;
     * trimmed without this check they would collapse. Either way a value went missing without a word.
     */
    public function testHeadersDifferingOnlyByWhitespaceThrowAsDuplicates(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', 'Name '],
            ['left', 'right'],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate header "Name"');

        Spreadsheet::excelToArray($path);
    }

    /** Surrounding whitespace is not part of a column's name. */
    public function testHeaderNamesAreTrimmed(): void {
        $path = $this->makeXlsx(['Data' => [
            ['  Name  ', "Age\n"],
            ['Ann', 30],
        ]]);

        self::assertSame([['Name' => 'Ann', 'Age' => '30']], Spreadsheet::excelToArray($path));
    }

    /**
     * Was: the blank header became the key '' and 'orphan' was returned under it — and a second
     * unnamed column would have overwritten it. An unnamed column carrying data is the same silent
     * loss as a duplicate, so it throws.
     */
    public function testBlankHeaderOverAColumnWithDataThrowsInsteadOfKeyingItUnderTheEmptyString(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', null],
            ['Ann', 'orphan'],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('holds data but its header cell B1 is blank');

        Spreadsheet::excelToArray($path);
    }

    /**
     * The other half of the blank-header rule: an unnamed column with nothing under it is dropped.
     * Discarding it loses no value, and a trailing empty column is too common to reject a file over.
     */
    public function testBlankHeaderOverAnEmptyColumnIsDroppedRatherThanKeyedUnderTheEmptyString(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', null],
            ['Ann', null],
            ['Bob', '   '], // whitespace-only is blank by the documented definition
        ]]);

        $rows = Spreadsheet::excelToArray($path);

        self::assertSame([['Name' => 'Ann'], ['Name' => 'Bob']], $rows);
        foreach ($rows as $row) {
            self::assertArrayNotHasKey('', $row);
        }
    }

    /**
     * A header row that names nothing while the rows below carry data is not a header row at all.
     */
    public function testEntirelyBlankHeaderRowOverDataThrows(): void {
        $path = $this->makeXlsx(['Data' => [
            [null, null],
            ['Ann', '30'],
        ]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Column A of');

        Spreadsheet::excelToArray($path);
    }

    /**
     * The documented escape hatch, and the reason throwing costs the caller nothing: every file the
     * $withHeader = true rules reject is still readable, losslessly, keyed by column letter. Both
     * 'Name' columns survive here — which is exactly what the old collapse destroyed.
     */
    public function testWithHeaderFalseReadsADuplicateHeaderFileLosslessly(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', 'Name'],
            ['left', 'right'],
        ]]);

        self::assertSame(
            [
                ['A' => 'Name', 'B' => 'Name'],
                ['A' => 'left', 'B' => 'right'],
            ],
            Spreadsheet::excelToArray($path, false)
        );
    }

    /** Same escape hatch for the unnamed-column rejection: nothing is lost, the key is the letter. */
    public function testWithHeaderFalseReadsABlankHeaderFileLosslessly(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', null],
            ['Ann', 'orphan'],
        ]]);

        self::assertSame(
            [
                ['A' => 'Name', 'B' => null],
                ['A' => 'Ann', 'B' => 'orphan'],
            ],
            Spreadsheet::excelToArray($path, false)
        );
    }

    public function testRemoveEmptyRowsDropsWhitespaceOnlyRowsAndReindexesContiguously(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name', 'Age'],
            ['Ann', 30],
            ['   ', ''],   // whitespace-only: blank by the documented definition
            ['Bob', 41],
        ]]);

        $rows = Spreadsheet::excelToArray($path);

        // Index is NOT a spreadsheet row number: the gap is closed.
        self::assertSame([0, 1], array_keys($rows));
        self::assertSame(
            [
                ['Name' => 'Ann', 'Age' => '30'],
                ['Name' => 'Bob', 'Age' => '41'],
            ],
            $rows
        );
    }

    public function testRemoveEmptyRowsFalseKeepsBlankRows(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Name'],
            ['Ann'],
            [null],
            ['Bob'],
        ]]);

        $rows = Spreadsheet::excelToArray($path, true, null, false);

        self::assertCount(3, $rows);
        self::assertSame(['Name' => 'Ann'], $rows[0]);
        self::assertSame(['Name' => null], $rows[1]);
        self::assertSame(['Name' => 'Bob'], $rows[2]);
    }

    public function testZeroIsNotTreatedAsABlankCellByTheEmptyRowFilter(): void {
        $path = $this->makeXlsx(['Data' => [
            ['Qty'],
            [0],
        ]]);

        self::assertSame([['Qty' => '0']], Spreadsheet::excelToArray($path));
    }

    // ------------------------------------------------ finding: '0'/'' sheet names were falsy

    /**
     * Regression for the audit finding "$sheetName values '0' and '' silently load the ACTIVE
     * sheet". The old `$sheetName ? getSheetByName(...) : getActiveSheet()` branched on truthiness,
     * so a tab literally named "0" was never looked up and the caller silently got the active
     * sheet's data. Fails without the `!== null` fix.
     */
    public function testSheetNamedZeroIsLookedUpByNameAndNotSilentlyReplacedByTheActiveSheet(): void {
        $path = $this->makeXlsx(
            [
                'Main' => [['Name'], ['from-active-sheet']],
                '0'    => [['Name'], ['from-sheet-zero']],
            ],
            activeSheetIndex: 0
        );

        self::assertSame(
            [['Name' => 'from-sheet-zero']],
            Spreadsheet::excelToArray($path, true, '0')
        );
    }

    /**
     * Companion to the above: '' is a name no workbook can carry, so it must fail loudly rather
     * than fall through to the active sheet. Without the `!== null` fix this returned the active
     * sheet's rows and threw nothing.
     */
    public function testEmptyStringSheetNameThrowsInsteadOfSilentlyLoadingTheActiveSheet(): void {
        $path = $this->makeXlsx(['Main' => [['Name'], ['from-active-sheet']]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Worksheet "" not found');

        Spreadsheet::excelToArray($path, true, '');
    }

    /**
     * Pins the documented CAVEAT on the null default, which is a trap worth freezing in a test.
     *
     * excelToArray() reads data-only, and a data-only .xlsx load discards the workbook-view metadata
     * that records the selected tab — so getActiveSheet() hands back the FIRST worksheet even though
     * this fixture was saved with the second tab active. "null = active sheet" therefore does NOT
     * mean "the tab the user was looking at". If a future change makes the reader honour the saved
     * active tab, this test fails and the docblock caveat must be revisited.
     */
    public function testNullSheetNameSelectsTheFirstTabBecauseDataOnlyReadingDiscardsTheSavedActiveTab(): void {
        $path = $this->makeXlsx(
            [
                'First'  => [['Name'], ['from-first']],
                'Second' => [['Name'], ['from-second']],
            ],
            activeSheetIndex: 1
        );

        // Saved with 'Second' active; read back as 'First'.
        self::assertSame([['Name' => 'from-first']], Spreadsheet::excelToArray($path, true, null));

        // Which is exactly why an explicit name is the only reliable way to target a worksheet.
        self::assertSame(
            [['Name' => 'from-second']],
            Spreadsheet::excelToArray($path, true, 'Second')
        );
    }

    // ------------------------------------- finding: every failure returned [] as a silent sentinel

    /**
     * Regression for the audit finding "every failure path returns [] as a silent sentinel".
     * A missing file used to be swallowed by the catch into [], so a bulk import read zero rows
     * from a file that was never there and reported success. Fails without the fix.
     */
    public function testMissingFileThrowsInsteadOfReturningAnEmptyArray(): void {
        $missing = $this->tempPath();
        self::assertFileDoesNotExist($missing);

        $this->expectException(\PhpOffice\PhpSpreadsheet\Exception::class);

        Spreadsheet::excelToArray($missing);
    }

    /**
     * Same finding: a file whose format no reader can identify used to come back as []. Fails
     * without the fix.
     *
     * The fixture must be binary — phpspreadsheet's Csv reader accepts ANY plain text (see
     * testReaderIsResolvedFromFileContentsNotFromTheExtension), so only bytes no reader claims
     * actually reach the unidentifiable path.
     */
    public function testUnidentifiableFileThrowsInsteadOfReturningAnEmptyArray(): void {
        $path = $this->tempPath('txt');
        file_put_contents($path, "\x00\x01\x02\xFF\xFE\x7F\x00\x99\x88");

        $this->expectException(\PhpOffice\PhpSpreadsheet\Exception::class);
        $this->expectExceptionMessage('Unable to identify a reader for this file');

        Spreadsheet::excelToArray($path);
    }

    /**
     * The documented "reader is resolved from the file's CONTENTS, not from its extension": a .txt
     * holding CSV is read as CSV rather than rejected.
     */
    public function testReaderIsResolvedFromFileContentsNotFromTheExtension(): void {
        $path = $this->tempPath('txt');
        file_put_contents($path, "Name,Age\nAnn,30\n");

        self::assertSame([['Name' => 'Ann', 'Age' => '30']], Spreadsheet::excelToArray($path));
    }

    /**
     * Same finding: a typo'd or renamed tab used to come back as [] — indistinguishable from a tab
     * that exists and is empty. Fails without the fix.
     */
    public function testUnknownSheetNameThrowsInsteadOfReturningAnEmptyArray(): void {
        $path = $this->makeXlsx(['Main' => [['Name'], ['Ann']]]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Worksheet "Typo" not found');

        Spreadsheet::excelToArray($path, true, 'Typo');
    }

    /**
     * The documented single-catch contract: every failure this method declares is a
     * \RuntimeException, so a caller can catch that one type without depending on the vendor class.
     */
    public function testEveryDocumentedFailureIsCatchableAsARuntimeException(): void {
        $book = $this->makeXlsx(['Main' => [['Name'], ['Ann']]]);
        $missing = $this->tempPath();
        $garbage = $this->tempPath('txt');
        file_put_contents($garbage, "\x00\x01\x02\xFF\xFE\x7F\x00\x99\x88"); // binary: no reader claims it
        $duplicate = $this->makeXlsx(['Data' => [['Name', 'Name'], ['left', 'right']]]);
        $unnamed = $this->makeXlsx(['Data' => [['Name', null], ['Ann', 'orphan']]]);

        $cases = [
            'missing file'       => fn() => Spreadsheet::excelToArray($missing),
            'unidentifiable'     => fn() => Spreadsheet::excelToArray($garbage),
            'unknown sheet name' => fn() => Spreadsheet::excelToArray($book, true, 'Nope'),
            'duplicate header'   => fn() => Spreadsheet::excelToArray($duplicate),
            'unnamed column'     => fn() => Spreadsheet::excelToArray($unnamed),
        ];

        foreach ($cases as $label => $call) {
            // Catch \Throwable, never \RuntimeException: PHPUnit's own AssertionFailedError extends
            // RuntimeException, so a catch of that type would swallow a self::fail() and let this
            // test pass while nothing threw at all.
            $caught = null;
            try {
                $call();
            } catch (\Throwable $e) {
                $caught = $e;
            }

            self::assertInstanceOf(
                \RuntimeException::class,
                $caught,
                "$label must fail loudly with a \\RuntimeException, not return a silent sentinel"
            );
            self::assertNotSame('', $caught->getMessage(), "$label threw with an empty message");
        }
    }
}
