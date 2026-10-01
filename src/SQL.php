<?php

namespace VD\PHPHelper;

class SQL {
    /**
     * The only characters an identifier derived from DATA may be built from.
     *
     * Deliberately far narrower than what MySQL accepts: identifiers cannot be escaped, so anything
     * auto-derived is either provably inert or refused. A caller who genuinely has a column outside
     * this set (a unicode name, a dot-qualified reference) passes $insertFields explicitly, which is
     * their own SQL text and is not checked against this.
     *
     * A CHARACTER SET checked with strspn(), not a regex, for the same reason
     * {@see Validator::isOctal()} is: this was written as '/^[A-Za-z0-9_$]+$/', and PCRE's '$'
     * matches before a FINAL NEWLINE, so "col\n" satisfied a pattern whose whole job was to refuse
     * anything but [A-Za-z0-9_$] — the exact hole isOctal() documents. strspn() has no anchors, no
     * newline exception and no backtracking, so there is nothing left to get subtly wrong. It does
     * accept the empty string (strspn("") === strlen("") === 0), which {@see self::isAutoIdentifier()}
     * rejects explicitly.
     */
    private const AUTO_IDENTIFIER_CHARS =
        'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789_$';

    /**
     * Whether $column is a name this class is willing to render as an identifier on its own.
     *
     * @param string $column The candidate name.
     * @return bool TRUE only if $column is non-empty and made solely of AUTO_IDENTIFIER_CHARS.
     *              FALSE for the empty string and for any name carrying anything else — including
     *              a trailing newline, a NUL byte or a backtick.
     */
    private static function isAutoIdentifier(string $column): bool {
        if ($column === '') {
            return false;
        }

        return strspn($column, self::AUTO_IDENTIFIER_CHARS) === strlen($column);
    }

