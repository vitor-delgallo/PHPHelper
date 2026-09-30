<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\URL;

final class URLTest extends TestCase {

    // ---------------------------------------------------------------- urlEncode

    public function testUrlEncodeEscapesSpacesAndNonAscii(): void {
        $this->assertSame('a%20b', URL::urlEncode('a b'));
        $this->assertSame('caf%C3%A9', URL::urlEncode('café'));
        $this->assertSame('', URL::urlEncode(''));
    }

    /**
     * urlEncode() deliberately leaves every RFC 3986 reserved character RAW. It is not component
     * encoding and provides no injection protection; getFormattedUrl() depends on it.
     */
    public function testUrlEncodeLeavesReservedCharactersRawAndIsNotComponentSafe(): void {
        $this->assertSame('x&role=admin&id=7', URL::urlEncode('x&role=admin&id=7'));
        $this->assertSame('a/../../etc', URL::urlEncode('a/../../etc'));
        $this->assertSame('#frag', URL::urlEncode('#frag'));

        foreach (['!', '*', "'", '(', ')', ';', ':', '@', '&', '=', '+', '$', ',', '/', '?', '%', '#', '[', ']'] as $reserved) {
            $this->assertSame($reserved, URL::urlEncode($reserved), "reserved char {$reserved} must survive raw");
        }
    }

    public function testUrlEncodeLeavesUnreservedCharactersRaw(): void {
        $this->assertSame('a-b_c.d~e', URL::urlEncode('a-b_c.d~e'));
    }

    /**
     * Regression: the reserved set was restored with sequential str_replace() passes, so '%25' was
     * turned back into '%' and a LATER pass then decoded the '%23' that produced: an already-
     * encoded '#' in the input came out as a raw '#', turning a query value into a fragment.
     */
    public function testUrlEncodeKeepsExistingPercentEscapesIntact(): void {
        $this->assertSame('%23', URL::urlEncode('%23'));
        $this->assertSame('%5B%5D', URL::urlEncode('%5B%5D'));
        $this->assertSame('q=%23tag&x=%20', URL::urlEncode('q=%23tag&x=%20'));
        $this->assertSame('100%', URL::urlEncode('100%'));
    }

    /** A space and a literal '+' used to both come out as '+', indistinguishable afterwards. */
    public function testUrlEncodeKeepsSpaceAndPlusDistinct(): void {
        $this->assertSame('a%20b', URL::urlEncode('a b'));
        $this->assertSame('a+b', URL::urlEncode('a+b'));
    }

    // ----------------------------------------------------------- formatProtocol

    public function testFormatProtocolAcceptsHttpAndHttpsInAnyShape(): void {
        $this->assertSame('http://', URL::formatProtocol('http'));
        $this->assertSame('https://', URL::formatProtocol('https'));
        $this->assertSame('https://', URL::formatProtocol('HTTPS'));
        $this->assertSame('https://', URL::formatProtocol('HTTPS://'));
        $this->assertSame('https://', URL::formatProtocol('https:'));
        $this->assertSame('https://', URL::formatProtocol('https:\\\\'));
        $this->assertSame('http://', URL::formatProtocol(' http '));
    }

    /** formatProtocol() reports an unusable protocol with false — it must never throw. */
    public function testFormatProtocolReturnsFalseForAnythingButHttpAndHttps(): void {
        foreach ([false, '', '0', 'ftp', 'ws', 'sftp', 'mailto', 'javascript', 'gopher', 'httpx'] as $bad) {
            $this->assertFalse(URL::formatProtocol($bad), var_export($bad, true) . ' must not be honored');
        }
    }

    /** Regression: every ':' '/' '\' was stripped wherever it sat, so 'ht:tp' read as 'http'. */
    public function testFormatProtocolOnlyStripsTrailingPunctuation(): void {
        $this->assertFalse(URL::formatProtocol('ht:tp'));
        $this->assertFalse(URL::formatProtocol('h/t/t/p/s'));
        $this->assertFalse(URL::formatProtocol('://https'));
    }

