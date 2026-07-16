<?php

namespace VD\PHPHelper;

class HTTP {
    /**
     * Sends an HTTP request using cURL and returns the response body.
     *
     * The URL is requested EXACTLY as supplied: no scheme, host, "www." or trailing slash is
     * ever added or removed. Only $queryParams is appended to it.
     *
     * ERROR CHANNEL — this method does not throw on transport or HTTP errors. Whenever the
     * request does not complete with a 2xx status, the returned string is a JSON envelope:
     *
     *     {"cError":{"code":<int>,"msg":"<string>"},"response":<mixed|null>}
     *
     * where `code` is the HTTP status code (0 when no status line was ever received, e.g. DNS
     * failure or connection refused), `msg` is the cURL error message ('' when the transfer
     * itself succeeded but the status was simply not 2xx), and `response` is whatever body was
     * received before the failure — decoded when it is valid JSON, or null when nothing was
     * received. A caller MUST json_decode() the result and test for the 'cError' key to detect
     * failure; a non-2xx body is never returned on its own.
     *
     * A successful 2xx body is returned VERBATIM and is not wrapped. Note the resulting
     * ambiguity: if the remote endpoint can itself return a top-level "cError" key on success,
     * this envelope cannot be distinguished from it.
     *
     * @param string $url Request URL, used verbatim. Required.
     * @param string $requestType HTTP method; upper-cased before use (GET, POST, PUT, DELETE, ...).
     * @param array $queryParams Query-string parameters, encoded with http_build_query() and
     *                           joined to $url with '?' or '&' depending on whether $url already
     *                           carries a query string.
     * @param array|string $postData Request body. An array is form-encoded
     *                               (application/x-www-form-urlencoded), or JSON-encoded when
     *                               $useRaw is true, or sent as multipart/form-data when $files
     *                               resolves to at least one readable file. A string is sent as a
     *                               raw body verbatim, and is ONLY accepted when $files is empty.
     * @param array $files Files to upload, as fieldName => path. Forces $useRaw to false and makes
     *                     the request multipart/form-data. Each path is resolved with realpath(),
     *                     falling back to is_uploaded_file() for PHP upload temp names; a path that
     *                     resolves to NEITHER is SILENTLY SKIPPED and no upload happens for it.
     *                     When the array's FIRST key is numeric, every field name is prefixed with
     *                     'f_' — so ['/tmp/a.pdf'] is sent under the field name 'f_0'.
     * @param bool $useRaw Send an array $postData as a raw JSON body. Ignored (forced false) when
     *                     $files is non-empty, since multipart and a raw body are exclusive.
     * @param array $headers Headers, as name => value pairs, or as ready-made "Name: value" strings
     *                       under numeric keys (see URL::buildHttpHeaderArray()).
     * @param int|null $timeout Connect and transfer timeout, in seconds. NULL — or any negative
     *                          value, which is coerced to NULL — means no timeout at all.
     * @param int|null $sslHost CURLOPT_SSL_VERIFYHOST: 0, 1 or 2. Any other value is coerced to 2.
     *                          NULL leaves cURL's default (2) in place.
     * @param int|null $sslPeer CURLOPT_SSL_VERIFYPEER: 0 disables peer certificate verification,
     *                          any other value enables it. NULL leaves cURL's default (enabled) in
     *                          place. SECURITY: passing 0 disables TLS certificate validation and
     *                          exposes the request to interception — never do this in production.
     * @param int $httpVersion One of the CURL_HTTP_VERSION_* constants.
     * @param string $encoding Accept-Encoding value; '' lets cURL advertise every encoding it supports.
     * @param int $maxRedirects Maximum redirects to follow; 0 disables redirect following entirely.
     *                          NOTE: redirects are replayed with the same $requestType, because
     *                          CURLOPT_CUSTOMREQUEST survives a redirect.
     *
     * @return string The raw 2xx response body, or the JSON error envelope described above.
     *
     * @throws \InvalidArgumentException If $postData is a string while $files is non-empty — a raw
     *                                   body and a multipart upload cannot both be sent.
     */
    public function callWebService(
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
            return self::buildErrorEnvelope(0, 'cURL extension not loaded', null);
        }

