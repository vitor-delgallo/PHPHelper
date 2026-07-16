<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\TestCase;
use VD\PHPHelper\SQL;
use VD\PHPHelper\System;

/**
 * Contract tests for {@see SQL}.
 *
 * prepareInsertOrUpdateMySQL() sizes its batch from System::getMemoryUsage(), so the partial-batch
 * tests need a memory budget small enough to close a batch on purpose. They get it WITHOUT mocking
 * anything and without touching the OS: memory_limit is lowered to (current usage + 60 MiB) for
 * the duration of the test, which makes freeBytes — and therefore the method's maxBytes — a known
 * quantity, and the row formatter then allocates a ballast larger than that budget. The subject
 * runs for real throughout; tearDown() restores memory_limit.
 */
final class SQLTest extends TestCase {
    /** A key comfortably over the documented 32-byte minimum. */
    private const KEY = 'unit-test-master-key-0123456789-abcdef';

    /** Headroom granted while forcing a partial batch. maxBytes lands at a third of this. */
    private const BUDGET_BYTES = 60 * 1024 * 1024;

    /** Allocated by the formatter to overshoot maxBytes (a third of BUDGET_BYTES). */
    private const BALLAST_BYTES = 24 * 1024 * 1024;

    private ?string $originalMemoryLimit = null;

    /** @var list<resource> */
    private array $openResources = [];

    protected function tearDown(): void {
        foreach ($this->openResources as $resource) {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }

        $this->openResources = [];

        if ($this->originalMemoryLimit !== null) {
            ini_set('memory_limit', $this->originalMemoryLimit);
            $this->originalMemoryLimit = null;
        }
    }

    /**
     * Pins the method's batch budget to a known value.
     *
     * getMemoryUsage() reports freeBytes as the headroom under memory_limit (capped by the OS's
     * free physical memory when it can be asked), and the method uses freeBytes/3 as its ceiling.
     * Granting exactly BUDGET_BYTES of headroom puts maxBytes at no more than BUDGET_BYTES/3
     * (20 MiB) — comfortably under BALLAST_BYTES (24 MiB), so the batch closes on the row after
     * the ballast is allocated, whatever the box's real free memory is. The limit stays far above
     * the peak the test actually reaches, so nothing can OOM.
     */
    private function forcePartialBatchBudget(): void {
        $this->originalMemoryLimit = (string) ini_get('memory_limit');
        ini_set('memory_limit', (string) (memory_get_usage(true) + self::BUDGET_BYTES));

        $maxBytes = System::getMemoryUsage()['freeBytes'] / 3;

        self::assertGreaterThan(0, $maxBytes, 'the budget must leave room for at least one row');
        self::assertLessThan(
            self::BALLAST_BYTES,
            $maxBytes,
            'the ballast must be able to overshoot the budget, or no batch would ever close'
        );
    }

    /** A formatter that emits a value group and, on its first call, grows the heap past maxBytes. */
    private function ballastFormatter(?array &$keysSeen = null): callable {
        $ballast = null;

        return function (array $row, int|string $key, array &$global) use (&$ballast, &$keysSeen): string {
            if ($keysSeen !== null) {
                $keysSeen[] = $key;
            }

            $ballast ??= str_repeat('x', self::BALLAST_BYTES);

            $values = [];
            foreach ($global['__insert'] as $column) {
                $values[] = SQL::escapeString($row[$column] ?? null);
            }

            return '(' . implode(',', $values) . ')';
        };
    }

    // ---------------------------------------------------------------- escapeString

    public function testEscapeStringQuotesAndReturnsAPlainString(): void {
        $result = SQL::escapeString('abc');

        self::assertIsString($result);
        self::assertSame("'abc'", $result);
    }

    public function testEscapeStringQuotesTheEmptyString(): void {
        self::assertSame("''", SQL::escapeString(''));
    }

    public function testEscapeStringEscapesSingleQuotes(): void {
        self::assertSame("'O\\'Brien'", SQL::escapeString("O'Brien"));
    }

    /**
     * A trailing backslash must not be able to escape the closing quote and break out of the
     * literal under MySQL's default sql_mode.
     */
    public function testEscapeStringEscapesTrailingBackslashSoItCannotBreakOutOfTheLiteral(): void {
        self::assertSame("'a\\\\'", SQL::escapeString('a\\'));

        // The classic payload: "\' OR 1=1 -- " must stay entirely inside the literal.
        self::assertSame("'\\\\\\' OR 1=1 -- '", SQL::escapeString("\\' OR 1=1 -- "));
    }