    /**
     * Escapes a value and renders it as a literal usable inside a MySQL statement.
     *
     * Total function: every accepted input becomes a valid SQL literal, and every input that has
     * no safe literal form is REJECTED with an exception. It never returns its input unescaped —
     * a passthrough would be an injection hole in the one call the caller made to prevent one.
     *
     *  - null               -> "NULL"       (bare keyword, unquoted)
     *  - bool               -> "1" / "0"
     *  - int, finite float  -> the decimal literal, unquoted
     *  - string             -> "'...'" with exactly three substitutions, and NO other change to
     *                          the bytes: ' -> '' (doubled), \ -> \\ and NUL -> \0.
     *
     * Why the quote is DOUBLED and not backslash-escaped: '' is the SQL-standard escape and is
     * honoured by MySQL in every sql_mode, by PostgreSQL, SQLite and SQL Server. \' is honoured
     * ONLY while backslash is an escape character — under MySQL's NO_BACKSLASH_ESCAPES (and in
     * PostgreSQL with standard_conforming_strings, SQLite, SQL Server) "\'" is a literal backslash
     * followed by the CLOSING quote, so the input "' OR 1=1 -- " broke straight out of the literal.
     * The backslash must still be doubled: under MySQL's default sql_mode a lone trailing
     * backslash (input "a\") would otherwise escape the closing quote.
     *
     * The result therefore NEVER lets a value escape its literal — under either MySQL sql_mode or
     * in a standard-conforming engine. It round-trips EXACTLY under MySQL's default sql_mode. In a
     * dialect where backslash is literal (NO_BACKSLASH_ESCAPES, standard-conforming PostgreSQL,
     * SQLite) a value containing a backslash or NUL is stored with that byte doubled / as "\0":
     * corrupted, never injected. Use bound parameters there.
     *
     * Control characters (other than NUL) are kept verbatim. They are inert inside a quoted literal
     * and this used to strip them, which silently changed the stored value ("a\x1Bb" became "ab").
     * NUL alone is escaped, because a raw NUL truncates the statement in anything that treats it as
     * a C string (SQLite's tokenizer, logs, some drivers).
     *
     * SECURITY — what this does NOT give you:
     *  - CHARSET: safe only on a connection whose charset never uses 0x5C (backslash) as the
     *    trailing byte of a multibyte character — utf8mb4, utf8mb3, latin1, ascii, binary, and the
     *    EUC charsets are fine. It is NOT safe on big5, cp932, gbk, gb18030 or sjis: there a lead
     *    byte can swallow the escaping backslash (the classic "\xBF\x27" attack), and no escaper
     *    that cannot see the live connection can prevent that.
     *  - Prefer PARAMETERIZED queries (PDO/mysqli prepared statements) for all untrusted input
     *    and use this only when a bound parameter is genuinely impossible.
     *  - It escapes VALUES only — never identifiers (table/column names), never SQL fragments,
     *    and it does not escape the LIKE wildcards % and _.
     *  - TOKEN FUSION: a negative number is rendered bare ("-5"). Concatenated straight after a
     *    minus operator ("credit-" . escapeString(-5)) that is "credit--5", and in SQLite and
     *    PostgreSQL "--" opens a comment that swallows the rest of the statement — the WHERE
     *    included. (MySQL requires whitespace after "--" precisely for this case.) Put a space or
     *    parentheses between an operator and a value you concatenate, in every dialect.
     *
     * BEHAVIOUR CHANGE: the quote used to be rendered as \' (injectable under NO_BACKSLASH_ESCAPES)
     * and control characters used to be stripped; both are gone, see above.
     *
     * @param mixed $data Value to render. Accepts null, bool, int, finite float and string.
     *
     * @return string A complete SQL literal, quoted when the input was a string.
     *
     * @throws \InvalidArgumentException For arrays, objects (including \Stringable — cast it
     *         yourself, so the conversion is visible at the call site), resources, and NAN/INF.
     *         None of these have a safe literal form, and failing loudly is the only safe answer
     *         for an escaper handed something it cannot escape.
     *
     * @ref https://stackoverflow.com/questions/574805/how-to-escape-strings-in-sql-server-using-php
     */
    public static function escapeString(mixed $data): string {
        if ($data === NULL) {
            return 'NULL';
        }
        if (is_bool($data)) {
            return $data ? '1' : '0';
        }
        if (is_int($data)) {
            return (string) $data;
        }
        if (is_float($data)) {
            if (is_nan($data) || is_infinite($data)) {
                throw new \InvalidArgumentException(
                    'SQL::escapeString(): NAN and INF have no valid SQL literal form.'
                );
            }

            // var_export() is locale-independent and round-trips the value; (string) is neither
            // guaranteed to keep precision nor to avoid a comma decimal separator.
            return var_export($data, true);
        }
        if (is_string($data)) {
            // strtr() with an array is a single left-to-right pass, so an inserted backslash is
            // never itself re-escaped.
            return "'" . strtr($data, ['\\' => '\\\\', "'" => "''", "\0" => '\\0']) . "'";
        }

        throw new \InvalidArgumentException(sprintf(
            'SQL::escapeString(): a value of type %s has no safe SQL literal form. Convert it to '
                . 'a scalar at the call site (e.g. (string) $stringable) before escaping it.',
            get_debug_type($data)
        ));
    }

    /**
     * Encrypts a string using a key with the "aes-256-gcm" algorithm.
     *
     * Thin pass-through to {@see Security::encryptDataDB()}; see it for the envelope format.
     *
     * @param mixed $str The value to encrypt; null stays null, "" is encrypted
     * @param string|Keyring $key The encryption key, or a keyring (its current key is used)
     * @param string $aad Context to bind — build it with Security::dbContext(). REQUIRED and unique
     *                    per logical cell. An empty AAD is rejected to forbid an unbound value.
     * @param string|null $salt Optional per-subject salt for key derivation
     *
     * @return string|null
     * @throws \Exception
     */
    public static function encryptDataDB(#[\SensitiveParameter] mixed $str, #[\SensitiveParameter] string|Keyring $key, string $aad, ?string $salt = ""): ?string {
        return Security::encryptDataDB($str, $key, $aad, $salt);
    }