        // A raw string body cannot be carried by a multipart/form-data request. Reject the
        // combination explicitly instead of letting the file loop fatal on a string offset.
        if (!empty($files) && is_string($postData)) {
            throw new \InvalidArgumentException(
                'callWebService(): $postData must be an array when $files is not empty; '
                . 'a raw string body cannot be sent together with a multipart file upload.'
            );
        }

        if ($timeout !== null && $timeout < 0) {
            $timeout = null;
        }

        if ($sslHost !== null && !in_array($sslHost, [0, 1, 2], true)) {
            $sslHost = 2;
        }

        if (!empty($files)) {
            $useRaw = false;
        }

        $requestType = strtoupper($requestType);
        $encoding = strtolower($encoding);

        $followRedirect = !empty($maxRedirects);

        $prefix = Validator::isNumericArray($files) ? 'f_' : '';

        $hasUpload = false;
        foreach ($files as $key => $file) {
            $source = realpath($file);
            if (empty($source) && is_uploaded_file($file)) {
                $source = $file;
            }
            if (!empty($source)) {
                // CURLFile is the only working upload mechanism on PHP >= 5.6: the legacy
                // "@/path" string has been inert since CURLOPT_SAFE_UPLOAD defaulted to true,
                // and it leaked the local absolute path into the request body.
                $postData[$prefix . (string) $key] = new \CURLFile($source);
                $hasUpload = true;
            }
        }

        //TODO: Verify using ODATA
        $builtUrl = $url;
        if (!empty($queryParams)) {
            $builtUrl .= (str_contains($url, '?') ? '&' : '?') . http_build_query($queryParams);
        }
        $headerArray = URL::buildHttpHeaderArray($headers);

        if (is_array($postData) && !empty($postData) && !$hasUpload) {
            // With an upload present the payload must stay an array, otherwise the CURLFile
            // entries would be destroyed and cURL could not emit multipart/form-data.
            $postData = $useRaw
                ? json_encode($postData, JSON_UNESCAPED_UNICODE)
                : http_build_query($postData);
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
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        // CURLINFO_HTTP_CODE is populated as soon as the status line is parsed, so a transfer
        // that dies mid-body still reports 2xx. curl_exec()'s own result is the authority on
        // whether the response is complete.
        if ($response === false || $errNo !== 0 || $httpCode < 200 || $httpCode > 299) {
            return self::buildErrorEnvelope($httpCode, $errMsg, $response);
        }

        return $response;
    }

