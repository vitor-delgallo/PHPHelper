<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\URL;

final class URLTest extends TestCase {

    // ---------------------------------------------------------------- urlEncode

    public function testUrlEncodeEscapesSpacesAndNonAscii(): void {
        $this->assertSame('a+b', URL::urlEncode('a b'));
        $this->assertSame('caf%C3%A9', URL::urlEncode('café'));
        $this->assertSame('', URL::urlEncode(''));
    }

    /**
     * Pins finding #1's documented contract: urlEncode() deliberately leaves every RFC 3986
     * reserved character RAW. It is not component encoding and provides no injection protection.
     * If someone "fixes" this into rawurlencode(), getFormattedUrl() breaks and this test fires.
     */
    public function testUrlEncodeLeavesReservedCharactersRawAndIsNotComponentSafe(): void {
        // The docblock's own worked example: a query value round-trips verbatim.
        $this->assertSame('x&role=admin&id=7', URL::urlEncode('x&role=admin&id=7'));
        // Not safe for a path segment either.
        $this->assertSame('a/../../etc', URL::urlEncode('a/../../etc'));
        $this->assertSame('#frag', URL::urlEncode('#frag'));

        foreach (['!', '*', "'", '(', ')', ';', ':', '@', '&', '=', '+', '$', ',', '/', '?', '%', '#', '[', ']'] as $reserved) {
            $this->assertSame($reserved, URL::urlEncode($reserved), "reserved char {$reserved} must survive raw");
        }
    }

    /** Pins the two lossy edges the docblock now admits to, so they stay known and intentional. */
    public function testUrlEncodeLossyEdgesAreDocumentedBehaviour(): void {
        // A literal '%' is emitted raw -> ambiguous percent-encoding downstream.
        $this->assertSame('100%', URL::urlEncode('100%'));
        // A space and a literal '+' are indistinguishable after encoding.
        $this->assertSame('a+b', URL::urlEncode('a b'));
        $this->assertSame('a+b', URL::urlEncode('a+b'));
        $this->assertSame(URL::urlEncode('a b'), URL::urlEncode('a+b'));
    }

    // ----------------------------------------------------------- formatProtocol

    public function testFormatProtocolAcceptsHttpAndHttpsInAnyShape(): void {
        $this->assertSame('http://', URL::formatProtocol('http'));
        $this->assertSame('https://', URL::formatProtocol('https'));
        $this->assertSame('https://', URL::formatProtocol('HTTPS'));
        $this->assertSame('https://', URL::formatProtocol('HTTPS://'));
        $this->assertSame('https://', URL::formatProtocol('https:'));
        $this->assertSame('http://', URL::formatProtocol(' http '));
    }

    /** formatProtocol() reports an unusable protocol with false — it must never throw. */
    public function testFormatProtocolReturnsFalseForAnythingButHttpAndHttps(): void {
        foreach ([false, '', '0', 'ftp', 'ws', 'sftp', 'mailto', 'javascript', 'gopher'] as $bad) {
            $this->assertFalse(URL::formatProtocol($bad), var_export($bad, true) . ' must not be honored');
        }
    }

    // ---------------------------------------------------------- getFormattedUrl

    public function testGetFormattedUrlHappyPath(): void {
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com/a/b'));
        $this->assertSame('https://example.com/a/b', URL::getFormattedUrl('https://example.com/a/b', false));
        $this->assertSame('https://example.com', URL::getFormattedUrl('example.com', true, 'https'));
        $this->assertSame('http://example.com', URL::getFormattedUrl('example.com', true, 'http'));
    }

    /**
     * Pins finding #3's documented contract: $onlyDomain returns PROTOCOL + authority, never a
     * bare host. The audit's caller wrote in_array(getFormattedUrl($u), ['example.com']) and got
     * an allowlist that never matches; the docblock now says so explicitly.
     */
    public function testOnlyDomainKeepsProtocolPrefixAndIsNotAHostExtractor(): void {
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com/a/b'));
        $this->assertNotSame('example.com', URL::getFormattedUrl('https://example.com/a/b'));

        // The authority is not a bare host: userinfo and port ride along.
        $this->assertSame('https://user@evil.com', URL::getFormattedUrl('https://user@evil.com/x'));
        $this->assertSame('example.com:8443', URL::getFormattedUrl('example.com:8443/x'));

        // Scheme-less input stays scheme-less rather than gaining an invented protocol.
        $this->assertSame('example.com', URL::getFormattedUrl('example.com'));
    }