    public function testEscapeStringStripsInvisibleControlCharacters(): void {
        self::assertSame("'ab'", SQL::escapeString("a\x00b"));
        self::assertSame("'ab'", SQL::escapeString("a\x1Fb"));
    }

    public function testEscapeStringRendersNullAsTheBareKeyword(): void {
        self::assertSame('NULL', SQL::escapeString(null));
    }

    public function testEscapeStringRendersBooleansAsUnquotedOneAndZero(): void {
        self::assertSame('1', SQL::escapeString(true));
        self::assertSame('0', SQL::escapeString(false));
    }

    public function testEscapeStringRendersIntsAndFiniteFloatsAsUnquotedLiterals(): void {
        self::assertSame('5', SQL::escapeString(5));
        self::assertSame('-3', SQL::escapeString(-3));
        self::assertSame('0', SQL::escapeString(0));
        self::assertSame('1.5', SQL::escapeString(1.5));
        self::assertSame('0.1', SQL::escapeString(0.1));
    }

    /**
     * FINDING (fixed): escapeString() was declared `: mixed` and fell through to `return $data` for
     * anything that was not null/string/bool. A \Stringable holding user input came straight back
     * out, so the caller's `"... WHERE c = " . SQL::escapeString($v)` concatenation invoked
     * __toString and interpolated raw, unquoted input — injection, from the one call made to
     * prevent it. Before the fix this returned the object and threw nothing.
     */
    public function testEscapeStringRejectsStringableInsteadOfReturningItUnescaped(): void {
        $payload = new class {
            public function __toString(): string {
                return "x'; DROP TABLE users; -- ";
            }
        };

        $this->expectException(\InvalidArgumentException::class);
        SQL::escapeString($payload);
    }

    /**
     * FINDING (fixed): an array — reachable from `?id[]=x` making $_GET['id'] an array, which the
     * `@param mixed` doc explicitly sanctions — used to be returned as-is, giving the caller an
     * "Array to string conversion" notice and the literal string "Array" in their SQL.
     */
    public function testEscapeStringRejectsArrayInsteadOfReturningItUnescaped(): void {
        $this->expectException(\InvalidArgumentException::class);
        SQL::escapeString(['a', 'b']);
    }

    public function testEscapeStringRejectsResource(): void {
        $handle = fopen('php://memory', 'rb');
        self::assertIsResource($handle);
        $this->openResources[] = $handle;

        $this->expectException(\InvalidArgumentException::class);
        SQL::escapeString($handle);
    }

    public function testEscapeStringRejectsNan(): void {
        $this->expectException(\InvalidArgumentException::class);
        SQL::escapeString(NAN);
    }

    public function testEscapeStringRejectsInf(): void {
        $this->expectException(\InvalidArgumentException::class);
        SQL::escapeString(INF);
    }

    // ---------------------------------------------------------------- encryptDataDB / decryptDataDB

    public function testEncryptDecryptDataDBRoundTrip(): void {
        $plain = 'formula: 2 parts A, 1 part B';
        $aad = 'products.formula:42';

        $cipher = SQL::encryptDataDB($plain, self::KEY, $aad);

        self::assertIsString($cipher);
        self::assertNotSame($plain, $cipher);
        self::assertStringNotContainsString('formula: 2 parts', $cipher);
        self::assertStringContainsString(':', $cipher, 'the envelope carries a version prefix');
        self::assertSame($plain, SQL::decryptDataDB($cipher, self::KEY, $aad));
    }

    public function testEncryptDecryptDataDBRoundTripWithSalt(): void {
        $cipher = SQL::encryptDataDB('secret', self::KEY, 't.c:1', 'per-subject-salt');

        self::assertSame('secret', SQL::decryptDataDB($cipher, self::KEY, 't.c:1', 'per-subject-salt'));
    }

