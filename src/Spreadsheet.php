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
     *                         e.g. [0 => ['Name' => 'Ann', 'Age' => '30']]. Header names are used
     *                         verbatim and NOT deduplicated: an empty/null header cell becomes the
     *                         key '', and two columns sharing a header collapse into one — the
     *                         RIGHTMOST column wins.
     *                         false: the result is a 0-indexed list of rows keyed by column letter,
     *                         row 1 INCLUDED — e.g. [0 => ['A' => 'Name', 'B' => 'Age']].
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
     * @throws \RuntimeException If phpoffice/phpspreadsheet is not installed, or if $sheetName is
     *                           given and the workbook has no tab with that name.
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

        $type = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filePath);
        $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($type);
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
            $headers = $sheet->rangeToArray("A1:$highestColumn" . "1", null, true, true, true)[1];

            for ($row = 2, $i = 0; $row <= $highestRow; ++$row) {
                $rowData = $sheet->rangeToArray("A$row:$highestColumn$row", null, true, true, true);
                if ($removeEmptyRows && self::isRowEmpty($rowData[$row])) {
                    continue;
                }

                foreach ($headers as $col => $header) {
                    $data[$i][$header] = $rowData[$row][$col] ?? null;
                }
                $i++;
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
