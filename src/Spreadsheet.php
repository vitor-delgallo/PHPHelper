<?php

namespace VD\PHPHelper;

/**
 * Reads spreadsheet files into PHP arrays.
 *
 * Requires the OPTIONAL dependency phpoffice/phpspreadsheet (^5.5). It is declared under composer
 * `require-dev` / `suggest` and NEVER under `require`, so a consumer of this library must install it
 * explicitly. When it is absent, this class throws instead of returning an empty result — a missing
 * dependency must never be mistaken for an empty file.
 */
class Spreadsheet {
    /**
     * Reads a spreadsheet file and returns its cell values as an array.
     *
     * The reader is resolved from the file's CONTENTS, not from its extension, so any format
     * phpoffice/phpspreadsheet can identify is accepted (.xlsx, .xls, .ods, .csv, .html, ...).
     *
     * What a value looks like. Every cell comes back as a string, or null when it is empty.
     *  - Binary/XML workbooks (.xlsx, .xls, .ods, ...) are read DATA-ONLY, and a data-only load does
     *    not read number formats. So a value is its raw content rendered as General — NOT what
     *    Excel displays: a date is its Excel serial number ('45322' for 2024-01-31; convert with
     *    \PhpOffice\PhpSpreadsheet\Shared\Date::excelToDateTimeObject((float) $v)), a percentage is
     *    its fraction ('0.125'), a currency has no symbol or separators, a boolean is 'TRUE' /
     *    'FALSE'. A float is PhpSpreadsheet's General rendering, which round-trips through
     *    (float) but need not be the shortest form (1234567.891234 reads '1234567.89123399998061').
     *    Formulas are recalculated by PhpSpreadsheet (not Excel's cached result, except for
     *    functions it does not implement), so volatile ones (NOW(), RAND()) reflect read time.
     *  - Text formats (.csv/.txt and HTML tables) are returned VERBATIM: every cell is exactly the
     *    text in the file. They used to go through PhpSpreadsheet's default value binder, which
     *    treats text as typed input — "=A2&B2" was EVALUATED as a formula, "+5511987654321" (a
     *    phone number) became "5511987654321", "1e3" became "1000", "1.50" became "1.5".
     *    CSV encoding is detected: a BOM (UTF-8/16/32) wins, then UTF-16/32 by shape, then UTF-8
     *    if the bytes are valid UTF-8, otherwise Windows-1252 — the encoding of Excel's own "CSV"
     *    export on Western-European Windows, which used to come back with every accented letter
     *    replaced by U+FFFD.
     *  - A cell holding only whitespace may read as null (the .xls reader drops such cells, and
     *    styled-but-empty cells are skipped by every reader — see below); both count as blank
     *    under the $removeEmptyRows / header rules anyway.
     * Styling, merges, images and charts are discarded.
     *
     * Memory. The whole workbook is loaded (every sheet, not only the one read). Cells that carry
     * formatting but no value are NOT materialised: a sheet with borders applied down to row
     * 100000 over one row of data used to create a cell object for every formatted cell and walk
     * all 100000 rows. The workbook is disconnected before returning, so a loop importing many
     * files does not accumulate PhpSpreadsheet's reference cycles.
     *
     * Failures THROW — none of them is reported as an empty return. An empty array therefore means
     * exactly one thing: the selected sheet yielded no rows. This is deliberate: the previous
     * contract collapsed "phpspreadsheet is not installed", "that sheet does not exist", "that file
     * does not exist" and "that file is corrupt" into the same [] a genuinely empty sheet returns,
     * which let a bulk import silently commit zero rows and report success.
     *
     * @param string $filePath Path to an existing, readable spreadsheet file. Stream wrappers
     *                         (phar://, etc.) are rejected by the underlying reader.
     * @param bool $withHeader true (default): row 1 is a header row; the result is a 0-indexed list
     *                         of associative rows keyed by the row-1 values, starting at row 2 —
     *                         e.g. [0 => ['Name' => 'Ann', 'Age' => '30']]. Passing true ASSERTS that
     *                         row 1 names every column that carries data, and that those names are
     *                         unique; a file that breaks the assertion THROWS (see below).
     *                         Header names are trimmed — surrounding whitespace is not part of a
     *                         column's name, and keeping it verbatim only means `$row['Name']` misses
     *                         on a file whose header cell reads 'Name '. Trimming is also what makes
     *                         'Name' and 'Name ' collide as duplicates instead of quietly becoming
     *                         two keys the caller only ever reads one of.
     *                         A column whose header cell is blank is UNNAMED. An unnamed column that
     *                         holds no data in any row is dropped — a trailing styled-but-empty
     *                         column is common and discarding it loses nothing. An unnamed column
     *                         that DOES hold data throws, because that is the same silent loss as a
     *                         duplicate: its values previously landed under the key '' (and every
     *                         unnamed column overwrote the previous one).
     *                         false: the result is a 0-indexed list of rows keyed by column letter,
     *                         row 1 INCLUDED — e.g. [0 => ['A' => 'Name', 'B' => 'Age']]. This mode
     *                         is lossless and asserts nothing about row 1: it is the way to read a
     *                         file that $withHeader = true rejects.
     * @param string|null $sheetName Worksheet to read. null (default) — and ONLY null — selects the
     *                               sheet phpspreadsheet reports as active. CAVEAT: this method reads
     *                               data-only, and a data-only load discards the workbook-view
     *                               metadata that records which tab was selected, so for .xlsx the
     *                               "active" sheet is the FIRST worksheet — NOT the tab that was
     *                               active when the file was saved. Other formats' readers may or may
     *                               not preserve it. Pass an explicit name whenever the worksheet
     *                               matters. Every non-null value, INCLUDING '' and '0', is looked up
     *                               by name and throws when no such tab exists. The lookup is
     *                               phpspreadsheet's: CASE-INSENSITIVE, and surrounding single
     *                               quotes are ignored ('data' and "'Data'" both find tab "Data").
     *                               A CSV/HTML file has one sheet, named "Worksheet".
     * @param bool $removeEmptyRows true (default): drop rows whose every cell is null, '' or
     *                              whitespace-only. Dropped rows leave no gap — the result is always
     *                              re-indexed contiguously from 0, so an array index is NOT a
     *                              spreadsheet row number under either $withHeader mode.
     * @return array 0-indexed list of rows, shaped per $withHeader. Empty ONLY when the selected
     *               sheet yielded no rows.
     * @throws \RuntimeException If phpoffice/phpspreadsheet is not installed; if $sheetName is given
     *                           and the workbook has no tab with that name; or, under
     *                           $withHeader = true, if the header row carries a DUPLICATE name or
     *                           leaves a data-bearing column UNNAMED.
     *
     *                           Why throw instead of de-duplicating (e.g. 'Name', 'Name_2'): a
     *                           duplicate header is genuinely AMBIGUOUS — nothing in the file says
     *                           which of the two columns the caller means — and inventing a key
     *                           resolves that ambiguity by guessing. Silently guessing in a bulk
     *                           import path is the exact bug this contract exists to prevent: the
     *                           caller reads $row['Name'], gets one of two columns, and no code path
     *                           ever tells them the other existed. Suffixing would fix the OVERWRITE
     *                           but not the SILENCE. Throwing puts the ambiguity in front of the one
     *                           party who can resolve it, and it costs the caller nothing, because
     *                           $withHeader = false already reads such a file losslessly.
     * @throws \PhpOffice\PhpSpreadsheet\Exception If $filePath is missing, unreadable, of an
     *                           unidentifiable format, or corrupt — including a structurally valid
     *                           container with a damaged part (e.g. a truncated sheet XML inside
     *                           an intact .xlsx zip), and any PHP warning/notice raised while the
     *                           file is parsed. Such a file used to print parser warnings and
     *                           return [] — the exact "silently zero rows" this contract forbids.
     *                           This vendor class extends \RuntimeException, so
     *                           `catch (\RuntimeException $e)` covers every documented failure of
     *                           this method without depending on the vendor type. No other
     *                           exception type escapes.
     */
    public static function excelToArray(
        string $filePath,
        bool $withHeader = true,
        ?string $sheetName = null,
        bool $removeEmptyRows = true
    ): array {
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            throw new \RuntimeException(
                'phpoffice/phpspreadsheet is not installed. It is an optional dependency of '
                . 'vitor-delgallo/phphelper: run `composer require phpoffice/phpspreadsheet` to use '
                . self::class . '::excelToArray().'
            );
        }