    // ---------------------------------------------------------- getFormattedUrl

    public function testGetFormattedUrlHappyPath(): void {
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com/a/b'));
        $this->assertSame('https://example.com/a/b', URL::getFormattedUrl('https://example.com/a/b', false));
        $this->assertSame('https://example.com', URL::getFormattedUrl('example.com', true, 'https'));
        $this->assertSame('http://example.com', URL::getFormattedUrl('example.com', true, 'http'));
    }

    /**
     * $onlyDomain returns PROTOCOL + host[:port], never a bare host. A caller who writes
     * in_array(getFormattedUrl($u), ['example.com']) gets an allowlist that never matches.
     */
    public function testOnlyDomainKeepsProtocolPrefixAndPortButIsNotAHostExtractor(): void {
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com/a/b'));
        $this->assertNotSame('example.com', URL::getFormattedUrl('https://example.com/a/b'));
        $this->assertSame('example.com:8443', URL::getFormattedUrl('example.com:8443/x'));
        $this->assertSame('https://example.com:8443', URL::getFormattedUrl('https://www.example.com:8443/x'));

        // Scheme-less input stays scheme-less rather than gaining an invented protocol.
        $this->assertSame('example.com', URL::getFormattedUrl('example.com'));
    }

    /**
     * Regression: the authority was cut at the first '/', so a query or fragment with no path
     * before it survived $onlyDomain whole.
     */
    public function testOnlyDomainDropsAQueryOrFragmentThatFollowsTheHostDirectly(): void {
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com?x=1'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com#frag'));
        $this->assertSame('example.com', URL::getFormattedUrl('example.com?next=/admin'));
    }

    /**
     * Regression: userinfo rode along in $onlyDomain, so 'https://trusted.com@evil.com' came back
     * as a "domain" that starts with trusted.com — while the host is evil.com. Credentials in
     * 'user:pass@' leaked into the result the same way.
     */
    public function testOnlyDomainDropsUserinfo(): void {
        $this->assertSame('https://evil.com', URL::getFormattedUrl('https://trusted.com@evil.com/x'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://user:secret@www.example.com/'));
        $this->assertSame('evil.com', URL::getFormattedUrl('user@evil.com'));
    }

    public function testFullUrlModeKeepsUserinfoAsPartOfTheUrl(): void {
        $this->assertSame('https://user@example.com/x', URL::getFormattedUrl('https://user@www.example.com/x', false));
    }

    public function testGetFormattedUrlStripsLeadingWwwAndTrailingSlashAndNormalizesBackslashes(): void {
        $this->assertSame('example.com', URL::getFormattedUrl('www.example.com/x'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://www.example.com/'));
        $this->assertSame('https://example.com/a', URL::getFormattedUrl('https:\\\\example.com\\a', false));
        $this->assertSame('https://example.com/a', URL::getFormattedUrl('https://example.com/a///', false));
    }

    /** Regression: the trailing-'/' trim ran on the whole URL and ate the end of a query value. */
    public function testGetFormattedUrlTrimsTheTrailingSlashFromThePathOnly(): void {
        $this->assertSame('https://x.com/?next=/', URL::getFormattedUrl('https://x.com/?next=/', false));
        $this->assertSame('https://x.com/dir/#top/', URL::getFormattedUrl('https://x.com/dir/#top/', false));
    }

    /** Regression: '\' was rewritten to '/' everywhere, including inside query values. */
    public function testGetFormattedUrlKeepsBackslashesInTheQuery(): void {
        $this->assertSame('https://x.com/a?p=c:%5Cdir', URL::getFormattedUrl('https://x.com/a?p=c:\\dir', false));
    }

    /** Hosts are case-insensitive: the host is lowercased, the path keeps its case. */
    public function testGetFormattedUrlLowercasesSchemeAndHostAndHandlesSchemeRelativeInput(): void {
        $this->assertSame('https://example.com', URL::getFormattedUrl('HTTPS://Example.COM/a'));
        $this->assertSame('https://example.com/Path/File', URL::getFormattedUrl('HTTPS://WWW.Example.COM/Path/File', false));
        $this->assertSame('x.com', URL::getFormattedUrl('//x.com/a'));
        $this->assertSame('x.com/a', URL::getFormattedUrl('//x.com/a', false));
        $this->assertSame('https://x.com', URL::getFormattedUrl('//x.com/a', true, 'https'));
    }

    /**
     * Regression: a non-ASCII host was percent-encoded ('m%C3%BCnchen.de'), which no resolver
     * reads. An internationalized host becomes punycode; the path is still percent-encoded.
     */
    #[RequiresPhpExtension('intl')]
    public function testGetFormattedUrlConvertsAnInternationalizedHostToPunycode(): void {
        $this->assertSame('https://xn--mnchen-3ya.de', URL::getFormattedUrl('https://münchen.de/straße'));
        $this->assertSame('https://xn--mnchen-3ya.de/stra%C3%9Fe', URL::getFormattedUrl('https://www.MÜNCHEN.de/straße', false));
    }

    #[RequiresPhpExtension('intl')]
    public function testGetFormattedUrlRejectsAnInvalidInternationalizedHost(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('internationalized domain name');
        URL::getFormattedUrl('https://ä..de/x');
    }

    public function testGetFormattedUrlKeepsAnIpv6LiteralAndItsPort(): void {
        $this->assertSame('http://[::1]:8080', URL::getFormattedUrl('http://[::1]:8080/x'));
        $this->assertSame('http://[::1]/x', URL::getFormattedUrl('http://[::1]/x/', false));
    }

    /** A space in a path is '%20'; '+' there is a literal plus, so the old '+' changed the path. */
    public function testGetFormattedUrlEncodesASpaceAsPercent20(): void {
        $this->assertSame('https://x.com/my%20file', URL::getFormattedUrl('https://x.com/my file', false));
    }

    /** Regression (via urlEncode): an encoded '#' in a query value was decoded into a fragment. */
    public function testGetFormattedUrlDoesNotDecodeExistingEscapes(): void {
        $this->assertSame('https://x.com/a?q=%23tag', URL::getFormattedUrl('https://x.com/a?q=%23tag', false));
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
        $this->assertSame('', URL::getFormattedUrl('/', true, 'https'));
    }

    /** A bare path has no host to put a scheme in front of: 'https:///a/b' would be nonsense. */
    public function testGetFormattedUrlNeverPrefixesASchemeToAHostlessPath(): void {
        $this->assertSame('', URL::getFormattedUrl('/a/b', true, 'https'));
        $this->assertSame('/a/b', URL::getFormattedUrl('/a/b/', false, 'https'));
    }

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
     * parse_url() rewrites control characters to '_', so "java\tscript:" parses with NO scheme and
     * would slip past a parse_url()-only check, while a browser strips the tab and executes it.
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
     * parse_url() reports a scheme for 'http:example.com' (RFC 3986 permits a scheme with no
     * '//'); the strip must follow the scheme parse_url() found instead of demanding the slashes.
     */
    public function testGetFormattedUrlStripsASchemeThatCarriesNoDoubleSlash(): void {
        $this->assertSame('http://example.com', URL::getFormattedUrl('http:example.com'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('https:example.com'));
        $this->assertSame('https://example.com/a/b', URL::getFormattedUrl('https:example.com/a/b', false));
        $this->assertSame('http://example.com', URL::getFormattedUrl('HTTP:example.com'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('http:example.com', true, 'https'));
    }

    /** A host that merely STARTS with 'http', and a scheme mid-string, must survive untouched. */
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

    /** 'http://'/'www.' are stripped only at the START, never inside a nested URL or a path. */
    public function testGetFormattedUrlDoesNotRewriteNestedUrlOrWwwInsideThePath(): void {
        $this->assertSame(
            'https://site/r?to=https://evil.com',
            URL::getFormattedUrl('https://site/r?to=https://evil.com', false)
        );
        $this->assertSame('https://site/www.foo', URL::getFormattedUrl('https://site/www.foo', false));
    }

    /**
     * An unsupported $protocol used to be silently discarded, returning a scheme-less string that
     * was indistinguishable at the call site from success.
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
        return [['ftp'], ['ws'], ['sftp'], ['mailto'], ['javascript'], ['0'], ['ht:tp']];
    }

    /** false and '' both mean "keep whatever protocol the URL already has" — neither may throw. */
    public function testGetFormattedUrlTreatsFalseAndEmptyProtocolAsKeepOriginal(): void {
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com', true, false));
        $this->assertSame('https://example.com', URL::getFormattedUrl('https://example.com', true, ''));
        $this->assertSame('example.com', URL::getFormattedUrl('example.com', true, ''));
        $this->assertSame('example.com', URL::getFormattedUrl('example.com', true, '   '));
    }

    public function testGetFormattedUrlProtocolOverridesUrlsOwnScheme(): void {
        $this->assertSame('http://example.com', URL::getFormattedUrl('https://example.com/a', true, 'http'));
        $this->assertSame('https://example.com', URL::getFormattedUrl('http://example.com/a', true, 'https'));
    }

    /** The result is NOT HTML-escaped: reserved characters survive, escape at the point of use. */
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
            ['Accept: application/json', 'X-Empty;'],
            URL::buildHttpHeaderArray(['Accept: application/json', 'X-Empty;'])
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

    /** Every line is a string, whatever scalar went in. */
    public function testBuildHttpHeaderArrayStringifiesScalarAndStringableValues(): void {
        $stringable = new class implements \Stringable {
            public function __toString(): string {
                return 'from-object';
            }
        };

        $this->assertSame(
            ['X-Int: 5', 'X-True: 1', 'X-Null: ', 'X-Obj: from-object', '7'],
            URL::buildHttpHeaderArray(['X-Int' => 5, 'X-True' => true, 'X-Null' => null, 'X-Obj' => $stringable, 7])
        );
    }

    public static function headerInjectionProvider(): array {
        return [
            'CRLF in value'            => [['X-A' => "1\r\nX-Injected: 2"]],
            'bare LF in value'         => [['X-A' => "1\nX-Injected: 2"]],
            'bare CR in value'         => [['X-A' => "1\rX-Injected: 2"]],
            'NUL in value'             => [['X-A' => "1\0"]],
            'CRLF in verbatim line'    => [["X-A: 1\r\nX-Injected: 2"]],
            'non-Stringable object'    => [['X-A' => new \ArrayIterator([])]],
        ];
    }

    /**
     * Regression (header injection / request splitting): CR/LF used to be emitted as-is, so a
     * value like "1\r\nX-Injected: 2" became a second header in the cURL request.
     */
    #[DataProvider('headerInjectionProvider')]
    public function testBuildHttpHeaderArrayRejectsHeaderInjectionAndNonScalarValues(array $headers): void {
        $this->expectException(\InvalidArgumentException::class);
        URL::buildHttpHeaderArray($headers);
    }

    public static function invalidHeaderNameProvider(): array {
        return [
            'trailing colon'  => ['Accept:'],
            'space'           => ['X Token'],
            'leading space'   => [' 5'],
            'CRLF'            => ["X-A\r\nX-B"],
            'non-ASCII'       => ['X-Ação'],
            'parenthesis'     => ['X(A)'],
        ];
    }

    /** A name that is not an RFC 7230 token used to be emitted verbatim ('Accept:: x'). */
    #[DataProvider('invalidHeaderNameProvider')]
    public function testBuildHttpHeaderArrayRejectsInvalidHeaderNames(string $name): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid HTTP header name');
        URL::buildHttpHeaderArray([$name => 'x']);
    }

    /** An array value used to become the literal 'X: Array', with an E_WARNING. */
    public function testBuildHttpHeaderArrayRejectsAnArrayValue(): void {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('array given');
        URL::buildHttpHeaderArray(['X' => ['a']]);
    }

    // ---------------------------------------------------- appendParamsToUrl

    public function testAppendParamsToUrlAddsQuestionMarkWhenUrlHasNoQuery(): void {
        $this->assertSame('http://x?a=1', URL::appendParamsToUrl('http://x', ['a' => '1']));
        $this->assertSame('http://x?a=1&b=2', URL::appendParamsToUrl('http://x', ['a' => '1', 'b' => '2']));
        $this->assertSame('?a=1', URL::appendParamsToUrl('', ['a' => '1']));
    }

    public function testAppendParamsToUrlAddsAmpersandWhenUrlAlreadyHasQuery(): void {
        $this->assertSame('http://x?a=1&b=2', URL::appendParamsToUrl('http://x?a=1', ['b' => '2']));
    }

    /** A URL already ending in its separator used to gain a second one ('http://x?&a=1'). */
    public function testAppendParamsToUrlDoesNotDoubleATrailingSeparator(): void {
        $this->assertSame('http://x?a=1', URL::appendParamsToUrl('http://x?', ['a' => '1']));
        $this->assertSame('http://x?a=1&b=2', URL::appendParamsToUrl('http://x?a=1&', ['b' => '2']));
    }

    public function testAppendParamsToUrlEncodesValues(): void {
        $this->assertSame('http://x?q=a+b%26c', URL::appendParamsToUrl('http://x', ['q' => 'a b&c']));
        $this->assertSame('http://x?k=', URL::appendParamsToUrl('http://x', ['k' => '']));
    }

    /** Regression: keys were concatenated raw, so a key holding '&' split into two parameters. */
    public function testAppendParamsToUrlEncodesKeys(): void {
        $this->assertSame('http://x?a%26b=1', URL::appendParamsToUrl('http://x', ['a&b' => '1']));
        $this->assertSame('http://x?a+b=1', URL::appendParamsToUrl('http://x', ['a b' => '1']));
    }

    /** Regression: the parameters landed INSIDE the fragment, where the server never sees them. */
    public function testAppendParamsToUrlKeepsTheFragmentAtTheEnd(): void {
        $this->assertSame('http://x/page?a=1#frag', URL::appendParamsToUrl('http://x/page#frag', ['a' => 1]));
        $this->assertSame('http://x/page?b=1&a=1#frag', URL::appendParamsToUrl('http://x/page?b=1#frag', ['a' => 1]));
    }

    /** Regression: '/dir/' was rewritten to '/dir' — a different resource. */
    public function testAppendParamsToUrlKeepsATrailingSlash(): void {
        $this->assertSame('http://x/dir/?a=1', URL::appendParamsToUrl('http://x/dir/', ['a' => '1']));
    }

    public function testAppendParamsToUrlWithNothingToAddReturnsTheUrlUnchanged(): void {
        $this->assertSame('http://x/', URL::appendParamsToUrl('http://x/', []));
        $this->assertSame('http://x', URL::appendParamsToUrl('http://x', []));
        $this->assertSame('http://x?a=1', URL::appendParamsToUrl('http://x?a=1', []));
        $this->assertSame('http://x#f', URL::appendParamsToUrl('http://x#f', ['skipped' => null]));
        $this->assertSame('', URL::appendParamsToUrl('', []));
    }

    /**
     * http_build_query() semantics: null is omitted (it raised a deprecation), booleans are 0/1
     * (false used to vanish into 'a='), and an array is encoded instead of throwing a TypeError.
     */
    public function testAppendParamsToUrlHandlesNullBoolAndArrayValues(): void {
        $this->assertSame(
            'http://x?f=0&t=1&list%5B0%5D=a&list%5B1%5D=b',
            URL::appendParamsToUrl('http://x', ['n' => null, 'f' => false, 't' => true, 'list' => ['a', 'b']])
        );
    }
}
