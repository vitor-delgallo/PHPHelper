<?php

namespace VD\PHPHelper;

class URL {
    /**
     * The only URL schemes this class models. A URL carrying any other explicit scheme is outside
     * the domain of getFormattedUrl() and is rejected rather than reformatted or passed through.
     */
    private const ALLOWED_SCHEMES = ['http', 'https'];

    /**
     * RFC 3986 reserved characters (plus '%'), mapped from their percent-encoded form back to the
     * raw character. See urlEncode().
     */
    private const RAW_RESERVED = [
        '%21' => '!', '%2A' => '*', '%27' => "'", '%28' => '(', '%29' => ')', '%3B' => ';',
        '%3A' => ':', '%40' => '@', '%26' => '&', '%3D' => '=', '%2B' => '+', '%24' => '$',
        '%2C' => ',', '%2F' => '/', '%3F' => '?', '%25' => '%', '%23' => '#', '%5B' => '[',
        '%5D' => ']',
    ];

    /**
     * Builds a list of "Header: value" lines for use with cURL's CURLOPT_HTTPHEADER.
     *
     * Two input shapes are accepted and may be mixed in one array:
     *  - named key    `['Accept' => 'application/json']` -> `'Accept: application/json'`
     *  - verbatim     `['Accept: application/json']`     -> `'Accept: application/json'`
     *
     * A key that is an integer or an empty string takes the VERBATIM path: the value is emitted
     * as-is and the caller owns the "Name: value" formatting. Beware that PHP casts canonical
     * numeric-string keys to int, so `['0' => $v]` is also verbatim.
     *
     * VALIDATION (BREAKING — this used to emit anything it was given):
     *  - a named key must be an RFC 7230 token (letters, digits and !#$%&'*+-.^_`|~): no space,
     *    no ':' — `['Accept:' => 'x']` used to produce the broken line 'Accept:: x';
     *  - no name, value or verbatim line may contain CR, LF or NUL. Those are header / request
     *    splitting ("X-A: 1\r\nX-Injected: 2"), so they throw instead of being passed to cURL;
     *  - values must be scalar, null (→ "") or Stringable. An array used to become the literal
     *    'Array' with an E_WARNING.
     *
     * Note for cURL: a named header with an empty value ('X-Foo: ') tells cURL to REMOVE that
     * header rather than send it empty; send the verbatim line 'X-Foo;' for an empty header.
     *
     * @param array $headers Header map, list of preformatted lines, or a mix of both.
     * @return array<int, string> Sequentially indexed list of header lines, all strings. Empty
     *                            input yields an empty array.
     * @throws \InvalidArgumentException On an invalid header name, a CR/LF/NUL anywhere, or a
     *                                   value that is not scalar/null/Stringable.
     */
    public static function buildHttpHeaderArray(array $headers): array {
        $formattedHeaders = [];

        foreach ($headers as $headerKey => $headerValue) {
            if (!is_scalar($headerValue) && $headerValue !== null && !$headerValue instanceof \Stringable) {
                throw new \InvalidArgumentException(
                    'Header ' . var_export($headerKey, true) . ' must have a scalar or Stringable value, '
                    . get_debug_type($headerValue) . ' given.'
                );
            }
            $headerValue = (string) $headerValue;

            if (is_int($headerKey) || $headerKey === '') {
                $line = $headerValue;
            } else {
                if (preg_match("/^[!#$%&'*+\\-.^_`|~0-9A-Za-z]+$/D", $headerKey) !== 1) {
                    throw new \InvalidArgumentException(
                        'Invalid HTTP header name ' . var_export($headerKey, true) . ': only RFC 7230 token characters are allowed.'
                    );
                }
                $line = $headerKey . ': ' . $headerValue;
            }

            if (strpbrk($line, "\r\n\0") !== false) {
                throw new \InvalidArgumentException(
                    'Header ' . var_export($headerKey, true) . ' contains CR, LF or NUL (header injection).'
                );
            }

            $formattedHeaders[] = $line;
        }

        return $formattedHeaders;
    }

