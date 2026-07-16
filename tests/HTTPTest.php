<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\HTTP;

/**
 * Contract tests for VD\PHPHelper\HTTP.
 *
 * Nothing here touches the public internet. Two local-only fixtures stand in for it:
 *
 *  - a PHP built-in web server bound to 127.0.0.1 on an ephemeral port, started once per class.
 *    It is what makes get_headers()/cURL exercise real HTTP without an external dependency.
 *  - a userland "http" stream wrapper, used to force get_headers() to fail with no warning.
 *
 * resolveAndExit() and sendStatusHeader() can terminate the process, so they are exercised in a
 * child process (proc_open) or through the fixture server, never inline.
 */
final class HTTPTest extends TestCase
{
    private static string $fixtureDir = '';
    private static string $baseUrl = '';
    /** @var resource|null */
    private static $server = null;

    /** @var list<string> */
    private array $tempFiles = [];
    /** @var array<string, mixed> */
    private array $serverBackup = [];
    private bool $httpWrapperReplaced = false;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'httptest_' . bin2hex(random_bytes(6));
        if (!mkdir(self::$fixtureDir) && !is_dir(self::$fixtureDir)) {
            self::markTestSkipped('Could not create the fixture directory.');
        }

        file_put_contents(self::$fixtureDir . DIRECTORY_SEPARATOR . 'router.php', self::routerSource());

        $port = self::findFreePort();
        $descriptors = [1 => ['file', self::$fixtureDir . DIRECTORY_SEPARATOR . 'out.log', 'w'],
                        2 => ['file', self::$fixtureDir . DIRECTORY_SEPARATOR . 'err.log', 'w']];

