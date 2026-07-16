<?php

namespace VD\PHPHelper;

class SQL {
    /**
     * Escapes and wraps a value for use in SQL queries.
     *
     * @param mixed $data The value to be formatted and escaped
     *
     * @return string The formatted string
     *
     * @ref https://stackoverflow.com/questions/574805/how-to-escape-strings-in-sql-server-using-php
     */
    public static function escapeString(mixed $data): mixed {
        if ($data === NULL) {
            return 'NULL';
        }
        if (is_string($data)) {
            // Escape BOTH backslash and single quote. Doubling only the quote (old behavior) let a
            // trailing backslash (input "a\") escape the closing quote under MySQL's default
            // sql_mode and break out of the literal -> SQL injection.
            // WARNING: manual escaping is NOT multibyte-safe on non-UTF-8 connections. Prefer
            // PARAMETERIZED queries (PDO/mysqli prepared statements) for all untrusted input; use
            // this helper only when a bound parameter is genuinely impossible.
            $escaped = str_replace(['\\', "'"], ['\\\\', "\\'"], Str::removeInvisibleCharacters($data));
            return "'" . $escaped . "'";
        }
        if (is_bool($data)) {
            return (int) $data;
        }

        return $data;
    }

    /**
     * Encrypts a string using a key with the "aes-256-gcm" algorithm.
     *
     * Thin pass-through to {@see Security::encryptDataDB()}; see it for the envelope format.
     *
     * @param mixed $str The string to encrypt
     * @param string $key The encryption key
     * @param string $aad Context to bind, e.g. "{table}.{column}:{row_id}". REQUIRED and should be
     *                    unique per logical cell. An empty AAD is rejected to forbid an unbound value.
     * @param string|null $salt Optional per-subject salt for key derivation
     *
     * @return string
     * @throws \Exception
     */
    public static function encryptDataDB(mixed $str, string $key, string $aad, ?string $salt = ""): string {
        return Security::encryptDataDB($str, $key, $aad, $salt);
    }

    /**
     * Decrypts a message after verifying its integrity using "aes-256-gcm".
     *
     * Thin pass-through to {@see Security::decryptDataDB()}. Fails loud: on a wrong key, a wrong
     * AAD, a tampered envelope or an unknown version it THROWS — it never returns a falsy value a
     * caller could mistake for success.
     *
     * @param string|null $str Encrypted message
     * @param string $key Encryption key
     * @param string $aad The same context bound at encryption time. REQUIRED.
     * @param string|null $salt The same per-subject salt used at encryption time
     *
     * @return string
     * @throws \Exception
     */
    public static function decryptDataDB(?string $str, string $key, string $aad, ?string $salt = ""): string {
        return Security::decryptDataDB($str, $key, $aad, $salt);
    }

    /**
     * Prepares a MySQL INSERT or INSERT ON DUPLICATE KEY UPDATE SQL statement from a dataset.
     *
     * Dynamically builds the query using provided column mappings and a row formatter function.
     * Supports partial batch execution based on memory usage and removes processed rows from the dataset.
     *
     * @param array $data Reference to the dataset to be inserted (each item must be an associative array)
     * @param string $table Target table name
     * @param string|null $insertFields Comma-separated column names for the INSERT clause. If null, will auto-generate.
     * @param string|null $updateFields Comma-separated column=value pairs for the ON DUPLICATE KEY UPDATE clause. If null, will auto-generate.
     * @param callable|null $rowFormatter Callback function to format each row for SQL. Must return:
     *        - string: a complete value group (e.g. "(1, 'abc')")
     *        - empty string: skip row
     *        - false: abort immediately and return false
     * @param array $global Reference variable passed into the formatter function (e.g. to share column names)
     *
     * @return string|bool A valid SQL string, TRUE if all values processed but no insert needed, or FALSE on error
     */
    public static function prepareInsertOrUpdateMySQL(
        array &$data,
        string $table,
        ?string $insertFields = null,
        ?string $updateFields = null,
        ?callable $rowFormatter = null,
        array $global = []
    ): string|bool {
        if (empty($data)) {
            return true;
        }

        if (empty($table)) {
            return false;
        }

        // Determine columns if not provided
        if ($insertFields === null || $updateFields === null) {
            $insertColumns = [];

            foreach ($data as $row) {
                foreach ($row as $column => $value) {
                    if (is_array($value) || is_object($value) || $value === []) {
                        continue;
                    }

                    $insertColumns[] = $column;
                }
                break; // only use first row
            }

            $insertColumns = array_unique($insertColumns);
            $global['__insert'] = $insertColumns;

            if ($insertFields === null) {
                $insertFields = implode(',', $insertColumns);
            }

            if ($updateFields === null) {
                $updateFields = implode(',', array_map(fn($col) => "$col=VALUES($col)", $insertColumns));
            }
        }

        // Define default row formatter if not provided
        if ($rowFormatter === null) {
            $rowFormatter = function ($row, $index, &$global) {
                $sql = '';

                foreach ($global['__insert'] AS $column) {
                    $value = $row[$column] ?? null;

                    if (is_array($value) || is_object($value) || $value === []) {
                        continue;
                    } elseif ($value === null) {
                        $value = "NULL";
                    } elseif (is_bool($value)) {
                        $value = $value ? 1 : 0;
                    } elseif (is_string($value) || $value === '') {
                        $value = "'" . addslashes($value) . "'";
                    } elseif (
                        filter_var($value, FILTER_VALIDATE_INT) !== false ||
                        filter_var($value, FILTER_VALIDATE_FLOAT) !== false
                    ) {
                        $value = $value * 1;
                    }

                    $sql .= ($sql !== '' ? ',' : '') . $value;
                }

                return $sql !== '' ? "($sql)" : '';
            };
        }

        $insertPrefix = "INSERT INTO $table ($insertFields) VALUES ";
        $updateClause = "ON DUPLICATE KEY UPDATE $updateFields";

        $memory = System::getMemoryUsage();
        $maxBytes = $memory['freeBytes'] / 3;
        $initialUsage = $memory['usageBytes'];
        $processedRows = 0;
        $query = '';

        foreach ($data as $key => $row) {
            $processedRows++;

            if (!is_array($row) && !is_object($row)) {
                continue;
            }

            $currentUsage = System::getMemoryUsage()['usageBytes'] - $initialUsage;
            $values = $rowFormatter($row, $key, $global);

            if ($values === false) {
                return false;
            }

            if ($values === '') {
                continue;
            }

            if ($query === '') {
                $query = $insertPrefix;
            }

            if (Str::strPos($query, $values) !== false) {
                continue;
            }

            $query .= $values;

            if ($currentUsage >= $maxBytes) {
                break;
            }

            $query .= ',';
        }

        if ($processedRows === 0) {
            return true;
        }

        $data = array_slice($data, $processedRows);

        if ($query !== '') {
            $query = trim($query);
            $query = Str::removeStringSuffix($query, ',');
            $query .= ' ' . $updateClause;
            return $query;
        }

        return true;
    }
}