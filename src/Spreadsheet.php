<?php

namespace VD\PHPHelper;

class Spreadsheet {
    /**
     * Converts an Excel spreadsheet file into an array.
     *
     * @param string $filePath Path to the Excel file
     * @param bool $withHeader Whether to use the first row as headers (default: true)
     * @param string|null $sheetName Optional: specific worksheet name to load (null = active sheet)
     * @param bool $removeEmptyRows Whether to skip rows with no values at all (default: true)
     * @return array Parsed spreadsheet content
     */
    public static function excelToArray(
        string $filePath,
        bool $withHeader = true,
        ?string $sheetName = null,
        bool $removeEmptyRows = true
    ): array {
        $data = [];
        if (!class_exists(\PhpOffice\PhpSpreadsheet\IOFactory::class)) {
            return $data;
        }

        try {
            $type = \PhpOffice\PhpSpreadsheet\IOFactory::identify($filePath);
            $reader = \PhpOffice\PhpSpreadsheet\IOFactory::createReader($type);
            $reader->setReadDataOnly(true);
            $spreadsheet = $reader->load($filePath);

            /** @var \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet $sheet */
            $sheet = $sheetName
                ? $spreadsheet->getSheetByName($sheetName)
                : $spreadsheet->getActiveSheet();

            if (!$sheet) {
                return []; // Sheet not found
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

        } catch (\PhpOffice\PhpSpreadsheet\Exception $e) {
            // Log or handle exception if needed
            $data = [];
        }

        return $data;
    }

    /**
     * Checks if a given row array is entirely empty.
     *
     * @param array $row A row of cell data
     * @return bool
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