    /**
     * Escapes spaces and non-ASCII bytes in an ALREADY-ASSEMBLED URL, deliberately leaving every
     * RFC 3986 reserved character raw so the URL keeps its structure.
     *
     * rawurlencode() is applied (a space becomes '%20'; unreserved "-_.~" stay raw) and the
     * reserved set is then restored in a single pass: `! * ' ( ) ; : @ & = + $ , / ? % # [ ]`.
     * This is what getFormattedUrl() needs — a whole URL must keep its ':' and '/' — and it is the
     * exact opposite of component encoding:
     *
     *   NOT SAFE for a user-supplied query value, path segment or fragment. It provides NO
     *   injection protection whatsoever: urlEncode('x&role=admin') returns 'x&role=admin'
     *   verbatim, so `'?q=' . URL::urlEncode($input)` is query-parameter injection. Use
     *   rawurlencode() for a component; use this only on a full URL you assembled yourself.
     *
     * Existing percent-escapes survive unchanged ('%23' stays '%23', '%20' stays '%20'), because
     * '%' itself is emitted raw. A lone '%' that starts no valid escape is emitted raw as well.
     *
     * BREAKING: a space is now '%20', not '+'. In a path '+' is a literal plus, so the old output
     * changed the resource, and a space and a real '+' became indistinguishable. The restore used
     * to run as sequential str_replace() passes, which DECODED escapes present in the input:
     * '%23' became '#', turning an encoded '#' in a query value into a fragment delimiter.
     *
     * @param string $string A full URL (or URL fragment) to escape.
     * @return string The input with spaces and non-ASCII escaped and reserved characters raw.
     */
    public static function urlEncode(string $string): string {
        // strtr() replaces in ONE pass and never rescans its own output.
        return strtr(rawurlencode($string), self::RAW_RESERVED);
    }

