<?php

namespace VD\PHPHelper;

class DBF {
    /**
     * Normalizes the path of an EXISTING DBF file.
     *
     * Pure validation/normalization helper: it reads the filesystem but NEVER writes to it.
     * No directory or file is created, moved or removed, on success or on failure.
     *
     * @param string $dbfPath Path to the DBF file including the extension. Absolute, or relative
     *                        to the current working directory. "/" and "\" are both accepted.
     *
     * @return string|null The resolved absolute path, when $dbfPath points to a file that ALREADY
     *                     exists. Null when the path is empty, does not exist, or is a directory —
     *                     a null return means "unusable path" and leaves the filesystem untouched.
     *                     (A former "resolved path must be at least 4 characters" rule is gone: it
     *                     protected nothing and refused real files such as "/db" on Unix.)
     */
    public static function DBFFormatPath(string $dbfPath): ?string {
        // createPath is deliberately false: this method only succeeds when the FILE already
        // exists (see the isFile check below), so creating the parent directory could never turn
        // a failure into a success — it would only be an unannounced, path-driven mkdir.
        $dbfPathInfo = File::getPathInfo(path: $dbfPath, keepFile: true, createPath: false);
        $dbfPath = $dbfPathInfo['path'];
        if(empty($dbfPath) || !$dbfPathInfo['isFile']) return null;

        return $dbfPath;
    }

    /**
     * Resolves the converter DBFReadBasic() applies to every field name and value.
     *
     * mbstring first; iconv as the fallback because mbstring lacks several code pages that DBF
     * files are routinely written in (CP437 — the dBase default — CP852, CP1250).
     *
     * @param string|null $encoding Source encoding, or null for "leave the bytes alone".
     * @return \Closure(string): string|null Null when $encoding is null.
     * @throws \InvalidArgumentException When neither mbstring nor iconv knows $encoding.
     */
    private static function textDecoder(?string $encoding): ?\Closure {
        if ($encoding === null) return null;

        // '' would reach iconv as "the locale's charset" — a silent, host-dependent choice.
        if (trim($encoding) === '') {
            throw new \InvalidArgumentException('DBF::DBFReadBasic(): the source encoding cannot be empty; pass null to keep the raw bytes.');
        }

        try {
            mb_convert_encoding('', 'UTF-8', $encoding);
            return static fn (string $text): string => mb_convert_encoding($text, 'UTF-8', $encoding);
        } catch (\ValueError) {
            // not an mbstring encoding; try iconv below
        }

        if (function_exists('iconv') && @iconv($encoding, 'UTF-8', 'a') === 'a') {
            return static function (string $text) use ($encoding): string {
                $converted = @iconv($encoding, 'UTF-8', $text);
                if ($converted === false) {
                    throw new \UnexpectedValueException(sprintf(
                        'DBF::DBFReadBasic(): a value is not valid %s and cannot be converted to UTF-8.',
                        $encoding
                    ));
                }
                return $converted;
            };
        }

        throw new \InvalidArgumentException(sprintf(
            'DBF::DBFReadBasic(): unknown source encoding %s (neither mbstring nor iconv supports it).',
            var_export($encoding, true)
        ));
    }

