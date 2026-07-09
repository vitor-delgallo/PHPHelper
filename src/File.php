<?php

namespace VD\PHPHelper;

class File {
    /**
     * @var int|float Default mode for creating files
     */
    private static int|float $defaultMode = 0;

    /**
     * @var int Default number of blocks read per operation for file downloads.
     *
     * This defines how much data (in bytes) is read from the source file per loop iteration.
     * For example, 8192 means 8KB chunks.
     */
    private static int $downloadBlockSize = 0;

    /**
     * Gets the number of bytes read per block when downloading files.
     * If no custom value has been set, it defaults to 8192 bytes (8 KB).
     *
     * @return int
     */
    public static function getDownloadBlockSize(): int {
        if (empty(self::$downloadBlockSize)) {
            self::setDownloadBlockSize(3 * 1024 * 1024);
        }

        return self::$downloadBlockSize;
    }

    /**
     * Sets a custom block size (in bytes) for file download operations.
     *
     * @param int $bytes Block size in bytes. If null, reverts to the default (8192).
     * @return void
     */
    public static function setDownloadBlockSize(int $bytes): void {
        self::$downloadBlockSize = $bytes;
    }

    /**
     * Returns the default file permission mode as an octal integer.
     *
     * @return int|float The default permission mode (e.g., "0777")
     */
    public static function getDefaultMode(): int|float {
        if(empty(self::$defaultMode)) {
            self::setDefaultMode("777");
        }
        return self::$defaultMode;
    }

    /**
     * Set the default file permission mode as an octal integer.
     *
     * @param string $defaultMode Default mode for creating files in octet string
     */
    public static function setDefaultMode(string $defaultMode): void {
        if(!Validator::isOctal($defaultMode)) {
            return;
        }
        self::$defaultMode = octdec($defaultMode);
    }

    /**
     * Get the file permission mode as an octal integer.
     *
     * @param string|null $permissionMode Permission mode in octet string
     * @return int|float The default permission mode (e.g., "0777")
     */
    public static function getPermissionMode(?string $permissionMode): int|float {
        if(!Validator::isOctal($permissionMode)) {
            return self::getDefaultMode();
        }
        return octdec($permissionMode);
    }

    /**
     * Creates directories on the server.
     *
     * @param string $path The directory path to be created
     * @param string|null $permissionMode The permission mode for directory creation (e.g., 0777). Default is system-defined.
     * @param bool $recursive Whether the creation should be recursive (may increase memory usage)
     *
     * @return int Returns positive result on success or negative on failure
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
        if (!mkdir($path, $mode, $recursive)) $ret = -2;
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

        if($createPath === null || (!is_bool($createPath) && !Validator::isOctal($createPath))) {
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
     * @param string $filePath The path where the file should be created
     * @param string $content The initial content to write into the file
     * @param bool $append Whether to append the content to the file or overwrite it
     * @param string|null $permissionMode Optional permission mode to apply to the file (e.g., '0777')
     *
     * @throws \Exception
     *
     * @ref https://chmodcommand.com/chmod-2777/
     */
    public static function writeFile(string $filePath, string $content = "", bool $append = false, ?string $permissionMode = null): void {
        if(empty($filePath)) {
            throw new \Exception("File path not provided for writing!");
        };
        $mode = self::getPermissionMode($permissionMode);

        $filePath = self::getPathInfo($filePath, keepFileNotExists: true, createPath: $permissionMode ?? true);
        if(empty($filePath['path']) || empty($filePath['file'])) {
            throw new \Exception("Invalid file path provided for writing!");
        }

        if(empty($filePath['dir']) || !is_dir($filePath['dir'])) {
            throw new \Exception("Invalid directory path provided for file writing!");
        }

        $oldMask = umask(0);
        @chmod($filePath['path'], $mode);
        umask($oldMask);

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
    }

