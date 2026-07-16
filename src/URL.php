<?php

namespace VD\PHPHelper;

class URL {
    /**
     * The only URL schemes this class models. A URL carrying any other explicit scheme is outside
     * the domain of getFormattedUrl() and is rejected rather than reformatted or passed through.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * Builds a list of "Header: value" lines for use with cURL's CURLOPT_HTTPHEADER.
     *
     * Two input shapes are accepted and may be mixed in one array:
     *  - named key    `['Accept' => 'application/json']` -> `'Accept: application/json'`
     *  - verbatim     `['Accept: application/json']`     -> `'Accept: application/json'`
     *
     * A key that is an integer or an empty string takes the VERBATIM path: the value is emitted
     * unchanged and the caller owns the "Name: value" formatting. Beware that PHP casts canonical
     * numeric-string keys to int, so `['0' => $v]` is also verbatim.
     *
     * No validation or escaping is performed — names and values are used exactly as given. A value
     * containing CR/LF is emitted as-is, so never build headers from untrusted input
     * (header-injection / response-splitting risk).
     *
     * @param array $headers Header map, list of preformatted lines, or a mix of both.
     * @return array Sequentially indexed list of header lines. Named-key entries are strings;
     *               verbatim entries are returned exactly as supplied, including non-strings.
     *               Empty input yields an empty array.
     */
    public static function buildHttpHeaderArray(array $headers): array {
        $formattedHeaders = [];

        foreach ($headers as $headerKey => $headerValue) {
            if (empty($headerKey) || filter_var($headerKey, FILTER_VALIDATE_INT)) {
                $formattedHeaders[] = $headerValue;
            } else {
                $formattedHeaders[] = $headerKey . ': ' . $headerValue;
            }
        }

        return $formattedHeaders;
    }

    /**
     * Escapes spaces and non-ASCII bytes in an ALREADY-ASSEMBLED URL, deliberately leaving every
     * RFC 3986 reserved character raw so the URL keeps its structure.
     *
     * urlencode() is applied and the reserved set is then decoded back:
     * `! * ' ( ) ; : @ & = + $ , / ? % # [ ]`. This is what getFormattedUrl() needs — a whole URL
     * must keep its ':' and '/' — and it is the exact opposite of component encoding:
     *
     *   NOT SAFE for a user-supplied query value, path segment or fragment. It provides NO
     *   injection protection whatsoever: urlEncode('x&role=admin') returns 'x&role=admin'
     *   verbatim, so `'?q=' . URL::urlEncode($input)` is query-parameter injection. Use
     *   rawurlencode() for a component; use this only on a full URL you assembled yourself.
     *
     * Two lossy edges follow from the reserved set being decoded, and are by design:
     *  - a space and a literal '+' both become '+', so they are indistinguishable after a decode;
     *  - a literal '%' is emitted raw, which can produce ambiguous percent-encoding downstream.
     *
     * @param string $string A full URL (or URL fragment) to escape.
     * @return string The input with spaces and non-ASCII escaped and reserved characters raw.
     */
    public static function urlEncode(string $string): string {
        $entities = ['%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%2B', '%24', '%2C', '%2F', '%3F', '%25', '%23', '%5B', '%5D'];
        $replacements = ['!', '*', "'", "(", ")", ";", ":", "@", "&", "=", "+", "$", ",", "/", "?", "%", "#", "[", "]"];
        return str_replace($entities, $replacements, urlencode($string));
    }

    /**
     * Normalizes a protocol name into a URL prefix ("http://" / "https://").
     *
     * Case-insensitive and tolerant of the punctuation callers include: all '\', '/' and ':' are
     * stripped first, so 'HTTPS://', 'https:' and 'https' are all accepted.
     *
     * Only http and https are recognized. Every other value — 'ftp', 'ws', 'mailto', '', false —
     * yields false. This method REPORTS an unusable protocol, it never throws; callers that treat
     * false as "keep the original protocol" must reject junk themselves before calling (see
     * getFormattedUrl(), which does exactly that).
     *
     * @param string|false $protocol Protocol name, with or without '://'.
     * @return string|false 'http://' or 'https://'; false if $protocol is false, empty, or any
     *                      scheme other than http/https.
     */
    public static function formatProtocol(string|false $protocol): string|false {
        if (empty($protocol)) return false;

        $protocol = Str::strToLower($protocol);
        $protocol = str_replace(['\\', '/', ':'], '', $protocol);
        $protocol = trim($protocol);

        if ($protocol === "http" || $protocol === "https") {
            return $protocol . "://";
        }

        return false;
    }