    /**
     * Reads a dBase III (.dbf) file.
     *
     * Read-only: it opens the file for reading and creates nothing on disk. It writes NOTHING to
     * standard output — every mode, including 'scan', returns its data in the return value.
     *
     * The deleted-record flag is NOT interpreted: a record flagged as deleted in the DBF is
     * returned like any other record (dBase's own default, SET DELETED OFF). 'scan' exposes it as
     * the first byte of each 'raw' buffer ('*' = deleted, ' ' = live).
     *
     * Values are returned as the bytes on disk, trimmed, and are NOT otherwise rewritten — every
     * value is a string, whatever the field type: N/F are decimal text ("12.50"), D is "YYYYMMDD",
     * L is one of "T", "F", "Y", "N", "?", and M (memo) is the block number into the companion
     * .dbt file, not the memo text.
     * BEHAVIOUR CHANGE: every string used to be passed through Parser::decodeTextArray(), which
     * rewrote any literal "\uXXXX" in the data into a character — a Windows path such as
     * "C:\ucafe\x.txt" came back as "C:㫾\x.txt". That decoder undoes json_encode() escaping and
     * has no business on raw DBF bytes. Real text decoding is now $encoding.
     *
     * @param string $dbfPath Path to the DBF file including the extension. Must already exist.
     * @param string|null $mode Read mode. Null, or any value outside the list below, silently
     *                          falls back to 'records'. Available modes:
     *                          'header'    => ['RecordCount' => int, 'FirstRecord' => int, 'RecordLength' => int]
     *                          'schema'    => list of ['fieldname' => string, 'fieldtype' => string,
     *                                         'offset' => int, 'fieldlen' => int, 'fielddec' => int]
     *                                         ('offset' is the descriptor's field-address word as
     *                                         stored — 0 or a stale memory address in many files;
     *                                         the reader does not use it)
     *                          'records'   => list of rows, each keyed by the TRIMMED field name,
     *                                         i.e. exactly the 'fieldname' reported by 'schema'.
     *                                         Two fields with the same name collapse into one key
     *                                         (the later field wins) — such a file is malformed.
     *                          'structure' => ['header' => ..., 'schema' => ...]
     *                          'all'       => ['header' => ..., 'schema' => ..., 'records' => ...]
     *                          'scan'      => debug mode: the 'all' payload plus
     *                                         'raw' => [int $recordIndex => string $rawRecordBuffer],
     *                                         each record's RecordLength bytes exactly as on disk:
     *                                         the deleted flag, then the field data. Never decoded.
     * @param string|null $encoding Code page the DBF text is written in, e.g. 'CP850' or 'CP437'
     *                              (DOS), 'CP1252' (Windows), 'ISO-8859-1'. When given, every field
     *                              name and value is converted from it to UTF-8. Null (default)
     *                              returns the bytes untouched — which is invalid UTF-8 for any
     *                              accented character in a legacy file. The file's language-driver
     *                              byte is not consulted: it is wrong too often to trust.
     *
     * @return array The payload for $mode. EMPTY ARRAY when $dbfPath is not an existing readable
     *               file, or when the file is not a parseable DBF (header shorter than 12 bytes).
     *               A truncated field table or a RecordCount larger than the file actually holds
     *               stops the parse and returns what was read up to that point, rather than
     *               failing: callers that require a complete read must compare the number of
     *               returned records against the header's RecordCount themselves.
     *
     * @throws \InvalidArgumentException When $encoding is not an encoding mbstring or iconv knows.
     * @throws \UnexpectedValueException When a value holds a byte that is not valid in an $encoding
     *                                   only iconv supports — single-byte code pages included: CP1250
     *                                   leaves 0x81, 0x83, 0x88, 0x90 and 0x98 undefined, and one such
     *                                   byte in any record aborts the whole read. (Code pages mbstring
     *                                   serves substitute the byte and carry on.)
     *
     * @ref https://www.php.net/manual/en/book.dbase.php
     */
    public static function DBFReadBasic(string $dbfPath, ?string $mode = null, ?string $encoding = null): array {
        if(
            empty($mode) ||
            !in_array($mode, array('header', 'records', 'schema', 'structure', 'all', 'scan'))
        ) $mode = "records";

        // Resolved before touching the file: a bad encoding is a caller bug, not an unreadable file.
        $decode = self::textDecoder($encoding);

        $dbfPath = self::DBFFormatPath($dbfPath);
        if(empty($dbfPath)) return array();

        $fdbf = @fopen($dbfPath,'rb');
        if($fdbf === false) return array();

        $fields       = array();
        $buf          = fread($fdbf,32);
        // Bytes 4..11 carry RecordCount/FirstRecord/RecordLength. Anything shorter is not a DBF,
        // and unpacking it would warn and yield false where an array is promised.
        if(!is_string($buf) || strlen($buf) < 12) {
            fclose($fdbf);
            return array();
        }
        $header       = unpack( "VRecordCount/vFirstRecord/vRecordLength", substr($buf,4,8));
        if(!is_array($header)) {
            fclose($fdbf);
            return array();
        }

        // Descriptors live strictly inside the header, so FirstRecord bounds the loop. Relying on
        // the 0x0D terminator alone walked straight into the record data of a file that lacks it
        // and parsed field values as field descriptors.
        while (ftell($fdbf) + 32 <= $header['FirstRecord']) {
            $buf = fread($fdbf,32);
            if (!is_string($buf) || strlen($buf) < 18 || $buf[0] === "\x0D") {
                // end of field list (0x0D), or a truncated descriptor we cannot trust
                break;
            }

            $field = unpack( "a11fieldname/A1fieldtype/Voffset/Cfieldlen/Cfielddec", substr($buf,0,18));
            if (!is_array($field)) {
                break;
            }

            // The name ends at its first NUL; what follows is padding, and some writers leave
            // garbage there rather than NULs.
            $name = trim(substr($field['fieldname'], 0, strcspn($field['fieldname'], "\0")));
            if ($name === '' || $field['fieldlen'] < 1) {
                // A nameless or zero-width field cannot be addressed in the record array.
                break;
            }

            $field['fieldname'] = $decode === null ? $name : $decode($name);
            $fields[] = $field;
        }

        $records    = array();
        $rawRecords = array();
        $dataLength = 0;
        foreach ($fields as $field) {
            $dataLength += $field['fieldlen'];
        }

        if($fields !== array() && $header['RecordLength'] > $dataLength) {
            // Each record is RecordLength bytes: a 1-byte deleted flag, then the fields in
            // descriptor order. Fields are cut by position with substr(), NOT with an unpack()
            // format assembled from the field names: a name is data, and a name starting with a
            // digit or holding '/' or '*' rewrote that format — "1ST" turned "A10" into a
            // 101-byte repeat, silently misaligning every field after it.
            fseek($fdbf, $header['FirstRecord']);
            for ($i = 0; $i < $header['RecordCount']; $i++) {
                $buf = fread($fdbf,$header['RecordLength']);
                if (!is_string($buf) || strlen($buf) < 1 + $dataLength) {
                    break; // truncated file, or a RecordCount the file cannot back
                }

                $position = 1;
                foreach ($fields as $field) {
                    $value = trim(substr($buf, $position, $field['fieldlen']));
                    $records[$i][$field['fieldname']] = $decode === null ? $value : $decode($value);
                    $position += $field['fieldlen'];
                }
                if($mode === "scan") {
                    $rawRecords[$i] = $buf;
                }
            }
        }
        fclose($fdbf);

        $ret = array();
        switch ($mode) {
            case "records":
                $ret = $records;
                break;
            case "schema":
                $ret = $fields;
                break;
            case "header":
                $ret = $header;
                break;
            case "structure":
                $ret = array(
                    'header' => $header,
                    'schema' => $fields,
                );
                break;
            case "all":
            case "scan":
                $ret = array(
                    'header' => $header,
                    'schema' => $fields,
                    'records' => $records,
                );
                break;
        }

        if($mode === "scan") {
            $ret['raw'] = $rawRecords;
        }

        return $ret;
    }
}
