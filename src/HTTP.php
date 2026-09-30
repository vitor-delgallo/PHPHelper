<?php

namespace VD\PHPHelper;

class HTTP {
    /**
     * Standard reason phrases, used by sendStatusHeader() when no custom message is supplied.
     */
    private const STATUS_TEXTS = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        103 => 'Early Hints',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-Status',
        208 => 'Already Reported',
        226 => 'IM Used',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        307 => 'Temporary Redirect',
        308 => 'Permanent Redirect',
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
        418 => "I'm a teapot",
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        509 => 'Bandwidth Limit Exceeded',
        510 => 'Not Extended',
        511 => 'Network Authentication Required',
    ];

    /**
     * Sends an HTTP request using cURL and returns the response body.
     *
     * The URL is requested EXACTLY as supplied: no scheme, host, "www." or trailing slash is
     * ever added or removed. Only $queryParams is appended to it.
     *
     * ONLY http:// AND https:// ARE SPOKEN, for the request itself and for every redirect hop.
     * libcurl otherwise honours file://, ftp://, gopher://, dict://, smb:// and more, so a
     * caller-influenced $url used to be a local-file read: callWebService('file:///etc/passwd')
     * handed the file back inside the error envelope's 'response'. Any other scheme now fails
     * with the error envelope (code 0, cURL's "protocol not supported" message).
     *
     * ERROR CHANNEL — this method does not throw on transport or HTTP errors. Whenever the
     * request does not complete with a 2xx status, the returned string is a JSON envelope:
     *
     *     {"cError":{"code":<int>,"msg":"<string>"},"response":<mixed|null>}
     *
     * where `code` is the HTTP status code (0 when no status line was ever received, e.g. DNS
     * failure or connection refused), `msg` is the cURL error message ('' when the transfer
     * itself succeeded but the status was simply not 2xx), and `response` is whatever body was
     * received before the failure — embedded as JSON when it is valid JSON (VERBATIM, so an
     * integer beyond PHP_INT_MAX keeps every digit), as a JSON string otherwise, or null when
     * nothing was received. A caller MUST json_decode() the result and test for the 'cError' key
     * to detect failure; a non-2xx body is never returned on its own.
     *
     * A successful 2xx body is returned VERBATIM and is not wrapped. Note the resulting
     * ambiguity: if the remote endpoint can itself return a top-level "cError" key on success,
     * this envelope cannot be distinguished from it.
     *
     * CALLER ERRORS THROW. Everything above describes the remote side failing. A request that
     * cannot be built as asked — see @throws — is a bug in the calling code, and is reported as
     * an \InvalidArgumentException BEFORE any connection is opened, rather than being sent in
     * some degraded form.
     *
     * @param string $url Request URL, used verbatim. Required. http(s) only, see above.
     * @param string $requestType HTTP method; trimmed and upper-cased before use (GET, POST, PUT,
     *                            DELETE, ...). It must be an RFC 9110 token: libcurl writes it into
     *                            the request line verbatim, so a method carrying CR/LF or a space
     *                            used to smuggle extra headers — or a whole second request line —
     *                            onto the wire. '' lets cURL pick (GET, or POST when a body is set).
     *                            HEAD is sent as a real HEAD (CURLOPT_NOBODY): as a bare custom
     *                            method cURL waited for the body the Content-Length announced, which
     *                            a HEAD response never carries, so every HEAD request "failed".
     * @param array $queryParams Query-string parameters, encoded with http_build_query() and
     *                           joined to $url with '?' or '&' depending on whether $url already
     *                           carries a query string.
     * @param array|string $postData Request body. An array is form-encoded
     *                               (application/x-www-form-urlencoded), or JSON-encoded when
     *                               $useRaw is true, or sent as multipart/form-data when there is at
     *                               least one upload — an entry of $files, or a \CURLFile anywhere in
     *                               $postData itself (it used to be form-encoded instead, which put
     *                               the file's absolute LOCAL PATH in the body and uploaded nothing).
     *                               Nested arrays become bracketed field names ("a[b]") in multipart
     *                               exactly as in the form-encoded body; they used to lose their
     *                               inner keys. A string is sent as a raw body verbatim — including
     *                               "0" — and is ONLY accepted when $files is empty.
     * @param array $files Files to upload, as fieldName => path, or fieldName => \CURLFile when you
     *                     need to choose the uploaded filename or MIME type yourself. Forces $useRaw
     *                     to false and makes the request multipart/form-data. A path is resolved with
     *                     realpath() (falling back to is_uploaded_file() for PHP upload temp names)
     *                     and MUST name a readable regular file — anything else throws, where it used
     *                     to be SILENTLY SKIPPED so the request went out without the upload and still
     *                     reported success. The file is announced under its BASENAME only; the
     *                     absolute local path used to be sent as the multipart filename.
     *                     When EVERY key is an integer, each field name is prefixed with 'f_' — so
     *                     ['/tmp/a.pdf'] is sent under the field name 'f_0'.
     * @param bool $useRaw Send an array $postData as a raw JSON body. A "Content-Type:
     *                     application/json" header is added unless $headers already sets a
     *                     Content-Type; without it cURL labelled the JSON as form-urlencoded. Ignored
     *                     (forced false) when $files is non-empty, since multipart and a raw body are
     *                     exclusive.
     * @param array $headers Headers, as name => value pairs, or as ready-made "Name: value" strings
     *                       under numeric keys (see URL::buildHttpHeaderArray()). Every resulting
     *                       line must be a string free of CR, LF and NUL — libcurl sends header
     *                       lines verbatim, so an embedded line break injected extra headers.
     * @param int|null $timeout Connect and transfer timeout, in seconds. NULL — or any negative
     *                          value, which is coerced to NULL — means no transfer timeout at all
     *                          (and cURL's own 300s connect timeout). 0 means the same to cURL.
     * @param int|null $sslHost CURLOPT_SSL_VERIFYHOST: 0, 1 or 2. Any other value is coerced to 2.
     *                          NULL leaves cURL's default (2) in place.
     * @param int|null $sslPeer CURLOPT_SSL_VERIFYPEER: 0 disables peer certificate verification,
     *                          any other value enables it. NULL leaves cURL's default (enabled) in
     *                          place. SECURITY: passing 0 disables TLS certificate validation and
     *                          exposes the request to interception — never do this in production.
     * @param int $httpVersion One of the CURL_HTTP_VERSION_* constants.
     * @param string $encoding Accept-Encoding value; '' lets cURL advertise every encoding it supports.
     * @param int $maxRedirects Maximum redirects to follow; 0 — or any negative value, which is
     *                          coerced to 0 — disables redirect following entirely, so a 3xx comes
     *                          back as the error envelope with that status.
     *                          NOTE: redirects are replayed with the same $requestType, because
     *                          CURLOPT_CUSTOMREQUEST survives a redirect. Custom $headers are sent
     *                          to every hop as well (libcurl only withholds Authorization and Cookie
     *                          from a different host), so do not put secrets in other headers of a
     *                          request that may be redirected off-site.
     *
     * @return string The raw 2xx response body, or the JSON error envelope described above.
     *
     * @throws \InvalidArgumentException If $postData is a string while $files is non-empty; if
     *                                   $requestType is not an RFC 9110 token; if a header line is
     *                                   not a string or contains CR/LF/NUL; if an entry of $files is
     *                                   neither a \CURLFile nor a path to a readable regular file;
     *                                   or if $useRaw is set and $postData cannot be JSON-encoded
     *                                   (e.g. invalid UTF-8, NAN) — that used to send an EMPTY body.
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

        $requestType = strtoupper(trim($requestType));
        if ($requestType !== '' && preg_match('/^[!#$%&\'*+.^_`|~0-9A-Z-]+$/', $requestType) !== 1) {
            throw new \InvalidArgumentException(
                'callWebService(): $requestType must be an HTTP method token (letters, digits and '
                . '!#$%&\'*+.^_`|~-); whitespace and line breaks would be written into the request line.'
            );
        }

        if ($timeout !== null && $timeout < 0) {
            $timeout = null;
        }

        if ($sslHost !== null && !in_array($sslHost, [0, 1, 2], true)) {
            $sslHost = 2;
        }

        // CURLOPT_MAXREDIRS reads -1 as "unlimited"; the documented meaning of "not positive" is
        // "do not follow".
        if ($maxRedirects < 0) {
            $maxRedirects = 0;
        }

        if (!empty($files)) {
            $useRaw = false;
        }

        $encoding = strtolower($encoding);
        $headerArray = self::buildHeaderLines($headers);

        $prefix = Validator::isNumericArray($files) ? 'f_' : '';
        $uploads = [];
        foreach ($files as $key => $file) {
            $uploads[$prefix . (string) $key] = self::toCurlFile($file, (string) $key);
        }

        //TODO: Verify using ODATA
        $builtUrl = $url;
        if (!empty($queryParams)) {
            $builtUrl .= (str_contains($url, '?') ? '&' : '?') . http_build_query($queryParams);
        }

        if (is_array($postData) && (!empty($uploads) || self::containsCurlFile($postData))) {
            // Multipart: the payload must stay an array so cURL can emit multipart/form-data, and it
            // must be FLAT — cURL cannot express a nested array and silently dropped the inner keys.
            $postData = self::flattenMultipartFields($postData);
            foreach ($uploads as $field => $curlFile) {
                $postData[$field] = $curlFile;
            }
        } elseif (is_array($postData) && $postData !== []) {
            if ($useRaw) {
                try {
                    $postData = json_encode($postData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                } catch (\JsonException $e) {
                    // json_encode() used to return false here, which the `!empty()` body check read
                    // as "no body": the request went out EMPTY and the caller never knew.
                    throw new \InvalidArgumentException(
                        'callWebService(): $postData could not be JSON-encoded: ' . $e->getMessage(),
                        0,
                        $e
                    );
                }

                if (!self::hasHeaderLine($headerArray, 'Content-Type')) {
                    $headerArray[] = 'Content-Type: application/json';
                }
            } else {
                $postData = http_build_query($postData);
            }
        }

        // Not empty(): the raw string body "0" is a real body.
        $hasBody = is_array($postData) ? $postData !== [] : $postData !== '';

        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $builtUrl);
        curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_NONE);
        curl_setopt($ch, CURLOPT_ENCODING, $encoding);
        curl_setopt($ch, CURLOPT_HTTP_VERSION, $httpVersion);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        if (defined('CURLOPT_PROTOCOLS_STR')) {
            curl_setopt($ch, CURLOPT_PROTOCOLS_STR, 'http,https');
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS_STR, 'http,https');
        } else {
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        }

        if ($hasBody) curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
        if (!empty($headerArray)) curl_setopt($ch, CURLOPT_HTTPHEADER, $headerArray);

        // Set AFTER the body: CURLOPT_POSTFIELDS switches the request to POST, and the method the
        // caller asked for has to win.
        if ($requestType === 'HEAD') {
            curl_setopt($ch, CURLOPT_NOBODY, true);
        } elseif ($requestType !== '') {
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $requestType);
        }

        curl_setopt($ch, CURLOPT_MAXREDIRS, $maxRedirects);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, $maxRedirects > 0);

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
        // No curl_close(): it has been a no-op since PHP 8.0 and is deprecated as of PHP 8.5. The
        // handle is released when the last reference goes.
        unset($ch);

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
     *                                a null 'response'. A valid-JSON body is embedded verbatim.
     * @return string JSON: {"cError":{"code":int,"msg":string},"response":mixed|null}.
     *                Invalid UTF-8 in a non-JSON body is substituted rather than allowed to fail
     *                the encode, so this always returns a valid JSON string.
     */
    private static function buildErrorEnvelope(int $code, string $msg, string|false|null $body): string {
        $flags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        $error = json_encode(['code' => $code, 'msg' => $msg], $flags);

        // Spliced in rather than decoded and re-encoded: a round trip turns an integer beyond
        // PHP_INT_MAX into a float, so an order id of 12345678901234567890123 came back as
        // 1.2345678901234568e+22. validateJson() guarantees the splice is well-formed JSON.
        $response = (is_string($body) && Validator::validateJson($body))
            ? trim($body)
            : json_encode(is_string($body) ? $body : null, $flags);

        return '{"cError":' . $error . ',"response":' . $response . '}';
    }

    /**
     * Turns $headers into cURL header lines, refusing any line that could split into two.
     *
     * @param array $headers As accepted by callWebService().
     * @return list<string> "Name: value" lines.
     *
     * @throws \InvalidArgumentException On a non-string line or one containing CR, LF or NUL.
     */
    private static function buildHeaderLines(array $headers): array {
        $lines = array_values(URL::buildHttpHeaderArray($headers));

        foreach ($lines as $line) {
            if (!is_string($line) || strpbrk($line, "\r\n\0") !== false) {
                throw new \InvalidArgumentException(
                    'callWebService(): every header must be a single line of text; CR, LF and NUL '
                    . 'are refused because libcurl would send them verbatim and inject extra headers.'
                );
            }
        }

        return $lines;
    }

    /**
     * Tells whether $lines already sets the header $name (case-insensitively).
     *
     * @param list<string> $lines "Name: value" lines, as produced by buildHeaderLines().
     * @param string $name Header name to look for.
     * @return bool
     */
    private static function hasHeaderLine(array $lines, string $name): bool {
        foreach ($lines as $line) {
            $colon = strpos($line, ':');
            $semicolon = strpos($line, ';');
            // cURL's "Name;" form sends an empty header, so it counts as set too.
            $end = $colon === false ? $semicolon : ($semicolon === false ? $colon : min($colon, $semicolon));
            if ($end !== false && strcasecmp(trim(substr($line, 0, $end)), $name) === 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves one $files entry into the \CURLFile to upload.
     *
     * @param mixed $file A \CURLFile (used as-is), or a path to a readable regular file.
     * @param string $field The entry's key, for the error message.
     * @return \CURLFile Announced under the file's basename, never its local absolute path.
     *
     * @throws \InvalidArgumentException When $file is neither.
     */
    private static function toCurlFile(mixed $file, string $field): \CURLFile {
        if ($file instanceof \CURLFile) {
            return $file;
        }

        // realpath('') is the current working directory, and a NUL byte makes it throw a
        // ValueError: both are refused before they reach it.
        if (is_string($file) && $file !== '' && !str_contains($file, "\0")) {
            $source = realpath($file);
            if ($source === false && is_uploaded_file($file)) {
                $source = $file;
            }

            if ($source !== false && is_file($source) && is_readable($source)) {
                // CURLFile's postname defaults to the path it was given, so the multipart filename
                // was the ABSOLUTE LOCAL PATH ($_FILES[...]['full_path'] on a PHP receiver).
                return new \CURLFile($source, '', basename($source));
            }
        }

        throw new \InvalidArgumentException(
            "callWebService(): \$files['{$field}'] must be a \\CURLFile or the path of a readable file."
        );
    }

    /**
     * Whether a \CURLFile sits anywhere in $data.
     *
     * @param array $data The request payload.
     * @return bool
     */
    private static function containsCurlFile(array $data): bool {
        foreach ($data as $value) {
            if ($value instanceof \CURLFile || (is_array($value) && self::containsCurlFile($value))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Flattens a multipart payload into the bracketed field names http_build_query() would use.
     *
     * NULL entries are skipped and booleans become '1'/'0', both as in http_build_query(). An
     * object is a \CURLFile (kept), a \Stringable (cast), or anything else (its public properties).
     *
     * @param array $fields The payload.
     * @param string $prefix Field-name prefix for the current nesting level.
     * @return array<string|int, string|\CURLFile>
     */
    private static function flattenMultipartFields(array $fields, string $prefix = ''): array {
        $flat = [];

        foreach ($fields as $key => $value) {
            $name = $prefix === '' ? (string) $key : $prefix . '[' . $key . ']';

            if ($value instanceof \CURLFile) {
                $flat[$name] = $value;
            } elseif (is_array($value) || (is_object($value) && !$value instanceof \Stringable)) {
                foreach (self::flattenMultipartFields(is_array($value) ? $value : get_object_vars($value), $name) as $subName => $subValue) {
                    $flat[$subName] = $subValue;
                }
            } elseif ($value === null) {
                continue;
            } elseif (is_bool($value)) {
                $flat[$name] = $value ? '1' : '0';
            } else {
                $flat[$name] = (string) $value;
            }
        }

        return $flat;
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
     *                         this always returns false there. When supplied, it must be an http://
     *                         or https:// URL (anything else returns false without any I/O: the
     *                         stream layer would otherwise try whatever wrapper the scheme names),
     *                         and it is fetched VERBATIM with get_headers(): that is a BLOCKING
     *                         NETWORK REQUEST, bounded only by default_socket_timeout, and it
     *                         follows redirects per php.ini's max_redirects. Across a redirect chain
     *                         only the FINAL response is inspected — the one the chain actually
     *                         delivered. (This used to return the FIRST hop's header, i.e. the
     *                         redirect's own Content-Type rather than the resource's.)
     * @return string|false The trimmed header value, or false when the header is absent, when
     *                      $headerName is empty, or when the remote request failed. A header that
     *                      is present but empty returns '' — falsy, yet not false — so callers MUST
     *                      compare with !== false rather than testing truthiness. When the header
     *                      repeats, the first occurrence (of the final response) wins.
     */
    public static function isHeaderPresent(string $headerName, ?string $url = null): string|false {
        if (empty($headerName)) return false;
        $headerName = Str::removeExcessSpaces(Str::strToLower($headerName), false);

        if (!empty($url)) {
            if (preg_match('#^https?://#i', $url) !== 1) return false;

            $headers = @get_headers($url);
            // get_headers() returns false on a failed request; foreach over it would warn.
            if (!is_array($headers)) return false;

            // Every hop contributes its own status line followed by its own headers; keep only the
            // block that starts at the LAST status line.
            $finalStart = 0;
            foreach ($headers as $index => $entry) {
                if (is_string($entry) && preg_match('#^HTTP/\d#i', $entry) === 1) {
                    $finalStart = $index;
                }
            }
            $headers = array_slice($headers, (int) $finalStart);
        } else {
            $headers = headers_list();
        }

        foreach ($headers as $entry) {
            if (!is_string($entry)) continue;

            $parts = explode(':', $entry, 2);
            // get_headers() yields each hop's status line ("HTTP/1.1 200 OK"). It carries no colon,
            // so it has no name/value to compare.
            if (count($parts) < 2) continue;

            if (Str::removeExcessSpaces(Str::strToLower($parts[0]), false) !== $headerName) continue;

            return trim($parts[1]);
        }

        return false;
    }

    /**
     * Sends an HTTP status line to the client, optionally overriding the reason phrase.
     *
     * NO-OP under the CLI SAPI, under any SAPI where the STDIN constant is defined (there is no
     * response to write to), and once the headers have already been sent — calling header() then
     * only raises "headers already sent", which a strict error handler turns into an exception.
     * Under the CGI/FastCGI SAPIs a "Status:" header is sent instead of a status line, as those
     * SAPIs require.
     *
     * @param int $httpCode Status code to send; must be 100–599. A valid code this method does not
     *                      know still goes out, with the reason phrase "Unknown".
     * @param string|null $customMessage Reason phrase to send instead of the standard one. Control
     *                                   characters (CR and LF included) are replaced with spaces:
     *                                   header() REFUSES a line break, so such a message used to
     *                                   send NO status at all and the response went out as 200.
     * @return bool TRUE when the status was handed to header(); FALSE when nothing was sent (CLI,
     *              or headers already sent).
     *
     * @throws \InvalidArgumentException If $httpCode is outside 100–599 — checked under every SAPI,
     *                                   CLI included. (This used to send a 500 and TERMINATE the
     *                                   script with exit(0) for 0, and to emit a malformed status
     *                                   line for any other out-of-range value.)
     *
     * @see https://stackoverflow.com/questions/4162223/how-to-send-500-internal-server-error-error-from-a-php-script
     */
    public static function sendStatusHeader(int $httpCode, ?string $customMessage = null): bool {
        if ($httpCode < 100 || $httpCode > 599) {
            throw new \InvalidArgumentException(
                "sendStatusHeader(): {$httpCode} is not an HTTP status code (expected 100-599)."
            );
        }

        if (PHP_SAPI === 'cli' || defined('STDIN') || headers_sent()) {
            return false;
        }

        $statusText = $customMessage === null
            ? (self::STATUS_TEXTS[$httpCode] ?? 'Unknown')
            : trim((string) preg_replace('/[\x00-\x08\x0A-\x1F\x7F]+/', ' ', $customMessage));

        // CGI environment
        if (Str::strIPos(PHP_SAPI, 'cgi') === 0) {
            header("Status: $httpCode $statusText", true);
            return true;
        }

        $serverProtocol = (
            isset($_SERVER['SERVER_PROTOCOL']) &&
            in_array($_SERVER['SERVER_PROTOCOL'], ['HTTP/1.0', 'HTTP/1.1', 'HTTP/2', 'HTTP/2.0'], true)
        ) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';

        header("$serverProtocol $httpCode $statusText", true, $httpCode);
        return true;
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
     *              False when the header is absent or not a string.
     */
    public static function isXmlHttpRequest(): bool {
        $header = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';

        return is_string($header) && Str::strToLower(trim($header)) === 'xmlhttprequest';
    }

    /**
     * Manually forces the download of a file to the client.
     *
     * This function handles both uploaded temporary files (via $_FILES['tmp_name']) and regular files from disk.
     * It sets appropriate headers and streams the file in blocks. Optionally removes the file after download and/or exits the script.
     *
     * Thin delegation to File::downloadFile(); see it for the authoritative contract. Both failure
     * modes below are raised BEFORE any header or byte is sent.
     *
     * @param string $filePath The path to the file to be downloaded. Can be a temporary uploaded file or a full path.
     * @param string|null $downloadName The name the file should have when downloaded (including
     *                                  extension). REQUIRED despite the nullable type: null, '' or
     *                                  whitespace-only throws (it used to make the call silently do
     *                                  nothing at all).
     * @param bool $deleteAfterDownload Whether to delete the file after the download completes. Default is false.
     * @param bool $terminateAfterDownload Whether to call exit() after sending the file. Default is true.
     *
     * @return void Does not return at all when $terminateAfterDownload is true (the default).
     *
     * @throws \InvalidArgumentException If $downloadName is null, empty or whitespace-only.
     * @throws \Exception If the file does not exist, is not readable, or cannot be opened.
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
     * Collects the candidate client IP addresses for the current request, MOST TRUSTWORTHY FIRST.
     *
     * Order:
     *  1. REMOTE_ADDR — the socket peer, set by the web server. The only value a client cannot
     *     forge (behind a reverse proxy it is the proxy's address).
     *  2. HTTP_X_FORWARDED_FOR, read RIGHT TO LEFT. Each proxy APPENDS the address it received the
     *     request from, so the rightmost entry was written by the nearest hop and the leftmost —
     *     the "original client" — is whatever the client itself chose to send.
     *  3. HTTP_CLIENT_IP, in header order.
     * Each value is split on ',', trimmed, validated with FILTER_VALIDATE_IP and de-duplicated
     * (first occurrence wins). Entries that are not syntactically valid IP addresses — including
     * an address with a ":port" suffix — are DROPPED, so the result may legitimately be empty (it
     * always is under the CLI SAPI, where none of these keys exist).
     *
     * BEHAVIOUR CHANGE: this used to return the client-controlled headers FIRST (Client-IP, then
     * X-Forwarded-For left to right, then REMOTE_ADDR), so the natural `$ips[0]` was attacker-chosen
     * — one forged header was enough to dodge an IP rate limit or plant a fake address in an audit
     * log. `$ips[0]` is now the socket peer.
     *
     * SECURITY: entries after the first are still only as trustworthy as the proxies in front of
     * you. Behind N trusted reverse proxies, the real client is the entry at index N; anything
     * beyond that was supplied by the client.
     *
     * @return array<int, string> Unique, syntactically valid IP addresses, in the order described
     *                            above. Possibly empty.
     *
     * @link https://stackoverflow.com/questions/3003145/how-to-get-the-client-ip-address-in-php
     */
    public static function getClientIpAddresses(): array {
        $ipList = [];
        // [$_SERVER key, read right-to-left]
        $sources = [
            ['REMOTE_ADDR', false],
            ['HTTP_X_FORWARDED_FOR', true],
            ['HTTP_CLIENT_IP', false],
        ];

        foreach ($sources as [$key, $rightToLeft]) {
            $raw = $_SERVER[$key] ?? null;
            if (!is_string($raw) || $raw === '') {
                continue;
            }

            $candidates = explode(',', $raw);
            if ($rightToLeft) {
                $candidates = array_reverse($candidates);
            }

            foreach ($candidates as $candidate) {
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
     * are discarded. A "q" that is not a plain non-negative decimal (e.g. "q=abc", "q=1e999",
     * "q=-1", "q=NAN") makes its entry unusable and it is discarded too; a q above 1 is clamped
     * to 1. (is_numeric() used to accept "1e999", which became INF and outranked every q=1 entry,
     * and "q=9" did the same.)
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
                $quality = preg_match('/^\d+(?:\.\d+)?$/', $value) === 1 ? min(1.0, (float) $value) : null;
                break;
            }

            // q=0 explicitly means "not acceptable"; null is a q we cannot read.
            if ($quality === null || $quality <= 0) {
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
     * This method NEVER returns to its caller: every branch ends in exit(0) — or throws, see below.
     *
     * Conversion rules, applied in order:
     *  - bool          → echoes "1" or "0", with no Content-Type, then exits.
     *  - null or ''    → exits with no output at all.
     *  - array/object  → JSON (or XML when $asXml), with the matching Content-Type. An object is
     *                    encoded the way json_encode() sees it: public properties, or whatever
     *                    jsonSerialize() returns. The XML form follows Parser::arrayToXml()'s rules.
     *  - a STRING holding a JSON object/array → as JSON it is echoed VERBATIM (re-encoding it would
     *                    turn an integer beyond PHP_INT_MAX into a float); as XML it is decoded first.
     *  - a STRING holding a JSON scalar → decoded, then echoed raw like any other scalar.
     *  - anything else (int, float, a string that is not JSON) → echoed raw, with no Content-Type.
     *
     * Invalid UTF-8 in the data is replaced with U+FFFD. It used to make json_encode() return
     * false, and the response went out as an EMPTY 200 under an application/json Content-Type.
     *
     * The Content-Type header is only sent when this script has not already set one (see
     * isHeaderPresent()) and the headers have not already gone out. Under the CLI SAPI
     * headers_list() is always empty, so it is always "sent" — where header() is itself a no-op.
     *
     * @param mixed $data The data to be output.
     * @param bool $asXml Output the data as XML instead of JSON. Only affects the array/object
     *                    branch; a scalar is echoed raw either way.
     *
     * @return void Declared for signature completeness only — this method always exits.
     *
     * @throws \JsonException When the data cannot be expressed as JSON at all (NAN/INF, a
     *                        recursive structure, nesting deeper than 512) — nothing is output.
     * @throws \InvalidArgumentException From Parser::arrayToXml() in the XML branch (e.g. a
     *                                   recursive structure) — nothing is output.
     */
    public static function resolveAndExit(mixed $data, bool $asXml = false): void {
        if (is_bool($data)) {
            echo (int) $data;
            exit(0);
        }
        if ($data === null || $data === '') {
            exit(0);
        }

        $isJsonContainer = is_string($data)
            && in_array(substr(ltrim($data), 0, 1), ['{', '['], true)
            && Validator::validateJson($data);

        if (is_array($data) || is_object($data) || $isJsonContainer) {
            // Rendered in full BEFORE any header or byte goes out, so a failure leaves the response
            // untouched for the caller's error handling.
            if (!$asXml) {
                $body = $isJsonContainer
                    ? trim($data)
                    : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR);
                self::sendContentTypeOnce('application/json; charset=utf-8');
            } else {
                if ($isJsonContainer) {
                    $data = json_decode($data, true, 512, JSON_BIGINT_AS_STRING);
                }
                $xml = null;
                Parser::arrayToXml($data, $xml);
                // asXML(), not __toString(): __toString() yields the element's TEXT content,
                // which for a container element is the empty string.
                $body = $xml->asXML();
                $xml = null;
                self::sendContentTypeOnce('application/xml; charset=utf-8');
            }

            echo $body;
            exit(0);
        }

        // Validator::validateJson() takes ?string: probing a non-string here is a TypeError.
        if (is_string($data) && Validator::validateJson($data)) {
            $data = json_decode($data, true);
        }

        echo is_bool($data) ? (int) $data : $data;
        exit(0);
    }

    /**
     * Sends a Content-Type header unless one is already set or the headers are already out.
     *
     * @param string $type The header value.
     */
    private static function sendContentTypeOnce(string $type): void {
        if (headers_sent() || self::isHeaderPresent('Content-Type') !== false) {
            return;
        }

        header('Content-Type: ' . $type);
    }
}
