<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\SQL;

/**
 * Verifies {@see SQL::escapeString()} and {@see SQL::prepareInsertOrUpdateMySQL()} against how SQL
 * engines actually LEX a string literal, instead of against the expected output text.
 *
 * Three independent oracles, all offline:
 *  - SQLite (pdo_sqlite, in memory): a real engine where backslash is an ordinary character and
 *    only '' escapes a quote — exactly the lexing rules of MySQL under NO_BACKSLASH_ESCAPES and of
 *    standard-conforming PostgreSQL. This is where the old \' escaping was injectable.
 *  - A reference MySQL string-literal lexer (below) for BOTH sql_modes, driven by a seeded random
 *    corpus. It models utf8mb4, where bytes 0x27 and 0x5C never occur inside a multibyte character.
 *  - A real MySQL server, only when VDPH_TEST_MYSQL_DSN (+ _USER / _PASSWORD) is set in the
 *    environment; skipped otherwise. No service is ever started by this suite.
 */
final class SQLEngineTest extends TestCase {
    /** Payloads chosen to break out of a literal under one lexing rule or the other. */
    public static function hostilePayloadProvider(): array {
        return [
            'plain quote'                   => ["O'Brien"],
            'classic tautology'             => ["' OR 1=1 -- "],
            'stacked statement'             => ["'); DROP TABLE t; --"],
            // Broke the OLD escaper under standard SQL: \' was a literal backslash + closing quote.
            'backslash-quote tautology'     => ["x\\' OR 1=1 -- "],
            'trailing backslash'            => ['a\\'],
            'backslash run then quote'      => ["\\\\\\'"],
            'quote run'                     => ["''''"],
            'double quote'                  => ['say "hi"'],
            'comment openers'               => ['/* -- # */'],
            'nul byte'                      => ["a\0b"],
            'control characters'            => ["a\x1B[31mb\x1A\x7F\r\n\t"],
            'multibyte'                     => ['São Paulo — 東京 🙂'],
            'empty'                         => [''],
            'escape-looking text'           => ['\\n \\0 \\Z \\%'],
        ];
    }

    // ------------------------------------------------------------------ SQLite (standard lexing)

    private static function sqlite(): \PDO {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE t (id INTEGER PRIMARY KEY, v TEXT)');

        return $pdo;
    }

    /**
     * What a backslash-literal engine stores for escapeString($s): the documented corruption of
     * backslash and NUL, and nothing else.
     */
    private static function storedUnderStandardSql(string $s): string {
        return strtr($s, ['\\' => '\\\\', "\0" => '\\0']);
    }

    /**
     * FINDING (fixed) — the high one. Before the fix this SELECT counted EVERY row for the
     * 'backslash-quote tautology' payload: 'x\' OR 1=1 -- ' lexes as the literal 'x\' followed by
     * OR 1=1 and a comment. Now the payload stays one literal and matches nothing.
     */
    #[DataProvider('hostilePayloadProvider')]
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testEscapedValueCannotLeaveItsLiteralOnAStandardConformingEngine(string $payload): void {
        $pdo = self::sqlite();
        $pdo->exec("INSERT INTO t (id, v) VALUES (1, 'row-one'), (2, 'row-two')");

        $count = (int) $pdo->query('SELECT COUNT(*) FROM t WHERE v = ' . SQL::escapeString($payload))->fetchColumn();

        self::assertSame(0, $count, 'the payload must be compared as ONE value, never evaluated as SQL');
        self::assertSame(2, (int) $pdo->query('SELECT COUNT(*) FROM t')->fetchColumn(), 'the table survived');
    }

