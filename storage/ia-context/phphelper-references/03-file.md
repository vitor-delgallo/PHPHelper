# File Helper

Class: `VD\PHPHelper\File`
Source file: `src/File.php`

Use for directories, paths, file reading/writing, `.env`, zip/unzip, uploads, and downloads.

## Methods

| Method | Use |
| --- | --- |
| `getDownloadBlockSize()` | Returns the block size for file downloads. |
| `setDownloadBlockSize(int $bytes)` | Sets the block size for downloads. |
| `getDefaultMode()` | Returns the default permission for creating files/directories. |
| `setDefaultMode(string $defaultMode)` | Sets the default permission as an octal string. |
| `getPermissionMode(?string $permissionMode)` | Converts octal permission or returns the default. |
| `createDir(string $path, ?string $permissionMode = null, bool $recursive = true)` | Creates a directory, optionally recursively. |
| `getPathInfo(?string $path, bool $keepFile = false, bool $keepFileNotExists = false, string|bool|null $createPath = false)` | Normalizes a path and returns parts such as path, dir, file, and flags. |
| `writeFile(string $filePath, string $content = "", bool $append = false, ?string $permissionMode = null)` | Writes or appends content to a file. |
| `createTempFile(string $prefix = "", string $content = "", ?string $permissionMode = null)` | Creates a temporary file with optional prefix and content. |
| `deleteFoldersRecursively(string $dir)` | Deletes folders recursively. Use with extreme care. |
| `resetFolder(string $dir)` | Cleans/recreates a folder. Use with care. |
| `standardizeFilesCaseRecursive(string $dir, bool $toUpper = false)` | Standardizes file name casing recursively. |
| `parseEnvFile(string $filePath)` | Reads a `.env` file and returns variables. |
| `updateEnvFile(string $filePath, array $variables)` | Updates variables in a `.env` file. |
| `unzipFile(string $zipPath, string $destinationPath, string|array $filesToExtract, ?string $permissionMode = null)` | Extracts a full zip or specific files. |
| `getDirectoryContents(string $directory, array &$results = [])` | Lists directory contents recursively. |
| `zipDirectory(string $source, string $outputPath, ?string $permissionMode = null, bool $contentOnly = false)` | Compresses a directory. |
| `zipMultipleFiles(array $files, string $outputPath, ?string $permissionMode = null)` | Compresses a list of files. |
| `deleteFiles(array $files, string $directory)` | Deletes specific files inside a directory. |
| `downloadFile(string $filePath, ?string $downloadName, bool $deleteAfterDownload = false, bool $terminateAfterDownload = true)` | Sends a file for download through headers. |
| `renameUploadFile(string $originalFileName, int $maxLength = 125)` | Normalizes an uploaded file name. |
| `uploadFileTo(?array $uploadedFile, ?string $targetDirectory, ?string $permissionMode = null)` | Moves an upload to the target directory and returns the result. |

## Cautions

- Destructive methods (`deleteFoldersRecursively`, `resetFolder`, `deleteFiles`) require strict path verification.
- `downloadFile` sends headers and can terminate execution.
- `updateEnvFile` can change sensitive configuration; never write secrets to logs.
- `uploadFileTo` should be combined with extension/size/type validation in the application flow.