    public function testEncryptDataDBIsNonDeterministicForTheSameInput(): void {
        $a = SQL::encryptDataDB('same', self::KEY, 't.c:1');
        $b = SQL::encryptDataDB('same', self::KEY, 't.c:1');

        self::assertNotSame($a, $b, 'a fresh IV must make every ciphertext unique');
        self::assertSame('same', SQL::decryptDataDB($a, self::KEY, 't.c:1'));
        self::assertSame('same', SQL::decryptDataDB($b, self::KEY, 't.c:1'));
    }

    public function testEncryptDataDBReturnsEmptyStringForNullAndEmptyInput(): void {
        self::assertSame('', SQL::encryptDataDB(null, self::KEY, 't.c:1'));
        self::assertSame('', SQL::encryptDataDB('', self::KEY, 't.c:1'));
    }

    public function testDecryptDataDBReturnsEmptyStringForNullAndEmptyInput(): void {
        self::assertSame('', SQL::decryptDataDB(null, self::KEY, 't.c:1'));
        self::assertSame('', SQL::decryptDataDB('', self::KEY, 't.c:1'));
    }

    /** The whole point of the AAD: a ciphertext lifted from one cell must not decrypt in another. */
    public function testDecryptDataDBRejectsWrongAadSoCiphertextCannotBeRelocated(): void {
        $cipher = SQL::encryptDataDB('salary', self::KEY, 'staff.salary:1');

        $this->expectException(\Exception::class);
        SQL::decryptDataDB($cipher, self::KEY, 'staff.salary:2');
    }

    public function testDecryptDataDBRejectsWrongSalt(): void {
        $cipher = SQL::encryptDataDB('secret', self::KEY, 't.c:1', 'salt-a');

        $this->expectException(\Exception::class);
        SQL::decryptDataDB($cipher, self::KEY, 't.c:1', 'salt-b');
    }

    public function testDecryptDataDBRejectsWrongKey(): void {
        $cipher = SQL::encryptDataDB('secret', self::KEY, 't.c:1');

        $this->expectException(\Exception::class);
        SQL::decryptDataDB($cipher, 'another-master-key-0123456789-abcdef', 't.c:1');
    }

    public function testDecryptDataDBRejectsTamperedEnvelope(): void {
        $cipher = SQL::encryptDataDB('secret', self::KEY, 't.c:1');
        $last = substr($cipher, -1);
        $tampered = substr($cipher, 0, -1) . ($last === 'A' ? 'B' : 'A');

        $this->expectException(\Exception::class);
        SQL::decryptDataDB($tampered, self::KEY, 't.c:1');
    }

    public function testDecryptDataDBRejectsUnknownEnvelopeVersion(): void {
        $cipher = SQL::encryptDataDB('secret', self::KEY, 't.c:1');
        $payload = substr($cipher, (int) strpos($cipher, ':') + 1);

        $this->expectException(\Exception::class);
        SQL::decryptDataDB('v999:' . $payload, self::KEY, 't.c:1');
    }

    public function testDecryptDataDBRejectsEnvelopeWithoutVersionPrefix(): void {
        $this->expectException(\Exception::class);
        SQL::decryptDataDB('bm90LWFuLWVudmVsb3Bl', self::KEY, 't.c:1');
    }

    public function testEncryptDataDBRejectsEmptyAad(): void {
        $this->expectException(\Exception::class);
        SQL::encryptDataDB('secret', self::KEY, '');
    }

    public function testDecryptDataDBRejectsEmptyAad(): void {
        $cipher = SQL::encryptDataDB('secret', self::KEY, 't.c:1');

        $this->expectException(\Exception::class);
        SQL::decryptDataDB($cipher, self::KEY, '');
    }

    // ---------------------------------------------------------------- prepareInsertOrUpdateMySQL: guards

    public function testPrepareReturnsTrueForEmptyDataset(): void {
        $data = [];

        self::assertTrue(SQL::prepareInsertOrUpdateMySQL($data, 'clients'));
        self::assertSame([], $data);
    }

    public function testPrepareReturnsFalseForEmptyTableAndLeavesDatasetUntouched(): void {
        $data = [['id' => 1]];

        self::assertFalse(SQL::prepareInsertOrUpdateMySQL($data, ''));
        self::assertSame([['id' => 1]], $data);
    }

