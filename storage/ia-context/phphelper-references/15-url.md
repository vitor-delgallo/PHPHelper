# URL Helper

Class: `VD\PHPHelper\URL`
Source file: `src/URL.php`

Use to build headers, encode URLs, normalize protocol/domain, and append query parameters.

## Methods

| Method | Use |
| --- | --- |
| `buildHttpHeaderArray(array $headers)` | Converts headers to a formatted array. |
| `urlEncode(string $string)` | URL-encodes a string. |
| `formatProtocol(string|false $protocol)` | Normalizes protocol. |
| `getFormattedUrl(string $url, bool $onlyDomain = true, string|false $protocol = false)` | Normalizes a URL, optionally keeping only the domain. |
| `appendParamsToUrl(string $url, array $getParams = [])` | Appends GET parameters to a URL. |

## Cautions

- In PHP Mini MVC projects, use `site_url()` for internal site URLs when available.
- For project assets, use `path_base_public()` when available.
- Use this class mainly for external URLs, normalization, and helper query strings.
