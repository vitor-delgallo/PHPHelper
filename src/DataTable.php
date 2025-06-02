<?php

namespace VD\PHPHelper;

class DataTable {
    /**
     * Parses individual DataTables server-side POST parameters into a structured array
     * for database queries, including paging, search and ordering.
     *
     * @param int|null $draw Draw counter for DataTables
     * @param int|null $start Pagination offset
     * @param int|null $length Number of records per page
     * @param mixed $columns Column definitions (array, JSON string or base64 encoded string)
     * @param mixed $search Global search parameter (array, JSON string or base64 encoded string)
     * @param mixed $order Sorting configuration (array, JSON string or base64 encoded string)
     *
     * @return array{
     *     draw: int|null,
     *     offset: int,
     *     limit: int,
     *     searchs: array,
     *     orders: array
     * }
     *
     * @link https://datatables.net/manual/server-side
     */
    public static function parseDataTableParams(
        ?int $draw,
        ?int $start,
        ?int $length,
        mixed $columns,
        mixed $search,
        mixed $order
    ): array {
        $params = [
            'draw' => $draw,
            'offset' => $start ?? 0,
            'limit' => $length ?? 0,
            'searchs' => [],
            'orders' => [],
        ];

        // --- Normalize columns
        if (Validator::isBase64Encoded($columns)) {
            $columns = Parser::base64Decode($columns);
        } elseif (Validator::isBase64UrlEncoded($columns)) {
            $columns = Parser::base64UrlDecode($columns);
        }
        if (Validator::validateJson($columns)) {
            $columns = json_decode($columns, true);
        }

        if (!is_array($columns)) {
            $columns = [];
        }

        // --- Normalize global search
        if (Validator::isBase64Encoded($search)) {
            $search = Parser::base64Decode($search);
        } elseif (Validator::isBase64UrlEncoded($search)) {
            $search = Parser::base64UrlDecode($search);
        }
        if (Validator::validateJson($search)) {
            $search = json_decode($search, true);
        }

        if (!is_array($search)) {
            $search = [];
        }

        // --- Handle searchable columns and per-column search
        foreach ($columns AS $index => $column) {
            if (empty($column['searchable'])) {
                continue;
            }

            $params['searchs'][$index] = [
                'column' => $column['data'], // TODO: consider using $column['name']
                'search' => [],
            ];

            if (!empty($column['search']['value'])) {
                $params['searchs'][$index]['search'][] = [
                    'value' => addslashes($column['search']['value']),
                    'regex' => !(empty($column['search']['regex']) || Str::strToLower($column['search']['regex']) === "false"),
                ];
            }

            if (!empty($search['value'])) {
                $params['searchs'][$index]['search'][] = [
                    'value' => addslashes($search['value']),
                    'regex' => !(empty($search['regex']) || Str::strToLower($search['regex']) === "false"),
                ];
            }
        }

        // --- Normalize and parse order array
        if (Validator::isBase64Encoded($order)) {
            $order = Parser::base64Decode($order);
        } elseif (Validator::isBase64UrlEncoded($order)) {
            $order = Parser::base64UrlDecode($order);
        }
        if (Validator::validateJson($order)) {
            $order = json_decode($order, true);
        }

        if (!is_array($order)) {
            $order = [];
        }

        foreach ($order as $orderItem) {
            $colIndex = $orderItem['column'] ?? null;

            if (
                $colIndex === null ||
                empty($columns[$colIndex]['orderable']) ||
                empty($columns[$colIndex]['data'])
            ) {
                continue;
            }

            $params['orders'][] = [
                'column' => $columns[$colIndex]['data'],
                'order' => $orderItem['dir'] ?? 'asc',
            ];
        }

        return $params;
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
        if (empty($selectFields) || empty($fromClause)) {
            return '';
        }

        // Subquery to count total records
        $countQuery = "SELECT COUNT(*) AS counter FROM {$fromClause}";
        foreach ($joinClauses as $join) {
            $countQuery .= " {$join}";
        }
        if (!empty($whereClause)) {
            $countQuery .= " WHERE {$whereClause}";
        }

        // Append count subquery as a JOIN
        $selectFields[] = "info.counter";
        $joinClauses[] = "INNER JOIN ({$countQuery}) AS info ON 1 = 1";

        // Start building the main SELECT
        $sql = "SELECT " . implode(', ', $selectFields);
        $sql .= " FROM {$fromClause}";

        foreach ($joinClauses as $join) {
            $sql .= " {$join}";
        }

        if (!empty($whereClause)) {
            $sql .= " WHERE {$whereClause}";
        }

        if (!empty($orderBy)) {
            $sql .= " ORDER BY " . implode(', ', $orderBy);
        }

        if (!empty($limit) && $limit > 0) {
            $sql .= " LIMIT {$limit}";

            if (!empty($offset) && $offset > 0) {
                $sql .= " OFFSET {$offset}";
            }
        }

        return $sql;
    }

}