    /**
     * Builds the JSON error envelope that callWebService() returns for every failed or non-2xx
     * request. This is callWebService()'s only error channel; see its docblock.
     *
     * @param int $code HTTP status code, or 0 when no status line was received.
     * @param string $msg Error message; '' when the transfer succeeded but the status was not 2xx.
     * @param string|false|null $body Body received before the failure, if any. false/null become
     *                                a null 'response'; a valid-JSON body is decoded.
     * @return string JSON: {"cError":{"code":int,"msg":string},"response":mixed|null}.
     *                Invalid UTF-8 in the body is substituted rather than allowed to fail the
     *                encode, so this always returns a valid JSON string.
     */
    private static function buildErrorEnvelope(int $code, string $msg, string|false|null $body): string {
        return json_encode([
            'cError' => [
                'code' => $code,
                'msg' => $msg,
            ],
            'response' => (is_string($body) && Validator::validateJson($body))
                ? json_decode($body, true)
                : (is_string($body) ? $body : null),
        ], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * Looks up a single HTTP header, either among the headers this script is about to send or
     * among those returned by a remote URL.
     *
     * The name is matched case-insensitively and ignoring whitespace. The returned value is
     * trimmed of leading/trailing optional whitespace, which RFC 9110 does not consider part of
     * the field value.
     *
     * @param string $headerName Header name to look for. An empty name always returns false.
     * @param string|null $url When null or empty, inspects the headers already set by THIS script
     *                         through headers_list() — which is ALWAYS empty under the CLI SAPI, so
     *                         this always returns false there. When supplied, the URL is fetched
     *                         VERBATIM with get_headers(): that is a BLOCKING NETWORK REQUEST, and
     *                         it follows redirects per php.ini's max_redirects. Across a redirect
     *                         chain get_headers() returns every hop's headers, and this returns the
     *                         FIRST match — i.e. the header of the FIRST response, not the final one.
     * @return string|false The trimmed header value, or false when the header is absent, when
     *                      $headerName is empty, or when the remote request failed. A header that
     *                      is present but empty returns '' — falsy, yet not false — so callers MUST
     *                      compare with !== false rather than testing truthiness.
     */
    public static function isHeaderPresent(string $headerName, ?string $url = null): string|false {
        if (empty($headerName)) return false;
        $headerName = Str::removeExcessSpaces(Str::strToLower($headerName), false);

        if (!empty($url)) {
            $headers = @get_headers($url);
            // get_headers() returns false on a failed request; foreach over it would warn.
            if (!is_array($headers)) return false;
        } else {
            $headers = headers_list();
        }

        foreach ($headers as $entry) {
            if (!is_string($entry)) continue;

            $parts = explode(':', $entry, 2);
            // get_headers() yields the status line ("HTTP/1.1 200 OK") as element 0, and one more
            // per redirect hop. It carries no colon, so it has no name/value to compare.
            if (count($parts) < 2) continue;

            if (Str::removeExcessSpaces(Str::strToLower($parts[0]), false) !== $headerName) continue;

            return trim($parts[1]);
        }

        return false;
    }

    /**
     * Sends an HTTP status line to the client, optionally overriding the reason phrase.
     *
     * NO-OP under the CLI SAPI, and under any SAPI where the STDIN constant is defined: there is
     * no response to write to. Under the CGI/FastCGI SAPIs a "Status:" header is sent instead of
     * a status line, as those SAPIs require.
     *
     * @param int $httpCode Status code to send. A code this method does not know still goes out,
     *                      with the reason phrase "Unknown".
     * @param string|null $customMessage Reason phrase to send instead of the standard one.
     * @return void Declared for signature completeness. Beware: when $httpCode is 0 this method
     *              does NOT return — it sends a 500 and TERMINATES the script with exit(0). That
     *              path is unreachable under the CLI SAPI, which returns early.
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
     * SECURITY: the header is set by the client and is trivially spoofable. It says how the
     * caller wants the response rendered — it is not a CSRF defence and not an authorisation check.
     *
     * @return bool True when X-Requested-With equals "xmlhttprequest", case-insensitively.
     *              False when the header is absent.
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
     * Thin delegation to File::downloadFile(); see it for the authoritative contract.
     *
     * @param string $filePath The path to the file to be downloaded. Can be a temporary uploaded file or a full path.
     * @param string|null $downloadName The name the file should have when downloaded (including extension).
     * @param bool $deleteAfterDownload Whether to delete the file after the download completes. Default is false.
     * @param bool $terminateAfterDownload Whether to call exit() after sending the file. Default is true.
     *
     * @return void Does not return at all when $terminateAfterDownload is true (the default).
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
     * Collects the candidate client IP addresses for the current request.
     *
     * Reads, in order: HTTP_CLIENT_IP, HTTP_X_FORWARDED_FOR, REMOTE_ADDR. Each value is split on
     * ',' — X-Forwarded-For and Client-IP carry a comma-separated PROXY CHAIN, not a single
     * address — then trimmed, validated with FILTER_VALIDATE_IP and de-duplicated. Entries that
     * are not syntactically valid IP addresses are DROPPED, so the result may legitimately be
     * empty (it always is under the CLI SAPI, where none of these keys exist).
     *
     * SECURITY: HTTP_CLIENT_IP and HTTP_X_FORWARDED_FOR are supplied by the CLIENT and are
     * trivially spoofable; only REMOTE_ADDR is set by the web server. Because those headers are
     * read FIRST, element 0 is NOT an authenticated identity. Do not key authorisation, rate
     * limiting or audit trails on it unless a trusted reverse proxy is known to overwrite these
     * headers on every inbound request.
     *
     * @return array<int, string> Unique, syntactically valid IP addresses, in the order described
     *                            above. Possibly empty.
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

            $raw = $_SERVER[$key];
            if (!is_string($raw) || $raw === '') {
                continue;
            }

            foreach (explode(',', $raw) as $candidate) {
                $candidate = trim($candidate);
                if ($candidate === '' || filter_var($candidate, FILTER_VALIDATE_IP) === false) {
                    continue;
                }
                if (in_array($candidate, $ipList, true)) {
                    continue;
                }

                $ipList[] = $candidate;
            }
        }

        return $ipList;
    }

    /**
     * Returns the language tag the client most prefers, from the Accept-Language request header.
     *
     * Entries are ranked by their RFC 9110 quality value, highest first; an entry with no "q"
     * parameter defaults to q=1. Ties keep the order they appear in the header. Entries with q=0
     * ("not acceptable"), the "*" wildcard, and anything that is not a well-formed language tag
     * are discarded.
     *
     * SECURITY: the result comes from a CLIENT-CONTROLLED header. It is validated for SHAPE only
     * (RFC 5646 grammar: alphabetic primary subtag, then alphanumeric subtags joined by '-'), and
     * NOT against any list of real locales — a well-formed tag naming a locale that does not exist
     * still passes. Treat it as untrusted input: never interpolate it straight into a file path,
     * an SQL statement or a shell command.
     *
     * @return string The preferred language tag, lower-cased (e.g. "pt-br", "en"). Falls back to
     *                "en" when the header is absent, empty, or holds no usable tag.
     */
    public static function getBrowserLanguage(): string {
        $fallback = 'en';

        $header = $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '';
        if (!is_string($header) || trim($header) === '') {
            return $fallback;
        }

        $ranked = [];
        foreach (explode(',', $header) as $entry) {
            $params = explode(';', $entry);
            $tag = trim((string) array_shift($params));

            if (!preg_match('/^[A-Za-z]{1,8}(?:-[A-Za-z0-9]{1,8})*$/', $tag)) {
                continue;
            }

            $quality = 1.0;
            foreach ($params as $param) {
                [$name, $value] = array_pad(explode('=', $param, 2), 2, '');
                if (Str::strToLower(trim($name)) !== 'q') {
                    continue;
                }
                $value = trim($value);
                if (is_numeric($value)) {
                    $quality = (float) $value;
                }
                break;
            }

            // q=0 explicitly means "not acceptable".
            if ($quality <= 0) {
                continue;
            }

            $ranked[] = ['tag' => Str::strToLower($tag), 'q' => $quality];
        }

        if (empty($ranked)) {
            return $fallback;
        }

        // PHP >= 8.0 sorts are stable, so equal q values keep their header order.
        usort($ranked, static fn(array $a, array $b): int => $b['q'] <=> $a['q']);

        return $ranked[0]['tag'];
    }

    /**
     * Outputs $data as JSON (or XML) and TERMINATES the script.
     *
     * This method NEVER returns to its caller: every branch ends in exit(0).
     *
     * Conversion rules, applied in order:
     *  - bool          → echoes "1" or "0", with no Content-Type, then exits.
     *  - null or ''    → exits with no output at all.
     *  - object        → converted via Parser::objectToArray(), then treated as an array.
     *  - a STRING that is valid JSON → decoded first, then treated as whatever it decodes to.
     *  - array         → JSON-encoded (or XML-encoded when $asXml), with the matching Content-Type.
     *  - anything else (int, float, a string that is not JSON) → echoed raw, with no Content-Type.
     *
     * The Content-Type header is only sent when this script has not already set one (see
     * isHeaderPresent()). Under the CLI SAPI headers_list() is always empty, so it is always
     * "sent" — where header() is itself a no-op.
     *
     * @param mixed $data The data to be output.
     * @param bool $asXml Output the data as XML instead of JSON. Only affects the array branch;
     *                    a scalar is echoed raw either way.
     *
     * @return void Declared for signature completeness only — this method always exits.
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
        // Validator::validateJson() takes ?string: probing a non-string here is a TypeError,
        // which used to make the whole array branch below unreachable.
        if (is_string($data) && Validator::validateJson($data)) {
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
                // asXML(), not __toString(): __toString() yields the element's TEXT content,
                // which for a container element is the empty string.
                echo $xml->asXML();
                $xml = null;
            }

            exit(0);
        }

        echo $data;
        exit(0);
    }
}
