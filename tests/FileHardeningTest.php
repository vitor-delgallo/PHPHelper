<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresOperatingSystemFamily;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\File;

/**
 * Regression tests for the File findings of the fifth review pass: link/junction handling in the
 * recursive helpers, blank paths resolving to the working directory, zip/unzip integrity and
 * containment, .env round-trips, download headers and upload handling.
 *
 * Every link these tests create points INSIDE the test's own scratch directory, and the cleanup
 * removes links as links (never walking into them), so nothing outside that directory can be
 * reached even if the code under test regresses.
 */
final class FileHardeningTest extends TestCase {
    private string $tmp;

    private string $originalCwd;

    protected function setUp(): void {
        $this->originalCwd = getcwd();
        $base = realpath(sys_get_temp_dir());
        $this->tmp = $base . DIRECTORY_SEPARATOR . 'phphelper_filehard_' . bin2hex(random_bytes(8));
        mkdir($this->tmp, 0777, true);
    }

    protected function tearDown(): void {
        chdir($this->originalCwd);
        File::setDownloadBlockSize(null);
        File::setDefaultMode('755');

        $this->removeTree($this->tmp);
    }

    /**
     * Link-safe recursive removal that does NOT depend on the code under test: a link (or a
     * Windows junction, which PHP reports as neither link, dir nor file) is removed as a link and
     * never descended into.
     */
    private function removeTree(string $path): void {
        clearstatcache(true, $path);
        if ($this->isLink($path)) {
            @unlink($path) || @rmdir($path);
            return;
        }
        if (!file_exists($path)) {
            return;
        }
        if (!is_dir($path)) {
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
        @chmod($path, 0777);
        @rmdir($path);
    }

    private function isLink(string $path): bool {
        if (is_link($path)) {
            return true;
        }
        if (PHP_OS_FAMILY !== 'Windows') {
            return false;
        }
        if (!is_dir($path) && !is_file($path)) {
            return file_exists($path) || @readlink($path) !== false;
        }
        $real = realpath($path);
        $parent = realpath(dirname($path));
        return $real !== false && $parent !== false
            && strcasecmp($real, rtrim($parent, '\\/') . '\\' . basename($path)) !== 0;
    }

    private function path(string ...$segments): string {
        return $this->tmp . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $segments);
    }

    private function seedFile(string $relative, string $content = 'seed'): string {
        $full = $this->path(...explode('/', $relative));
        if (!is_dir(dirname($full))) {
            mkdir(dirname($full), 0777, true);
        }
        file_put_contents($full, $content);
        return $full;
    }

    private function seedDir(string $relative): string {
        $full = $this->path(...explode('/', $relative));
        if (!is_dir($full)) {
            mkdir($full, 0777, true);
        }
        return $full;
    }

    private function entriesIn(string $dir): array {
        $entries = array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
        sort($entries);
        return $entries;
    }

    /**
     * Creates a directory link: a junction on Windows (no privilege needed, unlike symlink(), and
     * the case PHP handles worst — is_link() is FALSE for it), a symlink elsewhere.
     */
    private function makeDirectoryLink(string $target, string $link): void {
        $this->assertStringStartsWith($this->tmp, $target, 'Test links must never point outside the scratch directory.');

        if (PHP_OS_FAMILY === 'Windows') {
            exec('cmd /c mklink /J ' . escapeshellarg($link) . ' ' . escapeshellarg($target) . ' >NUL 2>NUL', $output, $code);
            if ($code !== 0 || @readlink($link) === false) {
                $this->markTestSkipped('Could not create a directory junction on this host.');
            }
            return;
        }

        if (!@symlink($target, $link)) {
            $this->markTestSkipped('symlink() is not permitted on this host.');
        }
    }

    private function makeFileSymlink(string $target, string $link): void {
        $this->assertStringStartsWith($this->tmp, $target);
        if (!@symlink($target, $link)) {
            $this->markTestSkipped('Creating a FILE symlink needs a privilege this host does not grant (Windows without Developer Mode).');
        }
    }

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

    private static function invokePrivate(string $method, mixed ...$args): mixed {
        return (new \ReflectionMethod(File::class, $method))->invoke(null, ...$args);
    }

    // ---------------------------------------------------------------------
    // Blank paths must never mean "the current working directory"
    // ---------------------------------------------------------------------

    public static function blankPathProvider(): array {
        return [
            'spaces' => ['   '],
            'tab' => ["\t"],
            'newline' => ["\n"],
            'mixed whitespace' => [" \t\r\n "],
        ];
    }

    /**
     * CRITICAL REGRESSION: trim() turned a whitespace-only path into "", and realpath("") is the
     * CURRENT WORKING DIRECTORY — so getPathInfo('   ') described the cwd as an existing directory
     * and deleteFoldersRecursively('   ') deleted it, recursively, and returned TRUE. A blank value
     * from a config or form field wiped the application directory.
     *
     * The working directory is moved into a sandbox inside the scratch dir, so a regression can
     * only destroy the sandbox.
     */
    #[DataProvider('blankPathProvider')]
    public function testDeleteFoldersRecursivelyNeverDeletesTheWorkingDirectoryForABlankPath(string $blank): void {
        $sandbox = $this->seedDir('sandbox');
        $this->seedFile('sandbox/precious.txt', 'keep');
        chdir($sandbox);

        $this->assertFalse(File::deleteFoldersRecursively($blank));

        chdir($this->originalCwd);
        $this->assertFileExists($sandbox . DIRECTORY_SEPARATOR . 'precious.txt', 'A blank path must not resolve to the working directory.');
    }

    #[DataProvider('blankPathProvider')]
    public function testGetPathInfoReturnsTheEmptyResultForABlankPath(string $blank): void {
        chdir($this->seedDir('sandbox'));

        $info = File::getPathInfo($blank, createPath: true);

        $this->assertNull($info['path']);
        $this->assertNull($info['dir']);
        $this->assertFalse($info['isDir']);
    }

    #[DataProvider('blankPathProvider')]
    public function testResetFolderRefusesABlankPathInsteadOfEmptyingTheWorkingDirectory(string $blank): void {
        $sandbox = $this->seedDir('sandbox');
        $this->seedFile('sandbox/precious.txt', 'keep');
        chdir($sandbox);

        $this->assertFalse(File::resetFolder($blank));

        chdir($this->originalCwd);
        $this->assertSame(['precious.txt'], $this->entriesIn($sandbox));
    }

    #[DataProvider('blankPathProvider')]
    public function testGetDirectoryContentsListsNothingForABlankPath(string $blank): void {
        $sandbox = $this->seedDir('sandbox');
        $this->seedFile('sandbox/precious.txt');
        chdir($sandbox);

        $this->assertSame([], File::getDirectoryContents($blank));
        // realpath('') is the working directory: the empty string used to list all of it.
        $this->assertSame([], File::getDirectoryContents(''));
    }

    #[DataProvider('blankPathProvider')]
    public function testDeleteFilesRefusesABlankDirectory(string $blank): void {
        $sandbox = $this->seedDir('sandbox');
        $this->seedFile('sandbox/a.txt');
        chdir($sandbox);

        $this->assertFalse(File::deleteFiles(['a.txt'], $blank));
        $this->assertFileExists($sandbox . DIRECTORY_SEPARATOR . 'a.txt');
    }