    #[DataProvider('hostilePayloadProvider')]
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testEscapedValueIsStoredAsOneValueOnAStandardConformingEngine(string $payload): void {
        $pdo = self::sqlite();

        $pdo->exec('INSERT INTO t (id, v) VALUES (1, ' . SQL::escapeString($payload) . ')');

        $rows = $pdo->query('SELECT id, v FROM t')->fetchAll(\PDO::FETCH_NUM);
        self::assertCount(1, $rows);
        self::assertSame(self::storedUnderStandardSql($payload), $rows[0][1]);
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testValuesWithoutBackslashOrNulRoundTripExactlyOnAStandardConformingEngine(): void {
        $pdo = self::sqlite();
        $value = "It's \"quoted\" — ünïcödé\x1B\r\n\t'';--";

        self::assertSame($value, $pdo->query('SELECT ' . SQL::escapeString($value))->fetchColumn());
    }

    #[RequiresPhpExtension('pdo_sqlite')]
    public function testNumericLiteralsAreAcceptedByARealEngine(): void {
        $pdo = self::sqlite();

        foreach ([1e25, 1e-7, -0.0, 0.1, 1.5, PHP_INT_MAX, PHP_INT_MIN, true, false] as $value) {
            $fetched = $pdo->query('SELECT ' . SQL::escapeString($value))->fetchColumn();
            self::assertEquals(is_bool($value) ? (int) $value : $value, $fetched, var_export($value, true));
        }

        self::assertNull($pdo->query('SELECT ' . SQL::escapeString(null))->fetchColumn());
    }

    /**
     * The generated statement is executed for real: everything up to ON DUPLICATE KEY UPDATE is
     * valid SQLite too (SQLite accepts MySQL's backtick identifiers), so the value groups can be
     * checked for alignment with the column list and for literal integrity, row by row.
     */
    #[RequiresPhpExtension('pdo_sqlite')]
    public function testPrepareInsertProducesAStatementWhoseValuesLandInTheRightColumns(): void {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE people (id INTEGER, name TEXT, note TEXT, ratio REAL)');

        $rows = [
            ['id' => 1, 'name' => "O'Brien", 'note' => "'); DROP TABLE people; --", 'ratio' => 1.5],
            ['id' => 2, 'name' => 'Ada', 'note' => null, 'ratio' => 0.25],
            ['id' => 3, 'name' => 'São Paulo 🙂', 'note' => "x' OR 1=1 -- ", 'ratio' => -2.0],
        ];
        $data = $rows;

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 'people');
        self::assertIsString($sql);
        self::assertSame([], $data);

        $insertOnly = substr($sql, 0, (int) strpos($sql, ' ON DUPLICATE KEY UPDATE '));
        $pdo->exec($insertOnly);

        $stored = $pdo->query('SELECT id, name, note, ratio FROM people ORDER BY id')->fetchAll(\PDO::FETCH_ASSOC);
        self::assertCount(3, $stored);
        foreach ($rows as $i => $row) {
            self::assertSame($row['id'], (int) $stored[$i]['id']);
            self::assertSame($row['name'], $stored[$i]['name']);
            self::assertSame($row['note'], $stored[$i]['note']);
            self::assertEqualsWithDelta($row['ratio'], (float) $stored[$i]['ratio'], 1e-12);
        }
    }

    // ------------------------------------------------------------------ reference MySQL lexer

    /**
     * Lexes a MySQL single-quoted literal starting at offset 0.
     *
     * @return array{0: int, 1: string}|null [index of the closing quote, decoded value], or null
     *                                       when the literal never closes.
     */
    private static function lexMySqlLiteral(string $sql, bool $backslashEscapes): ?array {
        self::assertSame("'", $sql[0] ?? '', 'a literal starts with a quote');

        // MySQL's escape table (https://dev.mysql.com/doc/refman/8.0/en/string-literals.html).
        $escapes = ['0' => "\0", "'" => "'", '"' => '"', 'b' => "\x08", 'n' => "\n", 'r' => "\r",
            't' => "\t", 'Z' => "\x1A", '\\' => '\\', '%' => '\\%', '_' => '\\_'];

        $decoded = '';
        $length = strlen($sql);
        for ($i = 1; $i < $length; $i++) {
            $char = $sql[$i];
            if ($backslashEscapes && $char === '\\') {
                if ($i + 1 >= $length) {
                    return null;
                }
                $next = $sql[++$i];
                $decoded .= $escapes[$next] ?? $next;
                continue;
            }
            if ($char === "'") {
                if ($i + 1 < $length && $sql[$i + 1] === "'") {
                    $decoded .= "'";
                    $i++;
                    continue;
                }

                return [$i, $decoded];
            }
            $decoded .= $char;
        }

        return null;
    }

