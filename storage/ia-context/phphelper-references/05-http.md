# HTTP Helper

Class: `VD\PHPHelper\HTTP`
Source file: `src/HTTP.php`

Use for HTTP headers, status, AJAX detection, client IP, browser language, downloads, and simple final responses.

## Methods

| Method | Use |
| --- | --- |
| `isHeaderPresent(string $headerName, ?string $url = null)` | Checks whether a header is present locally or on a remote URL. |
| `sendStatusHeader(int $httpCode, ?string $customMessage = null)` | Sends an HTTP status header. |
| `isXmlHttpRequest()` | Detects AJAX requests through headers. |
| `downloadFile(string $filePath, ?string $downloadName, bool $deleteAfterDownload = false, bool $terminateAfterDownload = true)` | Sends a file for download. |
| `getClientIpAddresses()` | Returns possible client IPs based on headers. |
| `getBrowserLanguage()` | Returns the browser preferred language. |
| `resolveAndExit(mixed $data, bool $asXml = false)` | Resolves data as a JSON/XML response and ends execution. |

## Cautions

- `sendStatusHeader`, `downloadFile`, and `resolveAndExit` manipulate the HTTP response directly.
- In PSR-7/MVC projects, prefer framework response helpers when they already exist.
- `isHeaderPresent` for remote URLs may depend on `ext-curl`.
- IPs from headers can be spoofed; do not use them alone as strong security.