    /**
     * Creates a temporary file, sets its permissions, and returns the file path.
     *
     * @param string $prefix Defines the prefix of the temporary file name
     * @param string $content Initial content to write into the file upon creation
     * @param string|null $permissionMode File permission mode to apply (optional)
     *
     * @return string The full path to the created temporary file
     * @throws \Exception If the file could not be created
     */
    public static function createTempFile(string $prefix = "", string $content = "", ?string $permissionMode = null): string {
        $file = tempnam(
            sys_get_temp_dir(),
            uniqid((genGuid() . (!empty($prefix) ? $prefix : "")), true)
        );
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
     * @param string $dir The directory to process
     * @param bool $toUpper If true, converts names to UPPERCASE; if false, to lowercase
     * @return bool TRUE if all renames succeeded, FALSE if any failed
     *
     * @ref https://stackoverflow.com/questions/32173320/php-rename-all-files-to-lower-case-in-a-directory-recursively
     */
    public static function standardizeFilesCaseRecursive(string $dir, bool $toUpper = false): bool {
        $pathInfo = self::getPathInfo($dir);
        if (empty($pathInfo['path']) || !$pathInfo['isDir']) {
            return false;
        }
        $dir = $pathInfo['path'];

        $caseFunction = $toUpper ? 'Str::strToUpper' : 'Str::strToLower';

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
     * Updates or appends key-value variables in a .env-style file.
     *
     * @param string $filePath Path to the file to be updated
     * @param array $variables Array of variables to write (key => value)
     * @return bool TRUE if successful, FALSE otherwise
     * @throws \Exception
     */
    public static function updateEnvFile(string $filePath, array $variables): bool {
        self::writeFile($filePath);

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
     * Unzips selected files from a .zip archive into a destination directory.
     *
     * @param string $zipPath Path to the .zip file
     * @param string $destinationPath Path where the files should be extracted
     * @param string|array $filesToExtract Files inside the archive to extract
     * @param string|null $permissionMode File permission mode to use when creating folders
     *
     * @return bool|array Returns true on success, false on failure, or an array of failed files
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

        $zip = null;
        $zipOpened = false;
        try {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath) !== true) {
                return false;
            }

            $zipOpened = true;
            $errors = [];

            foreach ($filesToExtract as &$file) {
                $file = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $file);
                if (!str_ends_with($file, DIRECTORY_SEPARATOR)) {
                    $file .= DIRECTORY_SEPARATOR;
                }
            }

            if (!str_ends_with($destinationPath, DIRECTORY_SEPARATOR)) {
                $destinationPath .= DIRECTORY_SEPARATOR;
            }

            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $zip->getNameIndex($i));