    /**
     * Normalizes a protocol name into a URL prefix ("http://" / "https://").
     *
     * Case-insensitive and tolerant of the punctuation callers include: surrounding whitespace
     * and TRAILING ':' '/' '\' are stripped, so 'HTTPS://', 'https:' and ' https ' are all
     * accepted. Punctuation elsewhere is not ('ht:tp' used to be accepted as 'http').
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
        if ($protocol === false || $protocol === '') return false;

        $protocol = strtolower(trim(rtrim(trim($protocol), ':/\\')));

        if ($protocol === "http" || $protocol === "https") {
            return $protocol . "://";
        }

        return false;
    }

    /**
     * Normalizes an http/https URL: settles the protocol prefix, lowercases the host and drops a
     * leading 'www.' from it, converts an internationalized host to punycode, trims a trailing
     * '/' from the path, and optionally reduces the URL to its host (and port).
     *
     * This is a FORMATTER guarded by an input-domain check — NOT an HTML/XSS sanitizer:
     *  - It REJECTS any $url carrying an explicit scheme other than http/https, and any $url
     *    containing control characters, so 'javascript:'/'data:' payloads and their "java\tscript:"
     *    obfuscations cannot pass through — including the "javascript:1/alert(1)" shape, which
     *    parse_url() reads as a HOST and a PORT (no scheme at all) while a browser executes it.
     *    Rejection is an exception, never a quiet empty string.
     *  - It does NOT make the result safe to embed in a page. Reserved characters survive by
     *    design (see urlEncode()), so escape at the point of use — htmlspecialchars() for an href.
     *  - It does NOT check that the host exists, resolves, or is one you trust. For a host
     *    allowlist, compare parse_url($result, PHP_URL_HOST) — not this return value.
     *
     * A $url with no scheme at all ('example.com', 'example.com:8443/x') is valid input: the
     * scheme is then taken from $protocol, or left off entirely when $protocol is false. A $url
     * with no host at all (a bare path such as '/a/b') never gets a scheme prefix. NOTE that a
     * browser reads a scheme-less result that carries a port ('example.com:8443/x') as the scheme
     * "example.com": for an href or a redirect, always pass $protocol so the result starts with
     * http(s)://.
     *
     * The host is converted with idn_to_ascii() when ext-intl is available ('münchen.de' →
     * 'xn--mnchen-3ya.de'); without ext-intl a non-ASCII host is percent-encoded instead.
     *
     * @param string $url URL to format. Backslashes in the scheme/authority/path are normalized to
     *                    '/' (browsers do the same); in the query and fragment they are kept. Only
     *                    a scheme prefix at the very START is stripped — including a scheme-relative
     *                    '//host' — so a nested URL inside a query string survives intact.
     * @param bool $onlyDomain If true (default), keep only the HOST and PORT and drop userinfo,
     *                    path, query and fragment. The protocol prefix is STILL INCLUDED: the result
     *                    is 'https://example.com', not 'example.com'. (BREAKING: the query and
     *                    fragment used to survive when no '/' preceded them —
     *                    'https://example.com?x=1' came back whole — and so did userinfo, so
     *                    'https://trusted.com@evil.com' came back looking like trusted.com.)
     * @param string|false $protocol Protocol to force onto the result. Only 'http'/'https' are
     *                    honored (see formatProtocol()). Pass false — or '' — to keep whatever
     *                    protocol $url already carries. Any OTHER value is a caller error and
     *                    throws, instead of being silently dropped and yielding a scheme-less URL.
     * @return string The formatted URL, passed through urlEncode(). '' when $url is empty, trims to
     *                nothing, or carries nothing to report ('/'), REGARDLESS of $protocol — an
     *                empty input never yields a bare 'https://'.
     * @throws \InvalidArgumentException If $protocol is a scheme other than http/https; if $url
     *                    carries a scheme other than http/https; if $url contains control
     *                    characters; if $url is unparseable; or if its host is not a valid IDN.
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

        $url = trim($url);
        if ($url === '') return '';

        if (preg_match('/[\x00-\x1F\x7F]/', $url) === 1) {
            // parse_url() rewrites control characters to '_', which would hide a "java\tscript:"
            // scheme from the check below while a browser still executes it. Refuse them outright.
            throw new \InvalidArgumentException('URL contains control characters.');
        }

        // Browsers read '\' as '/' up to the query; inside the query/fragment it is data.
        $queryStart = strcspn($url, '?#');
        $url = str_replace('\\', '/', substr($url, 0, $queryStart)) . substr($url, $queryStart);

        $parts = parse_url($url);
        if ($parts === false) {
            throw new \InvalidArgumentException('URL is malformed and cannot be parsed: "' . $url . '".');
        }

        $scheme = isset($parts['scheme']) ? strtolower($parts['scheme']) : '';
        if ($scheme !== '' && !in_array($scheme, self::ALLOWED_SCHEMES, true)) {
            throw new \InvalidArgumentException(
                'Unsupported URL scheme "' . $scheme . '": only http and https are supported.'
            );
        }

        // parse_url() reads "javascript:1/alert(1)" as host "javascript" with port 1 — NO scheme —
        // while a browser reads the same bytes as a javascript: URL (and "1/alert(1)" is valid
        // JavaScript). The check above never saw it. A leading word that spells a scheme a
        // browser would execute or open locally is refused whatever parse_url() made of it.
        if ($scheme === '' && preg_match('#^(javascript|vbscript|data|file|blob|about|filesystem|jar|ms-[a-z0-9]+):#i', $url, $dangerous) === 1) {
            throw new \InvalidArgumentException(
                'Unsupported URL scheme "' . strtolower($dangerous[1]) . '": only http and https are supported.'
            );
        }

        // Strip the scheme parse_url() ACTUALLY found (it may carry no '//': RFC 3986 permits
        // 'http:example.com'), rather than re-detecting it with a second pattern that could
        // disagree. $scheme is vetted against ALLOWED_SCHEMES; /i because $url keeps its casing.
        if ($scheme !== '') {
            $rest = preg_replace('#^' . preg_quote($scheme, '#') . ':(?://)?#i', '', $url);
        } else {
            $rest = preg_replace('#^//#', '', $url);
        }

        $authorityLength = strcspn($rest, '/?#');
        $authority = substr($rest, 0, $authorityLength);
        $tail = substr($rest, $authorityLength);

        $at = strrpos($authority, '@');
        $userInfo = $at === false ? '' : substr($authority, 0, $at + 1);
        $host = $at === false ? $authority : substr($authority, $at + 1);

        $port = '';
        if (str_starts_with($host, '[')) {
            // IPv6 literal: its colons are not a port separator.
            $closing = strpos($host, ']');
            if ($closing !== false) {
                $port = substr($host, $closing + 1);
                $host = substr($host, 0, $closing + 1);
            }
        } elseif (($colon = strrpos($host, ':')) !== false) {
            $port = substr($host, $colon);
            $host = substr($host, 0, $colon);
        }

        $host = self::normalizeHost(preg_replace('#^www\.#i', '', $host));

        if ($host === '') {
            // Nothing to put a scheme in front of: a bare path, or nothing at all ('/').
            return $onlyDomain ? '' : self::urlEncode(rtrim($tail, '/'));
        }

        $result = $forcedProtocol !== false ? $forcedProtocol : ($scheme !== '' ? $scheme . '://' : '');

        if ($onlyDomain) {
            return self::urlEncode($result . $host . $port);
        }

        // The trailing '/' is trimmed from the PATH only: a query value ending in '/' is data.
        $pathLength = strcspn($tail, '?#');
        $path = substr($tail, 0, $pathLength);
        $suffix = substr($tail, $pathLength);
        if ($suffix === '') {
            $path = rtrim($path, '/');
        }

        return self::urlEncode($result . $userInfo . $host . $port . $path . $suffix);
    }

    /**
     * Appends GET parameters to a URL, choosing '?' or '&' based on what $url already has.
     *
     * The query is built with http_build_query() (RFC 1738 encoding, a space becomes '+'), so KEYS
     * are encoded as well as values, and nested arrays become 'k[0]=v'. BREAKING changes, each of
     * which used to corrupt the URL:
     *  - keys are encoded ('a&b' → 'a%26b'; it used to be concatenated raw and split the pair);
     *  - a '#fragment' in $url stays at the end ('/p#f' + a=1 → '/p?a=1#f'; the parameters used to
     *    land INSIDE the fragment, where the server never sees them);
     *  - a trailing '/' is kept ('/dir/' and '/dir' are different resources);
     *  - a URL already ending in '?' or '&' gets no extra separator;
     *  - null values are omitted (they raised a deprecation), false is '0', true is '1', and an
     *    array value is encoded instead of throwing a TypeError.
     *
     * @param string $url Base URL.
     * @param array $getParams Parameters as key => value.
     * @return string URL with the parameters appended; $url unchanged when nothing is added.
     */
    public static function appendParamsToUrl(string $url, array $getParams = []): string {
        $queryString = http_build_query($getParams, '', '&', PHP_QUERY_RFC1738);
        if ($queryString === '') {
            return $url;
        }

        $fragment = '';
        $hash = strpos($url, '#');
        if ($hash !== false) {
            $fragment = substr($url, $hash);
            $url = substr($url, 0, $hash);
        }

        if (!str_contains($url, '?')) {
            $url .= '?';
        } elseif (!str_ends_with($url, '?') && !str_ends_with($url, '&')) {
            $url .= '&';
        }

        return $url . $queryString . $fragment;
    }

    /**
     * Lowercases a host and converts an internationalized one to its ASCII (punycode) form.
     *
     * @throws \InvalidArgumentException If the host is not a valid internationalized domain name.
     */
    private static function normalizeHost(string $host): string {
        if ($host === '' || !preg_match('/[^\x00-\x7F]/', $host)) {
            return strtolower($host);
        }

        if (!function_exists('idn_to_ascii')) {
            // Without ext-intl the host is left for urlEncode() to percent-encode.
            return mb_strtolower($host, 'UTF-8');
        }

        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
        if ($ascii === false) {
            throw new \InvalidArgumentException('URL host "' . $host . '" is not a valid internationalized domain name.');
        }

        return $ascii;
    }
}