        // A damaged part inside an otherwise valid container (a truncated sheet XML in an intact
        // .xlsx zip) makes the vendor parser log an XML error and carry on with an EMPTY sheet.
        // Where that error goes depends on process-wide state: a PHP warning by default, but
        // nothing at all once anything (PHPUnit, a framework) has enabled libxml's internal error
        // buffer — so the buffer is enabled here explicitly and inspected after the load. That is
        // the only way to tell "corrupt" from "empty".
        $libxmlWasInternal = libxml_use_internal_errors(true);
        $libxmlErrorsBefore = count(libxml_get_errors());

        // Anything else the parser warns about (a damaged .xls stream, an unexpected structure)
        // is the same silent-garbage situation, so it throws too. Deprecations are not failures
        // and go to whichever handler was installed before.
        $previousHandler = set_error_handler(
            static function (int $errno, string $errstr, string $errfile = '', int $errline = 0) use ($filePath, &$previousHandler): bool {
                if (!(error_reporting() & $errno)) {
                    return false; // silenced with @ by the vendor on purpose
                }
                if ($errno === E_DEPRECATED || $errno === E_USER_DEPRECATED) {
                    return is_callable($previousHandler)
                        ? (bool) $previousHandler($errno, $errstr, $errfile, $errline)
                        : false;
                }

                throw new \PhpOffice\PhpSpreadsheet\Reader\Exception(
                    sprintf('Unable to read "%s": %s', $filePath, $errstr),
                    0,
                    new \ErrorException($errstr, 0, $errno, $errfile, $errline)
                );
            }
        );

