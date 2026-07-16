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
     * Cells are read data-only: values are the FORMATTED strings (or null for an empty cell) with
     * formulas calculated; styling, merges and charts are discarded.
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
     *                               by name and throws when no such tab exists.
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
     *                           unidentifiable format, or corrupt. This vendor class extends
     *                           \RuntimeException, so `catch (\RuntimeException $e)` covers every
     *                           documented failure of this method without depending on the vendor
     *                           type. No other exception type escapes.
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

        $data = [];

        // createReaderForFile() identifies the format from the file's contents and hands back the
        // reader it built to do so. IOFactory::identify() builds that same reader internally, throws
        // it away and reports only its class name — which then had to be constructed a second time.
        // Both raise the identical Reader\Exception for a missing/unreadable/unidentifiable file.
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReaderForFile($filePath);
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($filePath);

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
