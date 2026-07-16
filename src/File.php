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
     * Gets the number of bytes downloadFile() reads (and buffers) per loop iteration.
     *
     * If no custom value has been set, it defaults to 3145728 bytes (3 MB) — NOT 8 KB.
     * Size memory_limit against this figure: each concurrent download holds one block
     * in the output buffer.
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
        if(empty(self::$defaultMode)) {
            self::setDefaultMode(self::DEFAULT_MODE);
        }
        return self::$defaultMode;
    }

    /**
     * Sets the process-wide default permission mode used when a caller passes no explicit mode.
     *
     * @param string $defaultMode Permission mode as an octal string: octal digits with an optional
     *                            leading zero (e.g., "755", "0700"). Anything else — including an
     *                            octal string too large to fit a PHP int (see parseOctalMode()) —
     *                            is SILENTLY IGNORED and the previous default is kept, so read
     *                            getDefaultMode() back if the value is not a literal.
     * @return void
     */
    public static function setDefaultMode(string $defaultMode): void {
        $mode = self::parseOctalMode($defaultMode);
        if($mode === null) {
            return;
        }
        self::$defaultMode = $mode;
    }

    /**
     * Parses an octal permission string into the int chmod()/mkdir() expect, or NULL if it is not
     * one this library can honour.
     *
     * Validator::isOctal() only checks the CHARACTER SET, so it happily accepts an arbitrarily long
     * run of octal digits. octdec() then silently returns a FLOAT for anything above PHP_INT_MAX
     * (8^21 - 1 on a 64-bit build), and that float reached mkdir()/chmod() as an uncaught
     * TypeError — "must be of type int, float given" — from inside createDir()/writeFile(), which
     * document a return code as their error channel and never a throw. The overflow is rejected
     * here instead, so it joins every other malformed mode on the documented fallback path.
     *
     * The bound is is_int(), NOT the raw string length: "0000000000000000000000700" is 25
     * characters but a perfectly good 0700, and rejecting it on length would silently WIDEN it to
     * the 0755 default — the exact class of bug this check exists to prevent.
     *
     * @param string|null $value Permission mode as an octal string (e.g., "0700").
     * @return int|null The mode as an octal integer, or NULL if $value is not a valid octal string
     *                  or does not fit a PHP int.
     */
    private static function parseOctalMode(?string $value): ?int {
        if(!Validator::isOctal($value)) {
            return null;
        }

        $mode = octdec($value);
        // octdec() returns int|float; a float here means the value overflowed PHP_INT_MAX.
        return is_int($mode) ? $mode : null;
    }

    /**
     * Converts an octal permission string into the octal integer chmod()/mkdir() expect.
     *
     * @param string|null $permissionMode Permission mode as an octal string (e.g., "0700").
     *                                    NULL — or any value that is not a valid octal string, or
     *                                    is too large to fit a PHP int (see parseOctalMode()) —
     *                                    falls back to getDefaultMode() rather than failing, so a
     *                                    malformed mode silently becomes the default (which may be
     *                                    WIDER than what the caller asked for). Callers that must
     *                                    not silently widen permissions have to reject a malformed
     *                                    mode themselves before calling; writeFile() does.
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
     * directory must not be world-readable.
     *
     * @param string $path The directory path to be created
     * @param string|null $permissionMode Directory mode as an octal string (e.g., "0700").
     *                                    Defaults to getDefaultMode() — 0755 unless changed via
     *                                    setDefaultMode(). A value that is not a valid octal
     *                                    string also falls back to that default (see
     *                                    getPermissionMode()). Ignored on Windows.
     * @param bool $recursive Whether the creation should be recursive (may increase memory usage)
     *
     * @return int Positive on success, negative on failure:
     *              2  directory created
     *              1  directory already existed (mode NOT applied — an existing directory is
     *                 never chmod'ed here)
     *             -1  $path was empty
     *             -2  mkdir() failed
     *
     * @ref http://php.net/manual/en/function.mkdir.php
     */
    public static function createDir(string $path, ?string $permissionMode = null, bool $recursive = true): int {
        $mode = self::getPermissionMode($permissionMode);
        if(empty($path)) {
            return -1;
        }

        $path = str_replace(array("\\", "/"), DIRECTORY_SEPARATOR, $path);
        if (is_dir($path)) {
            return 1;
        }

        $ret = null;
        $oldMask = umask(0);
        // Suppressed like the @fopen/@file_put_contents/@unlink elsewhere in this class: the -2
        // return is the documented error channel, and a raw warning would leak the path into the
        // response of a caller who is already handling the failure.
        if (!@mkdir($path, $mode, $recursive)) $ret = -2;
        umask($oldMask);

        if($ret !== null) {
            return $ret;
        }
        return 2;
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
     * @param string $path Path to normalize
     * @return string
     */
    private static function normalizeResolvedPath(string $path): string {
        $path = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $path);
        $prefix = "";

        if (preg_match('/^[A-Z]:' . preg_quote(DIRECTORY_SEPARATOR, '/') . '/i', $path) === 1) {
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

        $segments = [];
        $currentPath = $path;
        while ($currentPath !== "" && $currentPath !== ".") {
            $segment = basename($currentPath);
            if ($segment !== "" && $segment !== ".") {
                array_unshift($segments, $segment);
            }

            $parentPath = dirname($currentPath);
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
     * Ensures a directory path ends with the system directory separator.
     *
     * @param string $path Directory path
     * @return string
     */
    private static function ensureTrailingDirectorySeparator(string $path): string {
        return str_ends_with($path, DIRECTORY_SEPARATOR) ? $path : $path . DIRECTORY_SEPARATOR;
    }

    /**
     * Returns normalized path information for an existing or future directory/file path.
     *
     * The existing part of the path is resolved with realpath(), and missing child
     * directories or a missing final file are appended back to that resolved base.
     *
     * @param string|null $path Directory or file path
     * @param bool $keepFile Treat the final segment as a file, even when it has no extension
     * @param bool $keepFileNotExists Treat the final segment as a file even when it does not exist
     * @param string|bool|null $createPath If true or octal string, creates the directory portion when missing
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

        if(empty($path)) {
            return $ret;
        }

        // parseOctalMode(), not Validator::isOctal(): isOctal() accepts an unbounded run of octal
        // digits, and such a mode used to survive this guard and reach createDir() -> mkdir() as an
        // uncaught TypeError (octdec() overflows to float). An unusable mode means "do not create"
        // here, exactly like every other non-octal string already did.
        if($createPath === null || (!is_bool($createPath) && self::parseOctalMode($createPath) === null)) {
            $createPath = false;
        }

        $normalizedPath = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, trim($path));
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
     * @param string $filePath The path where the file should be created
     * @param string $content The content to write into the file
     * @param bool $append TRUE appends to the file; FALSE (default) TRUNCATES it before writing
     * @param string|null $permissionMode File mode as an octal string (e.g., '0600'), applied
     *                                    with chmod() AFTER the content is written. NULL (default)
     *                                    means the permissions are LEFT ALONE: a new file keeps
     *                                    fopen()'s 0666 & ~umask (typically 0644, world-readable)
     *                                    and an existing file keeps its own mode. Pass an explicit
     *                                    mode for files holding secrets. Ignored on Windows beyond
     *                                    the read-only bit.
     *
     * @throws \Exception If $filePath is empty, does not resolve to a file path, its directory is
     *                    missing/uncreatable, the file cannot be opened, the write fails, or the
     *                    requested $permissionMode could not be applied (the file is written
     *                    first — a chmod failure means the content IS on disk but is NOT protected
     *                    by the requested mode).
     * @throws \InvalidArgumentException If $permissionMode is given but is not a valid octal
     *                                   string, or is too large to fit a PHP int. It is rejected
     *                                   rather than silently falling back to the default mode,
     *                                   which would widen permissions on exactly the call that
     *                                   asked to restrict them.
     *
     * @ref https://chmodcommand.com/chmod-2777/
     */
    public static function writeFile(string $filePath, string $content = "", bool $append = false, ?string $permissionMode = null): void {
        if(empty($filePath)) {
            throw new \Exception("File path not provided for writing!");
        };

        // parseOctalMode(), not Validator::isOctal(): isOctal() accepts an unbounded run of octal
        // digits, so a 22-digit mode passed this guard and then resolved, via getPermissionMode(),
        // to the 0755 DEFAULT down at the chmod() below — silently widening the file on the very
        // call that asked to restrict it, which is what this throw exists to prevent. Parsed once
        // here and reused, so the guard and the chmod() can never disagree about the mode.
        $fileMode = null;
        if($permissionMode !== null) {
            $fileMode = self::parseOctalMode($permissionMode);
            if($fileMode === null) {
                throw new \InvalidArgumentException("Invalid permission mode provided for writing: '{$permissionMode}'!");
            }
        }

        $filePath = self::getPathInfo($filePath, keepFileNotExists: true, createPath: true);
        if(empty($filePath['path']) || empty($filePath['file'])) {
            throw new \Exception("Invalid file path provided for writing!");
        }

        if(empty($filePath['dir']) || !is_dir($filePath['dir'])) {
            throw new \Exception("Invalid directory path provided for file writing!");
        }

        $hasError = false;
        $file = @fopen($filePath['path'], (!empty($append) ? "a" : "w"));
        if($file !== FALSE) {
            if(fwrite($file, $content) === false) {
                $hasError = true;
            }
            @fclose($file);
            $file = null;
        } else {
            throw new \Exception("Failed to open the file for writing!");
        }

        if ($hasError) {
            throw new \Exception("Failed to write to the file!");
        }

        // chmod only when the caller explicitly asked for a mode, and only once the file exists.
        // It used to run BEFORE fopen() created the file (so the mode was silently dropped for
        // every new file), and a null mode used to resolve to the 0777 default and be applied
        // anyway (so a plain write relaxed an existing secret file to world-writable).
        if ($fileMode !== null && !chmod($filePath['path'], $fileMode)) {
            throw new \Exception("Failed to apply permission mode '{$permissionMode}' to the file!");
        }
    }

    /**
     * Creates a uniquely named temporary file in the system temp directory and writes content to it.
     *
     * The file is NOT removed automatically — the caller owns its lifetime and must unlink() it.
     *
     * @param string $prefix Prefix for the generated file name. Advisory only: uniqueness comes
     *                       from tempnam(), and on Windows only the first 3 characters of the
     *                       prefix survive, so never parse the returned name to recover it.
     * @param string $content Initial content to write into the file upon creation
     * @param string|null $permissionMode File mode as an octal string (e.g., '0600'), applied
     *                                    after the content is written. NULL (default) leaves
     *                                    tempnam()'s own permissions in place — on POSIX that is
     *                                    already 0600 (owner-only); on Windows the file is
     *                                    readable by any account that can reach the temp
     *                                    directory. See writeFile().
     *
     * @return string The full path to the created temporary file
     * @throws \Exception If the temporary file could not be created, or if writing/chmod'ing it
     *                    failed (see writeFile()).
     * @throws \InvalidArgumentException If $permissionMode is not a valid octal string.
     */
    public static function createTempFile(string $prefix = "", string $content = "", ?string $permissionMode = null): string {
        // NOTE: this used to call genGuid(), which is defined nowhere in this package, so every
        // call raised \Error ("undefined function") — an \Error is not an \Exception, so it sailed
        // straight through the documented catch. tempnam() already guarantees uniqueness.
        $file = tempnam(sys_get_temp_dir(), uniqid($prefix, true));
        if($file === false) {
            throw new \Exception("Unable to create temporary file!");
        }

        self::writeFile($file, $content, false, $permissionMode);
        return $file;
    }

    /**
     * Recursively deletes a folder and all its contents.
     *
     * @param string $dir Directory path to delete
     * @return bool TRUE on success, FALSE on failure
     *
     * @ref https://stackoverflow.com/questions/3338123/how-do-i-recursively-delete-a-directory-and-its-entire-contents-files-sub-dir
     */
    public static function deleteFoldersRecursively(string $dir): bool {
        $pathInfo = self::getPathInfo($dir);
        if (empty($pathInfo['path']) || !$pathInfo['isDir']) return false;
        $dir = $pathInfo['path'];

        try {
            foreach (
                new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
                    \RecursiveIteratorIterator::CHILD_FIRST
                ) as $file
            ) {
                $action = $file->isDir() ? 'rmdir' : 'unlink';
                $action($file->getRealPath());
            }

            return rmdir($dir);
        } catch (\Error|\Exception $err) {
        }

        return false;
    }

    /**
     * Clears all contents of a folder by deleting and recreating it.
     *
     * @param string $dir Directory to be reset
     * @return bool TRUE on success, FALSE on failure
     */
    public static function resetFolder(string $dir): bool {
        if (
            self::createDir($dir) <= 0 ||
            !self::deleteFoldersRecursively($dir) ||
            self::createDir($dir) <= 0
        ) {
            return false;
        }

        return true;
    }

    /**
     * Renames all files and folders within a directory to either uppercase or lowercase.
     *
     * Renaming is done children-first, so a renamed parent never invalidates a pending child path.
     *
     * @param string $dir The directory to process
     * @param bool $toUpper If true, converts names to UPPERCASE; if false, to lowercase
     * @return bool TRUE if every entry was renamed (or already had the target case), FALSE if
     *              $dir is not an existing directory, or if any single rename() failed — the
     *              walk still continues, so a FALSE means "at least one failed", not "nothing
     *              changed". Partial renames are NOT rolled back.
     *
     * @ref https://stackoverflow.com/questions/32173320/php-rename-all-files-to-lower-case-in-a-directory-recursively
     */
    public static function standardizeFilesCaseRecursive(string $dir, bool $toUpper = false): bool {
        $pathInfo = self::getPathInfo($dir);
        if (empty($pathInfo['path']) || !$pathInfo['isDir']) {
            return false;
        }
        $dir = $pathInfo['path'];

        // First-class callable, NOT the string 'Str::strToLower': string callables resolve against
        // the GLOBAL namespace at runtime (`use`/`namespace` are compile-time only), so the string
        // form looked for \Str and made call_user_func() raise a TypeError on the first entry —
        // i.e. this method threw on every non-empty directory and could never return the
        // documented bool.
        $caseFunction = $toUpper ? Str::strToUpper(...) : Str::strToLower(...);

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        $allSucceeded = true;
        foreach ($iterator as $oldPath => $file) {
            $newPath = $file->getPath() . DIRECTORY_SEPARATOR . call_user_func($caseFunction, $file->getFilename());
            $renamed = rename($oldPath, $newPath);
            if (!$renamed) {
                $allSucceeded = false;
            }
        }

        return $allSucceeded;
    }

    /**
     * Reads a file and extracts key-value pairs in the .env format.
     *
     * @param string $filePath Path to the file
     * @return array Associative array of variables found in the file
     */
    public static function parseEnvFile(string $filePath): array {
        $result = [];
        if (!file_exists($filePath)) {
            return $result;
        }

        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines AS $line) {
            $line = trim($line);

            if (
                str_starts_with($line, "#") ||
                str_starts_with($line, "=") ||
                !str_contains($line, "=") ||
                $line === ''
            ) {
                continue;
            }

            $parts = explode("=", $line, 2);
            $result[trim($parts[0])] = is_string($parts[1]) ? trim($parts[1]) : '';
        }

        return $result;
    }

    /**
     * Updates or appends key-value variables in a .env-style file, in place.
     *
     * Every line the caller did not name is preserved byte-for-byte, including comments, blank
     * lines and unrelated variables. A key already present is rewritten as "KEY=value" at its
     * original position (losing only that line's original spacing/quoting); a key not present is
     * appended at the end. The file is created when it does not exist.
     *
     * Values are written verbatim: no escaping or quoting is applied, so a value containing a
     * newline will corrupt the file, and one containing spaces or '#' may not survive a re-read
     * by a stricter .env parser.
     *
     * @param string $filePath Path to the file to be updated
     * @param array $variables Variables to write, as key => value. An empty array rewrites the
     *                         file with its current content.
     * @return bool TRUE if the file was written, FALSE if the final write failed
     * @throws \Exception If the file does not exist and could not be created (see writeFile()).
     */
    public static function updateEnvFile(string $filePath, array $variables): bool {
        // Create the file when absent — but NEVER touch an existing one here: writeFile() defaults to
        // $content="" with $append=false, so it opens "w" and truncates. Truncating before the read
        // below wiped every pre-existing variable and comment, leaving only $variables behind.
        if (!file_exists($filePath)) {
            self::writeFile($filePath);
        }

        $newContent = "";
        $lines = file($filePath, FILE_IGNORE_NEW_LINES);
        foreach ($lines AS $line) {
            $trimmedLine = trim($line);

            if (
                empty($trimmedLine) ||
                str_starts_with($trimmedLine, "#") ||
                str_starts_with($trimmedLine, "=") ||
                !str_contains($trimmedLine, "=")
            ) {
                $newContent .= $line . PHP_EOL;
                continue;
            }

            list($key) = explode("=", $trimmedLine, 2);
            $key = trim($key);

            if (Validator::hasProperty($key, $variables)) {
                $newContent .= "$key=" . $variables[$key] . PHP_EOL;
                unset($variables[$key]);
            } else {
                $newContent .= $line . PHP_EOL;
            }
        }

        foreach ($variables AS $key => $value) {
            $newContent .= "$key=$value" . PHP_EOL;
        }

        return file_put_contents($filePath, $newContent) !== false;
    }

    /**
     * Function unzipFile.
     * Unzips selected entries from a .zip archive into a destination directory.
     *
     * Each requested name is matched against the archive as EITHER an exact entry (a single file,
     * extracted to the destination root under its base name) OR a directory prefix (every entry
     * beneath it is extracted, keeping the structure below that prefix). Matching is
     * case-sensitive and separator-normalised.
     *
     * Entries whose path escapes the destination root ("..", absolute or drive-qualified) are
     * refused and reported as failures — they are never written (Zip Slip guard).
     *
     * @param string $zipPath Path to the .zip file
     * @param string $destinationPath Directory the entries are extracted into; created if missing
     * @param string|array $filesToExtract Entry name, or list of entry names, to extract: a file
     *                                     ("data/report.csv") or a directory ("data"). An empty
     *                                     value extracts nothing and is reported as success.
     * @param string|null $permissionMode Octal directory mode used when creating the destination
     *                                    and any sub-directories (see createDir()); NOT applied to
     *                                    extracted files, which land with file_put_contents()'s
     *                                    default permissions.
     *
     * @return bool|array TRUE when every requested name was found and extracted. FALSE when the
     *                    zip extension is missing, the archive/destination is unusable, the
     *                    archive could not be opened, or an unexpected ZipArchive error aborted
     *                    the run. Otherwise a non-empty list<string> of the names that FAILED:
     *                    archive entries that could not be written or were refused by the Zip
     *                    Slip guard, plus requested names that matched no entry at all.
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
        $destinationPathInfo = self::getPathInfo($destinationPath, createPath: $permissionMode ?? true);
        $zipPathInfo = self::getPathInfo($zipPath, keepFile: true);
        $destinationPath = $destinationPathInfo['path'];
        $zipPath = $zipPathInfo['path'];

        if (
            empty($destinationPath) ||
            empty($zipPath) ||
            !$destinationPathInfo['isDir'] ||
            !$zipPathInfo['isFile']
        ) return false;
        if (empty($filesToExtract)) return true;
        if (!is_array($filesToExtract)) {
            $filesToExtract = [$filesToExtract];
        }

        // Normalise the requested names ONCE, without a trailing separator. The old code appended
        // a separator to every needle, so "data/report.csv" became "data\report.csv\" and could
        // never str_starts_with-match the entry "data\report.csv": naming a FILE extracted nothing
        // and still returned TRUE. Only directory prefixes ever worked.
        $needles = [];
        foreach ($filesToExtract as $file) {
            $file = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, (string) $file);
            $file = rtrim($file, DIRECTORY_SEPARATOR);
            if ($file !== "") {
                $needles[$file] = false; // value = "matched at least one entry"
            }
        }
        if (empty($needles)) return true;

        if (!str_ends_with($destinationPath, DIRECTORY_SEPARATOR)) {
            $destinationPath .= DIRECTORY_SEPARATOR;
        }

        $zip = null;
        $zipOpened = false;
        $errors = [];
        $aborted = false;
        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return false;
            }

            $zipOpened = true;

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $zip->getNameIndex($i));

                foreach ($needles AS $fileToExtract => $matched) {
                    $fileToExtract = (string) $fileToExtract;

                    if (rtrim($filename, DIRECTORY_SEPARATOR) === $fileToExtract) {
                        // Exact entry match: extract it flat, under its own base name.
                        $needles[$fileToExtract] = true;
                        $relativePath = basename($filename);
                        if (str_ends_with($filename, DIRECTORY_SEPARATOR)) {
                            // The needle names a directory entry; its children carry the payload.
                            continue;
                        }
                    } elseif (str_starts_with($filename, $fileToExtract . DIRECTORY_SEPARATOR)) {
                        // Directory-prefix match: keep the structure below the prefix.
                        $needles[$fileToExtract] = true;
                        $relativePath = substr($filename, strlen($fileToExtract . DIRECTORY_SEPARATOR));
                    } else {
                        continue;
                    }

                    // Zip Slip guard: reject entries that escape the destination root via a
                    // parent-traversal ("..") segment or an absolute/drive-qualified path.
                    $pathSegments = array_filter(
                        explode(DIRECTORY_SEPARATOR, $relativePath),
                        static fn($s) => $s !== '' && $s !== '.'
                    );
                    if (
                        in_array('..', $pathSegments, true) ||
                        str_starts_with($relativePath, DIRECTORY_SEPARATOR) ||
                        preg_match('/^[A-Za-z]:/', $relativePath) === 1
                    ) {
                        $errors[$i] = $filename;
                        continue;
                    }

                    if (Str::strLen($relativePath) > 0) {
                        if (str_ends_with($filename, DIRECTORY_SEPARATOR)) {
                            if (!is_dir($destinationPath . $relativePath)) {
                                if (self::createDir($destinationPath . $relativePath, $permissionMode) < 0) {
                                    $errors[$i] = $filename;
                                }
                            }
                        } else {
                            if (dirname($relativePath) !== ".") {
                                if (!is_dir($destinationPath . dirname($relativePath))) {
                                    self::createDir($destinationPath . dirname($relativePath), $permissionMode);
                                }
                            }

                            if (@file_put_contents($destinationPath . $relativePath, $zip->getFromIndex($i)) === false) {
                                $errors[$i] = $filename;
                            }
                        }
                    }
                }
            }

            $zip->close();
            $zipOpened = false;
        } catch (\Error|\Exception $err) {
            // Swallowing this used to fall through to `return true` — a silent success on a
            // half-extracted archive.
            $aborted = true;
        } finally {
            try {
                if ($zipOpened && $zip !== null) {
                    $zip->close();
                }
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
     * Recursively retrieves all files and subdirectories within a given directory.
     *
     * @param string $directory The directory to scan
     * @param array $results Recursive accumulator of discovered paths
     * @return array List of full paths for files and subdirectories
     *
     * @ref https://stackoverflow.com/questions/24783862/list-all-the-files-and-folders-in-a-directory-with-php-recursive-function
     */
    public static function getDirectoryContents(string $directory, array &$results = []): array {
        $directory = realpath($directory);
        if (empty($directory)) return $results;

        $items = scandir($directory);
        foreach ($items as $item) {
            if ($item === "." || $item === "..") continue;

            $fullPath = realpath($directory . DIRECTORY_SEPARATOR . $item);
            $results[] = $fullPath;

            if (is_dir($fullPath)) {
                self::getDirectoryContents($fullPath, $results);
            }
        }

        return $results;
    }

    /**
     * Compresses a folder into a .zip file, with the option to include or exclude the root folder.
     *
     * @param string $source Directory path to be zipped
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
     *              directory is unusable, the archive could not be opened, or nothing was written
     *              — notably zipping an EMPTY directory with $contentOnly=true produces no
     *              archive and returns FALSE, because libzip discards an archive with no entries.
     *
     * @ref https://www.php.net/manual/en/class.ziparchive.php
     */
    public static function zipDirectory(
        string $source,
        string $outputPath,
        ?string $permissionMode = null,
        bool $contentOnly = false
    ): bool {
        if (!extension_loaded('zip')) {
            return false;
        }

        $source = realpath($source);
        if (empty($source) || !is_dir($source)) return false;

        $outputPathInfo = self::getPathInfo($outputPath, createPath: $permissionMode ?? true);
        if (empty($outputPathInfo['path']) || empty($outputPathInfo['dir']) || !is_dir($outputPathInfo['dir'])) return false;
        $sourceInfo = pathinfo($source);

        if (empty($outputPathInfo['file'])) {
            $outputFile = $outputPathInfo['path'] . $sourceInfo['basename'] . ".zip";
        } else {
            $outputFile = self::getZipName($outputPathInfo['path']);
        }

        $prefixLength = strlen(rtrim($sourceInfo['dirname'], "\\/") . DIRECTORY_SEPARATOR);

        $zip = new \ZipArchive();
        // The open() result used to be discarded, so a failure left an uninitialised object and
        // the next addEmptyDir() raised ValueError instead of returning the documented FALSE.
        if ($zip->open($outputFile, \ZipArchive::CREATE) !== true) {
            return false;
        }

        if (!$contentOnly) {
            $zip->addEmptyDir($sourceInfo['basename']);
        } else {
            $prefixLength += strlen($sourceInfo['basename'] . DIRECTORY_SEPARATOR);
        }

        foreach (self::getDirectoryContents($source) as $path) {
            // ZIP entry names must use '/' regardless of host separator (APPNOTE 4.4.17.1).
            // Storing the Windows '\' produced archives whose entries other tools restore as a
            // single flat file literally named "src\a.txt" instead of a src/ directory.
            $localPath = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, $prefixLength));

            if (is_file($path)) {
                $zip->addFile($path, $localPath);
            } elseif (is_dir($path)) {
                $zip->addEmptyDir($localPath);
            }
        }

        // close() is where libzip actually writes, so its result is the real success signal — but
        // it also returns TRUE for an archive with no entries, which it silently does not write.
        // Confirm the file landed: callers use this bool to decide whether to delete $source.
        if (!$zip->close()) {
            return false;
        }
        return is_file($outputFile);
    }

    /**
     * Function zipMultipleFiles.
     * Compresses multiple files and directories into a .zip file.
     *
     * @param array $files Files to be zipped. The array format must follow:
     *                     [
     *                       '.' => ['path/to/file.txt', 'path/to/folder'],
     *                       './folder1' => ['path/to/file1.txt', 'path/to/file2.xls'],
     *                       './folder2/subfolder' => ['path/to/file3.txt']
     *                     ]
     *                     Source paths that do not exist are SKIPPED silently; if that leaves the
     *                     archive with no entries, the call fails rather than reporting success.
     * @param string $outputPath Destination path for the resulting ZIP file; its extension is
     *                           replaced with ".zip"
     * @param string|null $permissionMode Octal DIRECTORY mode used if the output directory must be
     *                                    created (see createDir()); never applied to the .zip file
     *
     * @return bool TRUE only when the archive was actually written to disk. FALSE when the zip
     *              extension is missing, $files is empty, the output path is unusable, the archive
     *              could not be opened, or no entry was added (e.g. every source path was stale),
     *              since libzip silently writes no file for an empty archive.
     *
     * @ref https://www.php.net/manual/en/class.ziparchive.php
     */
    public static function zipMultipleFiles(array $files, string $outputPath, ?string $permissionMode = null): bool {
        if (!extension_loaded('zip')) {
            return false;
        }

        if (empty($files)) return false;

        $outputPathInfo = self::getPathInfo($outputPath, keepFileNotExists: true, createPath: $permissionMode ?? true);
        if (empty($outputPathInfo['path']) || empty($outputPathInfo['file']) || !is_dir($outputPathInfo['dir'])) return false;

        $outputFile = self::getZipName($outputPathInfo['path']);
        $zip = new \ZipArchive();
        // See zipDirectory(): an unchecked open() turns a failure into a ValueError, not FALSE.
        if ($zip->open($outputFile, \ZipArchive::CREATE) !== true) {
            return false;
        }

        foreach ($files as $virtualPath => $filePaths) {
            if (!is_array($filePaths)) continue;

            $localPath = str_replace(["\\", "/"], DIRECTORY_SEPARATOR, $virtualPath);
            if (strlen($localPath) >= 1) $localPath = Str::removeStringPrefix($localPath, '.');
            if (strlen($localPath) >= 1) $localPath = Str::removeStringPrefix($localPath, DIRECTORY_SEPARATOR);
            if (strlen($localPath) >= 1) $localPath = Str::removeStringSuffix($localPath, DIRECTORY_SEPARATOR);

            foreach ($filePaths as $file) {
                if ($file === "." || $file === "..") continue;

                $realFile = realpath($file);
                if (!empty($realFile)) {
                    $file = $realFile;
                } elseif (!is_uploaded_file($file)) {
                    $file = false;
                }
                if (empty($file)) continue;

                if (is_dir($file)) {
                    $sourceRoot = Str::removeStringSuffix($file, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;

                    foreach (self::getDirectoryContents($file) as $subFile) {
                        // The old form appended a separator to $subFile before stripping the
                        // prefix, so every FILE entry ended in one ("bundle/inner.txt\") — a
                        // trailing separator marks a directory in a zip, so extractors restored
                        // each file as an empty folder.
                        $relativePath = substr($subFile, strlen($sourceRoot));
                        if ($relativePath === "" || $relativePath === false) {
                            continue;
                        }

                        $entryName = self::buildZipEntryName($localPath, $relativePath);

                        if (is_file($subFile)) {
                            $zip->addFile($subFile, $entryName);
                        } elseif (is_dir($subFile)) {
                            $zip->addEmptyDir($entryName);
                        }
                    }
                } elseif (is_file($file)) {
                    $zip->addFile($file, self::buildZipEntryName($localPath, basename($file)));
                }
            }
        }

        // See zipDirectory(): close() alone is not proof — it returns TRUE for an empty archive
        // that it never writes, which is exactly what a list of stale source paths produces.
        if (!$zip->close()) {
            return false;
        }
        return is_file($outputFile);
    }

    /**
     * Joins a zip virtual folder and a relative path into a well-formed ZIP entry name.
     *
     * ZIP entry names always use '/', are relative, and never start with '/' (APPNOTE 4.4.17.1).
     * The documented '.' virtual path resolves to an EMPTY folder, and the old unconditional
     * "$localPath . '/' . $name" then produced a leading-slash — i.e. absolute — entry name for
     * the docblock's own primary example.
     *
     * @param string $localPath Virtual folder inside the archive, already stripped of its
     *                          leading './' and separators; "" means the archive root
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
     * extensions.
     *
     * This used to explode() the FULL PATH on '.' and drop everything after the last dot, which
     * mangled any path whose DIRECTORY contained a dot but whose file name did not:
     * "/tmp/a.b/out" became "/tmp/a.zip" — a different directory. A path with no dot at all fared
     * worse: "/tmp/out" became a bare ".zip", a hidden file in the process's working directory.
     * Either way zipDirectory()/zipMultipleFiles() silently wrote the archive somewhere the caller
     * never asked for.
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
        // pathinfo()'s 'dirname' is not usable here: it reports "." for a bare name, which would
        // invent a "./" prefix the caller never wrote.
        $prefix = substr($outputFile, 0, strlen($outputFile) - strlen($basename));

        $stem = pathinfo($basename, PATHINFO_FILENAME);
        if ($stem === "") {
            // A dotfile such as ".gitignore" has an empty stem; keep the leaf whole so the archive
            // does not collapse into a bare ".zip".
            $stem = $basename;
        }

        return $prefix . $stem . ".zip";
    }

    /**
     * Deletes multiple files from a given directory.
     *
     * @param array $files List of filenames to be deleted
     * @param string $directory Path to the directory where files are located
     * @return bool TRUE if all files were deleted successfully, FALSE otherwise
     */
    public static function deleteFiles(array $files, string $directory): bool {
        $pathInfo = self::getPathInfo($directory);
        $directory = $pathInfo['path'];
        if (empty($directory) || empty($files) || !$pathInfo['isDir']) return false;

        $success = true;
        foreach ($files as $file) {
            // Reduce to a leaf name so a crafted "../.." or absolute entry cannot escape
            // $directory and delete arbitrary files.
            $file = basename((string) $file);
            if ($file === '' || $file === '.' || $file === '..') {
                continue;
            }
            if (is_file($directory . $file)) {
                $success = @unlink($directory . $file) && $success;
            }
        }

        return $success;
    }

    /**
     * Manually forces the download of a file to the client.
     *
     * This function handles both uploaded temporary files (via $_FILES['tmp_name']) and regular files from disk.
     * It sets appropriate headers and streams the file in blocks of getDownloadBlockSize() bytes
     * (3 MB by default), buffering one block at a time. Optionally removes the file after download
     * and/or exits the script.
     *
     * It writes directly to the response and does not return normally in the success case, so it
     * must be the LAST thing a request does. Nothing may have been echoed before it: the headers
     * below would fail to send.
     *
     * @param string $filePath The path to the file to be downloaded. Can be a temporary uploaded file or a full path.
     * @param string|null $downloadName The name the file should have when downloaded (including
     *                                  extension). REQUIRED despite the nullable type: when empty,
     *                                  the method returns IMMEDIATELY — nothing is sent, nothing is
     *                                  deleted, no exception is raised and $terminateAfterDownload
     *                                  is not honoured.
     * @param bool $deleteAfterDownload Whether to delete the file after the download completes.
     *                                  Default is false. Ignored for a $_FILES temporary upload,
     *                                  which PHP cleans up itself.
     * @param bool $terminateAfterDownload Whether to call exit() after sending the file. Default is true.
     *
     * @throws \Exception If $filePath does not resolve to a readable file (or to a genuine
     *                    upload), or if it resolved but could not be opened for reading. A missing
     *                    file fails LOUDLY and $terminateAfterDownload is not honoured for it: the
     *                    caller gets the exception instead of an empty HTTP 200. This method used
     *                    to exit(0) on a missing file, so the client received a successful,
     *                    zero-byte response and the caller's try/catch never fired.
     */
    public static function downloadFile(
        string $filePath,
        ?string $downloadName,
        bool $deleteAfterDownload = false,
        bool $terminateAfterDownload = true
    ): void {
        if (empty($downloadName)) {
            return;
        }

        $isUploaded = true;
        $resolvedPath = realpath($filePath);

        if (!empty($resolvedPath) && is_file($resolvedPath)) {
            $isUploaded = false;
            $filePath = $resolvedPath;
        } elseif (!is_uploaded_file($filePath)) {
            $filePath = false;
        }

        $resolvedPath = null;

        // A missing file is an error, not a download. This used to exit(0) (or return quietly),
        // handing the client an empty 200 that is indistinguishable from a successful transfer.
        if (empty($filePath)) {
            throw new \Exception("The file requested for download does not exist or is not readable.");
        }

        header("Pragma: public");
        header("Expires: 0");
        header('Content-Description: File Transfer');
        header('Content-Type: application/download');
        header('Content-Disposition: attachment; filename="' . Str::decodeText($downloadName) . '"');
        header("Content-Length: " . filesize($filePath));
        header("Cache-Control: no-cache");
        header('Connection: close');

        $handle = fopen($filePath, "r");
        if (empty($handle)) {
            throw new \Exception("Unable to open the file for download.");
        }

        ob_start();
        while (!feof($handle)) {
            echo fread($handle, self::getDownloadBlockSize());
            ob_flush();
            flush();
        }
        ob_end_flush();
        fclose($handle);

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
     * "relatorio_final…csv"). '_' is in the allowlist precisely so that the space -> '_' step
     * survives the filter; it is not a path or shell metacharacter, so it costs nothing in safety
     * and keeps word boundaries readable. It follows that a '_' in the result is NOT necessarily
     * the suffix separator — an underscore (or a space) in the original name yields one too. The
     * result is safe to use as a path segment, but it can still be a reserved Windows device name
     * ("con", "nul"), so callers on Windows should not rely on this alone.
     *
     * The result NEVER starts with '-': leading dashes are stripped, because a file name beginning
     * with one is parsed as an OPTION rather than a path by most CLI tools that might later be run
     * over the upload directory ("-rf.txt" reaching rm). A '-' elsewhere in the name is kept.
     *
     * Uniqueness is best-effort: the suffix is a per-second timestamp plus a zero-padded
     * rand(0, 999), so two uploads within the same second collide at roughly 1/1000. Callers that
     * must not overwrite (uploadFileTo() does not check) should verify the target does not exist.
     *
     * @param string $originalFileName The original file name before uploading. Empty returns "".
     * @param int $maxLength Maximum number of characters allowed in the FINAL name, extension
     *                       included. Default (and fallback for a value <= 0) is 125. Must leave
     *                       room for the suffix — a FIXED 18 characters ('_' + 14-digit timestamp
     *                       + 3 padded random digits) — plus ".<extension>". The width is fixed so
     *                       that whether this method throws depends only on its arguments: an
     *                       unpadded random part made the suffix 16-18 characters and the
     *                       InvalidArgumentException below fire per DRAW, for identical arguments.
     * @return string The formatted name: "<base><suffix>.<extension>", never longer than
     *                $maxLength. An extension that sanitises away to nothing is dropped along with
     *                its dot, so the result never ends in '.' (Windows would silently strip it,
     *                orphaning the stored name).
     *
     * @throws \Exception If the current date cannot be read, which would silently drop the
     *                    timestamp and leave only rand(0, 999) guarding against collisions.
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

        // Internal helper function to clean file name.
        // '_' is part of the allowlist so the space -> '_' replacement above it actually survives:
        // the filter used to be [^A-Za-z0-9\-], which stripped the very underscore just inserted,
        // making the str_replace() dead code and silently welding words together.
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

        // getCurrentFormattedDate() catches its own \Exception and returns null, so the documented
        // throw could never fire; the null just concatenated to '' and silently threw away the
        // timestamp — the very uniqueness this method promises.
        $formattedDate = DateTime::getCurrentFormattedDate('YmdHis');
        if (empty($formattedDate)) {
            throw new \Exception("Unable to read the current date to build a unique file name suffix!");
        }
        // The random part is padded to a FIXED 3 digits. Unpadded, rand(0, 999) made the suffix 16,
        // 17 or 18 characters depending on the DRAW, so the $reserved check below — and therefore
        // the InvalidArgumentException — fired only for some draws: renameUploadFile('a', 17) threw
        // roughly 9 times out of 10 and returned a name the rest of the time. A function that
        // rejects its arguments non-deterministically passes CI all week and throws in production.
        $timestampSuffix = '_' . $formattedDate . str_pad((string) rand(0, 999), 3, '0', STR_PAD_LEFT);

        $fileParts = explode('.', $originalFileName);

        // The extension goes through the SAME filter as the base name. It used to be only
        // lowercased, so everything after the last dot ("a.p hp!x") landed in the returned name
        // unfiltered — including separators, for callers whose name does not come from $_FILES.
        $extension = '';
        if (count($fileParts) > 1) {
            $extension = $formatFileName(Str::strToLower((string) array_pop($fileParts)));
        }

        // The suffix, the dot and the extension are part of the returned name and must be paid for
        // out of $maxLength. Previously the whole budget went to the base name and the extension
        // was appended on top, so the limit was exceeded by 1 + strlen(extension) on EVERY call.
        $reserved = Str::strLen($timestampSuffix) + ($extension === '' ? 0 : Str::strLen($extension) + 1);
        if ($maxLength < $reserved) {
            throw new \InvalidArgumentException(
                "maxLength {$maxLength} is too small for the unique suffix and the '{$extension}' extension ({$reserved} characters required)!"
            );
        }

        // A stored name STARTING with '-' is read as an OPTION rather than a path by most CLI tools
        // a consumer might later run over the upload directory ("-rf.txt" becomes flags to rm, and
        // tar/ffmpeg/imagemagick parse it the same way), which hands the uploader control of the
        // first character. '-' stays legal INSIDE the name, where nothing can parse it as a flag.
        // Stripped rather than replaced, so nothing is silently welded together, and stripped
        // BEFORE the truncation: truncation only ever removes from the END, so it cannot bring a
        // leading '-' back, and the budget below is still respected. A base that is nothing but
        // dashes empties out, leaving the suffix's own leading '_' as the first character.
        $base = ltrim($formatFileName(implode('.', $fileParts)), '-');

        $name = Str::subStr($base, 0, $maxLength - $reserved) . $timestampSuffix;

        return $extension === '' ? $name : $name . '.' . $extension;
    }

    /**
     * Handles the upload of a file to a target directory on the server.
     *
     * The stored name comes from renameUploadFile(), so it is sanitised and timestamped rather
     * than the client-supplied name. Only genuine HTTP uploads are accepted (move_uploaded_file).
     *
     * SECURITY: this method does NOT set permissions on the stored file — it lands with
     * move_uploaded_file()'s default (0644 & ~umask, i.e. world-readable). $permissionMode does
     * not change that. It also does NOT validate the file's type, extension or size beyond the
     * $_FILES error code: the caller must do that before trusting the result.
     *
     * @param array|null $uploadedFile The $_FILES entry for the upload. Must contain 'tmp_name',
     *                                 'name' and 'error'. Empty/NULL yields type 7.
     * @param string|null $targetDirectory Destination directory where the file should be saved;
     *                                     created if missing.
     * @param string|null $permissionMode Octal DIRECTORY mode, and ONLY used when
     *                                    $targetDirectory does not already exist and must be
     *                                    created (see createDir()) — on every later upload into
     *                                    an existing directory it is ignored entirely. It is NOT
     *                                    a file mode: pass a directory-shaped value such as
     *                                    '0750'; a file-shaped '0600' would create a directory
     *                                    with no execute bit that nothing can traverse, breaking
     *                                    this and every subsequent upload.
     * @return array{type: int, file: string, path: string} 'file' (stored name) and 'path'
     *         (destination directory) are non-empty ONLY when type === 0; otherwise both are ''.
     *
     * Response['type'] codes:
     *  0 - Success
     *  1 - File exceeds PHP size limit
     *  2 - File exceeds HTML form size limit
     *  3 - File only partially uploaded
     *  4 - No file uploaded
     *  5 - Failed to move uploaded file (also when $uploadedFile is not a real HTTP upload)
     *  6 - Failed to create destination directory
     *  7 - Internal server error (empty input, malformed $_FILES entry, unknown error code, or
     *      any throwable raised on the way — nothing is rethrown)
     */
    public static function uploadFileTo(?array $uploadedFile, ?string $targetDirectory, ?string $permissionMode = null): array {
        $response = [
            'type' => 7,
            'file' => '',
            'path' => ''
        ];

        if (empty($uploadedFile)) {
            return $response;
        }
        try {
            $tempFile = $uploadedFile['tmp_name'];
            $originalName = $uploadedFile['name'];

            $formattedName = self::renameUploadFile($originalName);
            $targetPathInfo = self::getPathInfo($targetDirectory, createPath: $permissionMode ?? true);
            $finalPath = $targetPathInfo['path'];
            $fullFilePath = $finalPath . $formattedName;

            if (!empty($finalPath) && $targetPathInfo['isDir']) {
                $response['type'] = $uploadedFile['error'];

                if ($response['type'] === 0) {
                    if (move_uploaded_file($tempFile, $fullFilePath)) {
                        $response['file'] = $formattedName;
                        $response['path'] = $finalPath;
                    } else {
                        $response['type'] = 5;
                    }
                } elseif (!in_array($response['type'], [1, 2, 3, 4], true)) {
                    $response['type'] = 7;
                }
            } else {
                $response['type'] = 6;
            }
        } catch (\Throwable $err) {
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