    /** Seeded, so a failure is reproducible; biased towards the bytes that matter. */
    private static function randomCorpus(int $count): array {
        mt_srand(20260930);
        $alphabet = ["'", "'", '\\', '\\', "\0", '"', '-', ' ', 'a', 'Z', '0', '%', '_', "\n", "\x1A", "\xC3", "\xA9", "\xFF"];
        $corpus = [];
        for ($n = 0; $n < $count; $n++) {
            $s = '';
            for ($len = mt_rand(0, 24); $len > 0; $len--) {
                $s .= mt_rand(0, 3) === 0 ? chr(mt_rand(0, 255)) : $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $corpus[] = $s;
        }

        return $corpus;
    }

    /**
     * The literal must close exactly at its last byte (nothing leaks out after it) under the
     * DEFAULT sql_mode, and decode back to the input byte for byte.
     */
    public function testEveryEscapedLiteralClosesAtItsEndAndRoundTripsUnderMySqlDefaultMode(): void {
        foreach (array_merge(array_column(self::hostilePayloadProvider(), 0), self::randomCorpus(3000)) as $input) {
            $literal = SQL::escapeString($input);
            $lexed = self::lexMySqlLiteral($literal, true);

            self::assertNotNull($lexed, 'unterminated literal for ' . bin2hex($input));
            self::assertSame(strlen($literal) - 1, $lexed[0], 'the literal closed early for ' . bin2hex($input));
            self::assertSame($input, $lexed[1], 'lossy round trip for ' . bin2hex($input));
        }
    }

    /**
     * Same corpus under NO_BACKSLASH_ESCAPES: still exactly one literal, never a break-out. The
     * value is stored with backslash/NUL expanded — the documented cost of that sql_mode.
     */
    public function testEveryEscapedLiteralClosesAtItsEndUnderNoBackslashEscapes(): void {
        foreach (array_merge(array_column(self::hostilePayloadProvider(), 0), self::randomCorpus(3000)) as $input) {
            $literal = SQL::escapeString($input);
            $lexed = self::lexMySqlLiteral($literal, false);

            self::assertNotNull($lexed, 'unterminated literal for ' . bin2hex($input));
            self::assertSame(strlen($literal) - 1, $lexed[0], 'the literal closed early for ' . bin2hex($input));
            self::assertSame(self::storedUnderStandardSql($input), $lexed[1]);
        }
    }

    /** The oracle itself must be able to see the bug it guards against, or it proves nothing. */
    public function testTheReferenceLexerDetectsTheOldBackslashQuoteEscaping(): void {
        $old = "'" . str_replace(['\\', "'"], ['\\\\', "\\'"], "x' OR 1=1 -- ") . "'";

        $default = self::lexMySqlLiteral($old, true);
        self::assertSame(strlen($old) - 1, $default[0], 'the old form was fine under the default sql_mode');

        $noBackslash = self::lexMySqlLiteral($old, false);
        self::assertNotNull($noBackslash);
        self::assertLessThan(strlen($old) - 1, $noBackslash[0], 'and broke out under NO_BACKSLASH_ESCAPES');
    }

    // ------------------------------------------------------------------ real MySQL (opt-in)

    /**
     * Runs only with VDPH_TEST_MYSQL_DSN set, e.g. "mysql:host=127.0.0.1;port=3306;charset=utf8mb4",
     * plus VDPH_TEST_MYSQL_USER / VDPH_TEST_MYSQL_PASSWORD. Uses SELECT only; creates nothing.
     */
    public function testAgainstARealMySqlServerInBothSqlModes(): void {
        $dsn = getenv('VDPH_TEST_MYSQL_DSN');
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped('set VDPH_TEST_MYSQL_DSN (+ _USER/_PASSWORD) to verify against a live MySQL');
        }

        try {
            $pdo = new \PDO(
                $dsn,
                (string) getenv('VDPH_TEST_MYSQL_USER'),
                (string) getenv('VDPH_TEST_MYSQL_PASSWORD'),
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION, \PDO::ATTR_TIMEOUT => 3]
            );
        } catch (\PDOException $e) {
            self::markTestSkipped('MySQL not reachable with the given credentials: ' . $e->getMessage());
        }

        $pdo->exec('SET NAMES utf8mb4');
        // Invalid UTF-8 is refused/replaced by the server on a utf8mb4 connection — a charset
        // matter, not an escaping one — so only valid strings are compared byte for byte.
        $payloads = array_filter(
            array_merge(array_column(self::hostilePayloadProvider(), 0), self::randomCorpus(300)),
            static fn (string $p): bool => mb_check_encoding($p, 'UTF-8')
        );

        foreach (['' => false, 'NO_BACKSLASH_ESCAPES' => true] as $mode => $noBackslash) {
            $pdo->exec('SET SESSION sql_mode = ' . SQL::escapeString($mode));
            foreach ($payloads as $input) {
                $row = $pdo->query('SELECT ' . SQL::escapeString($input) . ' AS v, 1 AS sentinel')->fetch(\PDO::FETCH_ASSOC);
                $expected = $noBackslash ? self::storedUnderStandardSql($input) : $input;

                self::assertSame('1', (string) $row['sentinel'], "the statement shape changed in mode '$mode'");
                self::assertSame(bin2hex($expected), bin2hex((string) $row['v']), "mode '$mode', input " . bin2hex($input));
            }
        }
    }
}