    #[DataProvider('blankPathProvider')]
    public function testZipDirectoryRefusesABlankSourceAndNeverZipsTheWorkingDirectory(string $blank): void {
        $sandbox = $this->seedDir('sandbox');
        $this->seedFile('sandbox/a.txt');
        chdir($sandbox);

        $this->assertFalse(File::zipDirectory($blank, $this->path('out.zip')));
        // realpath('') is the working directory: the empty string used to archive all of it.
        $this->assertFalse(File::zipDirectory('', $this->path('out.zip')));
        $this->assertFileDoesNotExist($this->path('out.zip'));
    }

    /**
     * "0" is falsy for empty(), which is what the old guards used: a file or directory literally
     * named "0" could not be addressed at all.
     */
    public function testAPathNamedZeroIsAnOrdinaryRelativePath(): void {
        $sandbox = $this->seedDir('sandbox');
        chdir($sandbox);

        File::writeFile('0', 'zero');
        $this->assertSame('zero', file_get_contents($sandbox . DIRECTORY_SEPARATOR . '0'));

        $info = File::getPathInfo('0');
        $this->assertTrue($info['isFile']);
        $this->assertSame(realpath($sandbox) . DIRECTORY_SEPARATOR . '0', $info['path']);

        $other = $this->seedDir('sandbox2');
        chdir($other);
        $this->assertSame(2, File::createDir('0'));
        $this->assertDirectoryExists($other . DIRECTORY_SEPARATOR . '0');
        $this->assertTrue(File::deleteFoldersRecursively('0'));
        $this->assertDirectoryDoesNotExist($other . DIRECTORY_SEPARATOR . '0');
    }

    /**
     * REGRESSION (wrong-file): getPathInfo() trim()med the path, so " dir" — a legal name on every
     * platform — resolved to "dir", and deleteFoldersRecursively(' dir') deleted the OTHER
     * directory. Paths are now used verbatim.
     */
    public function testALeadingSpaceIsPartOfTheNameNotTrimmedAway(): void {
        $sandbox = $this->seedDir('sandbox');
        $this->seedFile('sandbox/dir/keep.txt', 'the other directory');
        $this->seedFile('sandbox/ dir/doomed.txt', 'the named directory');
        $this->seedFile('sandbox/ lead.txt', 'spaced');
        $this->seedFile('sandbox/lead.txt', 'plain');
        chdir($sandbox);

        $info = File::getPathInfo(' lead.txt');
        $this->assertSame(' lead.txt', $info['file']);
        $this->assertTrue($info['isFile']);

        $this->assertTrue(File::deleteFoldersRecursively(' dir'));

        $this->assertDirectoryDoesNotExist($sandbox . DIRECTORY_SEPARATOR . ' dir');
        $this->assertFileExists($sandbox . DIRECTORY_SEPARATOR . 'dir' . DIRECTORY_SEPARATOR . 'keep.txt', 'A different directory must never be deleted.');
    }

    /**
     * The POSIX half: "x.dbf " (trailing space) is a real, distinct file there, and used to resolve
     * to "x.dbf". Win32 strips trailing spaces from every path, so the two cannot coexist on Windows.
     */
    public function testATrailingSpaceIsPartOfTheNameOnPosix(): void {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Win32 strips trailing spaces from path segments; "x.dbf " cannot exist beside "x.dbf" here.');
        }
        $this->seedFile('x.dbf', 'plain');
        $this->seedFile('x.dbf ', 'spaced');

        $info = File::getPathInfo($this->path('x.dbf '), keepFile: true);