    /**
     * FINDING (fixed): when no column could be resolved, the old code built "INSERT INTO t ()
     * VALUES ", formatted every row to '', sliced every row out of the by-ref $data and returned
     * TRUE — which the docblock defines as success. The caller lost the whole dataset and was told
     * it worked.
     */
    public function testPrepareReturnsFalseWhenNoInsertColumnResolvesInsteadOfSilentlyEatingTheDataset(): void {
        $data = [['meta' => ['a' => 1]], ['meta' => ['b' => 2]]];

        self::assertFalse(SQL::prepareInsertOrUpdateMySQL($data, 't'));
        self::assertCount(2, $data, 'the dataset must survive an error return');
    }

    public function testPrepareReturnsFalseForAnEmptyInsertFieldsList(): void {
        $data = [['id' => 1]];

        self::assertFalse(SQL::prepareInsertOrUpdateMySQL($data, 't', ' , '));
        self::assertCount(1, $data);
    }

    // ---------------------------------------------------------------- prepareInsertOrUpdateMySQL: findings

    /**
     * FINDING (fixed): the exact call the docblock sanctions — both field lists supplied, formatter
     * and $global omitted, all three documented as independently optional. The column derivation
     * used to sit behind `if ($insertFields === null || $updateFields === null)`, so
     * $global['__insert'] was never set and the default formatter hit
     * `foreach ($global['__insert'])` with an undefined key: an E_WARNING (fatal under any
     * throwing error handler), then every row formatted to '', every row sliced out of $data, and
     * TRUE returned. Silent total data loss, reported as success.
     */
    public function testPrepareBuildsStatementWhenBothFieldListsSuppliedAndFormatterOmitted(): void {
        $data = [['id' => 1, 'name' => "O'Brien"], ['id' => 2, 'name' => 'Ada']];

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 'clients', 'id,name', 'name=VALUES(name)');