    public function testGetFormattedUrlStripsLeadingWwwAndTrailingSlashAndNormalizesBackslashes(): void {
        $this->assertSame('example.com', URL::getFormattedUrl('www.example.com/x'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://www.example.com/'));
        $this->assertSame('https://example.com/a', URL::getFormattedUrl('https:\\\\example.com\\a', false));
    }

    public function testGetFormattedUrlLowercasesSchemeAndHandlesSchemeRelativeInput(): void {
        $this->assertSame('https://Example.com', URL::getFormattedUrl('HTTPS://Example.com/a'));
        $this->assertSame('x.com', URL::getFormattedUrl('//x.com/a'));
        $this->assertSame('x.com/a', URL::getFormattedUrl('//x.com/a', false));
        $this->assertSame('https://x.com', URL::getFormattedUrl('//x.com/a', true, 'https'));
    }

    public function testGetFormattedUrlReturnsEmptyStringForEmptyInput(): void {
        $this->assertSame('', URL::getFormattedUrl(''));
        $this->assertSame('', URL::getFormattedUrl('   '));
        $this->assertSame('', URL::getFormattedUrl('/'));
    }

    /** An empty URL must never be dressed up as a bare 'https://' just because $protocol was set. */
    public function testGetFormattedUrlEmptyInputStaysEmptyEvenWithProtocol(): void {
        $this->assertSame('', URL::getFormattedUrl('', true, 'https'));
        $this->assertSame('', URL::getFormattedUrl('   ', false, 'http'));
    }

    /**
     * FINDING #2 (high) — regression pin. Before the fix this returned
     * 'javascript:alert(document.cookie)' byte-for-byte from a method documented to "sanitize",
     * handing stored XSS to any caller that trusted the docblock.
     */
    public function testGetFormattedUrlRejectsJavascriptScheme(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported URL scheme "javascript"');
        URL::getFormattedUrl('javascript:alert(document.cookie)');
    }

    public function testGetFormattedUrlRejectsJavascriptSchemeRegardlessOfCaseOrOnlyDomain(): void {
        $this->expectException(\InvalidArgumentException::class);
        URL::getFormattedUrl('JaVaScRiPt:alert(1)', false);
    }

    /**
     * FINDING #2 — the bypass that makes the allowlist real. parse_url() rewrites control
     * characters to '_', so "java\tscript:" parses with NO scheme and would slip past a naive
     * parse_url()-only check, while a browser strips the tab and executes it.
     */
    public function testGetFormattedUrlRejectsControlCharacterObfuscatedScheme(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        URL::getFormattedUrl("java\tscript:alert(1)");
    }

    public function testGetFormattedUrlRejectsControlCharactersAnywhere(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('control characters');
        URL::getFormattedUrl("https://example.com/\x00evil");
    }

    #[DataProvider('deniedSchemeProvider')]
    public function testGetFormattedUrlRejectsEverySchemeOutsideHttpAndHttps(string $url, string $scheme): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported URL scheme "' . $scheme . '"');
        URL::getFormattedUrl($url, false);
    }

    public static function deniedSchemeProvider(): array {
        return [
            'data'     => ['data:text/html,<script>alert(1)</script>', 'data'],
            'vbscript' => ['vbscript:msgbox(1)', 'vbscript'],
            'file'     => ['file:///etc/passwd', 'file'],
            'mailto'   => ['mailto:a@b.c', 'mailto'],
            'ftp'      => ['ftp://files.example.com/pub', 'ftp'],
        ];
    }

    /**
     * FINDING (medium) — parse_url() reports a scheme for 'http:example.com' (RFC 3986 permits a
     * scheme with no '//'), but the strip pattern '#^(https?:)?//#i' DEMANDED the slashes. Nothing
     * matched, so the scheme was emitted into the result AND retained in the remainder: the method
     * returned the corrupt 'http://http:example.com' while its docblock promised a start-anchored
     * scheme prefix. This is what that promise means.
     */
    public function testGetFormattedUrlStripsASchemeThatCarriesNoDoubleSlash(): void {
        $this->assertSame('http://example.com', URL::getFormattedUrl('http:example.com'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('https:example.com'));
        $this->assertSame('https://example.com/a/b', URL::getFormattedUrl('https:example.com/a/b', false));
        // $scheme is lowercased before it drives the strip, while $url keeps its original casing.
        $this->assertSame('http://example.com', URL::getFormattedUrl('HTTP:example.com'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('http:example.com', true, 'https'));
    }

    /**
     * The other half of that fix. The strip is now driven by the scheme parse_url() ACTUALLY
     * found, rather than by a second, independent pattern that could disagree with it — a
     * disagreement between the two is precisely what produced the bug above. So a host that merely
     * STARTS with 'http', and a scheme sitting mid-string, must both survive untouched.
     */
    public function testGetFormattedUrlDoesNotStripASchemeLookalike(): void {
        $this->assertSame('httpsfoo.com', URL::getFormattedUrl('httpsfoo.com'));
        $this->assertSame('httpfoo.com', URL::getFormattedUrl('httpfoo.com'));
        $this->assertSame('http.example.com', URL::getFormattedUrl('http.example.com'));
        $this->assertSame(
            'https://site/r?to=http:evil.com',
            URL::getFormattedUrl('https://site/r?to=http:evil.com', false)
        );
    }

    public function testGetFormattedUrlRejectsUnparseableUrl(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('malformed');
        URL::getFormattedUrl('http:///x');
    }

    /**
     * FINDING #2 (second half) — regression pin. The old blind str_replace() stripped
     * 'http://'/'https://'/'www.' at ANY offset, so this returned 'https://site/r?to=evil.com':
     * a "formatted URL" that was not the URL that went in.
     */
    public function testGetFormattedUrlDoesNotRewriteNestedUrlInsideQueryString(): void {
        $this->assertSame(
            'https://site/r?to=https://evil.com',
            URL::getFormattedUrl('https://site/r?to=https://evil.com', false)
        );
    }

    /** The same offset bug corrupted any path segment that merely contained 'www.'. */
    public function testGetFormattedUrlDoesNotStripWwwFromInsideThePath(): void {
        $this->assertSame('https://site/www.foo', URL::getFormattedUrl('https://site/www.foo', false));
    }

    /** A host that merely starts with the letters 'https' must not be given a fabricated scheme. */
    public function testGetFormattedUrlDoesNotInventSchemeForHostStartingWithHttps(): void {
        $this->assertSame('httpsfoo.com', URL::getFormattedUrl('httpsfoo.com'));
        $this->assertSame('httpfoo.com', URL::getFormattedUrl('httpfoo.com'));
    }

    /**
     * FINDING #4 (medium) — regression pin. Before the fix an unsupported $protocol was silently
     * discarded and the caller got a scheme-less string back: getFormattedUrl('ftp.example.com/pub',
     * false, 'ftp') returned 'ftp.example.com/pub', indistinguishable at the call site from success.
     */
    public function testGetFormattedUrlThrowsOnUnsupportedProtocolInsteadOfSilentlyDroppingIt(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported protocol "ftp"');
        URL::getFormattedUrl('ftp.example.com/pub', false, 'ftp');
    }

    #[DataProvider('deniedProtocolProvider')]
    public function testGetFormattedUrlThrowsForEveryUnsupportedProtocol(string $protocol): void {
        $this->expectException(\InvalidArgumentException::class);
        URL::getFormattedUrl('example.com', true, $protocol);
    }

    public static function deniedProtocolProvider(): array {
        return [['ftp'], ['ws'], ['sftp'], ['mailto'], ['javascript'], ['0']];
    }

    /** false and '' both mean "keep whatever protocol the URL already has" — neither may throw. */
    public function testGetFormattedUrlTreatsFalseAndEmptyProtocolAsKeepOriginal(): void {
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com', true, false));
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com', true, ''));
        $this->assertSame('example.com', URL::getFormattedUrl('example.com', true, ''));
        // Whitespace-only is still "keep original", matching formatProtocol()'s own empty check.
        $this->assertSame('example.com', URL::getFormattedUrl('example.com', true, '   '));
    }

    /** An explicit $protocol overrides the scheme already present in the URL. */
    public function testGetFormattedUrlProtocolOverridesUrlsOwnScheme(): void {
        $this->assertSame('http://example.com', URL::getFormattedUrl('https://example.com/a', true, 'http'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('http://example.com/a', true, 'https'));
    }

    /**
     * The docblock promises the result is NOT HTML-safe. Pin that honesty: reserved characters
     * survive, so a caller must still escape at the point of use.
     */
    public function testGetFormattedUrlResultIsNotHtmlEscaped(): void {
        $this->assertSame('https://site/a?x=1&y=2', URL::getFormattedUrl('https://site/a?x=1&y=2', false));
    }

    // --------------------------------------------------- buildHttpHeaderArray

    public function testBuildHttpHeaderArrayFormatsNamedKeys(): void {
        $this->assertSame(
            ['Accept: application/json', 'X-Token: abc'],
            URL::buildHttpHeaderArray(['Accept' => 'application/json', 'X-Token' => 'abc'])
        );
    }

    public function testBuildHttpHeaderArrayPassesVerbatimEntriesThrough(): void {
        $this->assertSame(
            ['Accept: application/json'],
            URL::buildHttpHeaderArray(['Accept: application/json'])
        );
    }

    /** Integer keys and empty-string keys both take the verbatim path; shapes may be mixed. */
    public function testBuildHttpHeaderArrayMixesBothShapesAndReindexesSequentially(): void {
        $result = URL::buildHttpHeaderArray([
            'Accept' => 'application/json',
            0        => 'X-Raw: 1',
            ''       => 'Empty-Key: 2',
            5        => 'N: 3',
        ]);

        $this->assertSame(['Accept: application/json', 'X-Raw: 1', 'Empty-Key: 2', 'N: 3'], $result);
        $this->assertSame([0, 1, 2, 3], array_keys($result));
    }

    /** PHP casts canonical numeric-string keys to int, so ['0' => $v] is verbatim, not '0: $v'. */
    public function testBuildHttpHeaderArrayTreatsNumericStringKeyAsVerbatim(): void {
        $this->assertSame(['X-Raw: 1'], URL::buildHttpHeaderArray(['0' => 'X-Raw: 1']));
    }

    public function testBuildHttpHeaderArrayReturnsEmptyArrayForEmptyInput(): void {
        $this->assertSame([], URL::buildHttpHeaderArray([]));
    }

    /**
     * The docblock states no escaping is performed and CRLF is emitted as-is. Pin it, so the
     * "never build headers from untrusted input" warning stays true rather than quietly rotting
     * into a half-sanitizer nobody documented.
     */
    public function testBuildHttpHeaderArrayDoesNotEscapeCrlfInValues(): void {
        $this->assertSame(
            ["X-A: 1\r\nX-Injected: 2"],
            URL::buildHttpHeaderArray(['X-A' => "1\r\nX-Injected: 2"])
        );
    }

    // ---------------------------------------------------- appendParamsToUrl

    public function testAppendParamsToUrlAddsQuestionMarkWhenUrlHasNoQuery(): void {
        $this->assertSame('http://x?a=1', URL::appendParamsToUrl('http://x', ['a' => '1']));
        $this->assertSame('http://x?a=1&b=2', URL::appendParamsToUrl('http://x', ['a' => '1', 'b' => '2']));
    }

    public function testAppendParamsToUrlAddsAmpersandWhenUrlAlreadyHasQuery(): void {
        $this->assertSame('http://x?a=1&b=2', URL::appendParamsToUrl('http://x?a=1', ['b' => '2']));
    }

    public function testAppendParamsToUrlEncodesValues(): void {
        $this->assertSame('http://x?q=a+b%26c', URL::appendParamsToUrl('http://x', ['q' => 'a b&c']));
        $this->assertSame('http://x?k=', URL::appendParamsToUrl('http://x', ['k' => '']));
    }

    /**
     * Documented hazard: keys are concatenated verbatim, never encoded. Pinned so the docblock's
     * "never build keys from untrusted input" warning cannot silently become false.
     */
    public function testAppendParamsToUrlDoesNotEncodeKeys(): void {
        $this->assertSame('http://x?a&b=1', URL::appendParamsToUrl('http://x', ['a&b' => '1']));
    }

    /** With no params the separator that was added is removed again. */
    public function testAppendParamsToUrlWithEmptyParamsLeavesUrlEffectivelyUnchanged(): void {
        $this->assertSame('http://x', URL::appendParamsToUrl('http://x/', []));
        $this->assertSame('http://x', URL::appendParamsToUrl('http://x', []));
        $this->assertSame('http://x?a=1', URL::appendParamsToUrl('http://x?a=1', []));
        $this->assertSame('', URL::appendParamsToUrl('', []));
    }

    public function testAppendParamsToUrlRejectsNonScalarValue(): void {
        $this->expectException(\TypeError::class);
        URL::appendParamsToUrl('http://x', ['a' => ['nested']]);
    }
}
