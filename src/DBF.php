<?php

namespace VD\PHPHelper;

class DBF {
    /**
     * Formats the path to the DBF file.
     *
     * @param string $dbfPath Path to the DBF file including the extension
     *
     * @return string|null
     */
    public static function DBFFormatPath(string $dbfPath): ?string {
        $dbfPath = File::getPath(dir: $dbfPath, createPath: TRUE);
        if(
            empty($dbfPath) ||
            Str::strLen($dbfPath) < 4
        ) return null;

        $dbfPath = realpath($dbfPath);
        if(empty($dbfPath)) return null;

        return $dbfPath;
    }

    /**
     * Reads a DBF file.
     *
     * @param string $dbfPath Path to the DBF file including the extension
     * @param string|null $mode Defines the read mode for the DBF. The following modes are available:
     *                          'header'    => Retrieves the DBF header
     *                          'schema'    => Retrieves the DBF fields
     *                          'records'   => Retrieves the DBF records
     *                          'structure' => Retrieves both the header and the fields
     *                          'all'       => Retrieves the header, fields, and records
     *                          'scan'      => Debug mode for the DBF
     *
     * @return array
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

        $fdbf         = fopen($dbfPath,'r');
        $fields       = array();
        $buf          = fread($fdbf,32);
        $header       = unpack( "VRecordCount/vFirstRecord/vRecordLength", substr($buf,4,8));
        if($mode === "scan") {
            echo 'Header: '.Parser::decodeText(json_encode($header)).'<br />';
        }

        $goon         = true;
        $unpackString = '';

        while ($goon && !feof($fdbf)) {
            // read fields:
            $buf = fread($fdbf,32);
            if (substr($buf,0,1) == chr(13)) {
                // end of field list
                $goon = false;
            } else {
                $field         = unpack( "a11fieldname/A1fieldtype/Voffset/Cfieldlen/Cfielddec", substr($buf,0,18));
                if($mode === "scan") {
                    echo 'Field: '.Parser::decodeText(json_encode($field)).'<br />';
                }
                $unpackString .= "A$field[fieldlen]$field[fieldname]/";
                $field['fieldname'] = trim($field['fieldname']);
                $fields[] = $field;
            }
        }

        $records = array();
        fseek($fdbf, ($header['FirstRecord'] + 1)); // move back to the start of the first record (after the field definitions)
        for ($i = 0; $i < $header['RecordCount']; $i++) {
            $buf    = fread($fdbf,$header['RecordLength']);
            $record = unpack($unpackString,$buf);
            foreach ($record AS $key => $item){
                $records[$i][$key] = trim($item);
            }
            if($mode === "scan") {
                echo 'Record: '.Parser::decodeText(json_encode($record)).'<br />';
                echo '$i: ' . $i . ' | $buf: ' . Parser::decodeText($buf) . '<br/>';
            }
        } //raw record
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
                $ret = array(
                    'header' => $header,
                    'schema' => $fields,
                    'records' => $records,
                );
                break;
            case "scan":
                break;
        }

        return Parser::decodeTextArray($ret);
    }
}