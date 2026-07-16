<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\File;

final class FileTest extends TestCase {
    /**
     * Absolute path to this test's private scratch directory, inside sys_get_temp_dir().
     */
    private string $tmp;

    protected function setUp(): void {
        $this->tmp = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'phphelper_filetest_' . bin2hex(random_bytes(8));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void {
        // Static state is process-wide: restore the documented defaults so ordering cannot leak.
        File::setDownloadBlockSize(null);
        File::setDefaultMode('755');

        $this->removeTree($this->tmp);
    }

    /**
     * Recursively removes a tree, clearing the read-only bit tests may have set.
     * Deliberately does NOT use File::deleteFoldersRecursively — the cleanup must not depend on
     * the code under test.
     */
    private function removeTree(string $path): void {
        if (!file_exists($path) && !is_link($path)) {
            return;
        }
        if (is_file($path)) {
            @chmod($path, 0666);
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
        }
        @rmdir($path);
    }

    /**
     * Builds an absolute path inside this test's scratch directory.
     */
    private function path(string ...$segments): string {
        return $this->tmp . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Writes a file inside the scratch directory, creating parents. Bypasses the class under test.
     */
    private function seedFile(string $relative, string $content = 'seed'): string {
        $full = $this->path($relative);
        $dir = dirname($full);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        file_put_contents($full, $content);
        return $full;
    }

    /**
     * Names of the entries directly inside $dir, sorted — real on-disk names, so a case-only
     * rename is observable even on a case-insensitive filesystem.
     */
    private function entriesIn(string $dir): array {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);
        return $entries;
    }

    /**
     * Builds a zip from [entryName => contents]. An entry name ending in '/' becomes a directory.
     */
    private function makeZip(string $zipPath, array $entries): string {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE));
        foreach ($entries as $name => $contents) {
            if (str_ends_with((string) $name, '/')) {
                $zip->addEmptyDir(rtrim((string) $name, '/'));
                continue;
            }
            $zip->addFromString((string) $name, (string) $contents);
        }
        $this->assertTrue($zip->close());
        return $zipPath;
    }

    /**
     * Entry names inside a zip, sorted.
     */
    private function zipEntries(string $zipPath): array {
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($zipPath));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $zip->close();
        sort($names);
        return $names;
    }

    // ---------------------------------------------------------------------
    // getDownloadBlockSize / setDownloadBlockSize
    // ---------------------------------------------------------------------

    /**
     * REGRESSION: the docblock advertised a default of 8192 bytes while the code used 3 MB —
     * a 384x gap that a caller sizing memory_limit would take straight into an OOM.
     */
    public function testGetDownloadBlockSizeDefaultsToThreeMegabytesAsDocumented(): void {
        $this->assertSame(3 * 1024 * 1024, File::getDownloadBlockSize());
        $this->assertSame(3145728, File::getDownloadBlockSize());
    }

    public function testSetDownloadBlockSizeAppliesACustomValue(): void {
        File::setDownloadBlockSize(8192);
        $this->assertSame(8192, File::getDownloadBlockSize());
    }

    /**
     * REGRESSION: the docblock documented "if null, reverts to the default", but the signature was
     * a non-nullable int, so the documented reset idiom raised a TypeError.
     */
    public function testSetDownloadBlockSizeWithNullRevertsToTheDefaultAsDocumented(): void {
        File::setDownloadBlockSize(4096);
        $this->assertSame(4096, File::getDownloadBlockSize());

        File::setDownloadBlockSize(null);

        $this->assertSame(3145728, File::getDownloadBlockSize());
    }

    public function testSetDownloadBlockSizeWithNoArgumentRevertsToTheDefault(): void {
        File::setDownloadBlockSize(4096);
        File::setDownloadBlockSize();
        $this->assertSame(3145728, File::getDownloadBlockSize());
    }

    /**
     * A non-positive block size would reach fread() and raise a ValueError mid-stream, after the
     * headers were already sent. It must be rejected at the setter instead.
     */
    public function testSetDownloadBlockSizeRejectsZero(): void {
        $this->expectException(\InvalidArgumentException::class);
        File::setDownloadBlockSize(0);
    }

    public function testSetDownloadBlockSizeRejectsNegativeValues(): void {
        $this->expectException(\InvalidArgumentException::class);
        File::setDownloadBlockSize(-1);
    }

    public function testSetDownloadBlockSizeRejectionLeavesThePreviousValueIntact(): void {
        File::setDownloadBlockSize(4096);
        try {
            File::setDownloadBlockSize(-5);
            $this->fail('Expected InvalidArgumentException for a negative block size.');
        } catch (\InvalidArgumentException) {
            // expected
        }
        $this->assertSame(4096, File::getDownloadBlockSize());
    }

    // ---------------------------------------------------------------------
    // getDefaultMode / setDefaultMode / getPermissionMode
    // ---------------------------------------------------------------------

    /**
     * REGRESSION: the default mode was 0777, so every directory the library created was
     * world-writable while createDir() documented "Default is system-defined".
     */
    public function testDefaultModeIsNotWorldWritable(): void {
        $this->assertSame(octdec('755'), File::getDefaultMode());
        $this->assertSame(0, File::getDefaultMode() & 0o002, 'The default mode must not be world-writable.');
    }

    public function testSetDefaultModeAppliesAValidOctalString(): void {
        File::setDefaultMode('700');
        $this->assertSame(octdec('700'), File::getDefaultMode());
    }

    public function testSetDefaultModeSilentlyIgnoresANonOctalStringAsDocumented(): void {
        File::setDefaultMode('700');
        File::setDefaultMode('999');
        $this->assertSame(octdec('700'), File::getDefaultMode(), 'An invalid octal must keep the previous default.');
    }

    public function testGetPermissionModeParsesAnOctalString(): void {
        $this->assertSame(octdec('700'), File::getPermissionMode('0700'));
        $this->assertSame(octdec('644'), File::getPermissionMode('644'));
    }

    public function testGetPermissionModeFallsBackToTheDefaultForNullOrInvalidInput(): void {
        $this->assertSame(File::getDefaultMode(), File::getPermissionMode(null));
        $this->assertSame(File::getDefaultMode(), File::getPermissionMode('not-octal'));
        $this->assertSame(File::getDefaultMode(), File::getPermissionMode(''));
    }

    /**
     * LIVE SECURITY CONTROL. getPermissionMode('+7') must never resolve to 0007 — owner nothing,
     * world rwx. It did exactly that while the octal check round-tripped through octdec(), which
     * silently DROPS the '+' and reads the rest as 7. A malformed mode must land on the safe
     * default instead.
     *
     * Now that File delegates to Validator::isOctal(), this pins the collapsed route too: a
     * regression in Validator::isOctal() surfaces here as a real permission bug.
     */
    public function testGetPermissionModeNeverResolvesASignedModeToWorldRwx(): void {
        foreach (['+7', ' 7', '7 ', '-0', '-7'] as $mode) {
            $resolved = File::getPermissionMode($mode);

            $this->assertSame(
                File::getDefaultMode(),
                $resolved,
                "A malformed mode '{$mode}' must fall back to the default, not be parsed."
            );
            $this->assertNotSame(0o007, $resolved, "'{$mode}' must never become world-rwx/owner-nothing.");
        }
    }

    /**
     * The old private File::isOctalMode() used preg_match('/^0?[0-7]+$/'), whose '$' also matches
     * just BEFORE a trailing newline — so "0755\n" passed the check and octdec() then happily
     * returned 493. Validator::isOctal() tests the character set with strspn() and has no such
     * hole, so the collapse tightened this. A mode with a trailing newline is malformed and must
     * fall back to the default rather than being honoured.
     */
    public function testGetPermissionModeRejectsAModeWithATrailingNewline(): void {
        // Both modes must DIFFER from the 0755 default, or the assertion could pass while the
        // newline-suffixed mode was being parsed and honoured.
        $this->assertSame(File::getDefaultMode(), File::getPermissionMode("700\n"));
        $this->assertSame(File::getDefaultMode(), File::getPermissionMode("0777\n"));
    }

    public function testSetDefaultModeIgnoresAModeWithATrailingNewline(): void {
        File::setDefaultMode('700');
        File::setDefaultMode("0777\n");

        $this->assertSame(octdec('700'), File::getDefaultMode(), 'A newline-suffixed mode must not become the default.');
    }

    /**
     * writeFile() rejects a malformed mode rather than silently widening to the default. That
     * guard now runs through Validator::isOctal(), so the newline hole is closed there too.
     */
    public function testWriteFileRejectsAPermissionModeWithATrailingNewline(): void {
        $this->expectException(\InvalidArgumentException::class);
        File::writeFile($this->path('nl.txt'), 'x', false, "0600\n");
    }

    // ---------------------------------------------------------------------
    // createDir
    // ---------------------------------------------------------------------

    public function testCreateDirCreatesANestedDirectoryAndReturnsTwo(): void {
        $target = $this->path('a', 'b', 'c');
        $this->assertSame(2, File::createDir($target));
        $this->assertDirectoryExists($target);
    }

    public function testCreateDirReturnsOneWhenTheDirectoryAlreadyExists(): void {
        $target = $this->path('already');
        mkdir($target);
        $this->assertSame(1, File::createDir($target));
    }

    public function testCreateDirReturnsMinusOneForAnEmptyPath(): void {
        $this->assertSame(-1, File::createDir(''));
    }

    public function testCreateDirReturnsMinusTwoWhenMkdirFails(): void {
        // A path whose parent is a regular file can never be created.
        $file = $this->seedFile('blocker.txt');
        $this->assertSame(-2, File::createDir($file . DIRECTORY_SEPARATOR . 'sub', null, false));
    }

    public function testCreateDirIsNotRecursiveWhenRecursiveIsFalse(): void {
        $target = $this->path('missing', 'deep');
        $this->assertSame(-2, File::createDir($target, null, false));
        $this->assertDirectoryDoesNotExist($target);
    }

    // ---------------------------------------------------------------------
    // getPathInfo
    // ---------------------------------------------------------------------

    public function testGetPathInfoReturnsAllNullsForAnEmptyPath(): void {
        $info = File::getPathInfo('');
        $this->assertNull($info['dir']);
        $this->assertNull($info['file']);
        $this->assertNull($info['path']);
        $this->assertFalse($info['exists']);
        $this->assertFalse($info['isDir']);
        $this->assertFalse($info['isFile']);
    }

    public function testGetPathInfoDescribesAnExistingFile(): void {
        $file = $this->seedFile('data.txt', 'x');
        $info = File::getPathInfo($file);

        $this->assertSame('data.txt', $info['file']);
        $this->assertTrue($info['exists']);
        $this->assertTrue($info['isFile']);
        $this->assertFalse($info['isDir']);
        $this->assertStringEndsWith(DIRECTORY_SEPARATOR, $info['dir']);
    }

    public function testGetPathInfoDescribesAnExistingDirectory(): void {
        $info = File::getPathInfo($this->tmp);

        $this->assertNull($info['file']);
        $this->assertTrue($info['isDir']);
        $this->assertFalse($info['isFile']);
        $this->assertTrue($info['exists']);
    }

    public function testGetPathInfoCreatesTheDirectoryWhenCreatePathIsTrue(): void {
        $target = $this->path('made', 'here');
        $info = File::getPathInfo($target, createPath: true);

        $this->assertTrue($info['isDir']);
        $this->assertDirectoryExists($target);
    }

    public function testGetPathInfoDoesNotCreateTheDirectoryByDefault(): void {
        $target = $this->path('untouched');
        $info = File::getPathInfo($target);

        $this->assertFalse($info['isDir']);
        $this->assertDirectoryDoesNotExist($target);
    }

    public function testGetPathInfoTreatsAMissingExtensionlessSegmentAsAFileWhenAsked(): void {
        $info = File::getPathInfo($this->path('ghost'), keepFileNotExists: true);
        $this->assertSame('ghost', $info['file']);
        $this->assertFalse($info['exists']);
    }

    // ---------------------------------------------------------------------
    // writeFile
    // ---------------------------------------------------------------------

    public function testWriteFileCreatesTheFileWithItsContentAndMissingParents(): void {
        $target = $this->path('deep', 'nested', 'out.txt');
        File::writeFile($target, 'hello');

        $this->assertFileExists($target);
        $this->assertSame('hello', file_get_contents($target));
    }

    public function testWriteFileTruncatesByDefault(): void {
        $target = $this->seedFile('t.txt', 'original-content');
        File::writeFile($target, 'new');
        $this->assertSame('new', file_get_contents($target));
    }

    public function testWriteFileAppendsWhenAppendIsTrue(): void {
        $target = $this->seedFile('t.txt', 'a');
        File::writeFile($target, 'b', true);
        $this->assertSame('ab', file_get_contents($target));
    }

    public function testWriteFileThrowsForAnEmptyPath(): void {
        $this->expectException(\Exception::class);
        File::writeFile('', 'x');
    }

    /**
     * REGRESSION: chmod() ran BEFORE fopen() created the file, so it always failed (and the `@`
     * swallowed it). A caller writing secrets with mode '0600' silently got a world-readable file.
     * 0444 is used because it is the one mode observable on both POSIX and Windows.
     */
    public function testWriteFilePermissionModeIsActuallyAppliedToANewlyCreatedFile(): void {
        $target = $this->path('secret.json');
        File::writeFile($target, '{"k":"v"}', false, '0444');

        clearstatcache(true, $target);
        $this->assertSame(0444, fileperms($target) & 0777, 'The requested mode must reach the created file.');
        $this->assertSame('{"k":"v"}', file_get_contents($target), 'The content must be written before the chmod.');
    }

    /**
     * REGRESSION: $permissionMode was documented "optional", but omitting it resolved to the 0777
     * default and chmod'ed the target anyway — a plain write relaxed an existing 0600 secret file
     * to world-writable. Omitting the mode must leave permissions untouched.
     */
    public function testWriteFileWithoutAPermissionModeDoesNotChangeAnExistingFilesPermissions(): void {
        $target = $this->seedFile('existing.txt', 'original');
        chmod($target, 0444);
        clearstatcache(true, $target);

        if (is_writable($target)) {
            $this->markTestSkipped('This filesystem/user ignores the read-only bit; mode changes are not observable.');
        }

        try {
            File::writeFile($target, 'overwritten');
        } catch (\Exception) {
            // Expected on a read-only file: without the silent chmod, the open fails.
        }

        clearstatcache(true, $target);
        $this->assertSame(0444, fileperms($target) & 0777, 'writeFile() must not chmod a file the caller gave no mode for.');
        $this->assertSame('original', file_get_contents($target), 'A read-only file must not be clobbered.');
    }

    /**
     * An invalid octal used to fall back to the 0777 default — widening permissions on exactly the
     * call that asked to restrict them. It must be refused.
     */
    public function testWriteFileRejectsANonOctalPermissionMode(): void {
        $this->expectException(\InvalidArgumentException::class);
        File::writeFile($this->path('bad.txt'), 'x', false, 'rw-------');
    }

    public function testWriteFileRejectsAnInvalidOctalPermissionModeWithoutCreatingTheFile(): void {
        $target = $this->path('never.txt');
        try {
            File::writeFile($target, 'x', false, '999');
            $this->fail('Expected InvalidArgumentException for a non-octal mode.');
        } catch (\InvalidArgumentException) {
            // expected
        }
        $this->assertFileDoesNotExist($target);
    }

    // ---------------------------------------------------------------------
    // createTempFile
    // ---------------------------------------------------------------------

    /**
     * REGRESSION: createTempFile() called genGuid(), which is defined nowhere in this package, so
     * every call raised \Error — not an \Exception, so it sailed through the documented catch.
     * The method could never return the documented path.
     */
    public function testCreateTempFileReturnsAWrittenFileAndDoesNotFatal(): void {
        $path = File::createTempFile('rep', 'payload');

        try {
            $this->assertIsString($path);
            $this->assertFileExists($path);
            $this->assertSame('payload', file_get_contents($path));
            $this->assertStringStartsWith(realpath(sys_get_temp_dir()), realpath($path));
        } finally {
            @unlink($path);
        }
    }

    public function testCreateTempFileWorksWithNoArgumentsAndCreatesAnEmptyFile(): void {
        $path = File::createTempFile();
        try {
            $this->assertFileExists($path);
            $this->assertSame('', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testCreateTempFileReturnsADistinctPathOnEveryCall(): void {
        $a = File::createTempFile('dup');
        $b = File::createTempFile('dup');
        try {
            $this->assertNotSame($a, $b);
            $this->assertFileExists($a);
            $this->assertFileExists($b);
        } finally {
            @unlink($a);
            @unlink($b);
        }
    }

    public function testCreateTempFileAppliesAnExplicitPermissionMode(): void {
        $path = File::createTempFile('perm', 'x', '0444');
        try {
            clearstatcache(true, $path);
            $this->assertSame(0444, fileperms($path) & 0777);
        } finally {
            @chmod($path, 0666);
            @unlink($path);
        }
    }

    public function testCreateTempFileRejectsANonOctalPermissionMode(): void {
        $this->expectException(\InvalidArgumentException::class);
        File::createTempFile('x', '', 'nope');
    }

    // ---------------------------------------------------------------------
    // deleteFoldersRecursively / resetFolder
    // ---------------------------------------------------------------------

    public function testDeleteFoldersRecursivelyRemovesNestedContent(): void {
        $root = $this->path('tree');
        $this->seedFile('tree' . DIRECTORY_SEPARATOR . 'a.txt');
        $this->seedFile('tree' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.txt');

        $this->assertTrue(File::deleteFoldersRecursively($root));
        $this->assertDirectoryDoesNotExist($root);
    }

    public function testDeleteFoldersRecursivelyReturnsFalseForAMissingDirectory(): void {
        $this->assertFalse(File::deleteFoldersRecursively($this->path('nope')));
    }

    public function testDeleteFoldersRecursivelyReturnsFalseWhenGivenAFile(): void {
        $file = $this->seedFile('plain.txt');
        $this->assertFalse(File::deleteFoldersRecursively($file));
        $this->assertFileExists($file);
    }

    public function testResetFolderEmptiesAnExistingFolderAndKeepsIt(): void {
        $root = $this->path('reset');
        $this->seedFile('reset' . DIRECTORY_SEPARATOR . 'old.txt');

        $this->assertTrue(File::resetFolder($root));
        $this->assertDirectoryExists($root);
        $this->assertSame([], $this->entriesIn($root));
    }

    public function testResetFolderCreatesTheFolderWhenItDoesNotExist(): void {
        $root = $this->path('fresh');
        $this->assertTrue(File::resetFolder($root));
        $this->assertDirectoryExists($root);
    }

    // ---------------------------------------------------------------------
    // standardizeFilesCaseRecursive
    // ---------------------------------------------------------------------

    /**
     * REGRESSION: $caseFunction was the string 'Str::strToLower', which call_user_func() resolves
     * against the GLOBAL namespace, so it raised a TypeError ("class Str not found") on the first
     * entry of any NON-EMPTY directory. The documented bool was unreachable; the method only
     * appeared to work on an empty directory.
     */
    public function testStandardizeFilesCaseRecursiveLowercasesNamesOnANonEmptyDirectory(): void {
        $root = $this->path('names');
        $this->seedFile('names' . DIRECTORY_SEPARATOR . 'UPPER.TXT');
        $this->seedFile('names' . DIRECTORY_SEPARATOR . 'SUB' . DIRECTORY_SEPARATOR . 'INNER.TXT');

        $this->assertTrue(File::standardizeFilesCaseRecursive($root));

        $this->assertSame(['sub', 'upper.txt'], $this->entriesIn($root));
        $this->assertSame(['inner.txt'], $this->entriesIn($root . DIRECTORY_SEPARATOR . 'sub'));
    }

    public function testStandardizeFilesCaseRecursiveUppercasesNamesWhenToUpperIsTrue(): void {
        $root = $this->path('names');
        $this->seedFile('names' . DIRECTORY_SEPARATOR . 'lower.txt');

        $this->assertTrue(File::standardizeFilesCaseRecursive($root, true));

        $this->assertSame(['LOWER.TXT'], $this->entriesIn($root));
    }

    public function testStandardizeFilesCaseRecursiveReturnsTrueOnAnEmptyDirectory(): void {
        $root = $this->path('empty');
        mkdir($root);
        $this->assertTrue(File::standardizeFilesCaseRecursive($root));
    }

    public function testStandardizeFilesCaseRecursiveReturnsFalseForANonDirectory(): void {
        $this->assertFalse(File::standardizeFilesCaseRecursive($this->path('missing')));
        $this->assertFalse(File::standardizeFilesCaseRecursive($this->seedFile('f.txt')));
    }

    // ---------------------------------------------------------------------
    // parseEnvFile
    // ---------------------------------------------------------------------

    public function testParseEnvFileReadsKeyValuePairsAndSkipsCommentsAndBlanks(): void {
        $env = $this->seedFile('.env', implode(PHP_EOL, [
            '# a comment',
            '',
            'APP_KEY=secret123',
            'DB_PASS = hunter2 ',
            '=broken',
            'no-equals-here',
            'URL=https://example.test/?a=b',
        ]));

        $this->assertSame([
            'APP_KEY' => 'secret123',
            'DB_PASS' => 'hunter2',
            'URL' => 'https://example.test/?a=b',
        ], File::parseEnvFile($env));
    }

    public function testParseEnvFileReturnsAnEmptyArrayForAMissingFile(): void {
        $this->assertSame([], File::parseEnvFile($this->path('absent.env')));
    }

    // ---------------------------------------------------------------------
    // updateEnvFile
    // ---------------------------------------------------------------------

    /**
     * REGRESSION (the fixed critical): updateEnvFile() called writeFile() to "ensure the file
     * exists", which opened it "w" and TRUNCATED it before the read-back. Rotating one credential
     * silently destroyed every other secret and comment — and returned TRUE, so nothing alerted.
     */
    public function testUpdateEnvFilePreservesEveryOtherVariableAndCommentWhenUpdatingOneKey(): void {
        $env = $this->seedFile('.env', implode(PHP_EOL, [
            '# keep me',
            'APP_KEY=secret123',
            'DB_PASS=hunter2',
            '',
            '# trailing note',
        ]));

        $this->assertTrue(File::updateEnvFile($env, ['DB_PASS' => 'newpass']));

        $contents = file_get_contents($env);
        $this->assertStringContainsString('# keep me', $contents);
        $this->assertStringContainsString('APP_KEY=secret123', $contents);
        $this->assertStringContainsString('DB_PASS=newpass', $contents);
        $this->assertStringContainsString('# trailing note', $contents);
        $this->assertStringNotContainsString('hunter2', $contents);

        $this->assertSame(
            ['APP_KEY' => 'secret123', 'DB_PASS' => 'newpass'],
            File::parseEnvFile($env)
        );
    }

    public function testUpdateEnvFileAppendsKeysThatAreNotPresentYet(): void {
        $env = $this->seedFile('.env', 'APP_KEY=secret123' . PHP_EOL);

        $this->assertTrue(File::updateEnvFile($env, ['NEW_KEY' => 'newvalue']));

        $this->assertSame(
            ['APP_KEY' => 'secret123', 'NEW_KEY' => 'newvalue'],
            File::parseEnvFile($env)
        );
    }

    public function testUpdateEnvFileCreatesTheFileWhenItDoesNotExist(): void {
        $env = $this->path('created.env');

        $this->assertTrue(File::updateEnvFile($env, ['A' => '1']));

        $this->assertFileExists($env);
        $this->assertSame(['A' => '1'], File::parseEnvFile($env));
    }

    public function testUpdateEnvFileWithNoVariablesLeavesTheContentIntact(): void {
        $env = $this->seedFile('.env', '# note' . PHP_EOL . 'A=1' . PHP_EOL);

        $this->assertTrue(File::updateEnvFile($env, []));

        $this->assertStringContainsString('# note', file_get_contents($env));
        $this->assertSame(['A' => '1'], File::parseEnvFile($env));
    }

    // ---------------------------------------------------------------------
    // unzipFile
    // ---------------------------------------------------------------------

    /**
     * REGRESSION: every needle got a DIRECTORY_SEPARATOR appended, so "data/report.csv" became
     * "data\report.csv\" and could never match the entry "data\report.csv". Naming a file
     * extracted NOTHING and still returned TRUE — a silent success the caller could not detect.
     */
    public function testUnzipFileExtractsAnExactlyNamedFile(): void {
        $zip = $this->makeZip($this->path('a.zip'), [
            'data/report.csv' => 'id,name',
            'top.txt' => 'root level',
        ]);
        $dest = $this->path('out');

        $this->assertTrue(File::unzipFile($zip, $dest, 'data/report.csv'));

        $this->assertFileExists($dest . DIRECTORY_SEPARATOR . 'report.csv');
        $this->assertSame('id,name', file_get_contents($dest . DIRECTORY_SEPARATOR . 'report.csv'));
    }

    public function testUnzipFileExtractsATopLevelFileByName(): void {
        $zip = $this->makeZip($this->path('a.zip'), ['top.txt' => 'root level']);
        $dest = $this->path('out');

        $this->assertTrue(File::unzipFile($zip, $dest, 'top.txt'));

        $this->assertSame('root level', file_get_contents($dest . DIRECTORY_SEPARATOR . 'top.txt'));
    }

    public function testUnzipFileExtractsAListOfNamedFiles(): void {
        $zip = $this->makeZip($this->path('a.zip'), [
            'a.txt' => 'AAA',
            'b.txt' => 'BBB',
            'c.txt' => 'CCC',
        ]);
        $dest = $this->path('out');

        $this->assertTrue(File::unzipFile($zip, $dest, ['a.txt', 'b.txt']));

        $this->assertSame(['a.txt', 'b.txt'], $this->entriesIn($dest));
    }

    public function testUnzipFileStillExtractsAWholeDirectoryPrefixKeepingItsStructure(): void {
        $zip = $this->makeZip($this->path('a.zip'), [
            'data/report.csv' => 'id,name',
            'data/sub/deep.txt' => 'deep',
            'other/skip.txt' => 'skip',
        ]);
        $dest = $this->path('out');

        $this->assertTrue(File::unzipFile($zip, $dest, 'data'));

        $this->assertSame('id,name', file_get_contents($dest . DIRECTORY_SEPARATOR . 'report.csv'));
        $this->assertSame('deep', file_get_contents($dest . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'deep.txt'));
        $this->assertFileDoesNotExist($dest . DIRECTORY_SEPARATOR . 'skip.txt');
    }

    /**
     * REGRESSION: a needle matching zero entries left $errors empty, so the caller was told TRUE
     * and then opened a file that was never written.
     */
    public function testUnzipFileReportsARequestedNameThatMatchesNoEntry(): void {
        $zip = $this->makeZip($this->path('a.zip'), ['present.txt' => 'x']);
        $dest = $this->path('out');

        $result = File::unzipFile($zip, $dest, ['present.txt', 'absent.txt']);

        $this->assertIsArray($result, 'A name that matches nothing must not be reported as success.');
        $this->assertContains('absent.txt', $result);
        $this->assertFileExists($dest . DIRECTORY_SEPARATOR . 'present.txt');
    }

    public function testUnzipFileRefusesEntriesThatEscapeTheDestinationRoot(): void {
        $zip = $this->makeZip($this->path('a.zip'), [
            'data/../../evil.txt' => 'pwned',
            'data/ok.txt' => 'fine',
        ]);
        $dest = $this->path('out');
        $escapeTarget = $this->path('evil.txt');

        $result = File::unzipFile($zip, $dest, 'data');

        $this->assertIsArray($result, 'A refused Zip Slip entry must be reported, not silently ignored.');
        $this->assertFileDoesNotExist($escapeTarget, 'A "../" entry must never be written outside the destination.');
        $this->assertSame('fine', file_get_contents($dest . DIRECTORY_SEPARATOR . 'ok.txt'), 'Safe siblings must still extract.');
    }

    public function testUnzipFileReturnsTrueWhenNothingIsRequested(): void {
        $zip = $this->makeZip($this->path('a.zip'), ['x.txt' => 'x']);
        $this->assertTrue(File::unzipFile($zip, $this->path('out'), ''));
        $this->assertTrue(File::unzipFile($zip, $this->path('out'), []));
    }

    public function testUnzipFileReturnsFalseWhenTheArchiveDoesNotExist(): void {
        $this->assertFalse(File::unzipFile($this->path('missing.zip'), $this->path('out'), 'x.txt'));
    }

    public function testUnzipFileReturnsFalseWhenTheArchiveIsNotAValidZip(): void {
        $notAZip = $this->seedFile('broken.zip', 'this is not a zip archive');
        $this->assertFalse(File::unzipFile($notAZip, $this->path('out'), 'x.txt'));
    }

    // ---------------------------------------------------------------------
    // getDirectoryContents
    // ---------------------------------------------------------------------

    public function testGetDirectoryContentsListsFilesAndDirectoriesRecursively(): void {
        $root = $this->path('scan');
        $this->seedFile('scan' . DIRECTORY_SEPARATOR . 'a.txt');
        $this->seedFile('scan' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.txt');

        $found = File::getDirectoryContents($root);

        $this->assertCount(3, $found);
        $this->assertContains(realpath($root . DIRECTORY_SEPARATOR . 'a.txt'), $found);
        $this->assertContains(realpath($root . DIRECTORY_SEPARATOR . 'sub'), $found);
        $this->assertContains(realpath($root . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'b.txt'), $found);
    }

    public function testGetDirectoryContentsReturnsEmptyForAMissingDirectory(): void {
        $this->assertSame([], File::getDirectoryContents($this->path('nowhere')));
    }

    // ---------------------------------------------------------------------
    // zipDirectory
    // ---------------------------------------------------------------------

    public function testZipDirectoryCreatesAnArchiveIncludingTheRootFolder(): void {
        $src = $this->path('src');
        $this->seedFile('src' . DIRECTORY_SEPARATOR . 'a.txt', 'A');
        $out = $this->path('archive.zip');

        $this->assertTrue(File::zipDirectory($src, $out));

        $this->assertFileExists($out);
        $this->assertSame(['src/', 'src/a.txt'], $this->zipEntries($out));
    }

    /**
     * REGRESSION: entry names were built with DIRECTORY_SEPARATOR, so on Windows the archive
     * stored "src\a.txt". The ZIP format requires '/' (APPNOTE 4.4.17.1), so every archive this
     * library produced on Windows restored elsewhere as one flat file literally named "src\a.txt"
     * instead of a src/ directory. unzipFile() normalises separators when reading, which hid the
     * bug on a round-trip through this same library.
     */
    public function testZipDirectoryEntryNamesUseForwardSlashesOnEveryPlatform(): void {
        $this->seedFile('src' . DIRECTORY_SEPARATOR . 'sub' . DIRECTORY_SEPARATOR . 'deep.txt', 'D');
        $out = $this->path('archive.zip');

        $this->assertTrue(File::zipDirectory($this->path('src'), $out, null, true));

        foreach ($this->zipEntries($out) as $entry) {
            $this->assertStringNotContainsString('\\', $entry, "Entry '{$entry}' must not contain a backslash.");
        }
        $this->assertContains('sub/deep.txt', $this->zipEntries($out));
    }

    public function testZipDirectoryWithContentOnlyOmitsTheRootFolder(): void {
        $src = $this->path('src');
        $this->seedFile('src' . DIRECTORY_SEPARATOR . 'a.txt', 'A');
        $out = $this->path('archive.zip');

        $this->assertTrue(File::zipDirectory($src, $out, null, true));

        $this->assertSame(['a.txt'], $this->zipEntries($out));
    }

    /**
     * REGRESSION: the open()/addFile()/close() results were all discarded and the method ended in
     * an unconditional `return true`. Zipping an empty directory with $contentOnly reported
     * SUCCESS while writing no archive at all — and the classic caller idiom is
     * `if (zipDirectory($src, $backup)) { deleteFoldersRecursively($src); }`.
     */
    public function testZipDirectoryReturnsFalseWhenNoArchiveIsActuallyWritten(): void {
        $src = $this->path('emptysrc');
        mkdir($src);
        $out = $this->path('archive.zip');

        $this->assertFalse(File::zipDirectory($src, $out, null, true));
        $this->assertFileDoesNotExist($out, 'A FALSE must mean no archive was left behind.');
    }

    public function testZipDirectoryReturnsFalseForAMissingSource(): void {
        $this->assertFalse(File::zipDirectory($this->path('nope'), $this->path('o.zip')));
    }

    /**
     * REGRESSION: getZipName() exploded the FULL PATH on '.' and dropped everything after the last
     * dot, so a dot in a DIRECTORY name rewrote the destination whenever the leaf carried no
     * extension of its own: "<tmp>/v1.2/backup" became "<tmp>/v1.zip" — a DIFFERENT directory.
     * zipDirectory() then returned TRUE, so the caller believed the archive was where it asked for.
     *
     * The output leaf is seeded as an existing file so getPathInfo() reports it as the file part
     * (an extension-less name is otherwise treated as a directory, which never reaches getZipName).
     */
    public function testZipDirectoryHonoursAnOutputPathWhoseDirectoryContainsADot(): void {
        $src = $this->path('src');
        $this->seedFile('src' . DIRECTORY_SEPARATOR . 'a.txt', 'A');

        $this->seedFile('v1.2' . DIRECTORY_SEPARATOR . 'backup', 'placeholder');
        $out = $this->path('v1.2', 'backup');

        $this->assertTrue(File::zipDirectory($src, $out, null, true));

        $this->assertFileExists(
            $this->path('v1.2', 'backup.zip'),
            'The archive must be written inside the dotted directory the caller named.'
        );
        $this->assertFileDoesNotExist(
            $this->path('v1.zip'),
            'The dotted DIRECTORY name must not be truncated into a different destination.'
        );
        $this->assertSame(['a.txt'], $this->zipEntries($this->path('v1.2', 'backup.zip')));
    }

    /**
     * REGRESSION: with no dot ANYWHERE in the path, the explode() loop produced an empty base and
     * getZipName() returned a bare ".zip" — a RELATIVE path, so the archive was written as a hidden
     * file into the process's current working directory, far from the requested location, and
     * zipDirectory() still returned TRUE.
     */
    public function testZipDirectoryHonoursAnExtensionlessOutputPathWithNoDotAtAll(): void {
        $src = $this->path('src');
        $this->seedFile('src' . DIRECTORY_SEPARATOR . 'a.txt', 'A');

        $this->seedFile('plainout', 'placeholder');
        $out = $this->path('plainout');

        $strayInCwd = getcwd() . DIRECTORY_SEPARATOR . '.zip';
        $this->assertFileDoesNotExist($strayInCwd, 'Precondition: no stray .zip in the working directory.');

        $this->assertTrue(File::zipDirectory($src, $out, null, true));

        $this->assertFileExists(
            $this->path('plainout.zip'),
            'An extension-less output must simply gain ".zip" beside itself.'
        );
        $this->assertFileDoesNotExist($strayInCwd, 'The archive must never collapse into a bare ".zip" in the CWD.');
        $this->assertSame(['a.txt'], $this->zipEntries($this->path('plainout.zip')));
    }

    /**
     * The documented "backup.tar.gz -> backup.tar.zip" rule must survive the rewrite: only the
     * LAST extension of the leaf is replaced.
     */
    public function testZipDirectoryReplacesOnlyTheLastExtensionOfTheOutputName(): void {
        $src = $this->path('src');
        $this->seedFile('src' . DIRECTORY_SEPARATOR . 'a.txt', 'A');
        $out = $this->path('backup.tar.gz');

        $this->assertTrue(File::zipDirectory($src, $out, null, true));

        $this->assertFileExists($this->path('backup.tar.zip'));
    }

    public function testZipDirectoryReturnsFalseWhenTheSourceIsAFile(): void {
        $file = $this->seedFile('plain.txt');
        $this->assertFalse(File::zipDirectory($file, $this->path('o.zip')));
    }

    public function testZipDirectoryTrueImpliesTheArchiveIsOnDiskAndReadable(): void {
        $src = $this->path('src');
        $this->seedFile('src' . DIRECTORY_SEPARATOR . 'a.txt', 'A');
        $out = $this->path('archive.zip');

        $this->assertTrue(File::zipDirectory($src, $out, null, true));

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($out));
        $this->assertSame('A', $zip->getFromName('a.txt'));
        $zip->close();
    }

    // ---------------------------------------------------------------------
    // zipMultipleFiles
    // ---------------------------------------------------------------------

    public function testZipMultipleFilesArchivesFilesUnderTheirVirtualPaths(): void {
        $a = $this->seedFile('one.txt', 'ONE');
        $b = $this->seedFile('two.txt', 'TWO');
        $out = $this->path('multi.zip');

        $this->assertTrue(File::zipMultipleFiles([
            '.' => [$a],
            './folder1' => [$b],
        ], $out));

        $this->assertSame(['folder1/two.txt', 'one.txt'], $this->zipEntries($out));
    }

    public function testZipMultipleFilesArchivesADirectorysContents(): void {
        $this->seedFile('dir' . DIRECTORY_SEPARATOR . 'inner.txt', 'IN');
        $out = $this->path('multi.zip');

        $this->assertTrue(File::zipMultipleFiles(['./bundle' => [$this->path('dir')]], $out));

        $this->assertSame(['bundle/inner.txt'], $this->zipEntries($out));
    }

    /**
     * REGRESSION: the directory branch appended a separator to each path before stripping the
     * prefix, so every FILE entry ended in one ("bundle/inner.txt\"). A trailing separator marks a
     * DIRECTORY in a zip, so extractors restored each file as an empty folder and the content was
     * unreachable.
     */
    public function testZipMultipleFilesEntriesFromADirectoryAreFilesNotTrailingSeparatorFolders(): void {
        $this->seedFile('dir' . DIRECTORY_SEPARATOR . 'inner.txt', 'IN');
        $out = $this->path('multi.zip');

        $this->assertTrue(File::zipMultipleFiles(['./bundle' => [$this->path('dir')]], $out));

        foreach ($this->zipEntries($out) as $entry) {
            $this->assertStringEndsNotWith('/', $entry, "File entry '{$entry}' must not look like a directory.");
            $this->assertStringNotContainsString('\\', $entry);
        }

        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($out));
        $this->assertSame('IN', $zip->getFromName('bundle/inner.txt'), 'The entry must carry the file content.');
        $zip->close();
    }

    /**
     * REGRESSION: the documented '.' virtual path (the docblock's own primary example) resolves to
     * an empty folder, and the entry name was built unconditionally as "$localPath . '/' . $name",
     * yielding "/one.txt" — a leading-slash, i.e. absolute, entry name that the ZIP format forbids
     * and extractors reject or silently rewrite.
     */
    public function testZipMultipleFilesRootVirtualPathDoesNotProduceAbsoluteEntryNames(): void {
        $a = $this->seedFile('one.txt', 'ONE');
        $out = $this->path('multi.zip');

        $this->assertTrue(File::zipMultipleFiles(['.' => [$a]], $out));

        foreach ($this->zipEntries($out) as $entry) {
            $this->assertStringStartsNotWith('/', $entry, "Entry '{$entry}' must be relative to the archive root.");
        }
        $this->assertSame(['one.txt'], $this->zipEntries($out));
    }

    /**
     * REGRESSION: nonexistent sources are skipped, and the method then unconditionally returned
     * TRUE — so a caller whose paths were all stale got TRUE and went on to attach or serve an
     * archive that does not exist.
     */
    public function testZipMultipleFilesReturnsFalseWhenEverySourceIsStale(): void {
        $out = $this->path('multi.zip');

        $this->assertFalse(File::zipMultipleFiles(['.' => [$this->path('gone.txt')]], $out));
        $this->assertFileDoesNotExist($out, 'A FALSE must mean no archive was left behind.');
    }

    public function testZipMultipleFilesReturnsFalseForAnEmptyFileList(): void {
        $this->assertFalse(File::zipMultipleFiles([], $this->path('multi.zip')));
    }

    public function testZipMultipleFilesSkipsNonArrayEntries(): void {
        $a = $this->seedFile('one.txt', 'ONE');
        $out = $this->path('multi.zip');

        $this->assertTrue(File::zipMultipleFiles(['.' => [$a], './bad' => 'not-an-array'], $out));

        $this->assertSame(['one.txt'], $this->zipEntries($out));
    }

    // ---------------------------------------------------------------------
    // deleteFiles
    // ---------------------------------------------------------------------

    public function testDeleteFilesRemovesTheNamedFiles(): void {
        $this->seedFile('bin' . DIRECTORY_SEPARATOR . 'a.txt');
        $this->seedFile('bin' . DIRECTORY_SEPARATOR . 'b.txt');
        $this->seedFile('bin' . DIRECTORY_SEPARATOR . 'keep.txt');
        $dir = $this->path('bin');

        $this->assertTrue(File::deleteFiles(['a.txt', 'b.txt'], $dir));

        $this->assertSame(['keep.txt'], $this->entriesIn($dir));
    }

    public function testDeleteFilesReducesTraversingNamesToTheirLeafAndCannotEscapeTheDirectory(): void {
        $outside = $this->seedFile('outside.txt', 'must survive');
        $this->seedFile('bin' . DIRECTORY_SEPARATOR . 'a.txt');
        $dir = $this->path('bin');

        File::deleteFiles(['..' . DIRECTORY_SEPARATOR . 'outside.txt', $outside], $dir);

        $this->assertFileExists($outside, 'deleteFiles() must never delete outside the given directory.');
    }

    public function testDeleteFilesReturnsFalseForAMissingDirectory(): void {
        $this->assertFalse(File::deleteFiles(['a.txt'], $this->path('nowhere')));
    }

    public function testDeleteFilesReturnsFalseForAnEmptyFileList(): void {
        $this->assertFalse(File::deleteFiles([], $this->tmp));
    }

    // ---------------------------------------------------------------------
    // renameUploadFile
    // ---------------------------------------------------------------------

    public function testRenameUploadFileReturnsEmptyStringForAnEmptyName(): void {
        $this->assertSame('', File::renameUploadFile(''));
    }

    public function testRenameUploadFileKeepsTheExtensionAndAppendsAUniqueSuffix(): void {
        $name = File::renameUploadFile('report.JPEG');

        $this->assertMatchesRegularExpression('/^report_\d{14}\d{1,3}\.jpeg$/', $name);
    }

    /**
     * REGRESSION: only the BASE name went through the sanitizer; the extension was merely
     * lowercased, so everything after the last dot ("a.p hp!x") landed in the returned name
     * unfiltered — spaces, shell metacharacters and, for names not from $_FILES, separators.
     * The docblock promised "a clean format" for the whole name.
     */
    public function testRenameUploadFileSanitizesTheExtensionNotJustTheBaseName(): void {
        $name = File::renameUploadFile('a.p hp!x');

        $this->assertStringNotContainsString(' ', $name);
        $this->assertStringNotContainsString('!', $name);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9\-_.]+$/', $name);
        // The extension goes through the SAME sanitizer as the base name, so its space becomes '_'
        // just like anywhere else. It was '.phpx' while the space->'_' step was dead code.
        $this->assertStringEndsWith('.p_hpx', $name);
    }

    public function testRenameUploadFileStripsSeparatorsFromAnExtensionSuppliedOutsideOfFiles(): void {
        $name = File::renameUploadFile('doc.php/../../evil');

        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('\\', $name);
        $this->assertStringNotContainsString('..', $name);
    }

    /**
     * REGRESSION: the extension and its dot were appended AFTER truncation and never counted, so
     * the returned name exceeded $maxLength by 1 + strlen(extension) on EVERY call with an
     * extension — a documented 125 default returned 130 characters for a plain ".jpeg" upload.
     */
    public function testRenameUploadFileNeverExceedsMaxLength(): void {
        foreach ([30, 40, 60, 125] as $maxLength) {
            $name = File::renameUploadFile('averyveryverylongfilenameindeedthatkeepsgoing.jpeg', $maxLength);
            $this->assertLessThanOrEqual(
                $maxLength,
                strlen($name),
                "renameUploadFile() must honour maxLength {$maxLength}, got '{$name}'."
            );
        }
    }

    public function testRenameUploadFileHonoursTheDocumented125DefaultForATypicalUpload(): void {
        $name = File::renameUploadFile(str_repeat('a', 200) . '.jpeg');
        $this->assertLessThanOrEqual(125, strlen($name));
        $this->assertStringEndsWith('.jpeg', $name);
    }

    public function testRenameUploadFileRejectsAMaxLengthThatCannotFitTheSuffixAndExtension(): void {
        $this->expectException(\InvalidArgumentException::class);
        File::renameUploadFile('x.jpeg', 20);
    }

    public function testRenameUploadFileHandlesANameWithoutAnExtension(): void {
        $name = File::renameUploadFile('plainname');

        $this->assertMatchesRegularExpression('/^plainname_\d{14}\d{1,3}$/', $name);
        $this->assertStringNotContainsString('.', $name);
    }

    public function testRenameUploadFileKeepsOnlyTheLastSegmentAsTheExtension(): void {
        $name = File::renameUploadFile('backup.tar.gz');

        $this->assertStringEndsWith('.gz', $name);
        $this->assertStringStartsWith('backuptar', $name);
    }

    /**
     * An extension made entirely of characters the sanitizer strips must not leave a trailing dot:
     * Windows silently drops it, so the stored name would no longer match the file on disk.
     */
    public function testRenameUploadFileDropsAnExtensionThatSanitizesAwayEntirely(): void {
        $name = File::renameUploadFile('file.###');

        $this->assertStringEndsNotWith('.', $name);
        $this->assertMatchesRegularExpression('/^file_\d{14}\d{1,3}$/', $name);
    }

    /**
     * The sanitizer folds accents to ASCII and turns each space into '_'.
     *
     * REGRESSION: the str_replace(' ', '_') was dead code — the following [^A-Za-z0-9\-] filter
     * stripped the underscore it had just inserted, so 'relatório final.csv' welded into
     * 'relatoriofinal_<ts>.csv'. '_' is now in the allowlist, so the replacement survives. '_' is
     * not a path or shell metacharacter, so the sanitizer stays just as strict.
     */
    public function testRenameUploadFileFoldsAccentsAndTurnsSpacesIntoUnderscores(): void {
        $name = File::renameUploadFile('relatório final.csv');

        $this->assertStringEndsWith('.csv', $name);
        $this->assertMatchesRegularExpression('/^relatorio_final_\d{14}\d{1,3}\.csv$/', $name);
    }

    /**
     * The '_' allowlist entry must not let anything else through: the result is still confined to
     * [A-Za-z0-9_-] plus the single dot before the extension. A '_' already present in the original
     * name is preserved rather than stripped.
     */
    public function testRenameUploadFileKeepsUnderscoresWithoutWideningTheCharset(): void {
        $name = File::renameUploadFile('my_file name!@#$.csv');

        $this->assertMatchesRegularExpression('/^my_file_name_\d{14}\d{1,3}\.csv$/', $name);
        $this->assertStringNotContainsString('/', $name);
        $this->assertStringNotContainsString('\\', $name);
        $this->assertStringNotContainsString('!', $name);
    }

    public function testRenameUploadFileFallsBackTo125ForANonPositiveMaxLength(): void {
        $name = File::renameUploadFile(str_repeat('b', 200) . '.txt', 0);
        $this->assertLessThanOrEqual(125, strlen($name));
        $this->assertGreaterThan(100, strlen($name), 'The fallback budget must be the documented 125, not 0.');
    }

    public function testRenameUploadFileReturnsADifferentNameForRepeatedCalls(): void {
        $names = [];
        for ($i = 0; $i < 25; $i++) {
            $names[] = File::renameUploadFile('same.txt');
        }
        // rand(0,999) can collide; assert the suffix shape holds for every result instead of
        // asserting uniqueness, which would be flaky by design (documented as best-effort).
        foreach ($names as $name) {
            $this->assertMatchesRegularExpression('/^same_\d{14}\d{1,3}\.txt$/', $name);
        }
    }

    // ---------------------------------------------------------------------
    // uploadFileTo
    // ---------------------------------------------------------------------

    public function testUploadFileToReturnsSevenForAnEmptyUpload(): void {
        $this->assertSame(
            ['type' => 7, 'file' => '', 'path' => ''],
            File::uploadFileTo(null, $this->tmp)
        );
        $this->assertSame(
            ['type' => 7, 'file' => '', 'path' => ''],
            File::uploadFileTo([], $this->tmp)
        );
    }

    public function testUploadFileToReturnsSixWhenTheTargetIsNotADirectory(): void {
        $blocker = $this->seedFile('blocker.txt');
        $result = File::uploadFileTo(
            ['tmp_name' => $blocker, 'name' => 'a.txt', 'error' => 0],
            $blocker
        );

        $this->assertSame(6, $result['type']);
        $this->assertSame('', $result['file']);
        $this->assertSame('', $result['path']);
    }

    public function testUploadFileToReturnsSixForAnEmptyTargetDirectory(): void {
        $file = $this->seedFile('src.txt');
        $result = File::uploadFileTo(['tmp_name' => $file, 'name' => 'a.txt', 'error' => 0], '');

        $this->assertSame(6, $result['type']);
    }

    /**
     * In a non-HTTP context move_uploaded_file() always fails, which is the documented type 5 —
     * and proves the method never moves a file that is not a genuine upload.
     */
    public function testUploadFileToReturnsFiveWhenTheSourceIsNotAGenuineUpload(): void {
        $file = $this->seedFile('src.txt', 'payload');
        $dest = $this->path('uploads');

        $result = File::uploadFileTo(['tmp_name' => $file, 'name' => 'a.txt', 'error' => 0], $dest);

        $this->assertSame(5, $result['type']);
        $this->assertSame('', $result['file'], 'file/path must be blank on any non-zero type.');
        $this->assertSame('', $result['path']);
        $this->assertSame([], $this->entriesIn($dest), 'Nothing may be written for a forged upload.');
    }

    /**
     * @return array<string, array{int}>
     */
    public static function phpUploadErrorProvider(): array {
        return [
            'exceeds php size limit' => [1],
            'exceeds form size limit' => [2],
            'partial upload' => [3],
            'no file uploaded' => [4],
        ];
    }

    #[DataProvider('phpUploadErrorProvider')]
    public function testUploadFileToPassesThroughTheDocumentedPhpUploadErrorCodes(int $code): void {
        $dest = $this->path('uploads');
        $result = File::uploadFileTo(
            ['tmp_name' => '', 'name' => 'a.txt', 'error' => $code],
            $dest
        );

        $this->assertSame($code, $result['type']);
        $this->assertSame('', $result['file']);
    }

    public function testUploadFileToMapsAnUnknownErrorCodeToSeven(): void {
        $dest = $this->path('uploads');
        $result = File::uploadFileTo(
            ['tmp_name' => '', 'name' => 'a.txt', 'error' => 99],
            $dest
        );

        $this->assertSame(7, $result['type']);
    }

    public function testUploadFileToCreatesTheDestinationDirectoryWhenMissing(): void {
        $file = $this->seedFile('src.txt');
        $dest = $this->path('made', 'uploads');

        File::uploadFileTo(['tmp_name' => $file, 'name' => 'a.txt', 'error' => 0], $dest);

        $this->assertDirectoryExists($dest, 'The destination directory must be created when absent.');
    }

    // ---------------------------------------------------------------------
    // downloadFile
    //
    // The streaming path cannot run under PHPUnit: it echoes the body (phpunit.xml sets
    // beStrictAboutOutputDuringTests) and exit(0)s the runner by default. Only the guard
    // branches, which are reachable and produce no output, are exercised here.
    // ---------------------------------------------------------------------

    public function testDownloadFileWithAnEmptyNameReturnsImmediatelyAndDeletesNothing(): void {
        $file = $this->seedFile('keep.txt', 'still here');

        $this->expectOutputString('');
        File::downloadFile($file, null, true, false);

        $this->assertFileExists($file, 'An empty download name must abort before any deletion.');
        $this->assertSame('still here', file_get_contents($file));
    }

    public function testDownloadFileWithAnEmptyNameDoesNotTerminateEvenWhenAskedTo(): void {
        $file = $this->seedFile('keep.txt');

        $this->expectOutputString('');
        // Documented quirk: the empty-name guard returns before $terminateAfterDownload is read,
        // so this must NOT exit() the process. Reaching the assertion below is the proof.
        File::downloadFile($file, '', false, true);

        $this->assertTrue(true, 'downloadFile() returned instead of terminating the process.');
    }

    /**
     * REGRESSION: a missing file used to exit(0) when $terminateAfterDownload was true (the
     * DEFAULT), handing the client an empty HTTP 200 that looks exactly like a successful
     * download, while the docblock advertised '@throws \Exception If the file cannot be opened'.
     * The caller's try/catch never fired. It must now fail loudly.
     *
     * The default $terminateAfterDownload=true is used deliberately: under the old code this test
     * would exit(0) and kill the PHPUnit runner rather than fail.
     */
    public function testDownloadFileThrowsForAMissingFileInsteadOfExitingWithAnEmptySuccess(): void {
        $this->expectOutputString('');
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        File::downloadFile($this->path('missing.bin'), 'x.bin');
    }

    public function testDownloadFileThrowsForAnUnresolvableFileEvenWhenNotTerminating(): void {
        $this->expectOutputString('');
        $this->expectException(\Exception::class);

        File::downloadFile($this->path('missing.bin'), 'x.bin', false, false);
    }

    /**
     * A missing file must fail BEFORE any header is sent, so the caller can still turn the
     * exception into a real error response instead of a half-written 200.
     */
    public function testDownloadFileDoesNotEmitOutputWhenTheFileIsMissing(): void {
        $this->expectOutputString('');

        try {
            File::downloadFile($this->path('missing.bin'), 'x.bin', false, false);
            $this->fail('downloadFile() must throw for a missing file.');
        } catch (\Exception $e) {
            $this->assertStringContainsString('does not exist or is not readable', $e->getMessage());
        }
    }
}