        self::assertSame(
            "INSERT INTO clients (id,name) VALUES (1,'O\\'Brien'),(2,'Ada') "
                . 'ON DUPLICATE KEY UPDATE name=VALUES(name)',
            $sql
        );
        self::assertSame([], $data, 'both rows were written, so both are consumed');
    }

    /**
     * The corollary of that fix: with $insertFields supplied, the value groups must follow THAT
     * list and not the row's keys — otherwise a row carrying an extra key emits a third value
     * against a two-column INSERT.
     */
    public function testPrepareFollowsInsertFieldsNotRowKeysSoExtraKeysCannotDesynchroniseGroups(): void {
        $data = [['id' => 1, 'name' => 'Ada', 'internal_note' => 'ignore me']];

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 'clients', 'id,name');

        self::assertSame(
            "INSERT INTO clients (id,name) VALUES (1,'Ada') "
                . 'ON DUPLICATE KEY UPDATE id=VALUES(id),name=VALUES(name)',
            $sql
        );
        self::assertStringNotContainsString('ignore me', (string) $sql);
    }

    /**
     * FINDING (fixed): the old code searched the query built so far for the row's value group and
     * dropped any row whose group already appeared — a skip the docblock never mentions while
     * enumerating the skip contract exhaustively. Two identical rows in one batch (a journal, an
     * audit trail, a table with an auto-increment PK) silently collapsed into one INSERT, and the
     * dropped row had already been sliced out of the by-ref $data, so it was unrecoverable.
     */
    public function testPrepareKeepsBothOfTwoIdenticalRows(): void {
        $data = [['level' => 'warn', 'msg' => 'disk'], ['level' => 'warn', 'msg' => 'disk']];

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 'journal');

        self::assertSame(
            "INSERT INTO journal (level,msg) VALUES ('warn','disk'),('warn','disk') "
                . 'ON DUPLICATE KEY UPDATE level=VALUES(level),msg=VALUES(msg)',
            $sql
        );
        self::assertSame(2, substr_count((string) $sql, "('warn','disk')"));
    }

    /**
     * The audit's other named case for the same bug: rows that are distinct in the dataset but
     * identical once projected onto $insertFields.
     */
    public function testPrepareKeepsRowsThatDifferOnlyInColumnsOutsideInsertFields(): void {
        $data = [
            ['id' => 1, 'name' => 'Ada'],
            ['id' => 2, 'name' => 'Ada'],
            ['id' => 3, 'name' => 'Ada'],
        ];

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 'people', 'name', 'name=VALUES(name)');

        self::assertSame(3, substr_count((string) $sql, "('Ada')"), 'all three rows must be inserted');
    }

    /**
     * FINDING (fixed): array_slice() defaulted to preserve_keys = false, so a partial batch did not
     * merely remove the processed rows — it renumbered the survivors 0..n. A caller keying rows by
     * primary key and reading that id from the formatter's $key wrote correct ids in the first
     * batch and 0, 1, 2 ... in every batch after, silently.
     */
    public function testPreparePreservesOuterKeysOfUnprocessedRowsAcrossAPartialBatch(): void {
        $this->forcePartialBatchBudget();

        $data = [
            101 => ['id' => 101, 'v' => 'a'],
            202 => ['id' => 202, 'v' => 'b'],
            303 => ['id' => 303, 'v' => 'c'],
            404 => ['id' => 404, 'v' => 'd'],
        ];

        $keysSeen = [];
        $sql = SQL::prepareInsertOrUpdateMySQL(
            $data,
            't',
            'id,v',
            'v=VALUES(v)',
            $this->ballastFormatter($keysSeen)
        );

        self::assertIsString($sql);
        self::assertSame([101, 202], $keysSeen, "the formatter sees the caller's real keys");
        self::assertSame(
            [303, 404],
            array_keys($data),
            'survivors keep their primary keys; before the fix these were renumbered to 0 and 1'
        );
        self::assertSame(['id' => 303, 'v' => 'c'], $data[303]);
        self::assertSame(['id' => 404, 'v' => 'd'], $data[404]);
    }

    public function testPrepareRemovesOnlyTheProcessedRowsOnAPartialBatch(): void {
        $this->forcePartialBatchBudget();

        $data = [
            ['id' => 1, 'v' => 'a'],
            ['id' => 2, 'v' => 'b'],
            ['id' => 3, 'v' => 'c'],
        ];

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 't', 'id,v', 'v=VALUES(v)', $this->ballastFormatter());

        self::assertSame(
            "INSERT INTO t (id,v) VALUES (1,'a'),(2,'b') ON DUPLICATE KEY UPDATE v=VALUES(v)",
            $sql
        );
        self::assertCount(1, $data, 'the unwritten row stays in the dataset for the next call');
        self::assertSame(['id' => 3, 'v' => 'c'], reset($data));
    }

    /** The documented drain loop must terminate and emit every row exactly once. */
    public function testPrepareDrainLoopEmitsEveryRowExactlyOnce(): void {
        $this->forcePartialBatchBudget();

        $data = [];
        for ($i = 1; $i <= 5; $i++) {
            $data['k' . $i] = ['id' => $i];
        }

        $emitted = [];
        $guard = 0;

        while (($sql = SQL::prepareInsertOrUpdateMySQL($data, 't', 'id', 'id=VALUES(id)', $this->ballastFormatter())) !== true) {
            self::assertNotFalse($sql, 'the drain loop must not error');
            preg_match_all('/\((\d+)\)/', (string) $sql, $matches);
            $emitted = array_merge($emitted, $matches[1]);
            self::assertLessThan(20, ++$guard, 'the drain loop must terminate');
        }

        self::assertSame(['1', '2', '3', '4', '5'], $emitted, 'every row exactly once, in order');
        self::assertSame([], $data);
    }

    // ---------------------------------------------------------------- prepareInsertOrUpdateMySQL: formatter contract

    public function testPrepareAutoDerivesColumnsAndUpdateClauseFromFirstRow(): void {
        $data = [['id' => 7, 'note' => null, 'ok' => true, 'ratio' => 1.5]];

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 't');

        self::assertSame(
            'INSERT INTO t (id,note,ok,ratio) VALUES (7,NULL,1,1.5) ON DUPLICATE KEY UPDATE '
                . 'id=VALUES(id),note=VALUES(note),ok=VALUES(ok),ratio=VALUES(ratio)',
            $sql
        );
    }

    public function testPrepareAutoDerivationSkipsArrayAndObjectValuedKeys(): void {
        $data = [['id' => 1, 'tags' => ['a'], 'meta' => new \stdClass(), 'name' => 'Ada']];

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 't');

        self::assertSame(
            "INSERT INTO t (id,name) VALUES (1,'Ada') ON DUPLICATE KEY UPDATE id=VALUES(id),name=VALUES(name)",
            $sql
        );
    }

    public function testPrepareDefaultFormatterRendersColumnMissingFromRowAsNull(): void {
        $data = [['id' => 1, 'name' => 'Ada'], ['id' => 2]];

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 't', 'id,name', 'name=VALUES(name)');

        self::assertSame(
            "INSERT INTO t (id,name) VALUES (1,'Ada'),(2,NULL) ON DUPLICATE KEY UPDATE name=VALUES(name)",
            $sql
        );
    }

    /**
     * A value with no literal form, in a column the caller explicitly listed, must fail loudly:
     * dropping it would shift every later value into the wrong column, silently.
     */
    public function testPrepareThrowsRatherThanEmitAMisalignedValueGroup(): void {
        $data = [['id' => 1, 'meta' => ['nested' => true], 'name' => 'Ada']];

        $this->expectException(\InvalidArgumentException::class);
        SQL::prepareInsertOrUpdateMySQL($data, 't', 'id,meta,name');
    }

    public function testPrepareDefaultFormatterEscapesRowValues(): void {
        $data = [['v' => "'; DROP TABLE t; -- "]];

        $sql = SQL::prepareInsertOrUpdateMySQL($data, 't', 'v', 'v=VALUES(v)');

        self::assertSame(
            "INSERT INTO t (v) VALUES ('\\'; DROP TABLE t; -- ') ON DUPLICATE KEY UPDATE v=VALUES(v)",
            $sql
        );
    }

    public function testPrepareReturnsFalseAndLeavesDatasetUntouchedWhenFormatterReturnsFalse(): void {
        $data = [['id' => 1], ['id' => 2]];
        $original = $data;

        $result = SQL::prepareInsertOrUpdateMySQL(
            $data,
            't',
            'id',
            'id=VALUES(id)',
            static fn (array $row, int|string $key, array &$global): string|false => $row['id'] === 2 ? false : '(1)'
        );

        self::assertFalse($result);
        self::assertSame($original, $data, 'an aborted run must not consume rows');
    }

    public function testPrepareSkipsRowWhenFormatterReturnsEmptyStringButStillConsumesIt(): void {
        $data = [['id' => 1], ['id' => 2], ['id' => 3]];

        $sql = SQL::prepareInsertOrUpdateMySQL(
            $data,
            't',
            'id',
            'id=VALUES(id)',
            static fn (array $row, int|string $key, array &$global): string =>
                $row['id'] === 2 ? '' : '(' . SQL::escapeString($row['id']) . ')'
        );

        self::assertSame('INSERT INTO t (id) VALUES (1),(3) ON DUPLICATE KEY UPDATE id=VALUES(id)', $sql);
        self::assertSame([], $data, 'the skipped row is still consumed');
    }

    public function testPrepareReturnsTrueWhenEveryRowIsSkipped(): void {
        $data = [['id' => 1], ['id' => 2]];

        $result = SQL::prepareInsertOrUpdateMySQL(
            $data,
            't',
            'id',
            'id=VALUES(id)',
            static fn (array $row, int|string $key, array &$global): string => ''
        );

        self::assertTrue($result);
        self::assertSame([], $data);
    }

    /** The documented $global['__insert'] contract that the default formatter depends on. */
    public function testPrepareAlwaysPublishesResolvedInsertColumnsInGlobalInsert(): void {
        $data = [['id' => 1, 'name' => 'Ada']];
        $seen = null;

        SQL::prepareInsertOrUpdateMySQL(
            $data,
            't',
            ' `id` , `name` ',
            'name=VALUES(name)',
            static function (array $row, int|string $key, array &$global) use (&$seen): string {
                $seen = $global['__insert'];

                return '(1)';
            },
            ['__insert' => ['caller junk']]
        );

        self::assertSame(['id', 'name'], $seen, 'backticks and padding are trimmed off the column list');
    }

    public function testPrepareDoesNotLeakGlobalMutationsBackToTheCaller(): void {
        $data = [['id' => 1]];
        $global = ['counter' => 0];

        SQL::prepareInsertOrUpdateMySQL(
            $data,
            't',
            'id',
            'id=VALUES(id)',
            static function (array $row, int|string $key, array &$global): string {
                $global['counter']++;

                return '(1)';
            },
            $global
        );

        self::assertSame(['counter' => 0], $global, '$global is passed by value into the method');
    }
}