        $this->assertSame('x.dbf ', $info['file']);
        $this->assertSame('spaced', file_get_contents($info['path']));
    }

    /**
     * realpath()/mkdir() throw a ValueError on a NUL byte. Every method below documents a return
     * code or FALSE as its error channel; none may let the ValueError escape.
     */
    public function testANulBytePathIsReportedThroughTheDocumentedChannelNotAValueError(): void {
        $bad = $this->path("evil\0.txt");

        $this->assertNull(File::getPathInfo($bad)['path']);
        $this->assertSame(-2, File::createDir($bad));
        $this->assertFalse(File::deleteFoldersRecursively($bad));
        $this->assertFalse(File::resetFolder($bad));
        $this->assertSame([], File::getDirectoryContents($bad));
        $this->assertSame([], File::parseEnvFile($bad));
        $this->assertFalse(File::unzipFile($bad, $this->path('out'), 'x'));
        $this->assertFalse(File::deleteFiles(["a\0b"], $this->tmp));
    }

    /**
     * The umask is process-wide. createDir() sets it to 0 around mkdir(); a ValueError thrown by
     * mkdir() used to skip the restore, leaving every file the process created afterwards
     * world-writable.
     */
    public function testCreateDirRestoresTheUmaskWhenMkdirWouldThrow(): void {
        $previous = umask(0o022);
        try {
            if (umask() !== 0o022) {
                $this->markTestSkipped('umask() is not honoured on this platform (Windows ZTS keeps it at 0).');
            }

            File::createDir($this->path("a\0b"));

            $this->assertSame(0o022, umask(), 'createDir() must leave the process umask as it found it.');
        } finally {
            umask($previous);
        }
    }

    // ---------------------------------------------------------------------
    // Links and junctions
    // ---------------------------------------------------------------------

    /**
     * CRITICAL REGRESSION: getPathInfo() resolved the link with realpath() first, so deleting a
     * LINK wiped the directory it points to — contents and all — and reported TRUE.
     */
    public function testDeleteFoldersRecursivelyOnALinkRemovesOnlyTheLink(): void {
        $target = $this->seedDir('target');
        $this->seedFile('target/keep.txt', 'precious');
        $link = $this->path('link');
        $this->makeDirectoryLink($target, $link);

        $this->assertTrue(File::deleteFoldersRecursively($link));

        $this->assertFileExists($target . DIRECTORY_SEPARATOR . 'keep.txt', 'The link target must survive.');
        clearstatcache();
        $this->assertFalse($this->isLink($link) || file_exists($link), 'The link itself must be gone.');
    }

    /**
     * A link INSIDE the tree is removed as a link and never walked. The old iterator called
     * rmdir()/unlink() on getRealPath() — the link's TARGET — so on Windows the junction made the
     * whole delete fail, and on POSIX an empty target directory was deleted outright.
     */
    public function testDeleteFoldersRecursivelyRemovesAnInnerLinkWithoutTouchingItsTarget(): void {
        $target = $this->seedDir('target');
        $this->seedFile('target/keep.txt', 'precious');
        $emptyTarget = $this->seedDir('emptytarget');
        $tree = $this->seedDir('tree/sub');
        $this->seedFile('tree/own.txt');
        $this->makeDirectoryLink($target, $tree . DIRECTORY_SEPARATOR . 'link');
        $this->makeDirectoryLink($emptyTarget, $this->path('tree', 'emptylink'));

        $this->assertTrue(File::deleteFoldersRecursively($this->path('tree')));

        $this->assertDirectoryDoesNotExist($this->path('tree'));
        $this->assertSame('precious', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
        $this->assertDirectoryExists($emptyTarget, 'An EMPTY link target must not be rmdir()ed through the link.');
    }

    /**
     * The POSIX half of the finding above, and the worst of it: a symlink to a FILE was deleted
     * with unlink(getRealPath()), i.e. the TARGET file was deleted, wherever it lived.
     */
    public function testDeleteFoldersRecursivelyNeverDeletesTheTargetOfAFileSymlink(): void {
        $target = $this->seedFile('outside.txt', 'precious');
        $this->seedDir('tree');
        $this->makeFileSymlink($target, $this->path('tree', 'link.txt'));

        $this->assertTrue(File::deleteFoldersRecursively($this->path('tree')));

        $this->assertSame('precious', file_get_contents($target));
    }

    /**
     * getDirectoryContents() realpath()ed every entry, so a link was reported as its TARGET — a
     * path outside the scanned directory — and walked into.
     */
    public function testGetDirectoryContentsListsALinkAtItsOwnPathAndDoesNotDescendIntoIt(): void {
        $this->seedFile('target/secret.txt');
        $this->seedFile('scan/a.txt');
        $this->makeDirectoryLink($this->path('target'), $this->path('scan', 'link'));

        $found = File::getDirectoryContents($this->path('scan'));
        sort($found);

        $this->assertSame([$this->path('scan', 'a.txt'), $this->path('scan', 'link')], $found);
    }

    /**
     * A link to an ANCESTOR used to recurse until the stack or memory ran out.
     */
    public function testGetDirectoryContentsTerminatesOnALinkToAnAncestor(): void {
        $this->seedFile('scan/deep/a.txt');
        $this->makeDirectoryLink($this->path('scan'), $this->path('scan', 'deep', 'loop'));

        $found = File::getDirectoryContents($this->path('scan'));

        $this->assertCount(3, $found);
    }

    /**
     * HIGH REGRESSION (information disclosure): a link inside the zipped directory pulled the files
     * it points to — "secret/key.pem" from outside the source — into the archive.
     */
    public function testZipDirectoryDoesNotFollowALinkOutOfTheSourceDirectory(): void {
        $this->seedFile('secret/key.pem', 'SECRET');
        $this->seedFile('src/a.txt', 'A');
        $this->makeDirectoryLink($this->path('secret'), $this->path('src', 'lnk'));
        $out = $this->path('out.zip');

        $this->assertTrue(File::zipDirectory($this->path('src'), $out));

        $this->assertSame(['src/', 'src/a.txt'], $this->zipEntries($out));
    }

    public function testZipMultipleFilesDoesNotFollowALinkInsideAListedDirectory(): void {
        $this->seedFile('secret/key.pem', 'SECRET');
        $this->seedFile('dir/a.txt', 'A');
        $this->makeDirectoryLink($this->path('secret'), $this->path('dir', 'lnk'));
        $out = $this->path('multi.zip');

        $this->assertTrue(File::zipMultipleFiles(['bundle' => [$this->path('dir')]], $out));

        $this->assertSame(['bundle/a.txt'], $this->zipEntries($out));
    }

    /**
     * resetFolder() on a link used to delete the TARGET (through deleteFoldersRecursively()) and
     * then fail to recreate the link path. Emptying through a link is refused outright.
     */
    public function testResetFolderRefusesALinkAndLeavesItsTargetIntact(): void {
        $target = $this->seedDir('target');
        $this->seedFile('target/keep.txt', 'precious');
        $link = $this->path('link');
        $this->makeDirectoryLink($target, $link);

        $this->assertFalse(File::resetFolder($link));

        $this->assertSame('precious', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
    }

    public function testResetFolderRemovesAnInnerLinkWithoutEmptyingItsTarget(): void {
        $target = $this->seedDir('target');
        $this->seedFile('target/keep.txt', 'precious');
        $this->seedFile('reset/old.txt');
        $this->makeDirectoryLink($target, $this->path('reset', 'link'));

        $this->assertTrue(File::resetFolder($this->path('reset')));

        $this->assertSame([], $this->entriesIn($this->path('reset')));
        $this->assertSame('precious', file_get_contents($target . DIRECTORY_SEPARATOR . 'keep.txt'));
    }

    /**
     * The folder is emptied IN PLACE. It used to be deleted and re-created with the DEFAULT mode,
     * silently widening a 0700 folder to 0755 (and changing its owner/ACL). The inode proves it
     * is the same directory on every platform, including Windows where the mode is not observable.
     */
    public function testResetFolderKeepsTheSameDirectoryInsteadOfRecreatingIt(): void {
        $dir = $this->seedDir('reset');
        $this->seedFile('reset/old.txt');
        $this->seedFile('reset/sub/deep.txt');
        clearstatcache();
        $inode = fileinode($dir);

        $this->assertTrue(File::resetFolder($dir));

        clearstatcache();
        $this->assertSame($inode, fileinode($dir), 'The folder itself must be kept, not re-created.');
        $this->assertSame([], $this->entriesIn($dir));
    }

    public function testResetFolderKeepsARestrictiveModeOnPosix(): void {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('Directory permission bits are not observable on Windows; the inode test above covers the behaviour.');
        }
        $dir = $this->seedDir('private');
        $this->seedFile('private/x.txt');
        chmod($dir, 0700);

        $this->assertTrue(File::resetFolder($dir));

        clearstatcache();
        $this->assertSame(0700, fileperms($dir) & 0777);
    }

    // ---------------------------------------------------------------------
    // Filesystem roots (never exercised destructively)
    // ---------------------------------------------------------------------

    public static function rootProvider(): array {
        return [
            'posix root' => ['/', true],
            'drive root' => ['C:\\', true],
            'bare drive' => ['D:', true],
            'unc share root' => ['\\\\server\\share\\', PHP_OS_FAMILY === 'Windows'],
            'ordinary dir' => ['/tmp/x', false],
            'drive dir' => ['C:\\data', false],
            'unc dir' => ['\\\\server\\share\\dir', false],
        ];
    }

    /**
     * deleteFoldersRecursively() and resetFolder() refuse a filesystem root. Checked through the
     * private predicate: calling the public methods on a real root would wipe the machine if the
     * guard ever regressed.
     */
    #[DataProvider('rootProvider')]
    public function testFilesystemRootsAreRecognised(string $path, bool $expected): void {
        $this->assertSame($expected, self::invokePrivate('isFilesystemRoot', $path));
    }

    // ---------------------------------------------------------------------
    // UNC paths
    // ---------------------------------------------------------------------

    /**
     * An unresolvable UNC path was normalised to "\server\share\...", which Windows resolves on
     * the CURRENT DRIVE — getPathInfo(createPath: true) then created the tree on C:\.
     */
    #[RequiresOperatingSystemFamily('Windows')]
    public function testNormalizeResolvedPathKeepsTheUncRoot(): void {
        $this->assertSame('\\\\server\\share\\b\\c', self::invokePrivate('normalizeResolvedPath', '\\\\server\\share\\a\\..\\b\\.\\c'));
        $this->assertSame('\\\\server\\share\\x', self::invokePrivate('normalizeResolvedPath', '\\\\server\\share\\..\\..\\x'));
        $this->assertSame('\\\\server\\share\\', self::invokePrivate('normalizeResolvedPath', '//server/share'));
    }

    // ---------------------------------------------------------------------
    // Permission modes
    // ---------------------------------------------------------------------

    public static function unusableModeProvider(): array {
        return [
            'php 8.1 octal literal' => ['0o700'],
            'trailing space' => ['0700 '],
            'above 07777' => ['17777'],
            'huge but fits an int' => ['7777777'],
            'overflowing' => [str_repeat('7', 22)],
            'symbolic' => ['u=rwx'],
        ];
    }

    /**
     * An explicit mode that cannot be honoured used to fall back to the (0755) DEFAULT — so
     * createDir($secrets, '0o700') produced a world-readable directory. It is now refused.
     * '7777777' also used to be accepted: the OS masks it to 07777, world-writable plus
     * setuid/setgid.
     */
    #[DataProvider('unusableModeProvider')]
    public function testCreateDirRejectsAnExplicitModeItCannotHonour(string $mode): void {
        $dir = $this->path('secrets');

        $this->assertSame(-3, File::createDir($dir, $mode));
        $this->assertDirectoryDoesNotExist($dir);
    }

    #[DataProvider('unusableModeProvider')]
    public function testWriteFileRejectsAnExplicitModeItCannotHonour(string $mode): void {
        $this->expectException(\InvalidArgumentException::class);
        File::writeFile($this->path('f.txt'), 'x', false, $mode);
    }

    public function testModesAboveTheSpecialBitsAreRejectedButEveryRealModeIsAccepted(): void {
        $this->assertSame(0o7777, File::getPermissionMode('7777'));
        $this->assertSame(0o4755, File::getPermissionMode('04755'));
        $this->assertSame(File::getDefaultMode(), File::getPermissionMode('10000'));
    }

    /**
     * 0 doubled as the "not resolved yet" sentinel, so setDefaultMode('0') made the next
     * getDefaultMode() reset to 0755 instead of keeping the previous default.
     */
    public function testSetDefaultModeZeroKeepsThePreviousDefault(): void {
        File::setDefaultMode('700');
        File::setDefaultMode('0');

        $this->assertSame(0o700, File::getDefaultMode());
    }

    public function testGetPathInfoCreatesTheDirectoryWithAnOctalCreatePathMode(): void {
        $dir = $this->path('made', 'private');

        $info = File::getPathInfo($dir, createPath: '0700');

        $this->assertTrue($info['isDir']);
        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache();
            $this->assertSame(0700, fileperms($dir) & 0777);
        }
    }

    public function testUnzipFileCreatesSubdirectoriesWithTheGivenMode(): void {
        $zip = $this->makeZip($this->path('m.zip'), ['d/sub/x.txt' => 'X']);

        $this->assertTrue(File::unzipFile($zip, $this->path('dest'), 'd', '0750'));

        $this->assertSame('X', file_get_contents($this->path('dest', 'sub', 'x.txt')));
        if (PHP_OS_FAMILY !== 'Windows') {
            clearstatcache();
            $this->assertSame(0750, fileperms($this->path('dest', 'sub')) & 0777);
            $this->assertSame(0750, fileperms($this->path('dest')) & 0777);
        }
    }

    public function testCreateDirReportsAnExistingDirectoryAfterAFailedMkdirRace(): void {
        // The race itself cannot be forced; this pins the other half of the contract: an
        // existing directory is 1, not -2, whatever the recursive flag.
        $dir = $this->seedDir('exists');
        $this->assertSame(1, File::createDir($dir, null, false));
    }

    public function testWriteFileRestoresTheUmaskAfterApplyingAMode(): void {
        $previous = umask(0o022);
        try {
            if (umask() !== 0o022) {
                $this->markTestSkipped('umask() is not honoured on this platform (Windows ZTS keeps it at 0).');
            }

            File::writeFile($this->path('secret.txt'), 's', false, '0600');

            $this->assertSame(0o022, umask());
            clearstatcache();
            $this->assertSame(0600, fileperms($this->path('secret.txt')) & 0777);
        } finally {
            umask($previous);
        }
    }

    /**
     * A rejected mode is checked BEFORE tempnam() creates anything; it used to leave an orphaned
     * empty file in the system temp directory on every such call.
     */
    public function testCreateTempFileWithAnInvalidModeLeavesNoFileBehind(): void {
        // tempnam() keeps only 3 prefix characters on Windows; a random one keeps other processes'
        // files out of the glob.
        $prefix = 'q' . substr(bin2hex(random_bytes(2)), 0, 2);
        $pattern = realpath(sys_get_temp_dir()) . DIRECTORY_SEPARATOR . $prefix . '*';
        $before = glob($pattern) ?: [];

        try {
            File::createTempFile($prefix, 'x', 'not-a-mode');
            $this->fail('An invalid mode must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame($before, glob($pattern) ?: [], 'No temporary file may be left behind.');
    }

    // ---------------------------------------------------------------------
    // standardizeFilesCaseRecursive
    // ---------------------------------------------------------------------

    /**
     * DATA LOSS REGRESSION: rename() REPLACES an existing destination. "\u{212A}.txt" (KELVIN SIGN)
     * lower-cases to "k.txt", and NTFS — like any case-sensitive filesystem — keeps the two apart,
     * so lower-casing the directory silently overwrote k.txt and returned TRUE. The same happens
     * for "A.txt" + "a.txt" on Linux.
     */
    public function testStandardizeFilesCaseRecursiveRefusesToRenameOntoAnotherExistingFile(): void {
        $dir = $this->seedDir('case');
        $this->seedFile('case/' . "\u{212A}.txt", 'KELVIN');
        $this->seedFile('case/k.txt', 'plain k');

        $this->assertFalse(File::standardizeFilesCaseRecursive($dir), 'A refused collision must be reported.');

        $this->assertSame('plain k', file_get_contents($dir . DIRECTORY_SEPARATOR . 'k.txt'));
        $this->assertSame('KELVIN', file_get_contents($dir . DIRECTORY_SEPARATOR . "\u{212A}.txt"));
    }

    public function testStandardizeFilesCaseRecursiveStillRenamesTheRestAfterACollision(): void {
        $dir = $this->seedDir('case');
        $this->seedFile('case/' . "\u{212A}.txt", 'KELVIN');
        $this->seedFile('case/k.txt', 'plain k');
        $this->seedFile('case/OTHER.TXT', 'other');

        File::standardizeFilesCaseRecursive($dir);

        $this->assertContains('other.txt', $this->entriesIn($dir));
    }

    public function testStandardizeFilesCaseRecursiveRenamesALinkButDoesNotWalkIntoIt(): void {
        $this->seedFile('target/INNER.TXT');
        $dir = $this->seedDir('case');
        $this->makeDirectoryLink($this->path('target'), $this->path('case', 'LINK'));

        File::standardizeFilesCaseRecursive($dir);

        $this->assertSame(['INNER.TXT'], $this->entriesIn($this->path('target')), 'The link target must not be renamed through the link.');
    }

    // ---------------------------------------------------------------------
    // deleteFiles
    // ---------------------------------------------------------------------

    /**
     * DATA LOSS REGRESSION: every name was reduced to basename(), so "sub/a.txt" deleted
     * "<dir>/a.txt" — a different file — and reported TRUE, while the file actually named survived.
     */
    public function testDeleteFilesRefusesANestedNameInsteadOfDeletingADifferentFile(): void {
        $root = $this->seedFile('dir/a.txt', 'ROOT');
        $nested = $this->seedFile('dir/sub/a.txt', 'SUB');

        $this->assertFalse(File::deleteFiles(['sub/a.txt'], $this->path('dir')));

        $this->assertSame('ROOT', file_get_contents($root), 'A file that was never named must not be deleted.');
        $this->assertFileExists($nested);
    }

    public function testDeleteFilesRefusesAnAbsolutePathInsteadOfDeletingTheSameNamedLeaf(): void {
        $inDir = $this->seedFile('dir/report.csv', 'KEEP');
        $elsewhere = $this->seedFile('elsewhere/report.csv', 'OTHER');

        $this->assertFalse(File::deleteFiles([$elsewhere], $this->path('dir')));

        $this->assertFileExists($inDir);
        $this->assertFileExists($elsewhere);
    }

    public function testDeleteFilesReportsANamedDirectoryAsAFailure(): void {
        $this->seedDir('dir/subdir');

        $this->assertFalse(File::deleteFiles(['subdir'], $this->path('dir')));
        $this->assertDirectoryExists($this->path('dir', 'subdir'));
    }

    public function testDeleteFilesTreatsAMissingNameAsAlreadyDeleted(): void {
        $this->seedDir('dir');
        $this->assertTrue(File::deleteFiles(['never-existed.txt'], $this->path('dir')));
    }

    public function testDeleteFilesRejectsNonStringNamesWithoutAWarning(): void {
        $this->seedFile('dir/1', 'one');

        $this->assertFalse(File::deleteFiles([['nested'], new \stdClass()], $this->path('dir')));
        $this->assertTrue(File::deleteFiles([1], $this->path('dir')), 'An int name is an ordinary leaf name.');
        $this->assertFileDoesNotExist($this->path('dir', '1'));
    }

    public function testDeleteFilesRemovesASymlinkButNotItsTarget(): void {
        $target = $this->seedFile('outside.txt', 'precious');
        $this->seedDir('dir');
        $this->makeFileSymlink($target, $this->path('dir', 'link.txt'));

        $this->assertTrue(File::deleteFiles(['link.txt'], $this->path('dir')));

        $this->assertSame('precious', file_get_contents($target));
    }

    // ---------------------------------------------------------------------
    // parseEnvFile / updateEnvFile
    // ---------------------------------------------------------------------

    public function testParseEnvFileUnquotesAMatchingPairOfQuotes(): void {
        $env = $this->seedFile('.env', "A=\"hunter2\"\nB='x y'\nC=\"unbalanced'\nD=\"\"\nE=plain\n");

        $this->assertSame(
            ['A' => 'hunter2', 'B' => 'x y', 'C' => "\"unbalanced'", 'D' => '', 'E' => 'plain'],
            File::parseEnvFile($env)
        );
    }

    public function testParseEnvFileIgnoresAUtf8BomOnTheFirstKey(): void {
        $env = $this->seedFile('.env', "\xEF\xBB\xBFAPP_KEY=x\r\nB=y\r\n");

        $this->assertSame(['APP_KEY' => 'x', 'B' => 'y'], File::parseEnvFile($env));
    }

    public function testParseEnvFileOnADirectoryReturnsEmptyWithoutAWarning(): void {
        $this->assertSame([], File::parseEnvFile($this->tmp));
    }

    /**
     * REGRESSION: every line was re-terminated with PHP_EOL, so on Windows one update converted a
     * whole LF .env to CRLF (every value then ends in "\r" for bash/Docker) and a missing final
     * newline was added. The docblock already promised "byte-for-byte".
     */
    public function testUpdateEnvFilePreservesLfLineEndingsByteForByte(): void {
        $env = $this->seedFile('.env', "A=1\nB=2\n# c\nC=3");

        $this->assertTrue(File::updateEnvFile($env, ['A' => 'x']));

        $this->assertSame("A=x\nB=2\n# c\nC=3", file_get_contents($env));
    }

    public function testUpdateEnvFilePreservesCrlfLineEndingsAndUsesThemForAppendedKeys(): void {
        $env = $this->seedFile('.env', "A=1\r\nB=2\r\n");

        $this->assertTrue(File::updateEnvFile($env, ['B' => 'y', 'NEW' => 'n']));

        $this->assertSame("A=1\r\nB=y\r\nNEW=n\r\n", file_get_contents($env));
    }

    public function testUpdateEnvFileAppendsAfterALastLineWithoutANewline(): void {
        $env = $this->seedFile('.env', "A=1\nB=2");

        $this->assertTrue(File::updateEnvFile($env, ['C' => '3']));

        $this->assertSame("A=1\nB=2\nC=3\n", file_get_contents($env));
    }

    /**
     * REGRESSION: only the FIRST occurrence of a key was rewritten. parseEnvFile() lets the LAST one
     * win, so the update silently had no effect at all.
     */
    public function testUpdateEnvFileRewritesEveryOccurrenceOfADuplicatedKey(): void {
        $env = $this->seedFile('.env', "K=old\nX=1\nK=older\n");

        $this->assertTrue(File::updateEnvFile($env, ['K' => 'new']));

        $this->assertSame("K=new\nX=1\nK=new\n", file_get_contents($env));
        $this->assertSame(['K' => 'new', 'X' => '1'], File::parseEnvFile($env));
    }

    public static function injectedEnvValueProvider(): array {
        return [
            'LF' => ["x\nADMIN_PASSWORD=owned"],
            'CRLF' => ["x\r\nADMIN_PASSWORD=owned"],
            'CR' => ["x\rADMIN_PASSWORD=owned"],
            'NUL' => ["x\0y"],
        ];
    }

    /**
     * INJECTION REGRESSION: values were written verbatim, so a value from user input carrying a
     * newline planted extra variables in the file. Refused before anything is written.
     */
    #[DataProvider('injectedEnvValueProvider')]
    public function testUpdateEnvFileRejectsALineBreakInAValue(string $value): void {
        $env = $this->seedFile('.env', "A=1\n");

        try {
            File::updateEnvFile($env, ['APP_NAME' => $value]);
            $this->fail('A value with a line break must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $this->assertSame("A=1\n", file_get_contents($env));
        $this->assertArrayNotHasKey('ADMIN_PASSWORD', File::parseEnvFile($env));
    }

    public static function invalidEnvKeyProvider(): array {
        return [
            'empty' => [''],
            'contains equals' => ['A=B'],
            'contains space' => ['A B'],
            'comment' => ['#A'],
            'newline' => ["A\n"],
            'NUL' => ["A\0"],
        ];
    }

    #[DataProvider('invalidEnvKeyProvider')]
    public function testUpdateEnvFileRejectsAnInvalidKey(string $key): void {
        $env = $this->seedFile('.env', "A=1\n");

        $this->expectException(\InvalidArgumentException::class);
        File::updateEnvFile($env, [$key => 'v']);
    }

    public function testUpdateEnvFileRejectsANonScalarValueInsteadOfWritingArray(): void {
        $env = $this->seedFile('.env', "A=1\n");

        $this->expectException(\InvalidArgumentException::class);
        File::updateEnvFile($env, ['A' => ['nested']]);
    }

    public function testUpdateEnvFileCastsScalarsAndNull(): void {
        $env = $this->seedFile('.env', '');

        $this->assertTrue(File::updateEnvFile($env, ['I' => 5, 'T' => true, 'N' => null]));

        $this->assertSame("I=5\nT=1\nN=\n", file_get_contents($env));
    }

    public function testUpdateEnvFileKeepsTheBom(): void {
        $env = $this->seedFile('.env', "\xEF\xBB\xBFA=1\n");

        $this->assertTrue(File::updateEnvFile($env, ['A' => '2']));

        $this->assertSame("\xEF\xBB\xBFA=2\n", file_get_contents($env));
    }

    // ---------------------------------------------------------------------
    // zipDirectory / zipMultipleFiles
    // ---------------------------------------------------------------------

    /**
     * REGRESSION: ZipArchive::CREATE alone OPENS an existing archive and ADDS to it, so re-running
     * a backup to the same path kept files long since deleted from the source.
     */
    public function testZipDirectoryReplacesAnExistingArchiveInsteadOfAppendingToIt(): void {
        $this->seedFile('src/old.txt', 'O');
        $out = $this->path('backup.zip');
        $this->assertTrue(File::zipDirectory($this->path('src'), $out, null, true));

        unlink($this->path('src', 'old.txt'));
        $this->seedFile('src/new.txt', 'N');
        $this->assertTrue(File::zipDirectory($this->path('src'), $out, null, true));

        $this->assertSame(['new.txt'], $this->zipEntries($out));
    }

    public function testZipDirectoryLeavesNoTemporaryFileBehind(): void {
        $this->seedFile('src/a.txt');
        $this->seedDir('out');

        $this->assertTrue(File::zipDirectory($this->path('src'), $this->path('out', 'a.zip')));

        $this->assertSame(['a.zip'], $this->entriesIn($this->path('out')));
    }

    public function testZipDirectoryFailureLeavesAPreviousArchiveIntact(): void {
        $this->seedFile('src/a.txt', 'A');
        $out = $this->path('backup.zip');
        $this->assertTrue(File::zipDirectory($this->path('src'), $out, null, true));
        $before = file_get_contents($out);

        // An empty source with $contentOnly has nothing to write: a failure, not a wiped backup.
        $this->seedDir('empty');
        $this->assertFalse(File::zipDirectory($this->path('empty'), $out, null, true));

        $this->assertSame($before, file_get_contents($out));
    }

    public function testZipDirectoryDoesNotArchiveTheOutputFileItself(): void {
        $this->seedFile('src/a.txt', 'A');
        $this->seedFile('src/backup.zip', 'stale archive from a previous run');

        $this->assertTrue(File::zipDirectory($this->path('src'), $this->path('src', 'backup.zip'), null, true));

        $this->assertSame(['a.txt'], $this->zipEntries($this->path('src', 'backup.zip')));
    }

    /**
     * REGRESSION: "./../../evil" survived as the entry prefix "../../evil/…" — the library itself
     * produced a Zip Slip archive for whoever extracted it next.
     */
    public function testZipMultipleFilesRefusesAVirtualPathThatClimbsOutOfTheArchive(): void {
        $a = $this->seedFile('a.txt', 'A');
        $out = $this->path('m.zip');

        $this->assertFalse(File::zipMultipleFiles(['./../../evil' => [$a]], $out));
        $this->assertFileDoesNotExist($out);
    }

    /**
     * REGRESSION: the leading '.' was stripped with removeStringPrefix('.'), so a ".config" virtual
     * folder silently became "config".
     */
    public function testZipMultipleFilesKeepsADotFolderName(): void {
        $a = $this->seedFile('a.txt', 'A');
        $out = $this->path('m.zip');

        $this->assertTrue(File::zipMultipleFiles(['.config' => [$a], './x/./y' => [$a]], $out));

        $this->assertSame(['.config/a.txt', 'x/y/a.txt'], $this->zipEntries($out));
    }

    /**
     * REGRESSION: addFile() overwrites an entry of the same name, so two DIFFERENT files with the
     * same base name in one folder produced a one-file archive and TRUE.
     */
    public function testZipMultipleFilesFailsInsteadOfSilentlyDroppingASameNamedFile(): void {
        $a = $this->seedFile('a/report.csv', 'A');
        $b = $this->seedFile('b/report.csv', 'B');
        $out = $this->path('m.zip');

        $this->assertFalse(File::zipMultipleFiles(['.' => [$a, $b]], $out));
        $this->assertFileDoesNotExist($out);
    }

    public function testZipMultipleFilesAcceptsTheSameFileListedTwice(): void {
        $a = $this->seedFile('a.txt', 'A');
        $out = $this->path('m.zip');

        $this->assertTrue(File::zipMultipleFiles(['.' => [$a, $a]], $out));
        $this->assertSame(['a.txt'], $this->zipEntries($out));
    }

    public function testZipMultipleFilesSkipsNonStringPathsWithoutATypeError(): void {
        $a = $this->seedFile('a.txt', 'A');
        $out = $this->path('m.zip');

        $this->assertTrue(File::zipMultipleFiles(['.' => [['nested'], 42, null, $a]], $out));
        $this->assertSame(['a.txt'], $this->zipEntries($out));
    }

    public function testZipMultipleFilesReplacesAnExistingArchive(): void {
        $a = $this->seedFile('a.txt', 'A');
        $b = $this->seedFile('b.txt', 'B');
        $out = $this->path('m.zip');

        $this->assertTrue(File::zipMultipleFiles(['.' => [$a]], $out));
        $this->assertTrue(File::zipMultipleFiles(['.' => [$b]], $out));

        $this->assertSame(['b.txt'], $this->zipEntries($out));
    }

    // ---------------------------------------------------------------------
    // unzipFile
    // ---------------------------------------------------------------------

    /**
     * REGRESSION: a new destination named with a dot ("release-1.2") was taken for a FILE with a
     * ".2" extension, so the call failed (and created the parent as a side effect).
     */
    public function testUnzipFileExtractsIntoANewDestinationWhoseNameHasADot(): void {
        $zip = $this->makeZip($this->path('a.zip'), ['d/x.txt' => 'X']);
        $dest = $this->path('release-1.2');

        $this->assertTrue(File::unzipFile($zip, $dest, 'd'));

        $this->assertSame('X', file_get_contents($dest . DIRECTORY_SEPARATOR . 'x.txt'));
    }

    /**
     * HIGH REGRESSION (Zip Slip through a link): a symlink/junction already inside the destination
     * was written THROUGH, so the entry "sub/pwn.txt" landed in the link's target, outside the
     * destination, and the call reported TRUE.
     */
    public function testUnzipFileNeverWritesThroughALinkInsideTheDestination(): void {
        $outside = $this->seedDir('outside');
        $dest = $this->seedDir('dest');
        $this->makeDirectoryLink($outside, $dest . DIRECTORY_SEPARATOR . 'sub');
        $zip = $this->makeZip($this->path('b.zip'), ['d/sub/pwn.txt' => 'PWN', 'd/sub/deeper/pwn2.txt' => 'PWN', 'd/ok.txt' => 'OK']);

        $result = File::unzipFile($zip, $dest, 'd');

        $this->assertIsArray($result);
        $this->assertSame([], $this->entriesIn($outside), 'Nothing may be written — not even a directory — through the link.');
        $this->assertSame('OK', file_get_contents($dest . DIRECTORY_SEPARATOR . 'ok.txt'));
    }

    /**
     * REGRESSION: libzip does not check the CRC when an entry is read, so a corrupted entry was
     * written and reported as extracted. It must be reported, and must not replace a good file.
     */
    public function testUnzipFileRejectsACorruptEntryAndKeepsTheExistingFile(): void {
        $zipPath = $this->path('f.zip');
        $zip = new \ZipArchive();
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('good.txt', 'GOOD');
        $zip->addFromString('bad.txt', str_repeat('hello world ', 200));
        $zip->setCompressionName('bad.txt', \ZipArchive::CM_STORE);
        $zip->close();
        $raw = file_get_contents($zipPath);
        $pos = strpos($raw, 'hello world hello');
        $raw[$pos + 50] = 'X';
        file_put_contents($zipPath, $raw);
        $dest = $this->seedDir('dest');
        $this->seedFile('dest/bad.txt', 'PREVIOUS GOOD COPY');

        $result = File::unzipFile($zipPath, $dest, ['good.txt', 'bad.txt']);

        $this->assertSame(['bad.txt'], $result);
        $this->assertSame('GOOD', file_get_contents($dest . DIRECTORY_SEPARATOR . 'good.txt'));
        $this->assertSame('PREVIOUS GOOD COPY', file_get_contents($dest . DIRECTORY_SEPARATOR . 'bad.txt'));
        $this->assertSame(['bad.txt', 'good.txt'], $this->entriesIn($dest), 'No temporary file may be left behind.');
    }

    /**
     * Two exact names with the same base name both land on "<dest>/r.txt"; the second used to
     * overwrite the first and the call still reported TRUE.
     */
    public function testUnzipFileReportsTwoEntriesThatWouldLandOnTheSameFile(): void {
        $zip = $this->makeZip($this->path('e.zip'), ['a/r.txt' => 'A', 'b/r.txt' => 'B']);
        $dest = $this->path('dest');

        $this->assertSame(['b' . DIRECTORY_SEPARATOR . 'r.txt'], File::unzipFile($zip, $dest, ['a/r.txt', 'b/r.txt']));
        $this->assertSame('A', file_get_contents($dest . DIRECTORY_SEPARATOR . 'r.txt'));
    }

    public function testUnzipFileExtractsAnEntryReachedThroughTwoNeedlesOnlyOnce(): void {
        $zip = $this->makeZip($this->path('e.zip'), ['data/r.txt' => 'R']);

        $this->assertTrue(File::unzipFile($zip, $this->path('dest'), ['data', 'data/r.txt']));
    }

    public function testUnzipFileNormalisesDotSegments(): void {
        $zip = $this->makeZip($this->path('e.zip'), ['data/./sub/./r.txt' => 'R']);

        $this->assertTrue(File::unzipFile($zip, $this->path('dest'), 'data'));
        $this->assertSame('R', file_get_contents($this->path('dest', 'sub', 'r.txt')));
    }

    public function testUnzipFileRejectsAMalformedPermissionModeUpFront(): void {
        $zip = $this->makeZip($this->path('e.zip'), ['data/r.txt' => 'R']);

        $this->assertFalse(File::unzipFile($zip, $this->path('dest'), 'data', '0o755'));
        $this->assertDirectoryDoesNotExist($this->path('dest'));
    }

    public function testUnzipFileReplacesAnExistingFileWithTheArchivedOne(): void {
        $zip = $this->makeZip($this->path('e.zip'), ['r.txt' => 'NEW']);
        $this->seedFile('dest/r.txt', 'OLD');

        $this->assertTrue(File::unzipFile($zip, $this->path('dest'), 'r.txt'));
        $this->assertSame('NEW', file_get_contents($this->path('dest', 'r.txt')));
    }

    public function testUnzipFileExtractsALargeEntryByStreaming(): void {
        $payload = random_bytes(3 * 1024 * 1024 + 17);
        $zip = $this->makeZip($this->path('big.zip'), ['big.bin' => $payload]);

        $this->assertTrue(File::unzipFile($zip, $this->path('dest'), 'big.bin'));
        $this->assertSame(md5($payload), md5_file($this->path('dest', 'big.bin')));
    }

    public static function windowsHostileEntryProvider(): array {
        return [
            'device name' => ['d/NUL'],
            'device name with extension' => ['d/con.txt'],
            'alternate data stream' => ['d/file:stream'],
            'trailing dot' => ['d/evil.'],
            'trailing space' => ['d/evil '],
            'reserved character' => ['d/a|b.txt'],
        ];
    }

    /**
     * An entry named "NUL" was "extracted" into the null device and reported TRUE; "file:stream"
     * wrote a hidden NTFS alternate data stream. Such names are refused on Windows.
     */
    #[RequiresOperatingSystemFamily('Windows')]
    #[DataProvider('windowsHostileEntryProvider')]
    public function testUnzipFileRefusesNamesThatAreNotPlainWindowsFiles(string $entry): void {
        $zip = $this->makeZip($this->path('w.zip'), [$entry => 'payload', 'd/ok.txt' => 'OK']);
        $dest = $this->path('dest');

        $result = File::unzipFile($zip, $dest, 'd');

        $this->assertSame([str_replace('/', '\\', $entry)], $result);
        $this->assertSame(['ok.txt'], $this->entriesIn($dest));
    }

    // ---------------------------------------------------------------------
    // uploadFileTo
    // ---------------------------------------------------------------------

    public static function malformedUploadProvider(): array {
        return [
            'missing tmp_name' => [['name' => 'a.txt', 'error' => 0]],
            'missing name' => [['tmp_name' => '/tmp/x', 'error' => 0]],
            'missing error' => [['tmp_name' => '/tmp/x', 'name' => 'a.txt']],
            'multi-file shape' => [['tmp_name' => ['/tmp/x'], 'name' => ['a.txt'], 'error' => [0]]],
            'string error code' => [['tmp_name' => '/tmp/x', 'name' => 'a.txt', 'error' => '0']],
        ];
    }

    /**
     * A malformed $_FILES entry is type 7 WITHOUT the "Undefined array key" warnings it used to
     * raise on the way (failOnWarning makes any warning fail this test).
     */
    #[DataProvider('malformedUploadProvider')]
    public function testUploadFileToReturnsSevenForAMalformedEntryWithoutWarnings(array $upload): void {
        $this->assertSame(['type' => 7, 'file' => '', 'path' => ''], File::uploadFileTo($upload, $this->path('up')));
        $this->assertDirectoryDoesNotExist($this->path('up'));
    }

    public function testUploadFileToReportsAnUploadErrorWithoutCreatingTheDestination(): void {
        $dest = $this->path('never', 'made');

        $result = File::uploadFileTo(['tmp_name' => '', 'name' => 'a.txt', 'error' => UPLOAD_ERR_NO_FILE], $dest);

        $this->assertSame(4, $result['type']);
        $this->assertDirectoryDoesNotExist($this->path('never'));
    }

    public function testUploadFileToTreatsADottedTargetAsADirectory(): void {
        $src = $this->seedFile('src.txt');
        $dest = $this->path('uploads', 'v1.2');

        $result = File::uploadFileTo(['tmp_name' => $src, 'name' => 'a.txt', 'error' => 0], $dest);

        // CLI: move_uploaded_file() always refuses, which is type 5 — AFTER the directory was made.
        $this->assertSame(5, $result['type']);
        $this->assertDirectoryExists($dest);
    }

    public function testUploadFileToRejectsAMalformedDirectoryModeWithSix(): void {
        $src = $this->seedFile('src.txt');

        $result = File::uploadFileTo(['tmp_name' => $src, 'name' => 'a.txt', 'error' => 0], $this->path('up'), 'rwx');

        $this->assertSame(6, $result['type']);
        $this->assertDirectoryDoesNotExist($this->path('up'));
    }

    // ---------------------------------------------------------------------
    // renameUploadFile
    // ---------------------------------------------------------------------

    /**
     * The random part came from rand(), which System::makeSeed()/srand() seed: two workers seeded
     * alike produced IDENTICAL suffixes in the same second. random_int() cannot be seeded.
     */
    public function testRenameUploadFileSuffixIsNotReproducibleBySeedingRand(): void {
        $pairs = [];
        for ($i = 0; $i < 20; $i++) {
            srand(12345);
            $a = File::renameUploadFile('same.txt');
            srand(12345);
            $b = File::renameUploadFile('same.txt');
            $pairs[] = substr($a, -7, 3) === substr($b, -7, 3);
        }
        mt_srand();

        $this->assertContains(false, $pairs, 'Seeding rand() must not make the random suffix repeat.');
    }

    public function testRenameUploadFileNeverYieldsAWindowsDeviceStem(): void {
        foreach (['con.txt', 'NUL', 'aux.tar.gz', 'COM1.log'] as $original) {
            $stem = explode('.', File::renameUploadFile($original))[0];
            $this->assertDoesNotMatchRegularExpression('/^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])$/i', $stem);
        }
    }

    // ---------------------------------------------------------------------
    // downloadFile
    // ---------------------------------------------------------------------

    public static function contentDispositionProvider(): array {
        return [
            'plain' => ['report.pdf', 'attachment; filename="report.pdf"'],
            'quote injection' => [
                'x"; filename*=UTF-8\'\'evil.html',
                'attachment; filename="x_; filename*=UTF-8\'\'evil.html"; filename*=UTF-8\'\'x%22%3B%20filename%2A%3DUTF-8%27%27evil.html',
            ],
            'CRLF header split' => ["a\r\nSet-Cookie: s=1.txt", 'attachment; filename="aSet-Cookie: s=1.txt"'],
            'escaped CRLF decoded by decodeText' => ['a\\u000d\\u000aX: y.txt', 'attachment; filename="aX: y.txt"'],
            'accents' => ['relatório.pdf', 'attachment; filename="relatorio.pdf"; filename*=UTF-8\'\'relat%C3%B3rio.pdf'],
            'path separators' => ['../../etc/passwd', 'attachment; filename=".._.._etc_passwd"'],
            'backslash' => ['a\\b.txt', 'attachment; filename="a_b.txt"'],
            'only control characters' => ["\r\n", 'attachment; filename="download"'],
        ];
    }

    /**
     * HEADER INJECTION REGRESSION: the name was pasted raw inside filename="...". A quote closed the
     * string and appended a filename* parameter — which browsers PREFER — naming the saved file
     * whatever the attacker wanted; a CR/LF made header() drop the whole header, so the file was
     * rendered inline instead of downloaded.
     */
    #[DataProvider('contentDispositionProvider')]
    public function testContentDispositionIsAlwaysASingleWellFormedHeaderValue(string $name, string $expected): void {
        $value = self::invokePrivate('buildContentDisposition', $name);

        $this->assertSame($expected, $value);
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F\x7F]/', $value);
        $this->assertSame(1, preg_match('/^attachment; filename="[^"\\\\]*"(; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]+)?$/', $value));
    }

    /**
     * An empty name used to return silently: nothing sent, $terminateAfterDownload ignored, and
     * the caller's page kept rendering after what it believed was an exit().
     */
    public function testDownloadFileRejectsAnEmptyName(): void {
        $file = $this->seedFile('keep.txt', 'still here');

        foreach ([null, '', '   '] as $name) {
            try {
                File::downloadFile($file, $name, true, true);
                $this->fail('An empty download name must be rejected.');
            } catch (\InvalidArgumentException) {
            }
        }

        $this->assertSame('still here', file_get_contents($file));
    }

    /**
     * Runs downloadFile() in a child CLI process after $prelude. Returns stdout, stderr and whether
     * the file survived.
     *
     * @return array{stdout: string, stderr: string, exit: int, fileExists: bool}
     */
    private function runDownloadChild(string $file, string $prelude, bool $delete, string $name = 'x.bin'): array {
        if (!function_exists('proc_open')) {
            $this->markTestSkipped('proc_open() is disabled.');
        }

        $script = $this->path('child_' . bin2hex(random_bytes(4)) . '.php');
        file_put_contents($script, sprintf(
            "<?php\nrequire %s;\n%s\ntry {\n    \\VD\\PHPHelper\\File::downloadFile(%s, %s, %s, false);\n    echo 'RETURNED';\n} catch (\\Throwable \$e) {\n    fwrite(STDERR, 'EXCEPTION: ' . \$e->getMessage());\n}\n",
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            $prelude,
            var_export($file, true),
            var_export($name, true),
            var_export($delete, true)
        ));

        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'output_buffering=0', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);
        clearstatcache(true, $file);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => $exit, 'fileExists' => is_file($file)];
    }

    /**
     * Anything already buffered (a stray echo, a BOM from an include, a framework's ob_start())
     * was flushed AHEAD of the file body, corrupting every download; a buffer without a chunk size
     * also accumulated the whole file in memory. Buffers are discarded before the body is sent.
     */
    public function testDownloadFileDiscardsOutputBufferedBeforeTheDownload(): void {
        $file = $this->seedFile('body.bin', 'EXACT BODY');

        $result = $this->runDownloadChild($file, "ob_start();\necho 'JUNK BEFORE';\nob_start();\necho 'MORE JUNK';", false);

        $this->assertSame('EXACT BODYRETURNED', $result['stdout'], "Child stderr:\n" . $result['stderr']);
    }

    /**
     * Once output has started the headers can no longer be sent: the body would be appended to the
     * page. That is now an exception BEFORE anything is streamed, and deleteAfterDownload is not
     * honoured for a download that never happened.
     */
    public function testDownloadFileRefusesToStartAfterOutputHasBeenSent(): void {
        $file = $this->seedFile('body.bin', 'BODY');

        $result = $this->runDownloadChild($file, "echo 'PAGE ALREADY STARTED';\nflush();", true);

        $this->assertSame('PAGE ALREADY STARTED', $result['stdout']);
        $this->assertStringContainsString('EXCEPTION: Cannot start the download', $result['stderr']);
        $this->assertTrue($result['fileExists'], 'A download that never started must not delete the file.');
    }

    /**
     * End-to-end check of the headers a real SAPI emits (the CLI discards headers). Uses the
     * php-cgi binary shipped next to PHP_BINARY when there is one.
     */
    public function testDownloadFileSendsASafeContentDispositionThroughARealSapi(): void {
        $cgi = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php-cgi.exe' : 'php-cgi');
        if (!is_file($cgi) || !function_exists('proc_open')) {
            $this->markTestSkipped('No php-cgi binary next to PHP_BINARY; header emission is covered by the pure builder tests above.');
        }

        $file = $this->seedFile('body.bin', 'BODY');
        $script = $this->path('cgi_child.php');
        file_put_contents($script, sprintf(
            "<?php\nrequire %s;\n\\VD\\PHPHelper\\File::downloadFile(%s, %s, false, true);\n",
            var_export(dirname(__DIR__) . '/vendor/autoload.php', true),
            var_export($file, true),
            var_export("x\"; filename*=UTF-8''evil.html\r\nX-Injected: 1", true)
        ));

        $process = proc_open(
            // The script as a positional argument: "-f" implies "no headers" in php-cgi.
            [$cgi, '-d', 'display_errors=stderr', '-d', 'cgi.force_redirect=0', $script],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        $this->assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        proc_close($process);

        [$head, $body] = array_pad(preg_split("/\r?\n\r?\n/", $stdout, 2), 2, '');
        $this->assertSame('BODY', $body, "stderr:\n" . $stderr);
        $this->assertDoesNotMatchRegularExpression('/^X-Injected/mi', $head, 'The CR/LF must not have started a header of its own.');
        $this->assertMatchesRegularExpression('/^Content-Disposition: attachment; filename="x_; filename\*=UTF-8\'\'evil.htmlX-Injected: 1"; filename\*=UTF-8\'\'[^\r\n"]+\r?$/mi', $head);
        $this->assertMatchesRegularExpression('/^Content-Length: 4\r?$/mi', $head);
        $this->assertMatchesRegularExpression('/^Content-Type: application\/octet-stream\r?$/mi', $head);
        $this->assertMatchesRegularExpression('/^X-Content-Type-Options: nosniff\r?$/mi', $head);
    }
}