        $server = @proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', self::$fixtureDir,
             self::$fixtureDir . DIRECTORY_SEPARATOR . 'router.php'],
            $descriptors,
            $pipes
        );

        if (!is_resource($server)) {
            self::markTestSkipped('Could not start the local PHP built-in server fixture.');
        }

        self::$server = $server;
        self::$baseUrl = 'http://127.0.0.1:' . $port;

        // Wait for the listener rather than sleeping a fixed amount, so the suite is not racy.
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
            if (is_resource($probe)) {
                fclose($probe);
                return;
            }
            usleep(100_000);
        }

        self::markTestSkipped('The local PHP built-in server fixture never became reachable.');
    }

    public static function tearDownAfterClass(): void
    {
        if (is_resource(self::$server)) {
            proc_terminate(self::$server);
            proc_close(self::$server);
            self::$server = null;
        }

        if (self::$fixtureDir !== '' && is_dir(self::$fixtureDir)) {
            foreach ((array) glob(self::$fixtureDir . DIRECTORY_SEPARATOR . '*') as $file) {
                @unlink((string) $file);
            }
            @rmdir(self::$fixtureDir);
        }
    }

    protected function setUp(): void
    {
        $this->serverBackup = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->serverBackup;

        if ($this->httpWrapperReplaced) {
            stream_wrapper_restore('http');
            $this->httpWrapperReplaced = false;
        }

        foreach ($this->tempFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        $this->tempFiles = [];
    }

    // ---------------------------------------------------------------- helpers

    private static function findFreePort(): int
    {
        $socket = @stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (!is_resource($socket)) {
            self::markTestSkipped('Could not bind a loopback socket to pick a free port.');
        }

        $name = (string) stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    /**
     * The fixture endpoint source. Kept as a string so the whole fixture lives in this one file
     * and is written to a temp dir at run time; nothing is ever added to the repository.
     */
    private static function routerSource(): string
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);

        return <<<PHP
        <?php
        require {$autoload};

        use VD\\PHPHelper\\HTTP;

        \$path = (string) parse_url(\$_SERVER['REQUEST_URI'], PHP_URL_PATH);

        if (\$path === '/headers') {
            header('Content-Type: application/json; charset=utf-8');
            header('X-Empty-Value:');
            header('X-Spaced:    padded-value');
            header('X-Hop: second');
            echo '{"ok":true}';
            exit;
        }

        if (\$path === '/redirect') {
            header('X-Hop: first', true, 302);
            header('Location: /headers');
            exit;
        }

        if (\$path === '/slow') {
            // Announce a body far larger than what is sent, then stall: the status line is
            // already parsed as 200 by the time the transfer dies.
            header('Content-Type: text/plain');
            header('Content-Length: 100');
            echo 'x';
            flush();
            sleep(5);
            exit;
        }

        if (\$path === '/status') {
            HTTP::sendStatusHeader((int) (\$_GET['code'] ?? 200), \$_GET['msg'] ?? null);
            echo 'status-body';
            exit;
        }

        if (\$path === '/resolve') {
            HTTP::resolveAndExit(['name' => 'x'], isset(\$_GET['xml']));
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'uri' => \$_SERVER['REQUEST_URI'],
            'method' => \$_SERVER['REQUEST_METHOD'],
            'post' => \$_POST,
            'files' => array_map(
                static fn(array \$f): array => [
                    'name' => \$f['name'],
                    'size' => \$f['size'],
                    'sha' => is_readable(\$f['tmp_name']) ? hash_file('sha256', \$f['tmp_name']) : null,
                ],
                \$_FILES
            ),
            'raw' => file_get_contents('php://input'),
            'ctype' => \$_SERVER['CONTENT_TYPE'] ?? '',
            'custom' => \$_SERVER['HTTP_X_CUSTOM'] ?? '',
        ]);
        PHP;
    }

    private function makeTempFile(string $contents, string $suffix = '.txt'): string
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'httptest_' . bin2hex(random_bytes(8)) . $suffix;
        file_put_contents($path, $contents);
        $this->tempFiles[] = $path;

        return $path;
    }

    /** Runs a snippet in a child process, so exit() inside the subject cannot kill the test run. */
    private function runIsolated(string $code): array
    {
        $script = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'httptest_' . bin2hex(random_bytes(8)) . '.php';
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);
        file_put_contents($script, "<?php\nrequire {$autoload};\nuse VD\\PHPHelper\\HTTP;\n{$code}\n");
        $this->tempFiles[] = $script;

        $process = @proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=E_ALL', $script],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes
        );
        self::assertIsResource($process, 'Failed to spawn the isolated PHP process.');

        $stdout = (string) stream_get_contents($pipes[1]);
        $stderr = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['stdout' => $stdout, 'stderr' => $stderr, 'exit' => proc_close($process)];
    }

    /** @return array<string, mixed> The fixture's decoded echo payload. */
    private function decodeEcho(string $raw): array
    {
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'Fixture did not return a JSON object. Raw: ' . $raw);

        return $decoded;
    }

    private function http(): HTTP
    {
        // callWebService() is the one non-static method on the class.
        return new HTTP();
    }

    // ------------------------------------------------- callWebService: success

    public function testCallWebServiceReturnsRawBodyOnSuccess(): void
    {
        $echo = $this->decodeEcho($this->http()->callWebService(self::$baseUrl . '/echo'));

        self::assertSame('GET', $echo['method']);
        self::assertSame('/echo', $echo['uri']);
    }

    /**
     * Pins the fix for: callWebService() routed $url through URL::getFormattedUrl(), which strips
     * "www." anywhere in the string and rtrims '/', so the request went to a DIFFERENT resource
     * than the caller asked for.
     */
    public function testCallWebServiceRequestsUrlVerbatimWithoutStrippingWwwOrTrailingSlash(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/v1/www.orders/', 'GET', ['a' => '1'])
        );

        self::assertSame('/v1/www.orders/?a=1', $echo['uri']);
    }

    public function testCallWebServiceAppendsQueryParamsWithAmpersandWhenUrlAlreadyHasQuery(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo?x=1', 'GET', ['y' => '2'])
        );

        self::assertSame('/echo?x=1&y=2', $echo['uri']);
    }

    public function testCallWebServiceLeavesUrlUntouchedWhenNoQueryParamsGiven(): void
    {
        $echo = $this->decodeEcho($this->http()->callWebService(self::$baseUrl . '/echo?keep=me'));

        self::assertSame('/echo?keep=me', $echo['uri']);
    }

    public function testCallWebServiceUpperCasesTheRequestMethod(): void
    {
        $echo = $this->decodeEcho($this->http()->callWebService(self::$baseUrl . '/echo', 'post'));

        self::assertSame('POST', $echo['method']);
    }

    public function testCallWebServiceFormEncodesArrayBodyByDefault(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], ['a' => '1', 'b' => '2'])
        );

        self::assertSame(['a' => '1', 'b' => '2'], $echo['post']);
        self::assertStringStartsWith('application/x-www-form-urlencoded', (string) $echo['ctype']);
    }

    public function testCallWebServiceSendsRawJsonBodyWhenUseRawIsTrue(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], ['a' => 1], [], true)
        );

        self::assertSame('{"a":1}', $echo['raw']);
    }

    public function testCallWebServiceSendsStringBodyVerbatim(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], '{"raw":true}')
        );

        self::assertSame('{"raw":true}', $echo['raw']);
    }

    public function testCallWebServiceSendsCustomHeaders(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(
                self::$baseUrl . '/echo',
                'GET',
                [],
                [],
                [],
                false,
                ['X-Custom' => 'custom-value']
            )
        );

        self::assertSame('custom-value', $echo['custom']);
    }

    // -------------------------------------------------- callWebService: uploads

    /**
     * Pins the fix for: the legacy "@/path" CURLOPT_POSTFIELDS syntax has been inert since
     * CURLOPT_SAFE_UPLOAD defaulted to true (PHP 5.6). The old code uploaded NOTHING while
     * leaking the server's absolute filesystem path into the request body.
     */
    public function testCallWebServiceUploadsFileAsMultipartInsteadOfLeakingLocalPath(): void
    {
        $file = $this->makeTempFile('FILECONTENT-1234', '.pdf');

        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], [], ['avatar' => $file])
        );

        self::assertArrayHasKey('avatar', $echo['files'], 'The file never reached $_FILES.');
        self::assertSame(16, $echo['files']['avatar']['size']);
        self::assertSame(hash('sha256', 'FILECONTENT-1234'), $echo['files']['avatar']['sha']);
        self::assertStringStartsWith('multipart/form-data', (string) $echo['ctype']);

        // The local absolute path must never be disclosed to the remote endpoint.
        self::assertStringNotContainsString(basename($file), (string) $echo['raw']);
        self::assertSame([], $echo['post']);
    }

    public function testCallWebServiceSendsUploadsAlongsideOrdinaryFields(): void
    {
        $file = $this->makeTempFile('body', '.txt');

        $echo = $this->decodeEcho(
            $this->http()->callWebService(
                self::$baseUrl . '/echo',
                'POST',
                [],
                ['title' => 'hello'],
                ['doc' => $file]
            )
        );

        self::assertSame(['title' => 'hello'], $echo['post']);
        self::assertArrayHasKey('doc', $echo['files']);
    }

    /** The 'f_' prefix on numerically-keyed $files arrays is documented; pin it. */
    public function testCallWebServicePrefixesNumericallyKeyedFilesWithFUnderscore(): void
    {
        $file = $this->makeTempFile('numeric', '.bin');

        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], [], [$file])
        );

        self::assertArrayHasKey('f_0', $echo['files']);
    }

    /** Documented behaviour: a path that resolves to nothing is silently skipped. */
    public function testCallWebServiceSilentlySkipsUnresolvableFilePaths(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(
                self::$baseUrl . '/echo',
                'POST',
                [],
                ['a' => '1'],
                ['ghost' => sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'does-not-exist-' . bin2hex(random_bytes(6))]
            )
        );

        self::assertSame([], $echo['files']);
        self::assertSame(['a' => '1'], $echo['post'], 'With no resolvable upload the body must stay form-encoded.');
    }

    /**
     * Pins the fix for: a string $postData plus a non-empty $files fatalled with
     * "TypeError: Cannot access offset of type string on string". Both are documented inputs,
     * so the contradiction has to be reported, not fatal.
     */
    public function testCallWebServiceRejectsRawStringBodyCombinedWithFiles(): void
    {
        $file = $this->makeTempFile('x', '.txt');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('$postData must be an array when $files is not empty');

        $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], '{"a":1}', ['avatar' => $file]);
    }

    // --------------------------------------------- callWebService: error envelope

    /**
     * Pins the fix for: CURLINFO_HTTP_CODE is set as soon as the status line is parsed, so a
     * transfer that dies mid-body reported 200 while curl_exec() returned false. The old guard
     * only looked at the status code, so false fell through the `: string` return type and was
     * weak-mode coerced to "" — neither a response nor the documented error information.
     */
    public function testCallWebServiceReturnsErrorEnvelopeWhenTransferDiesAfterSuccessfulStatusLine(): void
    {
        $raw = $this->http()->callWebService(self::$baseUrl . '/slow', 'GET', [], [], [], false, [], 2);

        self::assertNotSame('', $raw, 'A failed transfer must never return an empty string.');

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('cError', $decoded);
        self::assertSame(200, $decoded['cError']['code'], 'The status line was parsed before the transfer died.');
        self::assertNotSame('', $decoded['cError']['msg'], 'The cURL error message must be reported.');
        self::assertNull($decoded['response']);
    }

    public function testCallWebServiceReturnsErrorEnvelopeOnNon2xxStatus(): void
    {
        $raw = $this->http()->callWebService(self::$baseUrl . '/status?code=404');

        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded);
        self::assertSame(404, $decoded['cError']['code']);
        self::assertSame('', $decoded['cError']['msg'], 'The transfer itself succeeded, so there is no cURL error.');
        self::assertSame('status-body', $decoded['response'], 'The body received must still be handed back.');
    }

    public function testCallWebServiceErrorEnvelopeDecodesJsonBody(): void
    {
        $raw = $this->http()->callWebService(self::$baseUrl . '/status?code=500');
        $decoded = json_decode($raw, true);

        self::assertSame(500, $decoded['cError']['code']);
        self::assertSame('status-body', $decoded['response']);
    }

    public function testCallWebServiceReturnsZeroCodeEnvelopeWhenConnectionIsRefused(): void
    {
        $deadPort = self::findFreePort(); // free == nothing is listening on it

        $raw = $this->http()->callWebService('http://127.0.0.1:' . $deadPort . '/nope', 'GET', [], [], [], false, [], 5);
        $decoded = json_decode($raw, true);

        self::assertIsArray($decoded);
        self::assertSame(0, $decoded['cError']['code'], 'No status line was ever received.');
        self::assertNotSame('', $decoded['cError']['msg']);
        self::assertNull($decoded['response']);
    }

    public function testCallWebServiceSuccessfulBodyIsNotWrappedInEnvelope(): void
    {
        $raw = $this->http()->callWebService(self::$baseUrl . '/echo');

        self::assertStringNotContainsString('cError', $raw);
    }

    // ------------------------------------------------------------ isHeaderPresent

    public function testIsHeaderPresentReturnsFalseForEmptyName(): void
    {
        self::assertFalse(HTTP::isHeaderPresent(''));
        self::assertFalse(HTTP::isHeaderPresent('', self::$baseUrl . '/headers'));
    }

    /** Documented: headers_list() is always empty under the CLI SAPI. */
    public function testIsHeaderPresentReturnsFalseWithoutUrlUnderCli(): void
    {
        self::assertFalse(HTTP::isHeaderPresent('Content-Type'));
    }

    /**
     * Pins the fix for: get_headers() always returns the status line ("HTTP/1.1 200 OK") as
     * element 0. It has no colon, so `[$header, $value] = explode(':', $entry, 2)` raised
     * "Undefined array key 1" on EVERY call with a $url — which, under any handler that promotes
     * warnings to exceptions, threw instead of returning the documented string|false.
     *
     * phpunit.xml sets failOnWarning=true, so the pre-fix code fails this test on the warning
     * alone, before the assertion is even reached.
     */
    public function testIsHeaderPresentParsesRemoteHeadersWithoutWarningOnStatusLine(): void
    {
        self::assertSame(
            'application/json; charset=utf-8',
            HTTP::isHeaderPresent('Content-Type', self::$baseUrl . '/headers')
        );
    }

    /** Pins the fix for: the value came back with its leading OWS still attached. */
    public function testIsHeaderPresentTrimsOptionalWhitespaceFromValue(): void
    {
        self::assertSame('padded-value', HTTP::isHeaderPresent('X-Spaced', self::$baseUrl . '/headers'));
    }

    public function testIsHeaderPresentMatchesNameCaseInsensitively(): void
    {
        self::assertSame(
            'application/json; charset=utf-8',
            HTTP::isHeaderPresent('CONTENT-TYPE', self::$baseUrl . '/headers')
        );
    }

    /** Documented trap: a present-but-empty header returns '' — falsy, but not false. */
    public function testIsHeaderPresentReturnsEmptyStringNotFalseForPresentButEmptyHeader(): void
    {
        $value = HTTP::isHeaderPresent('X-Empty-Value', self::$baseUrl . '/headers');

        self::assertNotFalse($value, 'The header is present, so it must not report as absent.');
        self::assertSame('', $value);
    }

    public function testIsHeaderPresentReturnsFalseForAbsentHeader(): void
    {
        self::assertFalse(HTTP::isHeaderPresent('X-Not-Sent', self::$baseUrl . '/headers'));
    }

    /** Documented: across a redirect chain the FIRST hop's header wins. */
    public function testIsHeaderPresentReturnsFirstHopHeaderOnRedirectChain(): void
    {
        self::assertSame('first', HTTP::isHeaderPresent('X-Hop', self::$baseUrl . '/redirect'));
    }

    /**
     * Pins the fix for: get_headers() returns false on a failed request, and the old code ran
     * `foreach (false as ...)`, warning "foreach() argument must be of type array|object, bool
     * given" instead of returning the documented false.
     *
     * A userland wrapper is used rather than a dead socket: get_headers() rejects it with no
     * warning at all (wrapper_data is not an array), which isolates the guard exactly.
     */
    public function testIsHeaderPresentReturnsFalseWhenRemoteLookupFails(): void
    {
        stream_wrapper_unregister('http');
        stream_wrapper_register('http', FailingHttpWrapper::class, STREAM_IS_URL);
        $this->httpWrapperReplaced = true;

        self::assertFalse(HTTP::isHeaderPresent('Content-Type', 'http://irrelevant.test/x'));
    }

    // ----------------------------------------------------------- sendStatusHeader

    /** Documented: a no-op under the CLI SAPI, where there is no response to write to. */
    public function testSendStatusHeaderIsNoOpUnderCli(): void
    {
        $before = headers_list();
        HTTP::sendStatusHeader(404);

        self::assertSame($before, headers_list());
        self::assertTrue(PHP_SAPI === 'cli' || defined('STDIN'), 'This test asserts the CLI guard.');
    }

    public function testSendStatusHeaderSendsKnownStatusCode(): void
    {
        $headers = @get_headers(self::$baseUrl . '/status?code=404');

        self::assertIsArray($headers);
        self::assertStringContainsString('404 Not Found', $headers[0]);
    }

    public function testSendStatusHeaderSendsCustomReasonPhrase(): void
    {
        $headers = @get_headers(self::$baseUrl . '/status?code=404&msg=' . rawurlencode('Nope Nope'));

        self::assertIsArray($headers);
        self::assertStringContainsString('404 Nope Nope', $headers[0]);
    }

    /** Documented: an unrecognised-but-valid code still goes out, with the phrase "Unknown". */
    public function testSendStatusHeaderUsesUnknownPhraseForUnrecognisedCode(): void
    {
        $headers = @get_headers(self::$baseUrl . '/status?code=299');

        self::assertIsArray($headers);
        self::assertStringContainsString('299 Unknown', $headers[0]);
    }

    // ---------------------------------------------------------- isXmlHttpRequest

    public function testIsXmlHttpRequestTrueWhenHeaderPresent(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';

        self::assertTrue(HTTP::isXmlHttpRequest());
    }

    public function testIsXmlHttpRequestIsCaseInsensitive(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlHTTPrequest';

        self::assertTrue(HTTP::isXmlHttpRequest());
    }

    public function testIsXmlHttpRequestFalseWhenHeaderAbsent(): void
    {
        unset($_SERVER['HTTP_X_REQUESTED_WITH']);

        self::assertFalse(HTTP::isXmlHttpRequest());
    }

    public function testIsXmlHttpRequestFalseForOtherValue(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'fetch';

        self::assertFalse(HTTP::isXmlHttpRequest());
    }

    // ------------------------------------------------------ getClientIpAddresses

    /**
     * Pins the fix for: X-Forwarded-For carries a comma-separated proxy chain, and the old code
     * pushed the whole raw header as ONE element while documenting "List of IP addresses" — so
     * filter_var($ips[0], FILTER_VALIDATE_IP) returned false and an INET column write blew up.
     */
    public function testGetClientIpAddressesSplitsForwardedForChainIntoIndividualAddresses(): void
    {
        $_SERVER = ['HTTP_X_FORWARDED_FOR' => '203.0.113.7, 198.51.100.2, 10.0.0.1'];

        self::assertSame(['203.0.113.7', '198.51.100.2', '10.0.0.1'], HTTP::getClientIpAddresses());
    }

    public function testGetClientIpAddressesReturnsOnlyValidatableIpAddresses(): void
    {
        $_SERVER = ['HTTP_X_FORWARDED_FOR' => '203.0.113.7, not-an-ip, 198.51.100.2:8080'];

        $ips = HTTP::getClientIpAddresses();

        self::assertSame(['203.0.113.7'], $ips);
        foreach ($ips as $ip) {
            self::assertNotFalse(filter_var($ip, FILTER_VALIDATE_IP), 'Every element must be a real IP.');
        }
    }

    public function testGetClientIpAddressesOrdersClientControlledHeadersFirstAndDeduplicates(): void
    {
        $_SERVER = [
            'HTTP_CLIENT_IP' => '203.0.113.7',
            'HTTP_X_FORWARDED_FOR' => '203.0.113.7, 198.51.100.2',
            'REMOTE_ADDR' => '10.0.0.1',
        ];

        self::assertSame(['203.0.113.7', '198.51.100.2', '10.0.0.1'], HTTP::getClientIpAddresses());
    }

    public function testGetClientIpAddressesAcceptsIpv6(): void
    {
        $_SERVER = ['REMOTE_ADDR' => '2001:db8::1'];

        self::assertSame(['2001:db8::1'], HTTP::getClientIpAddresses());
    }

    public function testGetClientIpAddressesReturnsEmptyArrayWhenNothingUsableIsPresent(): void
    {
        $_SERVER = [];
        self::assertSame([], HTTP::getClientIpAddresses());

        $_SERVER = ['REMOTE_ADDR' => '', 'HTTP_X_FORWARDED_FOR' => ' , , '];
        self::assertSame([], HTTP::getClientIpAddresses());
    }

    // ------------------------------------------------------- getBrowserLanguage

    /**
     * Pins the fix for: the raw first Accept-Language entry was returned q-value and all, so a
     * caller doing $translations[HTTP::getBrowserLanguage()] silently missed on "en-gb;q=0.9".
     */
    public function testGetBrowserLanguageStripsQualityParameterFromTag(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-GB;q=0.9,en;q=0.8';

        self::assertSame('en-gb', HTTP::getBrowserLanguage());
    }

    /** Pins the fix for: "preferred" means highest q, not first in the header. */
    public function testGetBrowserLanguageReturnsHighestQualityTagNotFirstEntry(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US;q=0.8,pt-BR;q=1.0';

        self::assertSame('pt-br', HTTP::getBrowserLanguage());
    }

    /** The mainstream browser header must be unaffected by the q-ranking fix. */
    public function testGetBrowserLanguageKeepsMainstreamBrowserHeaderBehaviour(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'pt-BR,pt;q=0.9,en-US;q=0.8,en;q=0.7';

        self::assertSame('pt-br', HTTP::getBrowserLanguage());
    }

    public function testGetBrowserLanguageKeepsHeaderOrderForEqualQuality(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de,fr';

        self::assertSame('de', HTTP::getBrowserLanguage());
    }

    /** q=0 means "not acceptable" per RFC 9110. */
    public function testGetBrowserLanguageSkipsZeroQualityEntries(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en;q=0,pt-BR;q=0.5';

        self::assertSame('pt-br', HTTP::getBrowserLanguage());
    }

    public function testGetBrowserLanguageFallsBackToEnWhenHeaderAbsent(): void
    {
        unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);

        self::assertSame('en', HTTP::getBrowserLanguage());
    }

    #[DataProvider('unusableAcceptLanguageProvider')]
    public function testGetBrowserLanguageFallsBackToEnForUnusableHeaders(string $header): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $header;

        self::assertSame('en', HTTP::getBrowserLanguage());
    }

    public static function unusableAcceptLanguageProvider(): array
    {
        return [
            'empty' => [''],
            'whitespace only' => ['   '],
            'wildcard' => ['*'],
            'wildcard with q' => ['*;q=0.5'],
            'all rejected' => ['en;q=0,fr;q=0'],
            'path traversal' => ['../../etc/passwd'],
            'null byte' => ["en\0"],
            'sql-ish' => ["en' OR 1=1--"],
            'subtag too long' => ['abcdefghi-XX'],
            'not a tag' => ['12345'],
        ];
    }

    /** The tag is shape-validated, which is exactly what keeps a hostile header out of a path. */
    public function testGetBrowserLanguageOnlyEverReturnsAWellFormedTag(): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'zh-Hant-TW;q=0.9,../../evil;q=1.0';

        $language = HTTP::getBrowserLanguage();

        self::assertSame('zh-hant-tw', $language);
        self::assertMatchesRegularExpression('/^[a-z]{1,8}(?:-[a-z0-9]{1,8})*$/', $language);
    }

    // ----------------------------------------------------------- resolveAndExit

    /**
     * Pins the fix for: Validator::validateJson() takes ?string, so probing an ARRAY threw
     * "TypeError: ... must be of type ?string, array given". The documented call
     * HTTP::resolveAndExit(['status'=>'ok']) fatalled with a 500, and the entire is_array()
     * body — the whole point of the method — was unreachable dead code.
     */
    public function testResolveAndExitEncodesArrayAsJson(): void
    {
        $result = $this->runIsolated("HTTP::resolveAndExit(['status' => 'ok']);");

        self::assertSame('', $result['stderr'], 'The documented array call must not fatal.');
        self::assertSame(0, $result['exit']);
        self::assertSame('{"status":"ok"}', $result['stdout']);
    }

    /** Objects go through Parser::objectToArray() and hit the same previously-dead array branch. */
    public function testResolveAndExitEncodesObjectAsJson(): void
    {
        $result = $this->runIsolated("HTTP::resolveAndExit((object) ['a' => 1]);");

        self::assertSame('', $result['stderr']);
        self::assertSame('{"a":1}', $result['stdout']);
    }

    /**
     * Pins two defects at once: the array TypeError above, and the XML branch echoing
     * $xml->__toString() — which is the element's TEXT content, i.e. "" for a container — so the
     * response was an empty body under an application/xml Content-Type.
     */
    public function testResolveAndExitEncodesArrayAsXml(): void
    {
        $result = $this->runIsolated("HTTP::resolveAndExit(['name' => 'x'], true);");

        self::assertSame('', $result['stderr']);
        self::assertNotSame('', $result['stdout'], 'The XML branch must not emit an empty body.');
        self::assertStringContainsString('<root><name>x</name></root>', $result['stdout']);
    }

    public function testResolveAndExitDecodesJsonStringBeforeOutput(): void
    {
        $result = $this->runIsolated('HTTP::resolveAndExit(\'{"a":  1}\');');

        self::assertSame('{"a":1}', $result['stdout'], 'A JSON string is decoded, then re-encoded.');
    }

    public function testResolveAndExitPreservesUnicodeUnescaped(): void
    {
        $result = $this->runIsolated("HTTP::resolveAndExit(['msg' => 'ação']);");

        self::assertSame('{"msg":"ação"}', $result['stdout']);
    }

    #[DataProvider('scalarResolveProvider')]
    public function testResolveAndExitOutputsScalarsRaw(string $expression, string $expected): void
    {
        $result = $this->runIsolated("HTTP::resolveAndExit({$expression});");

        self::assertSame('', $result['stderr']);
        self::assertSame(0, $result['exit']);
        self::assertSame($expected, $result['stdout']);
    }

    public static function scalarResolveProvider(): array
    {
        return [
            'true becomes 1' => ['true', '1'],
            'false becomes 0' => ['false', '0'],
            'null outputs nothing' => ['null', ''],
            'empty string outputs nothing' => ["''", ''],
            'non-json string is echoed' => ["'hello'", 'hello'],
            'int is echoed' => ['42', '42'],
        ];
    }

    public function testResolveAndExitSetsJsonContentTypeOverRealSapi(): void
    {
        $body = @file_get_contents(self::$baseUrl . '/resolve');
        $headers = @get_headers(self::$baseUrl . '/resolve');

        self::assertSame('{"name":"x"}', $body);
        self::assertIsArray($headers);
        self::assertContains('Content-Type: application/json; charset=utf-8', $headers);
    }

    public function testResolveAndExitSetsXmlContentTypeOverRealSapi(): void
    {
        $body = (string) @file_get_contents(self::$baseUrl . '/resolve?xml=1');
        $headers = @get_headers(self::$baseUrl . '/resolve?xml=1');

        self::assertStringContainsString('<root><name>x</name></root>', $body);
        self::assertIsArray($headers);
        self::assertContains('Content-Type: application/xml; charset=utf-8', $headers);
    }
}

/**
 * A userland "http" wrapper whose stream opens successfully but exposes no header array, which
 * makes get_headers() return false without emitting any warning. Used to drive isHeaderPresent()'s
 * failed-lookup guard with no socket involved.
 */
final class FailingHttpWrapper
{
    /** @var resource|null */
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_read(int $count): string
    {
        return '';
    }

    public function stream_eof(): bool
    {
        return true;
    }

    public function stream_stat(): array
    {
        return [];
    }

    public function stream_close(): void
    {
    }
}
