<?php

namespace VD\PHPHelper;

class File {
    /**
     * Block size, in bytes, used by downloadFile() when no custom size is configured.
     */
    private const DEFAULT_DOWNLOAD_BLOCK_SIZE = 3 * 1024 * 1024;

    /**
     * Permission mode applied by createDir() when the caller passes no explicit mode.
     *
     * 0755 (not 0777): every directory this library creates is reachable through
     * getPathInfo(createPath: true), so a world-writable default would let any local
     * user plant files in a consumer's upload directory.
     */
    private const DEFAULT_MODE = "755";

    /**
     * Largest value accepted as a permission mode: the rwx bits plus setuid, setgid and sticky.
     */
    private const MAX_MODE = 0o7777;

    /**
     * @var int Default permission mode, as an octal integer, or 0 when not yet resolved.
     *          Lazily initialised to DEFAULT_MODE by getDefaultMode().
     */
    private static int $defaultMode = 0;

    /**
     * @var int Number of bytes read per loop iteration by downloadFile(), or 0 when not yet
     *          resolved. Lazily initialised to DEFAULT_DOWNLOAD_BLOCK_SIZE (3 MB) by
     *          getDownloadBlockSize().
     */
    private static int $downloadBlockSize = 0;

    /**
     * Gets the number of bytes downloadFile() reads and sends per loop iteration.
     *
     * If no custom value has been set, it defaults to 3145728 bytes (3 MB) — NOT 8 KB.
     * Size memory_limit against this figure: each concurrent download holds one block in memory.
     *
     * @return int Always >= 1.
     */
    public static function getDownloadBlockSize(): int {
        if (self::$downloadBlockSize < 1) {
            self::$downloadBlockSize = self::DEFAULT_DOWNLOAD_BLOCK_SIZE;
        }

        return self::$downloadBlockSize;
    }

    /**
     * Sets the block size (in bytes) used by downloadFile() to stream a file to the client.
     *
     * @param int|null $bytes Block size in bytes; must be >= 1. Pass null (or omit) to restore
     *                        the default of 3145728 bytes (3 MB).
     * @return void
     * @throws \InvalidArgumentException If $bytes is given and is less than 1. A non-positive
     *                                   size is rejected here rather than reaching downloadFile(),
     *                                   where fread() would raise a ValueError mid-stream, after
     *                                   the response headers had already been sent.
     */
    public static function setDownloadBlockSize(?int $bytes = null): void {
        if ($bytes === null) {
            self::$downloadBlockSize = self::DEFAULT_DOWNLOAD_BLOCK_SIZE;
            return;
        }

        if ($bytes < 1) {
            throw new \InvalidArgumentException("Download block size must be at least 1 byte, got {$bytes}!");
        }

        self::$downloadBlockSize = $bytes;
    }

    /**
     * Returns the default permission mode, as an octal integer, used when a caller passes no
     * explicit mode.
     *
     * Defaults to 0755 (decimal 493) unless setDefaultMode() changed it. In practice this is a
     * DIRECTORY mode: createDir() is the only method that falls back to it — writeFile() applies
     * a mode only when one is explicitly passed.
     *
     * @return int The default permission mode as an octal integer (e.g., 0755 === 493)
     */
    public static function getDefaultMode(): int {
        if(self::$defaultMode === 0) {
            self::setDefaultMode(self::DEFAULT_MODE);
        }
        return self::$defaultMode;
    }

    /**
     * Sets the process-wide default permission mode used when a caller passes no explicit mode.
     *
     * @param string $defaultMode Permission mode as an octal string: octal digits with an optional
     *                            leading zero (e.g., "755", "0700"), at most 07777. Anything else —
     *                            and a mode of 0, which would produce directories nothing can
     *                            enter — is SILENTLY IGNORED and the previous default is kept, so
     *                            read getDefaultMode() back if the value is not a literal.
     * @return void
     */
    public static function setDefaultMode(string $defaultMode): void {
        $mode = self::parseOctalMode($defaultMode);
        // 0 is also the "not resolved yet" sentinel: storing it made the NEXT getDefaultMode()
        // silently reset to 0755 instead of keeping the previous default as documented.
        if($mode === null || $mode === 0) {
            return;
        }
        self::$defaultMode = $mode;
    }

    /**
     * Parses an octal permission string into the int chmod()/mkdir() expect, or NULL if it is not
     * one this library can honour.
     *
     * Validator::isOctal() only checks the CHARACTER SET, so it accepts an arbitrarily long run of
     * octal digits. octdec() returns a FLOAT above PHP_INT_MAX (a TypeError in mkdir()/chmod()),
     * and any int above 07777 carries bits outside the permission set that the OS silently masks
     * off — "7777777" would quietly become 07777 (world-writable plus setuid/setgid). Both are
     * rejected, so they join every other malformed mode on the caller's error path.
     *
     * The bound is the VALUE, not the string length: "0000000000000000000000700" is a perfectly
     * good 0700.
     *
     * @param string|null $value Permission mode as an octal string (e.g., "0700").
     * @return int|null The mode as an octal integer, or NULL if $value is not a valid octal string
     *                  or is above 07777.
     */
    private static function parseOctalMode(?string $value): ?int {
        if(!Validator::isOctal($value)) {
            return null;
        }

        $mode = octdec($value);
        return (is_int($mode) && $mode <= self::MAX_MODE) ? $mode : null;
    }

    /**
     * Converts an octal permission string into the octal integer chmod()/mkdir() expect.
     *
     * @param string|null $permissionMode Permission mode as an octal string (e.g., "0700").
     *                                    NULL — or any value that is not a valid octal string or is
     *                                    above 07777 (see parseOctalMode()) — falls back to
     *                                    getDefaultMode() rather than failing, so a malformed mode
     *                                    silently becomes the default (which may be WIDER than
     *                                    what the caller asked for). Callers that must not
     *                                    silently widen permissions have to reject a malformed
     *                                    mode themselves before calling; writeFile() and
     *                                    createDir() do.
     * @return int The resolved permission mode as an octal integer. Never a float: mkdir()/chmod()
     *             reject one with a TypeError.
     */
    public static function getPermissionMode(?string $permissionMode): int {
        return self::parseOctalMode($permissionMode) ?? self::getDefaultMode();
    }

    /**
     * Creates directories on the server.
     *
     * The process umask is DELIBERATELY bypassed (umask(0)) while creating, so the resulting
     * directory gets exactly the requested mode — a hardened umask will NOT trim it. This is
     * the opposite of the usual mkdir() behaviour; pass an explicit restrictive mode if the
     * directory must not be world-readable. The umask is restored even if mkdir() throws.
     *
     * @param string $path The directory path to be created
     * @param string|null $permissionMode Directory mode as an octal string (e.g., "0700").
     *                                    NULL (default) uses getDefaultMode() — 0755 unless changed
     *                                    via setDefaultMode(). A value that is not a valid octal
     *                                    string, or is above 07777, is REJECTED with -3 rather than
     *                                    silently replaced by the (possibly wider) default: a
     *                                    caller writing "0o700" meant owner-only, not 0755.
     *                                    Ignored on Windows.
     * @param bool $recursive Whether the creation should be recursive (may increase memory usage)
     *
     * @return int Positive on success, negative on failure:
     *              2  directory created
     *              1  directory already existed — including one another process created
     *                 concurrently (mode NOT applied: an existing directory is never chmod'ed here)
     *             -1  $path was empty or whitespace-only
     *             -2  mkdir() failed (also for a path containing a NUL byte)
     *             -3  $permissionMode was given but is not a usable octal mode
     *
     * @ref http://php.net/manual/en/function.mkdir.php
     */
    public static function createDir(string $path, ?string $permissionMode = null, bool $recursive = true): int {
        if(trim($path) === '') {
            return -1;
        }

        if ($permissionMode === null) {
            $mode = self::getDefaultMode();
        } else {
            $mode = self::parseOctalMode($permissionMode);
            if ($mode === null) {
                return -3;
            }
        }

        // mkdir() throws a ValueError on a NUL byte; before the finally below, that escaped with
        // the process umask still at 0, so every file created afterwards was world-writable.
        if (str_contains($path, "\0")) {
            return -2;
        }

        $path = str_replace(array("\\", "/"), DIRECTORY_SEPARATOR, $path);
        if (is_dir($path)) {
            return 1;
        }

        $oldMask = umask(0);
        try {
            // Suppressed: the -2 return is the documented error channel, and a raw warning would
            // leak the path into the response of a caller who is already handling the failure.
            $created = @mkdir($path, $mode, $recursive);
        } finally {
            umask($oldMask);
        }

        if ($created) {
            return 2;
        }

        // Lost a race: another process created it between is_dir() and mkdir().
        clearstatcache(true, $path);
        return is_dir($path) ? 1 : -2;
    }

    /**
     * Returns whether a caller-supplied path is unusable: empty, whitespace-only or containing a
     * NUL byte.
     *
     * A blank path must never reach realpath(): realpath('') is the CURRENT WORKING DIRECTORY, so
     * a blank or whitespace-only argument used to resolve to it — and deleteFoldersRecursively('  ')
     * wiped the whole working directory. A NUL byte makes realpath()/mkdir() throw a ValueError.
     *
     * @param string|null $path Path to check
     * @return bool
     */
    private static function isUnusablePath(?string $path): bool {
        return $path === null || trim($path) === '' || str_contains($path, "\0");
    }

    /**
     * Appends path segments to a base path without losing root-only paths like C:\ or /.
     *
     * @param string $base Base path
     * @param array $segments Path segments to append
     * @return string
     */
    private static function appendPathSegments(string $base, array $segments): string {
        $path = rtrim(str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $base), "\\/");

        if ($path === "" || preg_match('/^[A-Z]:$/i', $path)) {
            $path .= DIRECTORY_SEPARATOR;
        }

        foreach ($segments as $segment) {
            if ($segment === "" || $segment === ".") {
                continue;
            }

            if (!str_ends_with($path, DIRECTORY_SEPARATOR)) {
                $path .= DIRECTORY_SEPARATOR;
            }
            $path .= $segment;
        }

