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
     *                     exists and whose resolved path is at least 4 characters long.
     *                     Null when the path is empty, does not exist, or is a directory — a null
     *                     return means "unusable path" and leaves the filesystem untouched.
     */
    public static function DBFFormatPath(string $dbfPath): ?string {
        // createPath is deliberately false: this method only succeeds when the FILE already
        // exists (see the isFile check below), so creating the parent directory could never turn
        // a failure into a success — it would only be an unannounced, path-driven mkdir.
        $dbfPathInfo = File::getPathInfo(path: $dbfPath, keepFile: true, createPath: false);
        $dbfPath = $dbfPathInfo['path'];
        if(
            empty($dbfPath) ||
            !$dbfPathInfo['isFile'] ||
            Str::strLen($dbfPath) < 4
        ) return null;

        return $dbfPath;
    }

    /**
     * Reads a dBase III (.dbf) file.
     *
     * Read-only: it opens the file for reading and creates nothing on disk. It writes NOTHING to
     * standard output — every mode, including 'scan', returns its data in the return value.
     *
     * The deleted-record flag is NOT interpreted: a record flagged as deleted in the DBF is
     * returned like any other record.
     *
     * @param string $dbfPath Path to the DBF file including the extension. Must already exist.
     * @param string|null $mode Read mode. Null, or any value outside the list below, silently
     *                          falls back to 'records'. Available modes:
     *                          'header'    => ['RecordCount' => int, 'FirstRecord' => int, 'RecordLength' => int]
     *                          'schema'    => list of ['fieldname' => string, 'fieldtype' => string,
     *                                         'offset' => int, 'fieldlen' => int, 'fielddec' => int]
     *                          'records'   => list of rows, each keyed by the TRIMMED field name,
     *                                         i.e. exactly the 'fieldname' reported by 'schema'
     *                          'structure' => ['header' => ..., 'schema' => ...]
     *                          'all'       => ['header' => ..., 'schema' => ..., 'records' => ...]
     *                          'scan'      => debug mode: the 'all' payload plus
     *                                         'raw' => [int $recordIndex => string $rawRecordBuffer],
     *                                         the undecoded bytes of each record as read from disk
     *
     * @return array The payload for $mode. EMPTY ARRAY when $dbfPath is not an existing readable
     *               file, or when the file is not a parseable DBF (header shorter than 12 bytes).
     *               A truncated field table or a RecordCount larger than the file actually holds
     *               stops the parse and returns what was read up to that point, rather than
     *               failing: callers that require a complete read must compare the number of
     *               returned records against the header's RecordCount themselves.
     *
     * @ref https://www.php.net/manual/en/book.dbase.php
     */
    public static function DBFReadBasic(string $dbfPath, ?string $mode = null): array {
        if(
            empty($mode) ||
            !in_array($mode, array('header', 'records', 'schema', 'structure', 'all', 'scan'))
        ) $mode = "records";

        $dbfPath = self::DBFFormatPath($dbfPath);
        if(empty($dbfPath)) return array();

        $fdbf = @fopen($dbfPath,'r');
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

        $goon         = true;
        $unpackString = '';

        while ($goon && !feof($fdbf)) {
            // read fields:
            $buf = fread($fdbf,32);
            if (!is_string($buf) || strlen($buf) < 18 || substr($buf,0,1) == chr(13)) {
                // end of field list (0x0D), or a truncated descriptor we cannot trust
                $goon = false;
                continue;
            }

            $field = unpack( "a11fieldname/A1fieldtype/Voffset/Cfieldlen/Cfielddec", substr($buf,0,18));
            if (!is_array($field)) {
                $goon = false;
                continue;
            }

            // Trim BEFORE building the unpack format: the raw name is NUL-padded to 11 bytes, and
            // an untrimmed name here would key every record by "NAME\0\0\0..." instead of "NAME",
            // contradicting the field names reported by 'schema'.
            $field['fieldname'] = trim($field['fieldname']);
            if ($field['fieldname'] === '' || $field['fieldlen'] < 1) {
                // A nameless or zero-width field cannot be addressed in the record array.
                $goon = false;
                continue;
            }

            $unpackString .= "A$field[fieldlen]$field[fieldname]/";
            $fields[] = $field;
        }

        $records    = array();
        $rawRecords = array();
        if($unpackString !== '' && $header['RecordLength'] > 0) {
            $dataLength = 0;
            foreach ($fields as $field) {
                $dataLength += $field['fieldlen'];
            }

            fseek($fdbf, ($header['FirstRecord'] + 1)); // move to the first record, past its deleted flag
            for ($i = 0; $i < $header['RecordCount']; $i++) {
                $buf = fread($fdbf,$header['RecordLength']);
                if (!is_string($buf) || strlen($buf) < $dataLength) {
                    break; // truncated file, or a RecordCount the file cannot back
                }

                $record = unpack($unpackString,$buf);
                if (!is_array($record)) {
                    break;
                }

                foreach ($record AS $key => $item){
                    $records[$i][$key] = trim($item);
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

        $ret = Parser::decodeTextArray($ret);
        if($mode === "scan") {
            // Attached after decoding: the point of 'raw' is to show the bytes as they are on
            // disk, so they must not be run through the text decoder.
            $ret['raw'] = $rawRecords;
        }

        return $ret;
    }
}
