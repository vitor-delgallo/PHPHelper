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
     * Returns the directory and file parts from a given file path.
     *
     * @param string $filePath The full file or directory path
     * @return array {
     *     @type string|null $dir  The directory portion of the path
     *     @type string|null $file The file name portion of the path
     *     @type string|null $path The full reconstructed path (dir + file)
     * }
     */
    public static function getFileAndPath(string $filePath): array {
        $ret = array(
            'dir' => null,
            'file' => null,
            'path' => null,
        );
        if(empty($filePath)) return $ret;

        $filePath = str_replace(array("\\", "/"), "/", $filePath);

        $ret['dir'] = preg_replace('/\/{0,}([^\/]+\.[^\/]+)|(\.[^\/]+)$/i', "", $filePath);
        if(empty($ret['dir'])) {
            $ret['dir'] = "./";
        } else {
            $ret['dir'] .= "/";
            $ret['dir'] = str_replace("/", DIRECTORY_SEPARATOR, $ret['dir']);
        }

        preg_match('/\/{0,}([^\/]+\.[^\/]+)|(\.[^\/]+)$/i', $filePath, $f);
        $filePath = null;
        if(!empty($f) && !empty($f[0])) {
            $filePath = $f[0];
            $f = null;

            if(Str::subStr($filePath, 0, 1) === "/") {
                $filePath = Str::subStr($filePath, 1);
            }
            $filePath = str_replace("/", DIRECTORY_SEPARATOR, $filePath);
        }

        $ret['file'] = $filePath;
        $filePath = null;

        $ret['path'] = $ret['dir'] . ($ret['file'] !== NULL ? $ret['file'] : "");
        return $ret;
    }

    /**
     * Validates and returns a properly formatted directory path if it passes the validation rules.
     *
     * @param string|null $dir Directory or file path containing the desired folder
     * @param bool $keepFile If true, includes the file name in the returned path (if it exists)
     * @param bool $keepFileNotExists If true, includes the file name even if it doesn't exist
     * @param string|bool $createPath If true or string permission (e.g. '0777'), creates the path if it doesn't exist
     *
     * @return string
     */
    public static function getPath(
        ?string $dir,
        bool $keepFile = false,
        bool $keepFileNotExists = false,
        string|bool $createPath = false
    ): string {
        if(empty($dir)) return "";

        if($keepFileNotExists) {
            $keepFile = FALSE;
        } else {
            $keepFile = TRUE;
        }
        if(!is_bool($createPath) && !Validator::isOctal($createPath)) {
            $createPath = FALSE;
        }

        $temp = self::getFileAndPath($dir);
        if(empty($temp['path'])) {
            return "";
        }

        if($createPath !== FALSE && !is_dir($temp['dir'])) {
            self::createDir($temp['dir'], $createPath === TRUE ? NULL : $createPath);
        }

        if(
            !is_dir($temp['dir']) || (
                $keepFile && !is_file($temp['file'])
            )
        ) {
            return "";
        } elseif(
            (
                $keepFile &&
                is_file($temp['file'])
            ) || $keepFileNotExists
        ) {
            return $temp['path'];
        }

        return $temp['dir'];
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

        $filePath = self::getFileAndPath($filePath);
        if(empty($filePath) || empty($filePath['file'])) {
            throw new \Exception("Invalid file path provided for writing!");
        }

        $filePath['dir'] = realpath(self::getPath(dir: $filePath['dir'], createPath: $permissionMode));
        if(empty($filePath['dir'])) {
            throw new \Exception("Invalid directory path provided for file writing!");
        }
        if(Str::subStr($filePath['dir'], -1) !== DIRECTORY_SEPARATOR) $filePath['dir'] .= DIRECTORY_SEPARATOR;
        $filePath['path'] = $filePath['dir'] . $filePath['file'];

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
        $dir = self::getPath($dir);
        if (empty($dir)) return false;

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
        $dir = self::getPath($dir);
        if (empty($dir)) {
            return false;
        }

        $caseFunction = $toUpper ? 'Str::strToUpper' : 'Str::strToLower';

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(realpath($dir), \FilesystemIterator::SKIP_DOTS),
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
        $destinationPath = self::getPath(dir: $destinationPath, createPath: $permissionMode);
        $zipPath = self::getPath(dir: $zipPath, keepFile: true);

        if (empty($destinationPath) || empty($zipPath)) return false;
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
        $outputPath = realpath($outputPath);
        if (empty($source)) return false;

        $zipPath = self::getPath(dir: $outputPath, createPath: $permissionMode);
        if (empty($zipPath)) return false;

        $sourceInfo = pathinfo($source);

        $outputFile = substr($outputPath, min($zipPath, strlen($outputPath)));
        if (empty($outputFile)) {
            $outputFile = $zipPath . Str::removeStringSuffix($sourceInfo['basename'], DIRECTORY_SEPARATOR) . ".zip";
        } else {
            $outputFile = self::getZipName($outputFile);
        }

        $prefixLength = strlen(Str::removeStringSuffix($sourceInfo['dirname'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR);

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

        $outputPath = realpath($outputPath);
        if (empty($outputPath)) return false;

        $zipPath = self::getPath(dir: $outputPath, createPath: $permissionMode);
        if (empty($zipPath)) return false;

        $outputFile = substr($outputPath, min($zipPath, strlen($outputPath)));
        if (empty($outputFile)) return false;

        $outputFile = self::getZipName($outputFile);
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
        $directory = self::getPath($directory);
        if (empty($directory) || empty($files)) return false;

        $success = true;
        foreach ($files as $file) {
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
            $finalPath = self::getPath(dir: $targetDirectory, createPath: $permissionMode);
            $fullFilePath = $finalPath . $formattedName;

            if (!empty($finalPath)) {
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