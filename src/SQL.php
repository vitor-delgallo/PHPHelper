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
            return "'" . str_replace("'", "''", Str::removeInvisibleCharacters($data)) . "'";
        }
        if (is_bool($data)) {
            return (int) $data;
        }

        return $data;
    }

    /**
     * Encrypts a string using a key with the "aes-256-gcm" algorithm.
     *
     * @param string|float|int|bool|null $str The string to encrypt
     * @param string|null $key The encryption key
     *
     * @return string
     * @throws \Exception
     */
    public static function encryptDataDB(string|float|int|bool|null $str, ?string $key): string {
        return Security::encryptDataDB($str, $key);
    }

    /**
     * Decrypts a message after verifying its integrity using "aes-256-gcm".
     *
     * @param string|null $str Encrypted message
     * @param string|null $key Encryption key
     *
     * @return string|false Decrypted text or FALSE if an error occurs
     * @throws \Exception
     */
    public static function decryptDataDB(?string $str, ?string $key): string|false {
        return Security::decryptDataDB($str, $key);
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

    /**
     * Builds a MySQL query string formatted for DataTables server-side usage.
     *
     * This function creates a SELECT query with JOINs, WHERE, ORDER, and pagination, including a subquery for total count.
     *
     * @param array $selectFields Array of SELECT fields (e.g., ['id', 'name'])
     * @param string $fromClause The FROM clause (e.g., 'users u')
     * @param array $joinClauses Array of JOIN clauses (e.g., ['INNER JOIN roles r ON r.id = u.role_id'])
     * @param string|null $whereClause Optional WHERE clause (e.g., 'u.active = 1')
     * @param array $orderBy Array of ORDER BY parts (e.g., ['u.name ASC', 'u.id DESC'])
     * @param int|null $limit Optional LIMIT value
     * @param int|null $offset Optional OFFSET value
     *
     * @return string Final SQL query string formatted for DataTables
     */
    public static function buildDataTableQueryFromParts(
        array $selectFields,
        string $fromClause,
        array $joinClauses = [],
        ?string $whereClause = null,
        array $orderBy = [],
        ?int $limit = null,
        ?int $offset = null
    ): string {
        return DataTable::buildDataTableQueryFromParts($selectFields, $fromClause, $joinClauses, $whereClause, $orderBy, $limit, $offset);
    }
}