    /**
     * Decrypts a message after verifying its integrity using "aes-256-gcm".
     *
     * Thin pass-through to {@see Security::decryptDataDB()}. Fails loud: on a wrong key, a wrong
     * AAD, a tampered or blank envelope, an unknown key id or an unknown version it THROWS — it
     * never returns a value a caller could mistake for success. null stays null.
     *
     * @param string|null $str Encrypted message
     * @param string|Keyring $key Encryption key, or a keyring holding it
     * @param string $aad The same context bound at encryption time. REQUIRED.
     * @param string|null $salt The same per-subject salt used at encryption time
     *
     * @return string|null
     * @throws \Exception
     */
    public static function decryptDataDB(?string $str, #[\SensitiveParameter] string|Keyring $key, string $aad, ?string $salt = ""): ?string {
        return Security::decryptDataDB($str, $key, $aad, $salt);
    }

    /**
     * Builds ONE "INSERT INTO t (cols) VALUES (..),(..) ON DUPLICATE KEY UPDATE .." statement from
     * a dataset, and consumes the rows it wrote out of $data so the caller can loop until done.
     *
     * Batching. Rows are appended until EITHER the statement reaches $maxStatementBytes OR the
     * memory grown since entry crosses a third of the free memory reported by
     * {@see System::getMemoryUsage()}; then the statement is closed.
     *
     * The byte cap is what keeps the statement deliverable: the server refuses any packet larger
     * than its max_allowed_packet (4 MiB by default on MySQL 5.7, 16 MiB on MariaDB, 64 MiB on
     * 8.0), and a memory budget knows nothing about that. Without the cap, a large dataset on a
     * host with generous memory produced one statement of hundreds of MB that the server rejected
     * ("packet too large" / "server has gone away") — after the rows had been consumed from $data.
     * It is a SOFT cap: the row that crosses it is still included, so a statement can exceed the
     * cap by at most one value group. Always at least one row per statement.
     *
     * The memory figure is PHP's own memory_limit headroom capped by the physical memory the OS
     * still has free — the budget the script can actually spend, not merely the machine's free
     * RAM. The OS probe behind
     * it costs milliseconds (it shells out), so it is read ONCE per call; the per-row check uses
     * memory_get_usage(true), the same number getMemoryUsage() reports as usageBytes.
     *
     * When that budget is UNKNOWABLE — memory_limit is "-1" AND the OS probe fails, which
     * getMemoryUsage() reports as freeBytes 0 — the ceiling is 0 and every batch closes after a
     * single row. The drain loop still terminates and still emits every row exactly once, but it
     * costs one statement per row; set a real memory_limit to get real batches.
     *
     * Only the rows actually consumed are removed from $data, and the surviving rows KEEP THEIR
     * OUTER KEYS, so the drain loop is:
     *
     *     while (($sql = SQL::prepareInsertOrUpdateMySQL($rows, 'clients')) !== true) {
     *         if ($sql === false) { throw new \RuntimeException('could not build the statement'); }
     *         $pdo->exec($sql);
     *     }
     *
     * Rows are NOT de-duplicated: two identical rows in one batch produce two value groups. Real
     * key collisions are what the ON DUPLICATE KEY UPDATE clause is for.
     *
     * SECURITY — this builds a statement by STRING CONCATENATION. It is not a prepared statement,
     * and "prepare" in the name means "assemble", not "PDO::prepare".
     *  - $table, $insertFields and $updateFields are interpolated RAW and are NOT escaped. They
     *    are identifiers and SQL fragments, and no escaping makes an identifier safe. They MUST be
     *    literals from your own code — never user input, never a request key.
     *  - When $insertFields is null the column list is derived from THE KEYS OF THE FIRST ROW of
     *    $data — names that come from the data rather than from your source. Those are NOT trusted:
     *    each must be non-empty and built solely from [A-Za-z0-9_$] or the call THROWS, and they
     *    are rendered backtick-quoted. The check is a strspn() character-set test, so it holds for
     *    the WHOLE name — a trailing newline ("col\n") is refused like any other stray byte. A
     *    crafted array key cannot reach the statement. Pass $insertFields explicitly when you need
     *    a column this refuses (a unicode or dot-qualified name).
     *  - Row VALUES are escaped by the default formatter via {@see self::escapeString()}, with
     *    that method's documented limits. A custom $rowFormatter escapes its own values; nothing
     *    here checks it. Prefer real prepared statements for untrusted values.
     *
     * @param array $data BY REFERENCE. The dataset; each item must be an associative array — a row
     *        that is not an array THROWS, and $data is left untouched, rather than being skipped
     *        and consumed. The rows written into the returned statement are removed from it, and
     *        the surviving rows keep their original keys. Left untouched when the function returns
     *        false.
     * @param string $table Target table name. Trusted identifier, interpolated raw; empty returns
     *        false.
     * @param string|null $insertFields Comma-separated column names for the INSERT clause, e.g.
     *        "id,name" or "`order`,`key`". Trusted identifiers: interpolated raw into the INSERT
     *        clause exactly as written, including any backticks. Null auto-derives them from the
     *        first row's keys, skipping keys whose value is an array or object; auto-derived names
     *        must be non-empty and built solely from [A-Za-z0-9_$] and are rendered backtick-quoted.
     * @param string|null $updateFields Comma-separated "col=expr" pairs for the ON DUPLICATE KEY
     *        UPDATE clause. Trusted fragment, interpolated raw. Null auto-generates
     *        "col=VALUES(col)" for every insert column, KEEPING each column's quoting as it appears
     *        in $insertFields (the library's own backticks when auto-derived) — so an explicit
     *        "`order`,`key`" yields "`order`=VALUES(`order`),`key`=VALUES(`key`)" and stays valid
     *        SQL for a column that needs quoting. Only surrounding whitespace is trimmed.
     *        VALUES(col) is deprecated since MySQL 8.0.20 (still executed, with warning 1287) and
     *        is the only form MariaDB accepts, so it is kept on purpose.
     * @param callable|null $rowFormatter fn(array $row, int|string $key, array &$global): string|false
     *        formatting a single row. Must return:
     *        - string: a complete value group, e.g. "(1,'abc')", holding exactly as many values as
     *          $insertFields has columns
     *        - empty string: skip this row (it is still consumed from $data)
     *        - false: abort now; the function returns false and $data is left untouched
     *        Any other return (null, an int, ...) THROWS, leaving $data untouched: it used to be
     *        concatenated as-is, so a formatter that fell off its end without a return produced
     *        "VALUES ,,," and consumed the rows.
     *        Null installs the default formatter, which emits one escaped literal per column of
     *        $global['__insert'], reading a missing column as NULL.
     * @param array $global Passed BY VALUE into this method and BY REFERENCE into the formatter,
     *        which may use it as scratch space shared across rows. Mutations never reach the
     *        caller. This method ALWAYS sets $global['__insert'] to the resolved insert column
     *        list (string[]) before the first row, overwriting anything the caller put there.
     * @param int $maxStatementBytes Soft cap on the statement's length in bytes (see Batching).
     *        Default 1 MiB, which fits every MySQL/MariaDB default max_allowed_packet with room
     *        for an oversized final row. Must be >= 1.
     *
     * @return string|bool The SQL statement; TRUE when there was nothing to write (empty $data, or
     *         every remaining row skipped by the formatter); FALSE on error (empty or
     *         whitespace-only $table, no resolvable insert column, or a formatter that returned
     *         false).
     *
     * @throws \InvalidArgumentException When an auto-derived column name (a key of the first row,
     *         used only when $insertFields is null) is not a valid identifier — see the SECURITY
     *         note above. $data is left untouched. Also when a row of $data is not an array (an
     *         object row included: it has no columns to read, and passing it to the formatter
     *         raised an uncaught \Error), again leaving $data untouched. Also from the default
     *         formatter, through self::escapeString(), when a column of $insertFields holds a value
     *         with no safe literal form (array/object/resource/NAN/INF). Also when the formatter
     *         returns something other than a string or false, and when $maxStatementBytes < 1.
     *         A custom formatter throws whatever it throws.
     */
    public static function prepareInsertOrUpdateMySQL(
        array &$data,
        string $table,
        ?string $insertFields = null,
        ?string $updateFields = null,
        ?callable $rowFormatter = null,
        array $global = [],
        int $maxStatementBytes = 1048576
    ): string|bool {
        if ($maxStatementBytes < 1) {
            throw new \InvalidArgumentException(sprintf(
                'SQL::prepareInsertOrUpdateMySQL(): $maxStatementBytes must be >= 1, %d given.',
                $maxStatementBytes
            ));
        }

        if (empty($data)) {
            return true;
        }

        // trim(), not empty(): empty() let "  " through (building "INSERT INTO    (...)") and
        // refused a table literally named "0".
        if (trim($table) === '') {
            return false;
        }

        $autoDerived = $insertFields === null;

        // The insert column list must be resolved UNCONDITIONALLY: it is the default formatter's
        // only input, and it is what keeps every value group aligned with the INSERT column list.
        // Deriving it only when a field list was omitted left the fully documented call
        // (both field lists given, default formatter) reading an undefined $global['__insert'].
        //
        // Two views of the same column list, built together and kept index-aligned:
        //  - $insertColumns:   the BARE name. What the formatter indexes the row with, and what
        //                      $global['__insert'] publishes.
        //  - $renderedColumns: the SQL TEXT for that column. What any identifier this method writes
        //                      itself must be built from — see the ON DUPLICATE KEY UPDATE clause.
        $insertColumns = [];
        $renderedColumns = [];

        if (!$autoDerived) {
            // Columns come from what the caller actually asked to insert, NOT from the row keys:
            // a row carrying extra keys must not desynchronise the value groups.
            foreach (explode(',', $insertFields) as $column) {
                // The bare name drops the caller's quoting; the rendered form KEEPS it. Stripping
                // the backticks off an explicit "`order`,`key`" and rebuilding the update clause
                // from the bare names emitted "ON DUPLICATE KEY UPDATE order=VALUES(order)" — a
                // syntax error on precisely the columns that needed the quoting the caller supplied.
                $bare = trim($column, " \t\n\r\0\x0B`");

                if ($bare === '') {
                    continue;
                }

                $insertColumns[] = $bare;
                $renderedColumns[] = trim($column, " \t\n\r\0\x0B");
            }
        } else {
            foreach ($data as $key => $row) {
                // A scalar/object first row used to end derivation with no columns and return
                // FALSE, while the same row anywhere else THROWS — one contract, one outcome.
                if (!is_array($row)) {
                    throw self::notARowException($key, $row);
                }

                foreach ($row as $column => $value) {
                    // An array/object value has no literal form, so it is not an insertable column.
                    if (is_array($value) || is_object($value)) {
                        continue;
                    }

                    $column = (string) $column;

                    // This name comes from the DATA, not from the caller's source: with $data built
                    // from user input, the array key is attacker-chosen. It is about to be
                    // interpolated as an IDENTIFIER, and no escaping makes an identifier safe, so
                    // the only defence is to refuse anything that is not provably inert.
                    if (!self::isAutoIdentifier($column)) {
                        throw new \InvalidArgumentException(sprintf(
                            'SQL::prepareInsertOrUpdateMySQL(): cannot auto-derive the column name %s '
                                . 'from the dataset — an identifier cannot be escaped, so only '
                                . '[A-Za-z0-9_$] is accepted here. Pass $insertFields explicitly as an '
                                . 'allow-list if this column is legitimate.',
                            var_export($column, true)
                        ));
                    }

                    $insertColumns[] = $column;
                }

                break; // only use first row
            }

            $insertColumns = array_values(array_unique($insertColumns));

            // This method wrote these identifiers, so it quotes them. Validated above to hold
            // nothing but AUTO_IDENTIFIER_CHARS — no backtick — so the quoting cannot be broken out
            // of, and it is what lets an ordinary column named after a reserved word ("order",
            // "key") survive at all. Built AFTER array_unique() to stay aligned with the bare list.
            $renderedColumns = array_map(
                static fn (string $col): string => '`' . $col . '`',
                $insertColumns
            );
        }

        if (empty($insertColumns)) {
            // "INSERT INTO t () VALUES ..." is not a statement. Falling through would build no
            // query and return TRUE — reporting success after the caller's rows had already been
            // sliced out of $data.
            return false;
        }

        $global['__insert'] = $insertColumns;

        if ($autoDerived) {
            $insertFields = implode(',', $renderedColumns);
        }

        // Built from the RENDERED names, never the bare ones: this clause is SQL text this method
        // writes, so every identifier in it must carry the same quoting the INSERT clause uses —
        // the library's backticks when auto-derived, the caller's own when explicit.
        if ($updateFields === null) {
            $updateFields = implode(',', array_map(
                static fn (string $col): string => "$col=VALUES($col)",
                $renderedColumns
            ));
        }

        // Define default row formatter if not provided
        if ($rowFormatter === null) {
            $rowFormatter = function ($row, $index, &$global) {
                $sql = '';

                foreach ($global['__insert'] AS $column) {
                    // Exactly one literal per column, always: dropping a value would silently
                    // shift every later value into the wrong column. escapeString() throws on a
                    // value it cannot render rather than let that happen.
                    $sql .= ($sql !== '' ? ',' : '') . self::escapeString($row[$column] ?? null);
                }

                return $sql !== '' ? "($sql)" : '';
            };
        }

        $insertPrefix = "INSERT INTO $table ($insertFields) VALUES ";
        $updateClause = "ON DUPLICATE KEY UPDATE $updateFields";

        // Paid ONCE, here. getMemoryUsage() probes the OPERATING SYSTEM for physical memory (two
        // wmic subprocesses on Windows, a /proc/meminfo read elsewhere) and only freeBytes needs
        // that probe. The loop below re-reads process usage with memory_get_usage(true), which is
        // exactly what this method reports as usageBytes — same arithmetic, no subprocess per row.
        $memory = System::getMemoryUsage();
        $maxBytes = $memory['freeBytes'] / 3;
        $initialUsage = $memory['usageBytes'];
        $processedRows = 0;
        $query = '';

        foreach ($data as $key => $row) {
            // The SAME guard the derive path applies, which is where it was missing: that path
            // refuses to read columns off a non-array row, but this one waved objects through with
            // `!is_array($row) && !is_object($row)` and the default formatter then hit
            // `$row[$column]` on a stdClass — an uncaught \Error out of a method whose documented
            // failures are \InvalidArgumentException. Scalars fared worse: they were skipped AND
            // consumed, so a bad row vanished from the by-ref $data while the call reported success.
            // A row that is not an array is a contract violation ("each item must be an associative
            // array"), and the only safe answer is to say so before anything is consumed.
            if (!is_array($row)) {
                throw self::notARowException($key, $row);
            }

            $processedRows++;

            $currentUsage = memory_get_usage(true) - $initialUsage;
            $values = $rowFormatter($row, $key, $global);

            if ($values === false) {
                return false;
            }

            if (!is_string($values)) {
                throw new \InvalidArgumentException(sprintf(
                    'SQL::prepareInsertOrUpdateMySQL(): the row formatter returned %s for row %s; '
                        . 'it must return a value group string, \'\' to skip the row, or false to abort.',
                    get_debug_type($values),
                    var_export($key, true)
                ));
            }

            if ($values === '') {
                continue;
            }

            if ($query === '') {
                $query = $insertPrefix;
            }

            $query .= $values;

            // +1 for the space before the clause; the trailing ',' about to be added is removed.
            if ($currentUsage >= $maxBytes || strlen($query) + 1 + strlen($updateClause) >= $maxStatementBytes) {
                break;
            }

            $query .= ',';
        }

        if ($processedRows === 0) {
            return true;
        }

        // preserve_keys = true. The contract is that the PROCESSED ROWS ARE REMOVED, not that the
        // survivors are renumbered: a caller keying rows by primary key ($data[$id] = $row) and
        // reading that id from the formatter's $key would otherwise get 0,1,2... on every batch
        // after the first, and write those to the database.
        $data = array_slice($data, $processedRows, null, true);

        if ($query !== '') {
            $query = trim($query);
            $query = Str::removeStringSuffix($query, ',');
            $query .= ' ' . $updateClause;
            return $query;
        }

        return true;
    }

    /**
     * The one exception for a $data entry that is not a row, wherever it is met.
     *
     * @param int|string $key Outer key of the offending entry.
     * @param mixed      $row The entry.
     */
    private static function notARowException(int|string $key, mixed $row): \InvalidArgumentException {
        return new \InvalidArgumentException(sprintf(
            'SQL::prepareInsertOrUpdateMySQL(): row %s of $data is a %s, not an associative '
                . 'array. Convert the dataset at the call site (e.g. (array) $row, or '
                . 'json_decode($json, true)) — skipping the row would consume it out of '
                . '$data and report success while dropping its data.',
            var_export($key, true),
            get_debug_type($row)
        ));
    }
}
