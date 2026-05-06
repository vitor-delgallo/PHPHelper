<?php

namespace VD\PHPHelper;

class URL {
    /**
     * Builds an array of headers formatted for use in HTTP requests.
     *
     * @param array $headers Input headers as an associative or numeric array
     * @return array Formatted array of headers
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
     * Encodes a string to be safely used in a URL, replacing special characters.
     *
     * @param string $string String to be encoded
     * @return string Encoded URL string
     */
    public static function urlEncode(string $string): string {
        $entities = ['%21', '%2A', '%27', '%28', '%29', '%3B', '%3A', '%40', '%26', '%3D', '%2B', '%24', '%2C', '%2F', '%3F', '%25', '%23', '%5B', '%5D'];
        $replacements = ['!', '*', "'", "(", ")", ";", ":", "@", "&", "=", "+", "$", ",", "/", "?", "%", "#", "[", "]"];
        return str_replace($entities, $replacements, urlencode($string));
    }

    /**
     * Formats the given protocol string as a valid URL protocol (e.g., "http://").
     * Returns false if the protocol is invalid or not recognized.
     *
     * @param string|false $protocol The protocol to format
     *
     * @return string|false Formatted protocol with "://" or false if invalid
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
     * Formats and sanitizes a URL.
     *
     * @param string $url The URL to format
     * @param bool $onlyDomain If true, return only the domain
     * @param string|false $protocol The protocol to use (false to keep original)
     * @return string The formatted URL
     */
    public static function getFormattedUrl(string $url, bool $onlyDomain = true, string|false $protocol = false): string {
        $protocol = self::formatProtocol($protocol);
        $result = '';

        $url = trim($url);
        if (empty($protocol) && Str::strLen($url) >= 5 && Str::strToLower(Str::subStr($url, 0, 5)) === 'https') {
            $result = 'https://';
        } elseif (empty($protocol) && Str::strLen($url) >= 4 && Str::strToLower(Str::subStr($url, 0, 4)) === 'http') {
            $result = 'http://';
        } elseif (!empty($protocol)) {
            $result = $protocol;
        }

        $url = str_replace(['\\', 'http://', 'https://', 'www.'], ['/', '', '', ''], $url);
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
     * Appends GET parameters to a base URL.
     *
     * @param string $url Base URL
     * @param array $getParams GET parameters to append
     * @return string URL with appended parameters
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