        $spreadsheet = null;
        try {
            // createReaderForFile() identifies the format from the file's contents and hands back
            // the reader it built to do so. IOFactory::identify() builds that same reader
            // internally, throws it away and reports only its class name — which then had to be
            // constructed a second time.
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
            self::configureReader($reader);
            $spreadsheet = $reader->load($filePath);

            // HTML is recovered, not validated: libxml reports every non-HTML4 tag, and the reader
            // already fails outright when nothing could be recovered.
            if (!$reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Html) {
                foreach (array_slice(libxml_get_errors(), $libxmlErrorsBefore) as $error) {
                    if ($error->level >= LIBXML_ERR_ERROR) {
                        throw new \PhpOffice\PhpSpreadsheet\Reader\Exception(sprintf(
                            'Unable to read "%s": malformed XML inside the file (%s at line %d), '
                                . 'so part of it could not be parsed.',
                            $filePath,
                            trim($error->message),
                            $error->line
                        ));
                    }
                }
            }

            return self::sheetToArray($spreadsheet, $filePath, $withHeader, $sheetName, $removeEmptyRows);
        } finally {
            // Restoring "false" also empties the buffer, so no error leaks to the caller unless the
            // caller had the buffer enabled already.
            libxml_use_internal_errors($libxmlWasInternal);
            restore_error_handler();
            // Worksheets and cells reference each other; without this every imported workbook
            // stays in memory until the cycle collector happens to run.
            $spreadsheet?->disconnectWorksheets();
        }
    }

    /**
     * Applies the reading policy documented on excelToArray() to a freshly identified reader.
     *
     * @param \PhpOffice\PhpSpreadsheet\Reader\IReader $reader Reader returned by createReaderForFile().
     */
    private static function configureReader(\PhpOffice\PhpSpreadsheet\Reader\IReader $reader): void {
        $reader->setReadDataOnly(true);
        // Skip cells that carry formatting but no value: they are not data, and materialising a
        // styled range costs a cell object per formatted cell plus a row walk to its bottom.
        $reader->setReadEmptyCells(false);

        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv) {
            $reader->setInputEncoding(\PhpOffice\PhpSpreadsheet\Reader\Csv::GUESS_ENCODING);
            $reader->setFallbackEncoding('CP1252');
        }

        if ($reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Html) {
            // HTML in the wild is not well-formed and the DOM is recovered anyway; without this
            // libxml's recoverable complaints surface as warnings, which excelToArray() treats as
            // a corrupt file.
            $reader->setSuppressLoadWarnings(true);
        }

        if (
            $reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Csv
            || $reader instanceof \PhpOffice\PhpSpreadsheet\Reader\Html
        ) {
            // Text formats carry no types: the file's text IS the value. The default binder would
            // re-type it as if a user were typing into Excel — evaluating "=..." as a formula and
            // turning "+55 11..." or "1e3" into numbers. The StringValueBinder's defaults store
            // every value as a string.
            $reader->setValueBinder(new \PhpOffice\PhpSpreadsheet\Cell\StringValueBinder());
        }
    }

    /**
     * Extracts the selected sheet of a loaded workbook, shaped as excelToArray() documents.
     *
     * @param \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet Loaded workbook.
     * @param string $filePath Only for exception messages.
     * @param bool $withHeader See excelToArray().
     * @param string|null $sheetName See excelToArray().
     * @param bool $removeEmptyRows See excelToArray().
     * @return array See excelToArray().
     * @throws \RuntimeException See excelToArray().
     */
    private static function sheetToArray(
        \PhpOffice\PhpSpreadsheet\Spreadsheet $spreadsheet,
        string $filePath,
        bool $withHeader,
        ?string $sheetName,
        bool $removeEmptyRows
    ): array {
        $data = [];

        // Truthiness would widen "active sheet" from null to '' and '0' as well, so a tab literally
        // named "0" would never be looked up and the caller would silently get the active sheet.
        if ($sheetName !== null) {
            $sheet = $spreadsheet->getSheetByName($sheetName);
            if ($sheet === null) {
                throw new \RuntimeException(sprintf(
                    'Worksheet "%s" not found in "%s".',
                    $sheetName,
                    $filePath
                ));
            }
        } else {
            $sheet = $spreadsheet->getActiveSheet();
        }

        $highestRow = $sheet->getHighestRow();
        $highestColumn = $sheet->getHighestColumn();

        if ($withHeader) {
            $headers = self::resolveHeaders($sheet, $filePath, $highestColumn, $highestRow);

            for ($row = 2; $row <= $highestRow; ++$row) {
                $rowData = $sheet->rangeToArray("A$row:$highestColumn$row", null, true, true, true)[$row];
                if ($removeEmptyRows && self::isRowEmpty($rowData)) {
                    continue;
                }

                // Built as a whole row and appended, so a sheet whose every column was dropped as
                // unnamed-and-empty still yields one entry per surviving row rather than silently
                // shortening the list.
                $rowValues = [];
                foreach ($headers as $col => $header) {
                    $rowValues[$header] = $rowData[$col] ?? null;
                }
                $data[] = $rowValues;
            }
        } else {
            $raw = $sheet->toArray(null, true, true, true);
            foreach ($raw as $row) {
                if ($removeEmptyRows && self::isRowEmpty($row)) {
                    continue;
                }
                $data[] = $row;
            }
        }

        return $data;
    }

    /**
     * Maps each named column of the header row to its name, rejecting a header row that cannot key
     * the sheet without losing a value.
     *
     * Unnamed-and-empty columns are dropped; a duplicate name, or a blank header over a column that
     * carries data, throws. See excelToArray()'s $withHeader / @throws for the reasoning.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Sheet being read.
     * @param string $filePath Only for the exception message.
     * @param string $highestColumn Rightmost column letter holding a cell.
     * @param int $highestRow Bottom-most row holding a cell.
     * @return array<string, string> Column letter => trimmed header name, in column order. Empty
     *                               when the sheet has no named column.
     * @throws \RuntimeException On a duplicate header name, or a data-bearing unnamed column.
     */
    private static function resolveHeaders(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $filePath,
        string $highestColumn,
        int $highestRow
    ): array {
        $raw = $sheet->rangeToArray("A1:{$highestColumn}1", null, true, true, true)[1];

        $headers = [];
        $seenIn = [];
        foreach ($raw as $col => $value) {
            $name = trim((string)($value ?? ''));

            if ($name === '') {
                if (self::isColumnEmpty($sheet, (string)$col, $highestRow)) {
                    continue;
                }

                throw new \RuntimeException(sprintf(
                    'Column %s of "%s" holds data but its header cell %s1 is blank. Every column '
                    . 'that carries data needs a header name when $withHeader is true, because an '
                    . 'unnamed column would be keyed under the empty string and overwritten by the '
                    . 'next one. Read the file with $withHeader = false to get every column keyed '
                    . 'by its column letter instead.',
                    $col,
                    $filePath,
                    $col
                ));
            }

            // Numeric-string names normalise to int keys on the way into $seenIn exactly as they do
            // on the way into a result row, so this lookup detects precisely the names that would
            // have collided.
            if (isset($seenIn[$name])) {
                throw new \RuntimeException(sprintf(
                    'Duplicate header "%s" in "%s": columns %s and %s of the header row carry the '
                    . 'same name, so one column would silently overwrite the other. Header names '
                    . 'must be unique when $withHeader is true. Read the file with '
                    . '$withHeader = false to get every column keyed by its column letter instead.',
                    $name,
                    $filePath,
                    $seenIn[$name],
                    $col
                ));
            }

            $seenIn[$name] = $col;
            $headers[$col] = $name;
        }

        return $headers;
    }

    /**
     * Reports whether a column holds no value below the header row.
     *
     * Uses the same definition of blank as isRowEmpty(), so a column of spaces counts as empty.
     *
     * @param \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet Sheet being read.
     * @param string $column Column letter.
     * @param int $highestRow Bottom-most row holding a cell.
     * @return bool True when rows 2..$highestRow of $column are all blank, including when the sheet
     *              has no row 2 at all.
     */
    private static function isColumnEmpty(
        \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet,
        string $column,
        int $highestRow
    ): bool {
        if ($highestRow < 2) {
            return true;
        }

        $cells = $sheet->rangeToArray("{$column}2:{$column}{$highestRow}", null, true, true, true);
        foreach ($cells as $row) {
            if (!self::isRowEmpty($row)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Reports whether every cell of a row is blank.
     *
     * Blank means null, '' or whitespace-only — a row of spaces counts as empty. Non-string scalars
     * are compared by their string form, so 0 and '0' are NOT blank.
     *
     * @param array $row One row of cell values, as produced by rangeToArray()/toArray().
     * @return bool True when the row holds no non-blank cell.
     */
    private static function isRowEmpty(array $row): bool {
        foreach ($row as $cell) {
            if ($cell !== null && trim((string)$cell) !== '') {
                return false;
            }
        }
        return true;
    }
}
