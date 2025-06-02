<?php

namespace VD\PHPHelper;

class HTTP {
    /**
     * Sends a web service (WS) request using cURL with various options.
     *
     * @param string $url The URL of the web service
     * @param string $requestType Type of request (GET, POST, DELETE, etc.)
     * @param array $queryParams GET parameters
     * @param array|string $postData POST parameters (can be string if raw)
     * @param array $files Files to send with the request
     * @param bool $useRaw Whether to send POST as raw JSON (default false)
     * @param array $headers Headers to send with the request
     * @param int|null $timeout Timeout in seconds (default 60, NULL for infinite)
     * @param int|null $sslHost SSL verify host (0, 1, 2 or NULL)
     * @param int|null $sslPeer SSL verify peer (0, 1 or NULL)
     * @param int $httpVersion HTTP version for cURL (default CURL_HTTP_VERSION_NONE)
     * @param string $encoding Encoding format (default empty string)
     * @param int $maxRedirects Maximum number of redirects (default 10)
     *
     * @return string JSON response or error information
     */
    function callWebService(
        string $url,
        string $requestType = 'GET',
        array $queryParams = [],
        array|string $postData = [],
        array $files = [],
        bool $useRaw = false,
        array $headers = [],
        ?int $timeout = 60,
        ?int $sslHost = null,
        ?int $sslPeer = null,
        int $httpVersion = CURL_HTTP_VERSION_NONE,
        string $encoding = '',
        int $maxRedirects = 10
    ): string {
        if (!extension_loaded('curl')) {
            return "cURL extension not loaded";
        }

        if ($timeout !== null && $timeout < 0) {
            $timeout = null;
        }

        if ($sslHost !== null) {
            if (!in_array($sslHost, [0, 1, 2])) {
                $sslHost = 2;
            }
        }

        if (!empty($files)) {
            $useRaw = false;
        }

        $requestType = strtoupper($requestType);
        $encoding = strtolower($encoding);

        $followRedirect = false;
        if (!empty($maxRedirects)) {
            $followRedirect = true;
        }

        $prefix = '';
        if (Validator::isNumericArray($files)) {
            $prefix = 'f_';
        }

        foreach ($files AS $key => $file) {
            $source = realpath($file);
            if (empty($source) && is_uploaded_file($file)) {
                $source = $file;
            }
            if (!empty($source)) {
                $postData[$prefix . (string) $key] = "@" . $source;
            }
        }

        //TODO: Verify using ODATA
        $builtUrl = URL::appendParamsToUrl(URL::getFormattedUrl($url, false, false), $queryParams);
        $headerArray = URL::buildHttpHeaderArray($headers);

        if (!empty($postData)) {
            if (is_array($postData)) {
                $postData = !$useRaw ? http_build_query($postData) : json_encode($postData, true);
            }
        }

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $builtUrl);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NONE);
        curl_setopt($ch, CURLOPT_ENCODING, $encoding);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, $httpVersion);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (!empty($postData)) curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        if (!empty($headerArray)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);
        if (!empty($requestType)) curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestType);

        curl_setopt($ch, CURLOPT_MAXREDIRS, $maxRedirects);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $followRedirect);

        if ($sslHost !== null) curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $sslHost);
        if ($sslPeer !== null) curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $sslPeer);

        if ($timeout !== null) {
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode < 200 || $httpCode > 299) {
            $response = json_encode([
                'cError' => [
                    'code' => $httpCode,
                    'msg' => addslashes(curl_error($ch)),
                ],
                'response' => (Validator::validateJson($response) ? json_decode($response, true) : $response),
            ], true);
        }

        curl_close($ch);
        return $response;
    }

    /**
     * Checks if a specific header is present in the headers of a response.
     *
     * @param string $headerName Name of the header to look for
     * @param string|null $url Optional URL to check headers against
     * @return string|false Header value if found, false otherwise
     */
    public static function isHeaderPresent(string $headerName, ?string $url = null): string|false {
        if (empty($headerName)) return false;
        $headerName = Str::removeExcessSpaces(Str::strToLower($headerName), false);

        $headers = !empty($url) ? get_headers(URL::getFormattedUrl($url, false, false)) : headers_list();

        foreach ($headers as $entry) {
            [$header, $value] = explode(':', $entry, 2);
            if (Str::removeExcessSpaces(Str::strToLower($header), false) !== $headerName) continue;

            return $value;
        }

        return false;
    }

    /**
     * Sends a custom HTTP status header to the client.
     * Optionally overrides the status message.
     *
     * @param int $httpCode The HTTP status code to send
     * @param string|null $customMessage Optional custom message for the status code
     * @return void
     *
     * @see https://stackoverflow.com/questions/4162223/how-to-send-500-internal-server-error-error-from-a-php-script
     */
    public static function sendStatusHeader(int $httpCode, ?string $customMessage = null): void {
        if (PHP_SAPI === 'cli' || defined('STDIN')) {
            return;
        }

        if (empty($httpCode) || !filter_var($httpCode, FILTER_VALIDATE_INT)) {
            self::sendStatusHeader(500, 'Status codes must be an integer');
            exit(0);
        }

        $httpCodes = [
            100 => 'Continue',
            101 => 'Switching Protocols',
            102 => 'Processing',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            207 => 'Multi-Status',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            307 => 'Temporary Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Request Entity Too Large',
            414 => 'Request-URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Requested Range Not Satisfiable',
            417 => 'Expectation Failed',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            424 => 'Failed Dependency',
            426 => 'Upgrade Required',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
            506 => 'Variant Also Negotiates',
            507 => 'Insufficient Storage',
            509 => 'Bandwidth Limit Exceeded',
            510 => 'Not Extended',
        ];

        $statusText = $customMessage ?? ($httpCodes[$httpCode] ?? 'Unknown');

        // CGI environment
        if (Str::strIPos(PHP_SAPI, 'cgi') === 0) {
            header("Status: $httpCode $statusText", true);
            return;
        }

        $serverProtocol = (
            isset($_SERVER['SERVER_PROTOCOL']) &&
            in_array($_SERVER['SERVER_PROTOCOL'], ['HTTP/1.0', 'HTTP/1.1', 'HTTP/2'], true)
        ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';

        header("$serverProtocol $httpCode $statusText", true, $httpCode);
    }

    /**
     * Verifies if the current request was made via XMLHttpRequest (AJAX).
     * Note: This check depends on the 'X-Requested-With: XMLHttpRequest' header,
     * which is added automatically by jQuery and native XMLHttpRequest,
     * but NOT by Axios or Fetch unless manually set.
     *
     * @return bool
     */
    public static function isXmlHttpRequest(): bool {
        return (
            Str::strToLower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest'
        );
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
        File::downloadFile($filePath, $downloadName, $deleteAfterDownload, $terminateAfterDownload);
    }

    /**
     * Retrieves possible client IP addresses from the current request.
     *
     * Checks the following server keys in order:
     * - HTTP_CLIENT_IP
     * - HTTP_X_FORWARDED_FOR
     * - REMOTE_ADDR
     *
     * Returns an array of unique values found.
     *
     * @return array List of IP addresses found in the request
     *
     * @link https://stackoverflow.com/questions/3003145/how-to-get-the-client-ip-address-in-php
     */
    public static function getClientIpAddresses(): array {
        $ipList = [];
        $headerKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];

        foreach ($headerKeys as $key) {
            if (!Validator::hasProperty($key, $_SERVER)) {
                continue;
            }

            $ip = $_SERVER[$key];
            if (!empty($ip) && !in_array($ip, $ipList)) {
                $ipList[] = $ip;
            }
        }

        return $ipList;
    }

    /**
     * Retrieves the preferred language code from the browser's HTTP_ACCEPT_LANGUAGE header.
     * Returns the full locale code (e.g., "pt-br")
     *
     * @return string Language code used by the browser (e.g., "pt-br", "en")
     */
    public static function getBrowserLanguage(): string {
        $language = 'en';
        if (!isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
            return $language;
        }

        $acceptedLanguages = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        if (!empty($acceptedLanguages[0])) {
            $language = Str::strToLower($acceptedLanguages[0]);
        }

        return $language;
    }

    /**
     * Outputs a value (object, array, JSON string, or scalar) as JSON or XML and exits the script.
     *
     * Automatically sets the `Content-Type` header to `application/json` or `application/xml`.
     * Accepts objects and arrays, and converts them accordingly.
     *
     * @param mixed $data The data to be output
     * @param bool $asXml Whether to output the data as XML instead of JSON (default: false)
     *
     * @return void
     */
    public static function resolveAndExit(mixed $data, bool $asXml = false): void {
        if (is_bool($data)) {
            echo (int) $data;
            exit(0);
        }
        if ($data === null || $data === '') {
            exit(0);
        }

        if (is_object($data)) {
            $data = Parser::objectToArray($data);
        }
        if(Validator::validateJson($data)) {
            $data = json_decode($data, true);
        }
        if (is_array($data)) {
            if (!$asXml) {
                if (self::isHeaderPresent('Content-Type') === false) {
                    header('Content-Type: application/json; charset=utf-8');
                }

                echo json_encode($data, JSON_UNESCAPED_UNICODE);
            } else {
                if (self::isHeaderPresent('Content-Type') === false) {
                    header('Content-Type: application/xml; charset=utf-8');
                }

                Parser::arrayToXml($data, $xml);
                echo $xml->__toString();
                $xml = null;
            }

            exit(0);
        }

        echo $data;
        exit(0);
    }
}