        return self::normalizeResolvedPath($path);
    }

    /**
     * Returns whether the path is absolute for the current platform.
     *
     * @param string $path Path to check
     * @return bool
     */
    private static function isAbsolutePath(string $path): bool {
        return preg_match('/^[A-Z]:[\\\\\/]/i', $path) === 1 ||
            str_starts_with($path, "\\\\") ||
            str_starts_with($path, "/");
    }

    /**
     * Normalizes separators and dot segments in an already resolved path.
     *
     * On Windows a UNC path keeps its "\\server\share\" root: it used to be treated like a
     * root-relative path and collapsed to "\server\share\...", which Windows resolves against the
     * CURRENT DRIVE — so getPathInfo(createPath: true) on an unreachable share created the
     * directories on C:\ instead. ".." never climbs above the share.
     *
     * @param string $path Path to normalize
     * @return string
     */
    private static function normalizeResolvedPath(string $path): string {
        $path = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $path);
        $prefix = "";

        if (DIRECTORY_SEPARATOR === "\\" && str_starts_with($path, "\\\\")) {
            $parts = explode("\\", substr($path, 2), 3);
            if (count($parts) >= 2 && $parts[0] !== "" && $parts[1] !== "") {
                $prefix = "\\\\" . $parts[0] . "\\" . $parts[1] . "\\";
                $path = $parts[2] ?? "";
            } else {
                $prefix = "\\\\";
                $path = substr($path, 2);
            }
        } elseif (preg_match('/^[A-Z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . '/i', $path) === 1) {
            $prefix = substr($path, 0, 3);
            $path = substr($path, 3);
        } elseif (str_starts_with($path, DIRECTORY_SEPARATOR)) {
            $prefix = DIRECTORY_SEPARATOR;
            $path = ltrim($path, DIRECTORY_SEPARATOR);
        }

        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $path) as $segment) {
            if ($segment === "" || $segment === ".") {
                continue;
            }

            if ($segment === "..") {
                if (!empty($segments) && end($segments) !== "..") {
                    array_pop($segments);
                } elseif ($prefix === "") {
                    $segments[] = $segment;
                }
                continue;
            }

            $segments[] = $segment;
        }

        return $prefix . implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Resolves a path using realpath for the longest existing parent and appends missing segments.
     *
     * @param string $path Directory path to resolve
     * @return string
     */
    private static function resolvePath(string $path): string {
        $path = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $path);
        if ($path === "") {
            return "";
        }

        $realPath = realpath($path);
        if ($realPath !== false) {
            return $realPath;
        }

        // A UNC path is never resolved ABOVE its share: dirname("\\server\share") is "\\server"
        // and then "\", which realpath() reads as the root of the CURRENT DRIVE — so a missing or
        // unreachable share resolved to C:\server\share\... and, with createPath, got created
        // there. The share is the floor; below it the path is kept as given (normalized).
        $uncShare = self::uncShareRoot($path);

        $segments = [];
        $currentPath = $path;
        while ($currentPath !== "" && $currentPath !== ".") {
            $segment = basename($currentPath);
            if ($segment !== "" && $segment !== ".") {
                array_unshift($segments, $segment);
            }

            $parentPath = dirname($currentPath);
            if ($uncShare !== null && strlen($parentPath) < strlen($uncShare)) {
                break;
            }

            $parentRealPath = realpath($parentPath);
            if ($parentRealPath !== false) {
                return self::appendPathSegments($parentRealPath, $segments);
            }

            if ($parentPath === $currentPath) {
                break;
            }

            $currentPath = $parentPath;
        }

        if (!self::isAbsolutePath($path)) {
            $path = getcwd() . DIRECTORY_SEPARATOR . $path;
        }

        return self::normalizeResolvedPath($path);
    }

    /**
     * The "\\server\share" root of a Windows UNC path, or null when $path is not one.
     *
     * @param string $path Path with the platform separator
     * @return string|null
     */
    private static function uncShareRoot(string $path): ?string {
        if (DIRECTORY_SEPARATOR !== "\\" || preg_match('/^\\\\\\\\([^\\\\]+)\\\\([^\\\\]+)/', $path, $m) !== 1) {
            return null;
        }

        return "\\\\" . $m[1] . "\\" . $m[2];
    }

    /**
     * Ensures a directory path ends with the system directory separator.
     *
     * @param string $path Directory path
     * @return string
     */
    private static function ensureTrailingDirectorySeparator(string $path): string {
        return str_ends_with($path, DIRECTORY_SEPARATOR) ? $path : $path . DIRECTORY_SEPARATOR;
    }

    /**
     * Returns whether $path is a symbolic link or, on Windows, a directory junction.
     *
     * Anything that walks or deletes a tree must ask this BEFORE following an entry: a link is
     * removed as a link, never walked, and never resolved with realpath() (which silently turns
     * it into its TARGET).
     *
     * @param string $path Path to check (unresolved)
     * @return bool
     */
    private static function isLinkOrJunction(string $path): bool {
        $path = rtrim($path, "\\/");
        if ($path === "" || preg_match('/^[A-Za-z]:$/', $path) === 1) {
            return false;
        }

        clearstatcache(true, $path);
        if (is_link($path)) {
            return true;
        }
        if (DIRECTORY_SEPARATOR !== "\\") {
            return false;
        }

        // PHP's Windows stat() does not report a directory junction as a link: a junction reads as
        // is_link(), is_dir() and is_file() all FALSE while file_exists() is TRUE and realpath()
        // resolves it to its target. Something that exists but is neither is a reparse point.
        if (!is_dir($path) && !is_file($path)) {
            return file_exists($path) || @readlink($path) !== false;
        }

        // Builds that do report a junction as a directory still betray it: it resolves somewhere
        // other than where it sits. An 8.3 short name ("PROGRA~1") legitimately expands under
        // realpath(), so a '~' leaf is not judged this way.
        $leaf = basename($path);
        if (str_contains($leaf, "~")) {
            return false;
        }
        $real = realpath($path);
        $parent = realpath(dirname($path));
        if ($real === false || $parent === false) {
            return false;
        }

        // mb_ comparison: strcasecmp() only folds ASCII, so a caller spelling "Ärger" for the
        // on-disk "ärger" would be taken for a link.
        return mb_strtolower($real, 'UTF-8') !== mb_strtolower(rtrim($parent, "\\/") . "\\" . $leaf, 'UTF-8');
    }

    /**
     * Removes a link or junction itself, never its target.
     *
     * unlink() removes a POSIX symlink and a Windows file symlink; a Windows directory symlink or
     * junction only goes with rmdir(). rmdir() cannot remove a non-empty directory and does not
     * follow a link, so neither call can reach the target's contents.
     *
     * @param string $path Link path
     * @return bool
     */
    private static function removeLink(string $path): bool {
        $path = rtrim($path, "\\/");
        return @unlink($path) || @rmdir($path);
    }

    /**
     * Returns whether $path is the root of a filesystem ("/", "C:\", "\\server\share").
     *
     * @param string $path Resolved path
     * @return bool
     */
    private static function isFilesystemRoot(string $path): bool {
        $trimmed = rtrim($path, "\\/");
        if ($trimmed === "" || preg_match('/^[A-Za-z]:$/', $trimmed) === 1) {
            return true;
        }

        return DIRECTORY_SEPARATOR === "\\" && preg_match('/^\\\\\\\\[^\\\\]+\\\\[^\\\\]+$/', $trimmed) === 1;
    }

    /**
     * Returns whether $path resolves to $root or to something beneath it.
     *
     * @param string $path Path to check; resolved with realpath(), so it must exist
     * @param string $root Already-resolved root
     * @return bool
     */
    private static function isPathInside(string $path, string $root): bool {
        $real = realpath($path);
        if ($real === false) {
            return false;
        }

        $real = rtrim($real, "\\/");
        $root = rtrim($root, "\\/");
        if (DIRECTORY_SEPARATOR === "\\") {
            $real = strtolower($real);
            $root = strtolower($root);
        }

        return $real === $root || str_starts_with($real . DIRECTORY_SEPARATOR, $root . DIRECTORY_SEPARATOR);
    }

    /**
     * Device + inode of an existing path ("dev:ino"), or null when it does not exist or the
     * platform reports no inode. Two names with the same identity are one file: a non-ASCII case
     * variant on a case-insensitive volume, an 8.3 short name, a hard link.
     *
     * @param string $path Path (links are followed: the identity is the target's)
     * @return string|null
     */
    private static function fileIdentity(string $path): ?string {
        clearstatcache(true, $path);
        $stat = @stat($path);
        if ($stat === false || empty($stat['ino'])) {
            return null;
        }

        return $stat['dev'] . ":" . $stat['ino'];
    }

    /**
     * The identity (see fileIdentity()) of a directory that is NOT a link, or null when it is a
     * link, is gone, is not a directory or reports no inode. The tree walkers compare it before
     * every operation on the directory, so a link (or another directory) swapped into the
     * directory's place while they run is noticed instead of followed.
     *
     * @param string $dir Directory path
     * @return string|null
     */
    private static function walkableDirectoryIdentity(string $dir): ?string {
        if (self::isLinkOrJunction($dir)) {
            return null;
        }
        clearstatcache(true, $dir);
        if (!is_dir($dir)) {
            return null;
        }

        return self::fileIdentity($dir) ?? "no-inode";
    }

    /**
     * Compares two absolute paths as strings, case-insensitively on Windows.
     *
     * @param string $a First path
     * @param string $b Second path
     * @return bool
     */
    private static function isSamePath(string $a, string $b): bool {
        $a = rtrim(str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $a), DIRECTORY_SEPARATOR);
        $b = rtrim(str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $b), DIRECTORY_SEPARATOR);

        return DIRECTORY_SEPARATOR === "\\" ? strcasecmp($a, $b) === 0 : $a === $b;
    }

    /**
     * Returns normalized path information for an existing or future directory/file path.
     *
     * The existing part of the path is resolved with realpath() — so symlinks and junctions in it
     * are followed — and missing child directories or a missing final file are appended back to
     * that resolved base.
     *
     * $path is used VERBATIM: it is no longer trim()med. Leading/trailing whitespace is part of a
     * legal file name (" report.csv"; on POSIX also "x.dbf "), and trimming silently re-targeted
     * the call at a DIFFERENT file — which the delete/reset helpers built on this then destroyed.
     * Callers holding user or config input must trim it themselves. (On Windows, Win32 itself
     * ignores trailing spaces and dots, so "a.txt " still reaches "a.txt" there: that is the OS's
     * rule, not this method's.)
     *
     * @param string|null $path Directory or file path. NULL, "", a whitespace-only string or a
     *                          path containing a NUL byte yields the all-NULL result: a blank path
     *                          is NEVER taken to mean the current working directory ("0", on the
     *                          other hand, is an ordinary relative name).
     * @param bool $keepFile Treat the final segment as a file, even when it has no extension
     * @param bool $keepFileNotExists Treat the final segment as a file even when it does not exist
     * @param string|bool|null $createPath If true or octal string, creates the directory portion
     *                                     when missing. An octal string that is not a usable mode
     *                                     (see createDir()) means "do not create".
     *
     * @return array {
     *     @type string|null $dir    Resolved directory path with trailing separator
     *     @type string|null $file   File name, when the path points to or is configured as a file
     *     @type string|null $path   Resolved full path, including the file when present
     *     @type bool        $exists Whether the full path exists
     *     @type bool        $isDir  Whether the full path is an existing directory
     *     @type bool        $isFile Whether the full path is an existing file
     * }
     */
    public static function getPathInfo(
        ?string $path,
        bool $keepFile = false,
        bool $keepFileNotExists = false,
        string|bool|null $createPath = false
    ): array {
        $ret = [
            'dir' => null,
            'file' => null,
            'path' => null,
            'exists' => false,
            'isDir' => false,
            'isFile' => false,
        ];

        if(self::isUnusablePath($path)) {
            return $ret;
        }

        if($createPath === null || (!is_bool($createPath) && self::parseOctalMode($createPath) === null)) {
            $createPath = false;
        }

        $normalizedPath = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $path);
        $hasTrailingSeparator = str_ends_with($normalizedPath, DIRECTORY_SEPARATOR);
        $realPath = realpath($normalizedPath);
        $isExistingFile = $realPath !== false && is_file($realPath);
        $isExistingDirectory = $realPath !== false && is_dir($realPath);
        $looksLikeFile = !$hasTrailingSeparator && pathinfo($normalizedPath, PATHINFO_EXTENSION) !== "";
        $shouldKeepFile = $isExistingFile || (!$isExistingDirectory && ($keepFile || $keepFileNotExists || $looksLikeFile));

        if ($shouldKeepFile) {
            $ret['file'] = basename($normalizedPath);
            $directoryPath = dirname($normalizedPath);
        } else {
            $directoryPath = $normalizedPath;
        }

        if ($directoryPath === "" || $directoryPath === ".") {
            $directoryPath = getcwd();
        }

        $resolvedDirectory = self::resolvePath($directoryPath);

        if($createPath !== false && !is_dir($resolvedDirectory)) {
            self::createDir($resolvedDirectory, $createPath === true ? null : $createPath);
            $createdPath = realpath($resolvedDirectory);
            if ($createdPath !== false) {
                $resolvedDirectory = $createdPath;
            }
        }

        $ret['dir'] = self::ensureTrailingDirectorySeparator($resolvedDirectory);
        $ret['path'] = $ret['dir'] . ($ret['file'] !== null ? $ret['file'] : "");
        $ret['exists'] = file_exists($ret['path']);
        $ret['isDir'] = is_dir($ret['path']);
        $ret['isFile'] = is_file($ret['path']);

        return $ret;
    }

    /**
     * Creates a file in the system and writes content to it.
     *
     * Missing parent directories are created with createDir()'s default mode; $permissionMode is
     * a FILE mode and is never used as the directory mode (a file-shaped mode such as '0600'
     * would produce a directory with no execute bit, which nothing could traverse into). Call
     * createDir() first if the directory mode matters.
     *
     * NOT atomic: with $append = false the file is truncated when it is opened, so a failure
     * part-way leaves it truncated or partially written.
     *
     * @param string $filePath The path where the file should be created
     * @param string $content The content to write into the file
     * @param bool $append TRUE appends to the file; FALSE (default) TRUNCATES it before writing
     * @param string|null $permissionMode File mode as an octal string (e.g., '0600'). It is applied
     *                                    BEFORE any content is written — a new file is even created
     *                                    under a umask that already excludes the unrequested bits —
     *                                    so the content never exists on disk under a wider mode.
     *                                    NULL (default) means the permissions are LEFT ALONE: a new
     *                                    file keeps fopen()'s 0666 & ~umask (typically 0644,
     *                                    world-readable) and an existing file keeps its own mode.
     *                                    Pass an explicit mode for files holding secrets. Ignored on
     *                                    Windows beyond the read-only bit.
     *
     * @throws \Exception If $filePath is empty, does not resolve to a file path, its directory is
     *                    missing/uncreatable, the file cannot be opened, the requested
     *                    $permissionMode could not be applied (no content is written then — but
     *                    with $append = false an existing file has already been truncated by the
     *                    open), or the write/close fails — including a SHORT write (e.g. a full
     *                    disk), which fwrite() reports as a byte count rather than as false.
     * @throws \InvalidArgumentException If $permissionMode is given but is not a valid octal
     *                                   string, or is above 07777. It is rejected rather than
     *                                   silently falling back to the default mode, which would
     *                                   widen permissions on exactly the call that asked to
     *                                   restrict them.
     *
     * @ref https://chmodcommand.com/chmod-2777/
     */
    public static function writeFile(string $filePath, string $content = "", bool $append = false, ?string $permissionMode = null): void {
        if(trim($filePath) === '') {
            throw new \Exception("File path not provided for writing!");
        }

        // Parsed once and reused, so the guard and the chmod() can never disagree about the mode.
        $fileMode = null;
        if($permissionMode !== null) {
            $fileMode = self::parseOctalMode($permissionMode);
            if($fileMode === null) {
                throw new \InvalidArgumentException("Invalid permission mode provided for writing: '{$permissionMode}'!");
            }
        }

        $filePath = self::getPathInfo($filePath, keepFileNotExists: true, createPath: true);
        if(empty($filePath['path']) || ($filePath['file'] ?? '') === '') {
            throw new \Exception("Invalid file path provided for writing!");
        }

        if(empty($filePath['dir']) || !is_dir($filePath['dir'])) {
            throw new \Exception("Invalid directory path provided for file writing!");
        }

        $oldMask = null;
        if ($fileMode !== null) {
            // A NEW file is created with at most the requested bits, so there is no window in
            // which another local user can open() it under fopen()'s default 0644.
            $oldMask = umask((~$fileMode) & 0o777);
        }
        try {
            $file = @fopen($filePath['path'], $append ? "a" : "w");
        } finally {
            if ($oldMask !== null) {
                umask($oldMask);
            }
        }

        if($file === false) {
            throw new \Exception("Failed to open the file for writing!");
        }

        $error = null;
        // chmod BEFORE writing: the content must never land under a wider mode than requested.
        // The already-open handle keeps its write access even when the new mode is read-only.
        if ($fileMode !== null && !@chmod($filePath['path'], $fileMode)) {
            $error = new \Exception("Failed to apply permission mode '{$permissionMode}' to the file!");
        } else {
            $written = @fwrite($file, $content);
            if($written === false || $written !== strlen($content)) {
                $error = new \Exception("Failed to write to the file!");
            }
        }

        $closed = @fclose($file);
        if ($error !== null) {
            throw $error;
        }
        if (!$closed) {
            throw new \Exception("Failed to write to the file!");
        }
    }

    /**
     * Creates a uniquely named temporary file in the system temp directory and writes content to it.
     *
     * The file is NOT removed automatically — the caller owns its lifetime and must unlink() it.
     * If anything fails after the file was created, it is removed before the exception propagates.
     *
     * @param string $prefix Prefix for the generated file name. Advisory only: uniqueness comes
     *                       from tempnam(), and on Windows only the first 3 characters of the
     *                       prefix survive, so never parse the returned name to recover it.
     * @param string $content Initial content to write into the file upon creation
     * @param string|null $permissionMode File mode as an octal string (e.g., '0600'), applied
     *                                    before the content is written. NULL (default) leaves
     *                                    tempnam()'s own permissions in place — on POSIX that is
     *                                    already 0600 (owner-only); on Windows the file is
     *                                    readable by any account that can reach the temp
     *                                    directory. See writeFile().
     *
     * @return string The full path to the created temporary file
     * @throws \Exception If the temporary file could not be created, or if writing/chmod'ing it
     *                    failed (see writeFile()).
     * @throws \InvalidArgumentException If $permissionMode is not a valid octal string. Checked
     *                                   BEFORE the file is created, so nothing is left behind.
     */
    public static function createTempFile(string $prefix = "", string $content = "", ?string $permissionMode = null): string {
        if ($permissionMode !== null && self::parseOctalMode($permissionMode) === null) {
            throw new \InvalidArgumentException("Invalid permission mode provided for writing: '{$permissionMode}'!");
        }

        // tempnam() throws a ValueError on a NUL byte in the prefix.
        $file = @tempnam(sys_get_temp_dir(), uniqid(str_replace("\0", "", $prefix), true));
        if($file === false) {
            throw new \Exception("Unable to create temporary file!");
        }

        try {
            self::writeFile($file, $content, false, $permissionMode);
        } catch (\Throwable $e) {
            @unlink($file);
            throw $e;
        }

        return $file;
    }

    /**
     * Deletes everything inside $dir without following links, leaving $dir itself in place.
     *
     * Links (symlinks and Windows junctions) are removed as links. Their targets are never
     * entered, and nothing is ever resolved through realpath() — the old iterator unlink()ed
     * getRealPath(), i.e. the TARGET of a file symlink, anywhere on the filesystem.
     *
     * CONCURRENT CHANGES: every entry is addressed by its path, which the OS resolves again on
     * each call, so a subdirectory that is replaced by a link WHILE the walk runs (by someone
     * with write access to the tree) would redirect the remaining unlink()s to the link's target.
     * $dir's identity (not a link, same device + inode) is therefore re-checked before every
     * operation, which shrinks that window to the gap between the check and the call that follows
     * it; PHP has no openat()/unlinkat(), so it cannot be closed. See deleteFoldersRecursively().
     *
     * @param string $dir Existing directory, not itself a link
     * @return bool TRUE if every entry was removed; FALSE when something failed or $dir changed
     *              identity midway (the walk stops where it is)
     */
    private static function removeDirectoryContents(string $dir): bool {
        $dir = rtrim($dir, "\\/");
        $identity = self::walkableDirectoryIdentity($dir);
        if ($identity === null) {
            return false;
        }

        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }

        $success = true;
        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }

            if (self::walkableDirectoryIdentity($dir) !== $identity) {
                return false;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (self::isLinkOrJunction($path)) {
                $success = self::removeLink($path) && $success;
            } elseif (is_dir($path)) {
                $success = self::removeDirectoryContents($path) && @rmdir($path) && $success;
            } else {
                $success = @unlink($path) && $success;
            }
        }

        return $success;
    }

    /**
     * Recursively deletes a folder and all its contents.
     *
     * Links are NEVER followed. If $dir itself is a symlink or (on Windows) a directory junction,
     * only the link is removed and its target is left untouched — it used to be resolved first,
     * so deleting a link wiped the directory it pointed to. Links found inside the tree are
     * likewise removed as links, never walked.
     *
     * THREAT BOUNDARY: that guarantee is about the tree as it IS when each entry is examined.
     * Another user who can write to the tree while this runs can swap a subdirectory for a link
     * between the check and the delete (a TOCTOU race); the walk re-checks the directory's identity
     * before every operation and stops when it changed, which narrows the window to microseconds
     * but cannot close it in PHP. Do not run this with privileges over a tree that other users can
     * write to (a shared /tmp, an upload folder owned by another account); run it as the owner of
     * the tree, or on a directory only this process writes.
     *
     * @param string $dir Directory path to delete. A blank path is refused (it used to resolve to
     *                    the current working directory), and so is a filesystem root.
     * @return bool TRUE on success, FALSE on failure (including a partial delete, which is NOT
     *              rolled back). Never emits warnings.
     *
     * @ref https://stackoverflow.com/questions/3338123/how-do-i-recursively-delete-a-directory-and-its-entire-contents-files-sub-dir
     */
    public static function deleteFoldersRecursively(string $dir): bool {
        if (self::isUnusablePath($dir)) {
            return false;
        }

        $unresolved = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $dir);
        if (self::isLinkOrJunction($unresolved)) {
            return self::removeLink($unresolved);
        }

        $pathInfo = self::getPathInfo($dir);
        if (empty($pathInfo['path']) || !$pathInfo['isDir'] || self::isFilesystemRoot($pathInfo['path'])) {
            return false;
        }

        $target = rtrim($pathInfo['path'], "\\/");
        return self::removeDirectoryContents($target) && @rmdir($target);
    }

    /**
     * Empties a folder, creating it when it does not exist.
     *
     * The folder itself is KEPT: only its contents are removed, so its mode, owner, ACL and
     * identity survive. It used to be deleted and re-created with the DEFAULT mode, which silently
     * widened a 0700 folder to 0755. Links inside it are removed as links, never followed — with
     * the same threat boundary as deleteFoldersRecursively(): a tree other users can write to
     * concurrently is not a tree this can clean safely.
     *
     * @param string $dir Directory to be reset. Refused (FALSE, nothing touched) when blank, a
     *                    filesystem root, or itself a symlink/junction — emptying through a link
     *                    would destroy data outside the path the caller named.
     * @return bool TRUE on success, FALSE on failure (a partial clean-up is NOT rolled back)
     */
    public static function resetFolder(string $dir): bool {
        if (self::isUnusablePath($dir)) {
            return false;
        }

        $unresolved = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $dir);
        if (self::isLinkOrJunction($unresolved)) {
            return false;
        }

        $created = self::createDir($unresolved);
        if ($created === 2) {
            return true;
        }
        if ($created !== 1) {
            return false;
        }

        $pathInfo = self::getPathInfo($unresolved);
        if (empty($pathInfo['path']) || !$pathInfo['isDir'] || self::isFilesystemRoot($pathInfo['path'])) {
            return false;
        }

        return self::removeDirectoryContents($pathInfo['path']);
    }

    /**
     * Case-converts a single file name.
     *
     * @param string $name File name
     * @param bool $toUpper TRUE for upper case
     * @return string
     */
    private static function changeNameCase(string $name, bool $toUpper): string {
        // mb_* would replace every invalid byte with '?', inventing a different (and on Windows
        // illegal) name; a name that is not UTF-8 only has its ASCII letters converted.
        if (!mb_check_encoding($name, 'UTF-8')) {
            return $toUpper ? strtoupper($name) : strtolower($name);
        }

        return $toUpper ? mb_strtoupper($name, 'UTF-8') : mb_strtolower($name, 'UTF-8');
    }

    /**
     * Renames the entries of one directory (children first), refusing any rename onto an
     * existing, different entry.
     *
     * @param string $dir Directory
     * @param bool $toUpper TRUE for upper case
     * @return bool
     */
    private static function standardizeCaseIn(string $dir, bool $toUpper): bool {
        $entries = @scandir($dir);
        if ($entries === false) {
            return false;
        }
        $entries = array_values(array_diff($entries, [".", ".."]));

        $success = true;
        foreach ($entries as $entry) {
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !self::isLinkOrJunction($path)) {
                $success = self::standardizeCaseIn($path, $toUpper) && $success;
            }
        }

        // The exact names currently present in $dir.
        $taken = array_fill_keys($entries, true);
        foreach ($entries as $entry) {
            $entry = (string) $entry;
            $newName = self::changeNameCase($entry, $toUpper);
            if ($newName === $entry) {
                continue;
            }

            $oldPath = $dir . DIRECTORY_SEPARATOR . $entry;
            $newPath = $dir . DIRECTORY_SEPARATOR . $newName;

            // rename() REPLACES an existing destination (MoveFileEx(REPLACE_EXISTING) on Windows),
            // so "A.txt" + "a.txt" on a case-sensitive filesystem — or "\u{212A}.txt" (Kelvin) +
            // "k.txt" on ANY filesystem — used to silently destroy one of the two files. A target
            // that exists as a different entry (another inode) is a collision; a case-insensitive
            // filesystem answering for the SAME entry (same inode) is not.
            clearstatcache(true, $newPath);
            if (
                isset($taken[$newName]) ||
                (file_exists($newPath) && @fileinode($newPath) !== @fileinode($oldPath))
            ) {
                $success = false;
                continue;
            }

            if (!@rename($oldPath, $newPath)) {
                $success = false;
                continue;
            }

            unset($taken[$entry]);
            $taken[$newName] = true;
        }

        return $success;
    }

    /**
     * Renames all files and folders within a directory to either uppercase or lowercase.
     *
     * Renaming is done children-first, so a renamed parent never invalidates a pending child path.
     * An entry whose new name is already taken by a DIFFERENT entry is left alone and reported as
     * a failure — it is never renamed over it. Links are renamed as links, never walked into.
     *
     * @param string $dir The directory to process
     * @param bool $toUpper If true, converts names to UPPERCASE; if false, to lowercase
     * @return bool TRUE if every entry was renamed (or already had the target case), FALSE if
     *              $dir is not an existing directory, or if any single rename failed or was
     *              refused as a collision — the walk still continues, so a FALSE means "at least
     *              one failed", not "nothing changed". Partial renames are NOT rolled back.
     *
     * @ref https://stackoverflow.com/questions/32173320/php-rename-all-files-to-lower-case-in-a-directory-recursively
     */
    public static function standardizeFilesCaseRecursive(string $dir, bool $toUpper = false): bool {
        $pathInfo = self::getPathInfo($dir);
        if (empty($pathInfo['path']) || !$pathInfo['isDir']) {
            return false;
        }

        return self::standardizeCaseIn(rtrim($pathInfo['path'], "\\/"), $toUpper);
    }

    /**
     * Reads a file and extracts key-value pairs in the .env format.
     *
     * Blank lines, "#" comments and lines without "=" are skipped; keys and values are trimmed; a
     * later duplicate key wins. A value wrapped in ONE matching pair of quotes ("x y" or 'x y') is
     * unquoted — without that, a standard `DB_PASS="hunter2"` came back as the 9-character string
     * `"hunter2"`, quotes included. No escape sequences or inline comments are interpreted. A
     * leading UTF-8 BOM is ignored. LF, CRLF and CR line endings are all accepted.
     *
     * @param string $filePath Path to the file
     * @return array Associative array of variables found in the file; [] when the file is missing,
     *               is not a regular file or cannot be read (never a warning).
     */
    public static function parseEnvFile(string $filePath): array {
        $result = [];
        if (self::isUnusablePath($filePath) || !is_file($filePath)) {
            return $result;
        }

        $content = @file_get_contents($filePath);
        if ($content === false) {
            return $result;
        }
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $content = substr($content, 3);
        }

        foreach (preg_split('/\r\n|\n|\r/', $content) as $line) {
            $key = self::envLineKey($line);
            if ($key === null) {
                continue;
            }

            $value = trim(explode("=", trim($line), 2)[1]);
            if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && str_ends_with($value, $value[0])) {
                $value = substr($value, 1, -1);
            }

            $result[$key] = $value;
        }

        return $result;
    }

    /**
     * Returns the key a .env line assigns, or NULL for a blank, comment or non-assignment line.
     *
     * @param string $line One line, without its terminator
     * @return string|null
     */
    private static function envLineKey(string $line): ?string {
        $trimmed = trim($line);
        if ($trimmed === '' || $trimmed[0] === '#' || $trimmed[0] === '=' || !str_contains($trimmed, '=')) {
            return null;
        }

        return trim(explode("=", $trimmed, 2)[0]);
    }

    /**
     * Updates or appends key-value variables in a .env-style file, in place.
     *
     * Every line the caller did not name is preserved byte-for-byte — line endings included, and
     * whether or not the file ends with one: it used to be rewritten with PHP_EOL throughout, so
     * one update on Windows converted a whole LF file to CRLF (and every value read by a POSIX
     * shell or Docker then ended in "\r"). EVERY line assigning a named key is rewritten as
     * "KEY=value" at its position (only rewriting the first one left a later duplicate in charge,
     * so the update silently had no effect); a key not present is appended at the end, using the
     * file's own line ending ("\n" when it has none). The file is created when it does not exist.
     *
     * The read-modify-write runs under an exclusive flock(), so two concurrent updates do not lose
     * each other's keys (advisory on POSIX: it only guards against other flock() users). It is not
     * atomic: a crash mid-write can leave the file truncated.
     *
     * Values are written verbatim — no quoting or escaping — so a value with spaces or '#' may not
     * survive a stricter .env parser, and one that starts and ends with the same quote character
     * comes back from parseEnvFile() without them.
     *
     * @param string $filePath Path to the file to be updated
     * @param array $variables Variables to write, as key => value. Keys must be non-empty, contain
     *                         no whitespace or '=', and not start with '#'. Values must be scalar,
     *                         NULL (written as "") or \Stringable (floats/bools are cast: TRUE is
     *                         "1", FALSE is ""), and may not contain CR, LF or NUL. An empty array
     *                         leaves the file untouched.
     * @return bool TRUE if the file holds the requested values, FALSE if it could not be opened,
     *              locked, read or written.
     * @throws \InvalidArgumentException If a key or value is invalid (see $variables). A newline in
     *                                   a value used to be written verbatim, which let whoever
     *                                   controlled the value inject extra variables — say
     *                                   "x\nADMIN_PASSWORD=..." — into the file. Nothing is
     *                                   written when this is thrown.
     * @throws \Exception If the file does not exist and could not be created (see writeFile()).
     */
    public static function updateEnvFile(string $filePath, array $variables): bool {
        $normalized = [];
        foreach ($variables as $key => $value) {
            $key = (string) $key;
            if (preg_match('/^[^\s=#\x00][^\s=\x00]*\z/', $key) !== 1) {
                throw new \InvalidArgumentException("Invalid .env key '{$key}'!");
            }
            if ($value !== null && !is_scalar($value) && !$value instanceof \Stringable) {
                throw new \InvalidArgumentException("The .env value for '{$key}' must be a scalar or \\Stringable, " . get_debug_type($value) . " given!");
            }

            $value = (string) $value;
            if (strpbrk($value, "\r\n\0") !== false) {
                throw new \InvalidArgumentException("The .env value for '{$key}' must not contain a line break or NUL byte!");
            }

            $normalized[$key] = $value;
        }

        // Create the file when absent — but NEVER touch an existing one here: writeFile() opens "w"
        // and would truncate it before the read below.
        if (!file_exists($filePath)) {
            self::writeFile($filePath);
        }

        $handle = @fopen($filePath, 'c+');
        if ($handle === false) {
            return false;
        }

        try {
            if (!flock($handle, LOCK_EX)) {
                return false;
            }

            $content = stream_get_contents($handle);
            if ($content === false) {
                return false;
            }

            $newContent = self::rewriteEnvContent($content, $normalized);
            if ($newContent === $content) {
                return true;
            }

            if (!ftruncate($handle, 0) || !rewind($handle)) {
                return false;
            }

            $written = fwrite($handle, $newContent);
            return $written === strlen($newContent) && fflush($handle);
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    /**
     * Rewrites .env content with $variables applied; see updateEnvFile().
     *
     * @param string $content Current file content
     * @param array<string, string> $variables Validated variables
     * @return string
     */
    private static function rewriteEnvContent(string $content, array $variables): string {
        $bom = "";
        if (str_starts_with($content, "\xEF\xBB\xBF")) {
            $bom = "\xEF\xBB\xBF";
            $content = substr($content, 3);
        }

        // Each line keeps its OWN terminator: [line0, eol0, line1, eol1, ..., lastLine].
        $parts = preg_split('/(\r\n|\n|\r)/', $content, -1, PREG_SPLIT_DELIM_CAPTURE);
        $eol = $parts[1] ?? "\n";

        $output = "";
        $seen = [];
        $count = count($parts);
        for ($i = 0; $i < $count; $i += 2) {
            $line = $parts[$i];
            $key = self::envLineKey($line);
            if ($key !== null && array_key_exists($key, $variables)) {
                $line = $key . "=" . $variables[$key];
                $seen[$key] = true;
            }

            $output .= $line . ($parts[$i + 1] ?? "");
        }

        $missing = array_diff_key($variables, $seen);
        if (!empty($missing)) {
            if ($output !== "" && preg_match('/[\r\n]\z/', $output) !== 1) {
                $output .= $eol;
            }
            foreach ($missing as $key => $value) {
                $output .= $key . "=" . $value . $eol;
            }
        }

        return $bom . $output;
    }

    /**
     * Validates and normalizes a path read from inside an archive, relative to the extraction root.
     *
     * @param string $relativePath Entry path in host separators
     * @return string|null The normalized path ('.' and empty segments dropped), or NULL if the
     *                     entry must not be written: it is empty, holds a NUL byte, is absolute or
     *                     drive-qualified, contains a ".." segment, or — on Windows — a segment
     *                     that is not a plain file name (see isPortableWindowsName()).
     */
    private static function normalizeArchivePath(string $relativePath): ?string {
        if ($relativePath === "" || str_contains($relativePath, "\0")) {
            return null;
        }
        if (str_starts_with($relativePath, DIRECTORY_SEPARATOR) || preg_match('/^[A-Za-z]:/', $relativePath) === 1) {
            return null;
        }

        $segments = [];
        foreach (explode(DIRECTORY_SEPARATOR, $relativePath) as $segment) {
            if ($segment === "" || $segment === ".") {
                continue;
            }
            if ($segment === ".." || (DIRECTORY_SEPARATOR === "\\" && !self::isPortableWindowsName($segment))) {
                return null;
            }
            $segments[] = $segment;
        }

        return empty($segments) ? null : implode(DIRECTORY_SEPARATOR, $segments);
    }

    /**
     * Returns whether a single path segment names an ordinary file on Windows.
     *
     * ':' selects an NTFS alternate data stream ("report:hidden" writes a hidden stream of
     * "report"), control characters and <>"|?* are illegal, Win32 silently strips a trailing '.'
     * or ' ' (so "a." aliases "a"), and a device name (CON, NUL, COM1...) addresses a DEVICE, even
     * with an extension — an entry named "NUL" was "extracted" into the void and reported TRUE.
     *
     * @param string $segment One path segment
     * @return bool
     */
    private static function isPortableWindowsName(string $segment): bool {
        if (preg_match('/[\x00-\x1F<>:"|?*]/', $segment) === 1) {
            return false;
        }
        if (str_ends_with($segment, ".") || str_ends_with($segment, " ")) {
            return false;
        }

        return preg_match('/^(CON|PRN|AUX|NUL|COM[0-9]|LPT[0-9])(\..*)?$/i', $segment) !== 1;
    }

    /**
     * Creates (as needed) the directory $relativeDirectory beneath $root, refusing to pass
     * through any symlink or junction, and confirms the result still resolves inside $root.
     *
     * Checked level by level BEFORE anything is created, so a link planted inside the
     * destination can neither receive the extracted file nor get directories created at its
     * target.
     *
     * @param string $root Resolved extraction root (no trailing separator)
     * @param string $relativeDirectory Normalized relative directory ("" for $root itself)
     * @param string|null $permissionMode Directory mode for createDir()
     * @param array<string, string> $verified Cache of directories already checked, BY REFERENCE
     * @return string|null The directory path, or NULL if it is unsafe or could not be created
     */
    private static function ensureDirectoryInside(string $root, string $relativeDirectory, ?string $permissionMode, array &$verified): ?string {
        if (isset($verified[$relativeDirectory])) {
            return $verified[$relativeDirectory];
        }

        $current = self::walkDirectoryInside($root, $relativeDirectory, $permissionMode, true);
        if ($current === null) {
            return null;
        }

        // The cache only saves the createDir() calls: extractZipEntry() re-walks the path (with
        // directoryIsStillInside()) right before it writes, so the trust never outlives a check.
        return $verified[$relativeDirectory] = $current;
    }

    /**
     * Re-checks, with no cache, that $root/$relativeDirectory still has no link in it and still
     * resolves inside $root — right before a write, so a link swapped in after the directory was
     * first verified is caught.
     *
     * @param string $root Resolved extraction root
     * @param string $relativeDirectory Normalized relative directory ("" for $root itself)
     * @return bool
     */
    private static function directoryIsStillInside(string $root, string $relativeDirectory): bool {
        return self::walkDirectoryInside($root, $relativeDirectory, null, false) !== null;
    }

    /**
     * Walks $root/$relativeDirectory segment by segment, refusing a link at any level and (with
     * $create) creating what is missing, then checks the result resolves inside $root.
     *
     * @return string|null The directory path, or NULL when it is unsafe, missing or could not be created
     */
    private static function walkDirectoryInside(string $root, string $relativeDirectory, ?string $permissionMode, bool $create): ?string {
        $current = $root;
        foreach (explode(DIRECTORY_SEPARATOR, $relativeDirectory) as $segment) {
            if ($segment === "") {
                continue;
            }

            $current .= DIRECTORY_SEPARATOR . $segment;
            if (self::isLinkOrJunction($current)) {
                return null;
            }
            clearstatcache(true, $current);
            if (!is_dir($current) && (!$create || self::createDir($current, $permissionMode, false) < 0)) {
                return null;
            }
        }

        $check = $current === "" ? DIRECTORY_SEPARATOR : $current;
        if (!self::isPathInside($check, $root === "" ? DIRECTORY_SEPARATOR : $root)) {
            return null;
        }

        return $current;
    }

    /**
     * Streams one archive entry to $root/$relativePath, verifying its size and CRC-32.
     *
     * The data goes to a temporary sibling first and is renamed into place only once verified, so
     * a corrupt entry never replaces an existing file, and rename() replaces a link at the target
     * instead of writing through it. Streaming keeps memory flat however large the entry is.
     *
     * @param \ZipArchive $zip Open archive
     * @param int $index Entry index
     * @param string $root Resolved extraction root
     * @param string $relativePath Normalized relative path of the file
     * @param string|null $permissionMode Directory mode for missing parents
     * @param array<string, string> $verified Directory cache, BY REFERENCE
     * @return bool
     */
    private static function extractZipEntry(\ZipArchive $zip, int $index, string $root, string $relativePath, ?string $permissionMode, array &$verified): bool {
        $slash = strrpos($relativePath, DIRECTORY_SEPARATOR);
        $leaf = $slash === false ? $relativePath : substr($relativePath, $slash + 1);
        $relativeDirectory = $slash === false ? "" : substr($relativePath, 0, $slash);
        $parent = self::ensureDirectoryInside($root, $relativeDirectory, $permissionMode, $verified);
        if ($parent === null) {
            return false;
        }

        $target = $parent . DIRECTORY_SEPARATOR . $leaf;
        if (self::isLinkOrJunction($target) || is_dir($target)) {
            return false;
        }

        $stat = $zip->statIndex($index);
        $input = $stat === false ? false : $zip->getStreamIndex($index);
        if ($input === false) {
            return false;
        }

        // Re-walked WITHOUT the cache right before the temporary file is created (and again before
        // the rename below): the directory was verified once, possibly long ago in a large
        // extraction, and a link swapped into it since would receive the write.
        if (!self::directoryIsStillInside($root, $relativeDirectory)) {
            fclose($input);
            return false;
        }

        $temporary = $parent . DIRECTORY_SEPARATOR . ".unzip-" . bin2hex(random_bytes(8)) . ".part";
        $output = @fopen($temporary, "xb");
        if ($output === false) {
            fclose($input);
            return false;
        }

        $hash = hash_init("crc32b");
        $size = 0;
        $success = true;
        while (!feof($input)) {
            $chunk = @fread($input, 65536);
            if ($chunk === false) {
                $success = false;
                break;
            }
            if ($chunk === "") {
                break;
            }

            hash_update($hash, $chunk);
            $size += strlen($chunk);
            if (@fwrite($output, $chunk) !== strlen($chunk)) {
                $success = false;
                break;
            }
        }
        @fclose($input);
        $success = @fclose($output) && $success;

        // libzip does NOT verify the CRC on this path: a corrupted entry streams out "successfully"
        // (so did getFromIndex(), which this replaced), and was written and reported as TRUE.
        $success = $success
            && $size === (int) $stat['size']
            && hash_final($hash) === sprintf('%08x', $stat['crc']);

        if (
            !$success
            || !self::directoryIsStillInside($root, $relativeDirectory)
            || self::isLinkOrJunction($target)
            || !@rename($temporary, $target)
        ) {
            @unlink($temporary);
            return false;
        }

        return true;
    }

    /**
     * Function unzipFile.
     * Unzips selected entries from a .zip archive into a destination directory.
     *
     * Each requested name is matched against the archive as EITHER an exact entry (a single file,
     * extracted to the destination root under its base name) OR a directory prefix (every entry
     * beneath it is extracted, keeping the structure below that prefix). Matching is
     * case-sensitive and separator-normalised. Existing files at a target are REPLACED.
     *
     * An entry is refused, reported as a failure and never written when its path escapes the
     * destination root ("..", absolute or drive-qualified), when it would be written THROUGH a
     * symlink or junction already present in the destination, when (on Windows) a segment is not
     * a plain file name — a device name such as NUL, an alternate data stream "a:b", a trailing
     * '.' or ' ' — or when two different entries would land on the same file: two exact names
     * with the same base name, two names that differ only in case on a case-insensitive volume
     * (ASCII or not: "Ä.txt" and "ä.txt"), or an 8.3 short name ("LONGFI~1.TXT") of a long name
     * already extracted — the file written is identified by device + inode, not by its spelling.
     * Each entry is streamed and verified against its recorded size and CRC-32; a corrupt entry is
     * a failure and never replaces an existing file.
     *
     * THREAT BOUNDARY: the link checks describe the destination as it is when each entry is
     * written (the path is re-verified, uncached, right before every write and rename). Another
     * user who can write to the destination while the extraction runs can still swap a directory
     * for a link in the microseconds between a check and the write — PHP cannot open relative to
     * a directory handle. Extract into a directory other users cannot write to.
     *
     * @param string $zipPath Path to the .zip file
     * @param string $destinationPath Directory the entries are extracted into; created if missing.
     *                                Always treated as a directory, even when its name has a dot
     *                                ("release-1.2" used to be taken for a FILE, and the call
     *                                failed).
     * @param string|array $filesToExtract Entry name, or list of entry names, to extract: a file
     *                                     ("data/report.csv") or a directory ("data"). An empty
     *                                     value extracts nothing and is reported as success.
     * @param string|null $permissionMode Octal directory mode used when creating the destination
     *                                    and any sub-directories (see createDir()); NOT applied to
     *                                    extracted files, which land with the default permissions.
     *                                    A malformed mode fails the whole call (FALSE) up front.
     *
     * @return bool|array TRUE when every requested name was found and extracted. FALSE when the
     *                    zip extension is missing, $permissionMode is malformed, the
     *                    archive/destination is unusable, the archive could not be opened, or an
     *                    unexpected error aborted the run. Otherwise a non-empty list<string> of
     *                    the names that FAILED: archive entries that could not be written or were
     *                    refused (see above), plus requested names that matched no entry at all.
     *
     * @ref https://stackoverflow.com/questions/1334613/how-to-recursively-zip-a-directory-in-php
     */
    public static function unzipFile(
        string $zipPath,
        string $destinationPath,
        string|array $filesToExtract,
        ?string $permissionMode = null
    ): bool|array {
        if (!extension_loaded('zip')) {
            return false;
        }
        if ($permissionMode !== null && self::parseOctalMode($permissionMode) === null) {
            return false;
        }
        if (self::isUnusablePath($destinationPath) || self::isUnusablePath($zipPath)) {
            return false;
        }

        $destinationPathInfo = self::getPathInfo(
            rtrim($destinationPath, "\\/") . DIRECTORY_SEPARATOR,
            createPath: $permissionMode ?? true
        );
        $zipPathInfo = self::getPathInfo($zipPath, keepFile: true);

        if (
            empty($destinationPathInfo['path']) ||
            empty($zipPathInfo['path']) ||
            !$destinationPathInfo['isDir'] ||
            !$zipPathInfo['isFile']
        ) return false;

        $destinationRoot = realpath($destinationPathInfo['path']);
        if ($destinationRoot === false) {
            return false;
        }
        $destinationRoot = rtrim($destinationRoot, "\\/");

        if (empty($filesToExtract)) return true;
        if (!is_array($filesToExtract)) {
            $filesToExtract = [$filesToExtract];
        }

        // Requested names, normalised once and WITHOUT a trailing separator, so a file name can
        // match its entry exactly.
        $needles = [];
        foreach ($filesToExtract as $file) {
            $file = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, (string) $file);
            $file = rtrim($file, DIRECTORY_SEPARATOR);
            if ($file !== "") {
                $needles[$file] = false; // value = "matched at least one entry"
            }
        }
        if (empty($needles)) return true;

        $zip = new \ZipArchive();
        if ($zip->open($zipPathInfo['path'], \ZipArchive::RDONLY) !== true) {
            return false;
        }

        $errors = [];
        $written = [];  // target key => index of the entry that produced it
        $identities = []; // "dev:ino" of every file this call wrote => index of the entry
        $verified = []; // directory cache for ensureDirectoryInside()
        $aborted = false;
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rawName = $zip->getNameIndex($i);
                if ($rawName === false) {
                    $errors[$i] = "#{$i}";
                    continue;
                }

                $filename = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $rawName);
                $isDirectoryEntry = str_ends_with($filename, DIRECTORY_SEPARATOR);

                foreach ($needles AS $fileToExtract => $matched) {
                    $fileToExtract = (string) $fileToExtract;

                    if (rtrim($filename, DIRECTORY_SEPARATOR) === $fileToExtract) {
                        // Exact entry match: extract it flat, under its own base name.
                        $needles[$fileToExtract] = true;
                        if ($isDirectoryEntry) {
                            // The needle names a directory entry; its children carry the payload.
                            continue;
                        }
                        $slash = strrpos($filename, DIRECTORY_SEPARATOR);
                        $relativePath = $slash === false ? $filename : substr($filename, $slash + 1);
                    } elseif (str_starts_with($filename, $fileToExtract . DIRECTORY_SEPARATOR)) {
                        // Directory-prefix match: keep the structure below the prefix.
                        $needles[$fileToExtract] = true;
                        $relativePath = substr($filename, strlen($fileToExtract . DIRECTORY_SEPARATOR));
                        if (rtrim($relativePath, DIRECTORY_SEPARATOR) === "") {
                            continue;
                        }
                    } else {
                        continue;
                    }

                    $relativePath = self::normalizeArchivePath($relativePath);
                    if ($relativePath === null) {
                        $errors[$i] = $filename;
                        continue;
                    }

                    if ($isDirectoryEntry) {
                        if (self::ensureDirectoryInside($destinationRoot, $relativePath, $permissionMode, $verified) === null) {
                            $errors[$i] = $filename;
                        }
                        continue;
                    }

                    $targetKey = DIRECTORY_SEPARATOR === "\\" ? strtolower($relativePath) : $relativePath;
                    if (isset($written[$targetKey])) {
                        // The same entry reached twice through two needles is fine; a DIFFERENT
                        // entry landing on the same file would silently replace the first.
                        if ($written[$targetKey] !== $i) {
                            $errors[$i] = $filename;
                        }
                        continue;
                    }

                    // Spellings the string comparison above cannot see — a non-ASCII case variant
                    // on a case-insensitive volume, an 8.3 short name — still land on ONE file:
                    // a target that already IS a file this call wrote is a collision too.
                    $target = $destinationRoot . DIRECTORY_SEPARATOR . $relativePath;
                    $existing = (is_file($target) && !is_link($target)) ? self::fileIdentity($target) : null;
                    if ($existing !== null && isset($identities[$existing]) && $identities[$existing] !== $i) {
                        $errors[$i] = $filename;
                        continue;
                    }

                    if (self::extractZipEntry($zip, $i, $destinationRoot, $relativePath, $permissionMode, $verified)) {
                        $written[$targetKey] = $i;
                        $identity = self::fileIdentity($target);
                        if ($identity !== null) {
                            $identities[$identity] = $i;
                        }
                    } else {
                        $errors[$i] = $filename;
                    }
                }
            }
        } catch (\Throwable) {
            // Swallowing this used to fall through to `return true` — a silent success on a
            // half-extracted archive.
            $aborted = true;
        } finally {
            try {
                $zip->close();
            } catch (\Throwable) {
            }
        }

        if ($aborted) {
            return false;
        }

        // A needle that matched no entry is a failure: the caller asked for something the archive
        // does not contain, and must not be told the extraction succeeded.
        foreach ($needles as $needle => $matched) {
            if (!$matched) {
                $errors[] = (string) $needle;
            }
        }

        return empty($errors) ? true : array_values($errors);
    }

    /**
     * Collects the entries beneath $dir, depth-first, without following links.
     *
     * @param string $dir Directory (no trailing separator)
     * @param array $results Accumulator, BY REFERENCE
     * @return void
     */
    private static function collectDirectoryContents(string $dir, array &$results): void {
        $entries = @scandir($dir);
        if ($entries === false) {
            return;
        }

        foreach ($entries as $entry) {
            if ($entry === "." || $entry === "..") {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            $results[] = $path;

            if (is_dir($path) && !self::isLinkOrJunction($path)) {
                self::collectDirectoryContents($path, $results);
            }
        }
    }

    /**
     * Recursively retrieves all files and subdirectories within a given directory.
     *
     * Paths are the resolved $directory plus the on-disk names beneath it. Symlinks and (on
     * Windows) junctions are LISTED at their own location but never descended into and never
     * resolved: each entry used to go through realpath(), so a link was reported as its TARGET —
     * a path outside $directory — and walked, which leaked out-of-tree files into zipDirectory()
     * and recursed forever on a link to an ancestor. Unreadable directories are skipped silently.
     *
     * @param string $directory The directory to scan. A blank path, a missing path or a FILE
     *                          yields $results unchanged (a blank one used to resolve to the
     *                          current working directory, and a file raised warnings).
     * @param array $results Recursive accumulator of discovered paths
     * @return array List of full paths for files and subdirectories
     *
     * @ref https://stackoverflow.com/questions/24783862/list-all-the-files-and-folders-in-a-directory-with-php-recursive-function
     */
    public static function getDirectoryContents(string $directory, array &$results = []): array {
        if (self::isUnusablePath($directory)) {
            return $results;
        }

        $root = realpath($directory);
        if ($root === false || !is_dir($root)) {
            return $results;
        }

        self::collectDirectoryContents(rtrim($root, "\\/"), $results);
        return $results;
    }

    /**
     * Largest file writeZipArchive() reads into memory itself (so that what it checked is what it
     * archives), and the most it buffers per archive; bigger or later files go through
     * ZipArchive::addFile(), which libzip reads from the PATH when the archive is closed.
     *
     * @var int
     */
    private const ZIP_BUFFERED_FILE_BYTES = 4194304;   // 4 MiB
    private const ZIP_BUFFERED_TOTAL_BYTES = 33554432; // 32 MiB

    /**
     * Writes a zip archive through a temporary sibling, replacing $outputFile only on success.
     *
     * ZipArchive::CREATE alone OPENS an existing archive and adds to it, so re-running a backup to
     * the same path kept every entry since deleted from the source. The archive is now always built
     * fresh, and a failure leaves any previous archive at $outputFile untouched.
     *
     * WHAT IS CHECKED IS WHAT IS ARCHIVED, as far as PHP allows. ZipArchive::addFile() records a
     * path and libzip only opens it at close(), long after the caller's link check: a file swapped
     * for a symlink in between (by someone who can write to the source tree) was read through the
     * link and its target archived. Each source is therefore opened here, verified to be a regular
     * file and the same inode the directory entry names, and — up to ZIP_BUFFERED_FILE_BYTES per
     * file and ZIP_BUFFERED_TOTAL_BYTES per archive, to bound memory — its CONTENT is handed to
     * libzip (addFromString). Larger files use addFile() with FL_OPEN_FILE_NOW where PHP has it
     * (8.3+), so libzip opens them immediately; on older PHP the window between this check and
     * libzip's open remains for those files only. See zipDirectory() for the threat boundary.
     *
     * @param string $outputFile Final archive path
     * @param array<string, string> $files Entry name => source file
     * @param array<string, true> $directories Directory entry names (ending in '/')
     * @return bool TRUE only when the archive is on disk at $outputFile
     */
    private static function writeZipArchive(string $outputFile, array $files, array $directories): bool {
        if (empty($files) && empty($directories)) {
            // libzip writes nothing for an archive without entries.
            return false;
        }

        $temporary = $outputFile . "." . bin2hex(random_bytes(6)) . ".tmp";
        $zip = new \ZipArchive();
        if ($zip->open($temporary, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
            return false;
        }

        $success = true;
        foreach ($directories as $name => $unused) {
            $success = $zip->addEmptyDir((string) $name) && $success;
        }

        $buffered = 0;
        $addFileFlags = \ZipArchive::FL_OVERWRITE | (defined('ZipArchive::FL_OPEN_FILE_NOW') ? \ZipArchive::FL_OPEN_FILE_NOW : 0);
        foreach ($files as $name => $source) {
            $fp = self::openRegularFileForArchive($source);
            if ($fp === null) {
                $success = false;
                break;
            }

            $size = (int) (fstat($fp)['size'] ?? -1);
            if ($size >= 0 && $size <= self::ZIP_BUFFERED_FILE_BYTES && $buffered + $size <= self::ZIP_BUFFERED_TOTAL_BYTES) {
                $content = stream_get_contents($fp);
                fclose($fp);
                if ($content === false || strlen($content) !== $size) {
                    $success = false;
                    break;
                }
                $buffered += $size;
                $success = $zip->addFromString((string) $name, $content) && $success;
            } else {
                fclose($fp);
                $success = $zip->addFile($source, (string) $name, 0, 0, $addFileFlags) && $success;
            }
        }
        if (!$success) {
            $zip->unchangeAll();
        }

        // close() is where libzip reads the sources and writes; an unreadable source fails here.
        $closed = @$zip->close();
        if (!$success || !$closed || !is_file($temporary) || !@rename($temporary, $outputFile)) {
            @unlink($temporary);
            return false;
        }

        return true;
    }

    /**
     * Opens $source for the archive writer: a regular file, not a link, and — after the open —
     * the very inode the directory entry names, so a link swapped in between is not read through.
     *
     * @param string $source Path listed by zipDirectory()/zipMultipleFiles()
     * @return resource|null Handle opened for reading, or null when the file must not be archived
     */
    private static function openRegularFileForArchive(string $source) {
        clearstatcache(true, $source);
        $entry = @lstat($source);
        if ($entry === false || is_link($source) || !is_file($source) || self::isLinkOrJunction($source)) {
            return null;
        }

        $fp = @fopen($source, "rb");
        if ($fp === false) {
            return null;
        }

        $opened = @fstat($fp);
        if (
            $opened === false
            || (!empty($entry['ino']) && !empty($opened['ino']) && ($entry['ino'] !== $opened['ino'] || $entry['dev'] !== $opened['dev']))
        ) {
            fclose($fp);
            return null;
        }

        return $fp;
    }

    /**
     * Compresses a folder into a .zip file, with the option to include or exclude the root folder.
     *
     * The archive is built FRESH: an existing archive at the output path is replaced (only once the
     * new one is complete), never appended to. Symlinks and junctions inside $source are SKIPPED —
     * following them put files from outside $source into the archive — and so is the output
     * archive itself when it lives inside $source. A link passed AS $source is followed.
     *
     * THREAT BOUNDARY: "skipped" describes the tree as it is when it is listed and when each file
     * is opened for the archive (see writeZipArchive(): small files are read right after that
     * check; large ones are opened by libzip at once on PHP 8.3+, or at close() on older PHP).
     * Another user who can write to $source while this runs can still, in that window, replace a
     * listed file with a link to something outside the tree and have it archived. Zip only trees
     * other users cannot write to concurrently.
     *
     * @param string $source Directory path to be zipped. A filesystem root is refused.
     * @param string $outputPath Destination path for the .zip file. A directory (or a path without
     *                           an extension, which is CREATED as a directory) produces
     *                           "<outputPath>/<source-basename>.zip"; a file path has its
     *                           extension replaced with ".zip".
     * @param string|null $permissionMode Octal DIRECTORY mode used if the output directory must be
     *                                    created (see createDir()); never applied to the .zip file
     * @param bool $contentOnly If true, only the contents of the folder will be zipped (not the folder itself)
     *
     * @return bool TRUE only when the archive was actually written to disk. FALSE when the zip
     *              extension is missing, $source is not an existing directory, the output
     *              directory is unusable, an entry could not be added/read, or there was nothing
     *              to write — notably zipping an EMPTY directory with $contentOnly=true produces no
     *              archive and returns FALSE, because libzip discards an archive with no entries.
     *              FALSE never leaves a new or modified archive behind.
     *
     * @ref https://www.php.net/manual/en/class.ziparchive.php
     */
    public static function zipDirectory(
        string $source,
        string $outputPath,
        ?string $permissionMode = null,
        bool $contentOnly = false
    ): bool {
        if (!extension_loaded('zip') || self::isUnusablePath($source)) {
            return false;
        }

        $source = realpath($source);
        if ($source === false || !is_dir($source) || self::isFilesystemRoot($source)) return false;
        $source = rtrim($source, "\\/");
        $rootName = basename($source);

        $outputPathInfo = self::getPathInfo($outputPath, createPath: $permissionMode ?? true);
        if (empty($outputPathInfo['path']) || empty($outputPathInfo['dir']) || !is_dir($outputPathInfo['dir'])) return false;

        if (($outputPathInfo['file'] ?? '') === '') {
            $outputFile = $outputPathInfo['path'] . $rootName . ".zip";
        } else {
            $outputFile = self::getZipName($outputPathInfo['path']);
        }

        // ZIP entry names always use '/' (APPNOTE 4.4.17.1), whatever the host separator.
        $prefix = $contentOnly ? "" : $rootName . "/";
        $files = [];
        $directories = [];
        if (!$contentOnly) {
            $directories[$prefix] = true;
        }

        $rootLength = strlen($source) + 1;
        foreach (self::getDirectoryContents($source) as $path) {
            if (self::isLinkOrJunction($path) || self::isSamePath($path, $outputFile)) {
                continue;
            }

            $name = $prefix . str_replace(DIRECTORY_SEPARATOR, "/", substr($path, $rootLength));
            if (is_dir($path)) {
                $directories[$name . "/"] = true;
            } elseif (is_file($path)) {
                $files[$name] = $path;
            }
        }

        return self::writeZipArchive($outputFile, $files, $directories);
    }

    /**
     * Normalizes a zipMultipleFiles() virtual folder into a relative, '/'-separated entry prefix.
     *
     * @param string $virtualPath Virtual folder as given ("./folder1", ".", "a\\b")
     * @return string|null The folder ("" for the archive root), or NULL if it contains a ".."
     *                     segment, a drive letter or a NUL byte — an archive with such entry names
     *                     is a Zip Slip payload for whichever extractor opens it next.
     */
    private static function normalizeZipFolder(string $virtualPath): ?string {
        $segments = [];
        foreach (explode("/", str_replace("\\", "/", $virtualPath)) as $segment) {
            if ($segment === "" || $segment === ".") {
                continue;
            }
            if ($segment === ".." || str_contains($segment, "\0") || preg_match('/^[A-Za-z]:$/', $segment) === 1) {
                return null;
            }
            $segments[] = $segment;
        }

        return implode("/", $segments);
    }

    /**
     * Function zipMultipleFiles.
     * Compresses multiple files and directories into a .zip file.
     *
     * The archive is built FRESH and only replaces an existing one at the output path once it is
     * complete (see zipDirectory()). Symlinks/junctions found INSIDE a listed directory are
     * skipped; a path listed explicitly is followed.
     *
     * @param array $files Files to be zipped. The array format must follow:
     *                     [
     *                       '.' => ['path/to/file.txt', 'path/to/folder'],
     *                       './folder1' => ['path/to/file1.txt', 'path/to/file2.xls'],
     *                       './folder2/subfolder' => ['path/to/file3.txt']
     *                     ]
     *                     A listed folder contributes its CONTENTS under the virtual folder. Source
     *                     paths that do not exist, and non-array/non-string values, are SKIPPED
     *                     silently; if that leaves the archive with no entries, the call fails. A
     *                     virtual folder keeps dot-names (".config" stays ".config").
     * @param string $outputPath Destination path for the resulting ZIP file; its extension is
     *                           replaced with ".zip"
     * @param string|null $permissionMode Octal DIRECTORY mode used if the output directory must be
     *                                    created (see createDir()); never applied to the .zip file
     *
     * @return bool TRUE only when the archive was actually written to disk. FALSE — with nothing
     *              written — when the zip extension is missing, $files is empty, the output path is
     *              unusable, a virtual folder contains ".." or a drive letter, two DIFFERENT
     *              sources would produce the same entry name (the second used to silently replace
     *              the first), an entry could not be read, or no entry was added at all.
     *
     * @ref https://www.php.net/manual/en/class.ziparchive.php
     */
    public static function zipMultipleFiles(array $files, string $outputPath, ?string $permissionMode = null): bool {
        if (!extension_loaded('zip') || empty($files)) {
            return false;
        }

        $outputPathInfo = self::getPathInfo($outputPath, keepFileNotExists: true, createPath: $permissionMode ?? true);
        if (empty($outputPathInfo['path']) || ($outputPathInfo['file'] ?? '') === '' || !is_dir($outputPathInfo['dir'])) return false;

        $outputFile = self::getZipName($outputPathInfo['path']);
        $entries = [];
        $directories = [];

        foreach ($files as $virtualPath => $filePaths) {
            if (!is_array($filePaths)) continue;

            $folder = self::normalizeZipFolder((string) $virtualPath);
            if ($folder === null) {
                return false;
            }

            foreach ($filePaths as $file) {
                if (!is_string($file) || self::isUnusablePath($file) || $file === "." || $file === "..") continue;

                $realFile = realpath($file);
                if ($realFile === false) {
                    if (!is_uploaded_file($file)) continue;
                    $realFile = $file;
                }

                $sources = [];
                if (is_dir($realFile)) {
                    $rootLength = strlen(rtrim($realFile, "\\/")) + 1;
                    foreach (self::getDirectoryContents($realFile) as $subFile) {
                        if (self::isLinkOrJunction($subFile) || self::isSamePath($subFile, $outputFile)) {
                            continue;
                        }

                        $entryName = self::buildZipEntryName($folder, substr($subFile, $rootLength));
                        if (is_dir($subFile)) {
                            $directories[$entryName . "/"] = true;
                        } elseif (is_file($subFile)) {
                            $sources[$entryName] = $subFile;
                        }
                    }
                } elseif (is_file($realFile)) {
                    $sources[self::buildZipEntryName($folder, basename($realFile))] = $realFile;
                }

                foreach ($sources as $entryName => $source) {
                    if (isset($entries[$entryName]) && $entries[$entryName] !== $source) {
                        return false;
                    }
                    $entries[$entryName] = $source;
                }
            }
        }

        return self::writeZipArchive($outputFile, $entries, $directories);
    }

    /**
     * Joins a zip virtual folder and a relative path into a well-formed ZIP entry name.
     *
     * ZIP entry names always use '/', are relative, and never start with '/' (APPNOTE 4.4.17.1).
     *
     * @param string $localPath Virtual folder inside the archive, as returned by
     *                          normalizeZipFolder(); "" means the archive root
     * @param string $relativePath Path of the entry beneath $localPath, in host separators
     * @return string Entry name using '/', without a leading or trailing separator
     */
    private static function buildZipEntryName(string $localPath, string $relativePath): string {
        $relativePath = trim(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath), '/');
        $localPath = trim(str_replace(DIRECTORY_SEPARATOR, '/', $localPath), '/');

        if ($localPath === "") {
            return $relativePath;
        }
        return $relativePath === "" ? $localPath : $localPath . '/' . $relativePath;
    }

    /**
     * Generates a .zip filename by replacing the LEAF name's last extension with ".zip".
     *
     * Only the last extension of the file name itself is replaced, so "backup.tar.gz" becomes
     * "backup.tar.zip". The directory part is preserved byte-for-byte and is never parsed for
     * extensions (a dot in a DIRECTORY name used to truncate the path into a different directory).
     *
     * @param string $outputFile The original file path or name (e.g., with any extension)
     * @return string The same path with the leaf's last extension replaced by ".zip". A leaf with
     *                no extension simply gains one ("/tmp/out" -> "/tmp/out.zip"), and a dotfile
     *                leaf is kept whole (".gitignore" -> ".gitignore.zip") rather than collapsing
     *                to a bare ".zip".
     */
    private static function getZipName(string $outputFile): string {
        $basename = basename($outputFile);
        // Everything ahead of the leaf name, kept exactly as given ("" for a bare file name).
        $prefix = substr($outputFile, 0, strlen($outputFile) - strlen($basename));

        $stem = pathinfo($basename, PATHINFO_FILENAME);
        if ($stem === "") {
            $stem = $basename;
        }

        return $prefix . $stem . ".zip";
    }

    /**
     * Deletes multiple files from a given directory.
     *
     * Each name must be a plain LEAF name inside $directory. A name containing a separator ('/' or
     * '\'), a NUL byte, "." or ".." — or, on Windows, a ':' — is REFUSED and makes the result
     * FALSE. It used to be reduced to its base name, which deleted a DIFFERENT file than the one
     * named: "sub/a.txt" removed "<directory>/a.txt" and reported TRUE. A name that is a symlink is
     * removed as a link; its target is never touched.
     *
     * @param array $files List of file names to be deleted
     * @param string $directory Path to the directory where files are located
     * @return bool TRUE if every named file is gone afterwards — a name that did not exist counts as
     *              already deleted. FALSE if $directory is not a directory, $files is empty, a name
     *              was refused, names a directory, or could not be deleted.
     */
    public static function deleteFiles(array $files, string $directory): bool {
        $pathInfo = self::getPathInfo($directory);
        $directory = $pathInfo['path'];
        if (empty($directory) || empty($files) || !$pathInfo['isDir']) return false;

        $success = true;
        foreach ($files as $file) {
            if (!is_string($file) && !is_int($file)) {
                $success = false;
                continue;
            }

            $file = (string) $file;
            if (
                $file === '' || $file === '.' || $file === '..' ||
                strpbrk($file, "/\\\0") !== false ||
                (DIRECTORY_SEPARATOR === "\\" && str_contains($file, ':'))
            ) {
                $success = false;
                continue;
            }

            $path = $directory . $file;
            clearstatcache(true, $path);
            if (is_link($path) || is_file($path)) {
                $success = @unlink($path) && $success;
            } elseif (file_exists($path)) {
                $success = false;
            }
        }

        return $success;
    }

    /**
     * Builds the Content-Disposition header value for a download name.
     *
     * The name used to be pasted raw inside filename="...": a '"' in it closed the quoted string
     * and let the rest of the name inject parameters — `x"; filename*=UTF-8''evil.html` makes
     * browsers, which PREFER filename*, save the file under an attacker-chosen name and type — and
     * a CR/LF (also reachable through Str::decodeText()'s "\u000a") made header() drop the WHOLE
     * header with a warning, so the browser rendered the file inline instead of downloading it.
     *
     * The ASCII filename= fallback has accents folded and anything outside printable ASCII, plus
     * '"' and '\', replaced with '_'; the exact UTF-8 name travels percent-encoded in filename*
     * (RFC 6266 / RFC 8187) whenever it differs from the fallback.
     *
     * @param string $downloadName Requested name
     * @return string Header value, e.g. attachment; filename="report.pdf"
     */
    private static function buildContentDisposition(string $downloadName): string {
        $name = (string) Str::decodeText($downloadName);
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        // Browsers keep only the leaf of a name anyway; do not hand them a path.
        $name = trim(str_replace(['/', '\\'], '_', $name));
        if ($name === '') {
            $name = 'download';
        }

        $ascii = preg_replace('/[^\x20-\x7E]/', '_', Str::removeAccents($name));
        $ascii = str_replace(['"', '\\'], '_', $ascii);

        $value = 'attachment; filename="' . $ascii . '"';
        if ($ascii !== $name && mb_check_encoding($name, 'UTF-8')) {
            $value .= "; filename*=UTF-8''" . rawurlencode($name);
        }

        return $value;
    }

    /**
     * Manually forces the download of a file to the client.
     *
     * This function handles both uploaded temporary files (via $_FILES['tmp_name']) and regular
     * files from disk. It discards any output still buffered (it would otherwise be sent AHEAD of
     * the file and corrupt it), disables zlib output compression (which would make the body
     * disagree with Content-Length), sends the headers and streams exactly Content-Length bytes in
     * blocks of getDownloadBlockSize() bytes (3 MB by default). Optionally removes the file after
     * a COMPLETE download and/or exits the script.
     *
     * It writes directly to the response and does not return normally in the success case, so it
     * must be the LAST thing a request does.
     *
     * @param string $filePath The path to the file to be downloaded. Can be a temporary uploaded file or a full path.
     * @param string|null $downloadName The name the file should have when downloaded (including
     *                                  extension). Required: an empty/blank name THROWS (it used to
     *                                  return silently, sending nothing and ignoring
     *                                  $terminateAfterDownload, so the rest of the page rendered
     *                                  instead). Quotes, control characters, separators and
     *                                  non-ASCII characters are handled safely — see
     *                                  buildContentDisposition().
     * @param bool $deleteAfterDownload Whether to delete the file after the download completes.
     *                                  Default is false. Only done when every byte was sent, and
     *                                  never for a genuine $_FILES upload (PHP cleans those up).
     * @param bool $terminateAfterDownload Whether to call exit() after sending the file. Default is true.
     *
     * @throws \InvalidArgumentException If $downloadName is null, empty or whitespace-only.
     * @throws \Exception BEFORE anything is sent: if $filePath does not resolve to a readable file
     *                    (or to a genuine upload), cannot be opened, or output has already started
     *                    (the headers could no longer be sent). AFTER the body started: if the file
     *                    ended before Content-Length bytes were read (the file is then kept).
     */
    public static function downloadFile(
        string $filePath,
        ?string $downloadName,
        bool $deleteAfterDownload = false,
        bool $terminateAfterDownload = true
    ): void {
        if ($downloadName === null || trim($downloadName) === '') {
            throw new \InvalidArgumentException("A download name is required to send a file.");
        }

        if ($filePath === '' || str_contains($filePath, "\0")) {
            throw new \Exception("The file requested for download does not exist or is not readable.");
        }

        // is_uploaded_file() FIRST: a genuine upload also resolves through realpath(), so the old
        // realpath-first order treated it as an ordinary file and deleteAfterDownload unlink()ed
        // PHP's own temporary upload.
        $isUploaded = is_uploaded_file($filePath);
        if (!$isUploaded) {
            $resolvedPath = realpath($filePath);
            if ($resolvedPath === false || !is_file($resolvedPath)) {
                throw new \Exception("The file requested for download does not exist or is not readable.");
            }
            $filePath = $resolvedPath;
        }

        // Opened before any header goes out, so an unreadable file is still a clean exception the
        // caller can turn into an error page, not a 200 with an empty body.
        $handle = @fopen($filePath, "rb");
        if ($handle === false) {
            throw new \Exception("Unable to open the file for download.");
        }

        $stat = fstat($handle);
        $size = $stat === false ? 0 : (int) $stat['size'];

        if (headers_sent()) {
            fclose($handle);
            throw new \Exception("Cannot start the download: output has already been sent, so the download headers can no longer be sent.");
        }

        while (ob_get_level() > 0 && @ob_end_clean()) {
        }
        @ini_set('zlib.output_compression', 'Off');

        header("Pragma: public");
        header("Expires: 0");
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('X-Content-Type-Options: nosniff');
        header('Content-Disposition: ' . self::buildContentDisposition($downloadName));
        header("Content-Length: " . $size);
        header("Cache-Control: no-cache");
        header('Connection: close');

        $sent = 0;
        $blockSize = self::getDownloadBlockSize();
        while ($sent < $size) {
            $chunk = fread($handle, min($blockSize, $size - $sent));
            // A read error or a file truncated mid-download: stop instead of spinning on feof().
            if ($chunk === false || $chunk === '') {
                break;
            }
            echo $chunk;
            $sent += strlen($chunk);
            flush();
        }
        fclose($handle);

        if ($sent !== $size) {
            throw new \Exception("The download ended early: {$sent} of {$size} bytes were sent.");
        }

        if ($deleteAfterDownload && !$isUploaded) {
            @unlink($filePath);
        }

        if ($terminateAfterDownload) {
            exit(0);
        }
    }

    /**
     * Renames a file name before uploading it to the server, ensuring a clean format
     * and appending a timestamp suffix for uniqueness.
     *
     * The WHOLE returned name is sanitised — base name AND extension — down to [A-Za-z0-9_-]:
     * accents are folded to ASCII, each SPACE becomes '_', and every other character, INCLUDING
     * dots inside the base name, is REMOVED rather than replaced ("relatório final.csv" ->
     * "relatorio_final…csv"). It follows that a '_' in the result is NOT necessarily the suffix
     * separator — an underscore (or a space) in the original name yields one too. Because the
     * suffix always follows the base, the stem is never exactly a Windows device name ("con.txt"
     * becomes "con_<suffix>.txt").
     *
     * The result NEVER starts with '-': leading dashes are stripped, because a file name beginning
     * with one is parsed as an OPTION rather than a path by most CLI tools that might later be run
     * over the upload directory ("-rf.txt" reaching rm). A '-' elsewhere in the name is kept.
     *
     * Uniqueness is best-effort: the suffix is a per-second timestamp plus a zero-padded
     * random_int(0, 999), so two uploads within the same second collide at roughly 1/1000. (It used
     * rand(), which System::makeSeed() seeds — two workers seeded alike drew the SAME suffixes.)
     * uploadFileTo() re-draws when the name is taken; other callers should check too.
     *
     * @param string $originalFileName The original file name before uploading. Empty returns "".
     * @param int $maxLength Maximum number of characters allowed in the FINAL name, extension
     *                       included. Default (and fallback for a value <= 0) is 125. Must leave
     *                       room for the suffix — a FIXED 18 characters ('_' + 14-digit timestamp
     *                       + 3 padded random digits) — plus ".<extension>", so whether this
     *                       method throws depends only on its arguments, never on the draw.
     * @return string The formatted name: "<base><suffix>.<extension>", never longer than
     *                $maxLength. An extension that sanitises away to nothing is dropped along with
     *                its dot, so the result never ends in '.' (Windows would silently strip it,
     *                orphaning the stored name).
     *
     * @throws \Exception If the current date cannot be read, which would silently drop the
     *                    timestamp and leave only the random part guarding against collisions.
     * @throws \InvalidArgumentException If $maxLength cannot fit the suffix and the extension —
     *                                   returning an over-long name would overflow the caller's
     *                                   column or key, which is what this parameter exists to
     *                                   prevent.
     */
    public static function renameUploadFile(string $originalFileName, int $maxLength = 125): string {
        if (empty($originalFileName)) {
            return "";
        }

        if ($maxLength <= 0) {
            $maxLength = 125;
        }

        // '_' is in the allowlist so the space -> '_' replacement actually survives the filter.
        $formatFileName = function (string $name): string {
            return preg_replace(
                '/[^A-Za-z0-9_\-]/',
                '',
                str_replace(
                    ' ',
                    '_',
                    Str::removeAccents($name)
                )
            );
        };

        // getCurrentFormattedDate() catches its own \Exception and returns null; a null would
        // silently drop the timestamp from the suffix.
        $formattedDate = DateTime::getCurrentFormattedDate('YmdHis');
        if (empty($formattedDate)) {
            throw new \Exception("Unable to read the current date to build a unique file name suffix!");
        }
        // FIXED 3 digits: an unpadded draw made the suffix 16-18 characters, so the $reserved check
        // below — and the exception — depended on the draw rather than on the arguments.
        $timestampSuffix = '_' . $formattedDate . str_pad((string) random_int(0, 999), 3, '0', STR_PAD_LEFT);

        $fileParts = explode('.', $originalFileName);

        // The extension goes through the SAME filter as the base name.
        $extension = '';
        if (count($fileParts) > 1) {
            $extension = $formatFileName(Str::strToLower((string) array_pop($fileParts)));
        }

        // The suffix, the dot and the extension are part of the returned name and are paid for out
        // of $maxLength.
        $reserved = Str::strLen($timestampSuffix) + ($extension === '' ? 0 : Str::strLen($extension) + 1);
        if ($maxLength < $reserved) {
            throw new \InvalidArgumentException(
                "maxLength {$maxLength} is too small for the unique suffix and the '{$extension}' extension ({$reserved} characters required)!"
            );
        }

        // Leading dashes are stripped BEFORE truncation (which only removes from the END, so it
        // cannot bring one back). A base of nothing but dashes empties out, leaving the suffix's
        // own leading '_' as the first character.
        $base = ltrim($formatFileName(implode('.', $fileParts)), '-');

        $name = Str::subStr($base, 0, $maxLength - $reserved) . $timestampSuffix;

        return $extension === '' ? $name : $name . '.' . $extension;
    }

    /**
     * Handles the upload of a file to a target directory on the server.
     *
     * The stored name comes from renameUploadFile(), so it is sanitised and timestamped rather
     * than the client-supplied name, and it is re-drawn while a file of that name already exists:
     * move_uploaded_file() silently REPLACES an existing file, so a same-second collision used to
     * overwrite another upload. (The check and the move are not atomic; two concurrent uploads can
     * still race for one name.) Only genuine HTTP uploads are accepted (move_uploaded_file).
     *
     * SECURITY: this method does NOT set permissions on the stored file — it lands with
     * move_uploaded_file()'s default (0644 & ~umask, i.e. world-readable). $permissionMode does
     * not change that. It also does NOT validate the file's type, extension or size beyond the
     * $_FILES error code: the caller must do that before trusting the result.
     *
     * @param array|null $uploadedFile The $_FILES entry for ONE upload: string 'tmp_name', string
     *                                 'name' and int 'error'. Empty/NULL or any other shape (e.g. a
     *                                 multi-file entry whose fields are arrays) yields type 7.
     * @param string|null $targetDirectory Destination directory where the file should be saved;
     *                                     created if missing. Always treated as a directory, even
     *                                     when its name has a dot ("uploads/v1.2").
     * @param string|null $permissionMode Octal DIRECTORY mode, and ONLY used when
     *                                    $targetDirectory does not already exist and must be
     *                                    created (see createDir()) — on every later upload into
     *                                    an existing directory it is ignored entirely. It is NOT
     *                                    a file mode: pass a directory-shaped value such as
     *                                    '0750'; a file-shaped '0600' would create a directory
     *                                    with no execute bit that nothing can traverse, breaking
     *                                    this and every subsequent upload. A malformed mode means
     *                                    the directory is not created (type 6).
     * @return array{type: int, file: string, path: string} 'file' (stored name) and 'path'
     *         (destination directory) are non-empty ONLY when type === 0; otherwise both are ''.
     *
     * Response['type'] codes:
     *  0 - Success
     *  1 - File exceeds PHP size limit
     *  2 - File exceeds HTML form size limit
     *  3 - File only partially uploaded
     *  4 - No file uploaded
     *  5 - Failed to move uploaded file (also when $uploadedFile is not a real HTTP upload, or no
     *      free name could be drawn)
     *  6 - Failed to create destination directory
     *  7 - Internal server error (empty input, malformed $_FILES entry, unknown error code, or
     *      any throwable raised on the way — nothing is rethrown)
     *
     * An upload error (1-4) is reported before the destination is touched, so a failed upload
     * never creates directories.
     */
    public static function uploadFileTo(?array $uploadedFile, ?string $targetDirectory, ?string $permissionMode = null): array {
        $response = [
            'type' => 7,
            'file' => '',
            'path' => ''
        ];

        // Checked up front: a missing key used to raise "Undefined array key" warnings on the way
        // to the documented 7.
        if (
            empty($uploadedFile) ||
            !is_string($uploadedFile['tmp_name'] ?? null) ||
            !is_string($uploadedFile['name'] ?? null) ||
            !is_int($uploadedFile['error'] ?? null)
        ) {
            return $response;
        }

        $error = $uploadedFile['error'];
        if ($error !== UPLOAD_ERR_OK) {
            $response['type'] = in_array($error, [1, 2, 3, 4], true) ? $error : 7;
            return $response;
        }

        try {
            if (self::isUnusablePath($targetDirectory)) {
                $response['type'] = 6;
                return $response;
            }

            $targetPathInfo = self::getPathInfo(
                rtrim($targetDirectory, "\\/") . DIRECTORY_SEPARATOR,
                createPath: $permissionMode ?? true
            );
            $finalPath = $targetPathInfo['path'];
            if (empty($finalPath) || !$targetPathInfo['isDir']) {
                $response['type'] = 6;
                return $response;
            }

            $formattedName = null;
            for ($attempt = 0; $attempt < 10; $attempt++) {
                $candidate = self::renameUploadFile($uploadedFile['name']);
                if ($candidate === '') {
                    return $response;
                }
                if (!file_exists($finalPath . $candidate)) {
                    $formattedName = $candidate;
                    break;
                }
            }

            if ($formattedName !== null && @move_uploaded_file($uploadedFile['tmp_name'], $finalPath . $formattedName)) {
                $response['type'] = 0;
                $response['file'] = $formattedName;
                $response['path'] = $finalPath;
            } else {
                $response['type'] = 5;
            }
        } catch (\Throwable) {
            $response['type'] = 7;
        } finally {
            if ($response['type'] !== 0) {
                $response['file'] = '';
                $response['path'] = '';
            }
        }

        return $response;
    }
}