    /**
     * Normalizes an http/https URL: settles the protocol prefix, drops a leading 'www.', trims a
     * trailing '/', and optionally reduces the URL to its authority.
     *
     * This is a FORMATTER guarded by an input-domain check — NOT an HTML/XSS sanitizer:
     *  - It REJECTS any $url carrying an explicit scheme other than http/https, and any $url
     *    containing control characters, so 'javascript:'/'data:' payloads and their "java\tscript:"
     *    obfuscations cannot pass through. Rejection is an exception, never a quiet empty string.
     *  - It does NOT make the result safe to embed in a page. Reserved characters survive by
     *    design (see urlEncode()), so escape at the point of use — htmlspecialchars() for an href.
     *  - It does NOT check that the host exists, resolves, or is one you trust. For a host
     *    allowlist, compare parse_url($result, PHP_URL_HOST) — not this return value.
     *
     * A $url with no scheme at all ('example.com', 'example.com:8443/x') is valid input: the
     * scheme is then taken from $protocol, or left off entirely when $protocol is false.
     *
     * @param string $url URL to format. Backslashes are normalized to '/'. Only a scheme prefix at
     *                    the very START is stripped — including a scheme-relative '//host' — so a
     *                    nested URL inside a query string ('?to=https://other') survives intact.
     * @param bool $onlyDomain If true (default), keep only the AUTHORITY and drop path/query/
     *                    fragment. The protocol prefix is STILL INCLUDED: the result is
     *                    'https://example.com', not 'example.com'. The authority is not a bare
     *                    host either — userinfo and port ride along ('user@example.com',
     *                    'example.com:8443'). This is not a host extractor; for that use
     *                    parse_url($url, PHP_URL_HOST).
     * @param string|false $protocol Protocol to force onto the result. Only 'http'/'https' are
     *                    honored (see formatProtocol()). Pass false — or '' — to keep whatever
     *                    protocol $url already carries. Any OTHER value is a caller error and
     *                    throws, instead of being silently dropped and yielding a scheme-less URL.
     * @return string The formatted URL, passed through urlEncode(). '' when $url is empty, trims to
     *                nothing, or carries nothing to report ('/'), REGARDLESS of $protocol — an
     *                empty input never yields a bare 'https://'.
     * @throws \InvalidArgumentException If $protocol is a scheme other than http/https; if $url
     *                    carries a scheme other than http/https; if $url contains control
     *                    characters; or if $url is unparseable.
     */
    public static function getFormattedUrl(string $url, bool $onlyDomain = true, string|false $protocol = false): string {
        $forcedProtocol = false;
        if ($protocol !== false && trim($protocol) !== '') {
            $forcedProtocol = self::formatProtocol($protocol);
            if ($forcedProtocol === false) {
                throw new \InvalidArgumentException(
                    'Unsupported protocol "' . $protocol . '": only "http" and "https" are supported. '
                    . 'Pass false to keep the protocol already present in the URL.'
                );
            }
        }

        $url = str_replace('\\', '/', trim($url));
        if ($url === '') return '';

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            // parse_url() rewrites control characters to '_', which would hide a "java\tscript:"
            // scheme from the check below while a browser still executes it. Refuse them outright.
            throw new \InvalidArgumentException('URL contains control characters.');
        }

        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException('URL is malformed and cannot be parsed: "' . $url . '".');
        }

        $scheme = isset($parts['scheme']) ? Str::strToLower($parts['scheme']) : '';
        if ($scheme !== '' && !in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \InvalidArgumentException(
                'Unsupported URL scheme "' . $scheme . '": only http and https are supported.'
            );
        }

        if ($forcedProtocol !== false) {
            $result = $forcedProtocol;
        } elseif ($scheme !== '') {
            $result = $scheme . '://';
        } else {
            $result = '';
        }

        // Anchored: strip the scheme (or scheme-relative '//') prefix and 'www.' only at the start,
        // never mid-string, so a nested URL in a query string is not silently rewritten.
        //
        // The '//' after the scheme must be OPTIONAL. parse_url() reports a scheme for
        // 'http:example.com' (no '//' — RFC 3986 permits it), but the old '#^(https?:)?//#i'
        // required the slashes, so nothing matched: the scheme was emitted into $result AND left
        // in $url, and 'http:example.com' came back as the corrupt 'http://http:example.com'.
        //
        // Strip the scheme parse_url() ACTUALLY found rather than re-detecting it with a second,
        // independent pattern. The two cannot then disagree about what the scheme is — and a
        // disagreement is precisely what produced the bug above. $scheme is already vetted against
        // ALLOWED_SCHEMES, and preg_quote keeps it inert; /i because $scheme was lowercased while
        // $url still carries the original casing ('HTTPS://Example.com').
        if ($scheme !== '') {
            $url = preg_replace('#^' . preg_quote($scheme, '#') . ':(?://)?#i', '', $url);
        } else {
            // No scheme: only the scheme-relative '//example.com' form is left to strip.
            $url = preg_replace('#^//#', '', $url);
        }
        $url = preg_replace('#^www\.#i', '', $url);
        $url = rtrim(trim($url), '/');

        if ($onlyDomain) {
            $segments = explode('/', $url);
            if (!empty($segments[0])) $result .= $segments[0];
        } else {
            $result .= $url;
        }

        return self::urlEncode($result);
    }

    /**
     * Appends GET parameters to a base URL, choosing '?' or '&' based on what $url already has.
     *
     * Values are escaped with urlencode() (a space becomes '+'). KEYS ARE NOT ESCAPED — they are
     * concatenated verbatim, so a key holding '&', '=' or '#' corrupts the query string. Never
     * build keys from untrusted input.
     *
     * With an empty $getParams the separator that was added is removed again, so the URL comes
     * back unchanged apart from a trailing '/' being dropped when $url carries no '?' yet.
     *
     * @param string $url Base URL. If it already contains a '?', '&' is used as the separator.
     * @param array $getParams Parameters as key => value. Values must be scalar: urlencode()
     *                         raises a TypeError for an array and a deprecation notice for null.
     * @return string URL with the parameters appended.
     */
    public static function appendParamsToUrl(string $url, array $getParams = []): string {
        if (!Str::containsString($url, '?')) {
            $url = empty($url) ? '?' : rtrim($url, '/') . '?';
        } else {
            $url .= '&';
        }

        $queryString = '';
        foreach ($getParams as $key => $value) {
            if (!empty($queryString)) $queryString .= '&';
            $queryString .= $key . '=' . urlencode($value);
        }

        $url .= $queryString;
        if (empty($queryString)) {
            $url = Str::subStr($url, 0, -1);
        }

        return $url;
    }
}