                foreach ($filesToExtract AS $fileToExtract) {
                    if (str_starts_with($filename, $fileToExtract)) {
                        $relativePath = substr($filename, Str::strLen($fileToExtract, "UTF-8"));
                        $relativePath = str_replace(["/", "\\"], DIRECTORY_SEPARATOR, $relativePath);

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

                        if (Str::strLen($relativePath, "UTF-8") > 0) {
                            if (str_ends_with($filename, DIRECTORY_SEPARATOR)) {
                                if (!is_dir($destinationPath . $relativePath)) {
                                    if (self::createDir($destinationPath . $relativePath) < 0) {
                                        $errors[$i] = $filename;
                                    }
                                }
                            } else {
                                if (dirname($relativePath) !== ".") {
                                    if (!is_dir($destinationPath . dirname($relativePath))) {
                                        self::createDir($destinationPath . dirname($relativePath));
                                    }
                                }

                                if (@file_put_contents($destinationPath . $relativePath, $zip->getFromIndex($i)) === false) {
                                    $errors[$i] = $filename;
                                }
                            }
                        }
                    }
                }
            }

            $zip->close();
            $zipOpened = false;
        } catch (\Error|\Exception $err) {
        } finally {
            try {
                if ($zipOpened && $zip !== null) {
                    $zip->close();
                }
            } catch (\Throwable) {
            }
        }

        return empty($errors) ? true : $errors;
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
     * @param string $outputPath Destination path for the .zip file (can be a folder or file path)
     * @param string|null $permissionMode Permission mode for file creation (if necessary)
     * @param bool $contentOnly If true, only the contents of the folder will be zipped (not the folder itself)
     *
     * @return bool TRUE on success, FALSE on failure
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
        $zip->open($outputFile, \ZipArchive::CREATE);

        if (!$contentOnly) {
            $zip->addEmptyDir($sourceInfo['basename']);
        } else {
            $prefixLength += strlen($sourceInfo['basename'] . DIRECTORY_SEPARATOR);
        }

        foreach (self::getDirectoryContents($source) as $path) {
            $localPath = substr($path, $prefixLength);

            if (is_file($path)) {
                $zip->addFile($path, $localPath);
            } elseif (is_dir($path)) {
                $zip->addEmptyDir($localPath);
            }
        }

        $zip->close();
        return true;
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
     * @param string $outputPath Destination path for the resulting ZIP file
     * @param string|null $permissionMode Optional permission mode for file creation
     *
     * @return bool Returns TRUE on success or FALSE on failure
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
        $zip->open($outputFile, \ZipArchive::CREATE);

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
                    foreach (self::getDirectoryContents($file) as $subFile) { //REVISE AQUI AAAAAHHHH
                        $relativePath =
                            substr(
                                Str::removeStringSuffix($subFile, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR,
                                strlen(Str::removeStringSuffix($file, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
                            );
                        $relativePath = $localPath . "/" . $relativePath;

                        if (is_file($subFile)) {
                            $zip->addFile($subFile, $relativePath);
                        } elseif (is_dir($subFile)) {
                            $zip->addEmptyDir($relativePath);
                        }
                    }
                } elseif (is_file($file)) {
                    $segments = explode(DIRECTORY_SEPARATOR, $file);
                    $fileName = end($segments);
                    $zip->addFile($file, $localPath . '/' . $fileName);
                }
            }
        }

        $zip->close();
        return true;
    }

    /**
     * Generates a .zip filename by removing the current extension from the given file path.
     *
     * This function splits the input by dots (.) and reconstructs the filename without the last extension,
     * appending ".zip" instead. Useful for converting filenames like "backup.tar.gz" into "backup.tar.zip".
     *
     * @param string $outputFile The original file path or name (e.g., with any extension)
     * @return string The filename ending with ".zip"
     */
    private static function getZipName(string $outputFile): string {
        $parts = explode(".", $outputFile);
        $basePath = "";
        for ($i = 0; $i < count($parts) - 1; $i++) {
            if ($i !== 0) $basePath .= ".";
            $basePath .= $parts[$i];
        }
        return $basePath . ".zip";
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
     * It sets appropriate headers and streams the file in blocks. Optionally removes the file after download and/or exits the script.
     *
     * @param string $filePath The path to the file to be downloaded. Can be a temporary uploaded file or a full path.
     * @param string|null $downloadName The name the file should have when downloaded (including extension).
     * @param bool $deleteAfterDownload Whether to delete the file after the download completes. Default is false.
     * @param bool $terminateAfterDownload Whether to call exit() after sending the file. Default is true.
     *
     * @throws \Exception If the file cannot be opened for reading.
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

        if (empty($filePath)) {
            if ($terminateAfterDownload) {
                exit(0);
            }
            return;
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
     * @param string $originalFileName The original file name before uploading.
     * @param int $maxLength The maximum number of characters allowed in the final name. Default is 125.
     * @return string The formatted and renamed file name with timestamp and extension.
     *
     * @throws \Exception If the system fails to get the current timestamp.
     */
    public static function renameUploadFile(string $originalFileName, int $maxLength = 125): string {
        if (empty($originalFileName)) {
            return "";
        }

        if ($maxLength <= 0) {
            $maxLength = 125;
        }

        // Internal helper function to clean file name
        $formatFileName = function (string $name): string {
            return preg_replace(
                '/[^A-Za-z0-9\-]/',
                '',
                str_replace(
                    ' ',
                    '_',
                    Str::removeAccents($name)
                )
            );
        };

        $timestampSuffix = '_' . DateTime::getCurrentFormattedDate('YmdHis') . rand(0, 999);
        $fileParts = explode('.', $originalFileName);

        if (count($fileParts) === 1) {
            // No file extension
            return Str::subStr($formatFileName($fileParts[0]), 0, $maxLength - Str::strLen($timestampSuffix)) . $timestampSuffix;
        }

        $extension = array_pop($fileParts);
        $extension = Str::strToLower($extension);

        $baseName = implode('.', $fileParts);
        $formattedName = Str::subStr($formatFileName($baseName), 0, $maxLength - Str::strLen($timestampSuffix));

        return $formattedName . $timestampSuffix . '.' . $extension;
    }

    /**
     * Handles the upload of a file to a target directory on the server.
     *
     * @param array|null $uploadedFile The $_FILES array containing the uploaded file data.
     * @param string|null $targetDirectory Destination path where the file should be saved.
     * @param string|null $permissionMode File permissions or directory mode.
     * @return array An array containing the upload status and resulting file name/path.
     *
     * Response['type'] codes:
     *  0 - Success
     *  1 - File exceeds PHP size limit
     *  2 - File exceeds HTML form size limit
     *  3 - File only partially uploaded
     *  4 - No file uploaded
     *  5 - Failed to move uploaded file
     *  6 - Failed to create destination directory
     *  7 - Internal server error
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
