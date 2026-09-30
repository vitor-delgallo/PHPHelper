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
 *
 * The built-in server is SINGLE-THREADED on Windows: a request that stalls it stalls every test
 * queued behind it, which is why the /slow endpoint watches for the client hanging up instead of
 * sleeping blindly.
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
     *
     * Every endpoint that calls into the library records PHP diagnostics in the body as
     * "[WARN:...]", so a test can prove a code path is warning-free under a real web SAPI.
     */
    private static function routerSource(): string
    {
        $autoload = var_export(dirname(__DIR__) . '/vendor/autoload.php', true);

        return <<<PHP
        <?php
        require {$autoload};

        use VD\\PHPHelper\\HTTP;

        set_error_handler(static function (int \$no, string \$str): bool {
            echo '[WARN:' . \$str . ']';
            return true;
        });

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
            header('X-Only-On-Redirect: yes');
            header('Location: /headers');
            exit;
        }

        if (\$path === '/redirect-ftp') {
            header('Location: ftp://127.0.0.1:' . (int) (\$_GET['port'] ?? 1) . '/x', true, 302);
            exit;
        }

        if (\$path === '/head') {
            // The Content-Length a GET would carry, and a body only for a non-HEAD request.
            header('Content-Type: text/plain');
            header('Content-Length: 50');
            header('X-Method: ' . \$_SERVER['REQUEST_METHOD']);
            if (\$_SERVER['REQUEST_METHOD'] !== 'HEAD') {
                echo str_repeat('x', 50);
            }
            exit;
        }

        if (\$path === '/slow') {
            // Announce a body far larger than what is sent, then stall: the status line is
            // already parsed as 200 by the time the transfer dies. Trickle a byte at a time so the
            // client hanging up is NOTICED and this worker is freed at once.
            header('Content-Type: text/plain');
            header('Content-Length: 100');
            echo 'x';
            flush();
            for (\$i = 0; \$i < 50 && !connection_aborted(); \$i++) {
                usleep(100000);
                echo ' ';
                flush();
            }
            exit;
        }

        if (\$path === '/json-error') {
            http_response_code(422);
            header('Content-Type: application/json');
            echo '{"id":12345678901234567890123,"errors":["bad"]}';
            exit;
        }

        if (\$path === '/status') {
            HTTP::sendStatusHeader((int) (\$_GET['code'] ?? 200), \$_GET['msg'] ?? null);
            echo 'status-body';
            exit;
        }

        if (\$path === '/status-after-output') {
            echo 'early-output;';
            flush();
            \$sent = HTTP::sendStatusHeader(404);
            echo 'returned=' . var_export(\$sent, true);
            exit;
        }

        if (\$path === '/resolve') {
            \$case = \$_GET['case'] ?? '';
            if (\$case === 'invalid-utf8') {
                HTTP::resolveAndExit(['s' => "caf\\xE9"]);
            }
            if (\$case === 'after-output') {
                echo 'early;';
                flush();
                HTTP::resolveAndExit(['a' => 1]);
            }
            if (\$case === 'content-type-preset') {
                header('Content-Type: text/plain');
                HTTP::resolveAndExit(['a' => 1]);
            }
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
                    'full_path' => \$f['full_path'] ?? null,
                    'type' => \$f['type'],
                    'size' => \$f['size'],
                    'sha' => is_readable(\$f['tmp_name']) ? hash_file('sha256', \$f['tmp_name']) : null,
                ],
                \$_FILES
            ),
            'raw' => file_get_contents('php://input'),
            'ctype' => \$_SERVER['CONTENT_TYPE'] ?? '',
            'custom' => \$_SERVER['HTTP_X_CUSTOM'] ?? '',
            'headers' => getallheaders(),
        ], JSON_INVALID_UTF8_SUBSTITUTE);
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
        self::assertArrayNotHasKey('cError', $decoded, 'The request failed: ' . $raw);

        return $decoded;
    }

    /** @return array<string, mixed> The decoded error envelope. */
    private function decodeEnvelope(string $raw): array
    {
        $decoded = json_decode($raw, true);
        self::assertIsArray($decoded, 'Not a JSON envelope. Raw: ' . $raw);
        self::assertArrayHasKey('cError', $decoded, 'Expected the error envelope. Raw: ' . $raw);

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

    /** Any RFC 9110 token is a method, not just the well-known ones. */
    public function testCallWebServiceAcceptsAnyTokenMethod(): void
    {
        $echo = $this->decodeEcho($this->http()->callWebService(self::$baseUrl . '/echo', ' patch '));

        self::assertSame('PATCH', $echo['method']);
    }

    /**
     * Pins the fix for: CURLOPT_CUSTOMREQUEST 'HEAD' without CURLOPT_NOBODY makes cURL wait for
     * the body the Content-Length announces — which a HEAD response never carries. Every HEAD
     * came back as the error envelope ("end of response with 50 bytes missing"); older libcurl
     * hung until the timeout instead.
     */
    public function testCallWebServiceSendsARealHeadRequest(): void
    {
        $raw = $this->http()->callWebService(self::$baseUrl . '/head', 'head', [], [], [], false, [], 5);

        self::assertSame('', $raw, 'A successful HEAD has an empty body, not an error envelope.');
    }

    public function testCallWebServiceFormEncodesArrayBodyByDefault(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], ['a' => '1', 'b' => '2'])
        );

        self::assertSame(['a' => '1', 'b' => '2'], $echo['post']);
        self::assertStringStartsWith('application/x-www-form-urlencoded', (string) $echo['ctype']);
    }

    /**
     * Pins the fix for: a raw JSON body went out labelled application/x-www-form-urlencoded
     * (cURL's default for CURLOPT_POSTFIELDS), which most JSON APIs reject or misparse.
     */
    public function testCallWebServiceSendsRawJsonBodyWithJsonContentType(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], ['a' => 1], [], true)
        );

        self::assertSame('{"a":1}', $echo['raw']);
        self::assertSame('application/json', $echo['ctype']);
    }

    /** ...and a Content-Type the caller chose is never overridden. */
    public function testCallWebServiceKeepsCallerContentTypeForRawJson(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(
                self::$baseUrl . '/echo',
                'POST',
                [],
                ['a' => 1],
                [],
                true,
                ['content-type' => 'application/vnd.api+json']
            )
        );

        self::assertSame('application/vnd.api+json', $echo['ctype']);
    }

    /**
     * Pins the fix for: json_encode() returned false on invalid UTF-8, the `!empty($postData)`
     * check read false as "no body", and the POST went out EMPTY while the caller got a 2xx.
     */
    public function testCallWebServiceRejectsAnUnencodableRawJsonBodyInsteadOfSendingItEmpty(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('could not be JSON-encoded');

        $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], ['s' => "caf\xE9"], [], true);
    }

    public function testCallWebServiceSendsStringBodyVerbatim(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], '{"raw":true}')
        );

        self::assertSame('{"raw":true}', $echo['raw']);
    }

    /** Pins the fix for: `!empty($postData)` dropped the legitimate raw body "0". */
    public function testCallWebServiceSendsTheStringBodyZero(): void
    {
        $echo = $this->decodeEcho(
            $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], '0')
        );

        self::assertSame('0', $echo['raw']);
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

    // ------------------------------------------- callWebService: request building

    /**
     * Pins the fix for: libcurl sends CURLOPT_HTTPHEADER lines verbatim, so a CR/LF inside a
     * header value arrived at the server as a SECOND, attacker-chosen header.
     *
     * @return array<string, array{0: array<int|string, mixed>}>
     */
    public static function injectedHeaderProvider(): array
    {
        return [
            'CRLF in a value'          => [['X-A' => "v\r\nX-Injected: yes"]],
            'bare LF in a value'       => [['X-A' => "v\nX-Injected: yes"]],
            'CRLF in a raw line'       => [["X-A: v\r\nX-Injected: yes"]],
            'NUL in a value'           => [['X-A' => "v\0w"]],
            'non-string raw line'      => [[['nested']]],
        ];
    }

    #[DataProvider('injectedHeaderProvider')]
    public function testCallWebServiceRefusesHeadersThatWouldSplitIntoSeveral(array $headers): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->http()->callWebService(self::$baseUrl . '/echo', 'GET', [], [], [], false, $headers);
    }

    /**
     * Pins the fix for: the method is written into the request line verbatim, so
     * "GET /x HTTP/1.1\r\nX-Inj: 1\r\nFoo:" rewrote the request path and injected headers.
     *
     * @return array<string, array{0: string}>
     */
    public static function invalidMethodProvider(): array
    {
        return [
            'CRLF request smuggling' => ["GET /x HTTP/1.1\r\nX-Inj: 1\r\nFoo:"],
            'embedded space'         => ['GET /admin'],
            'embedded LF'            => ["GET\nX: 1"],
            'separator'              => ['GE(T)'],
        ];
    }

    #[DataProvider('invalidMethodProvider')]
    public function testCallWebServiceRefusesAMethodThatIsNotAToken(string $method): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('method token');

        $this->http()->callWebService(self::$baseUrl . '/echo', $method);
    }

    /**
     * Pins the fix for: no CURLOPT_PROTOCOLS, so libcurl honoured file:// — and because a file://
     * transfer has no HTTP status, the envelope handed the LOCAL FILE back in 'response'.
     */
    public function testCallWebServiceRefusesFileUrlsAndLeaksNoLocalContent(): void
    {
        $secret = $this->makeTempFile('LOCAL-SECRET-CONTENT');

        $raw = $this->http()->callWebService('file:///' . str_replace('\\', '/', $secret), 'GET', [], [], [], false, [], 5);

        self::assertStringNotContainsString('LOCAL-SECRET-CONTENT', $raw);
        $envelope = $this->decodeEnvelope($raw);
        self::assertSame(0, $envelope['cError']['code']);
        self::assertNull($envelope['response']);
        self::assertStringContainsStringIgnoringCase('file', $envelope['cError']['msg']);
    }

    /**
     * ...and a redirect cannot switch protocols either (CURLOPT_REDIR_PROTOCOLS). Before the fix
     * cURL followed the Location to ftp:// and reported a connection failure on port 1.
     */
    public function testCallWebServiceRefusesToFollowARedirectToANonHttpScheme(): void
    {
        $envelope = $this->decodeEnvelope(
            $this->http()->callWebService(self::$baseUrl . '/redirect-ftp', 'GET', [], [], [], false, [], 5)
        );

        self::assertSame(302, $envelope['cError']['code']);
        self::assertStringContainsStringIgnoringCase('ftp', $envelope['cError']['msg']);
    }

    /**
     * Pins the fix for: CURLOPT_MAXREDIRS reads -1 as "unlimited" (libcurl 8.16 actually bails
     * with "Maximum (0) redirects followed"). Negative is now plain "do not follow": the 302
     * comes back as the envelope with no cURL error.
     */
    public function testCallWebServiceTreatsNegativeMaxRedirectsAsDoNotFollow(): void
    {
        $envelope = $this->decodeEnvelope(
            $this->http()->callWebService(self::$baseUrl . '/redirect', 'GET', [], [], [], false, [], 5, null, null, CURL_HTTP_VERSION_NONE, '', -1)
        );

        self::assertSame(302, $envelope['cError']['code']);
        self::assertSame('', $envelope['cError']['msg']);
    }

    /**
     * TLS certificate verification is ON unless the caller explicitly turns it off. Proven against a
     * loopback HTTPS server presenting a SELF-SIGNED certificate: the default call must refuse it,
     * and only the explicit $sslHost = 0 / $sslPeer = 0 opt-out may reach it.
     */
    public function testCallWebServiceVerifiesTlsCertificatesByDefault(): void
    {
        [$process, $pipes, $port] = $this->startSelfSignedTlsServer();

        try {
            $default = $this->decodeEnvelope(
                $this->http()->callWebService('https://127.0.0.1:' . $port . '/', 'GET', [], [], [], false, [], 5)
            );
            $optedOut = $this->http()->callWebService('https://127.0.0.1:' . $port . '/', 'GET', [], [], [], false, [], 5, 0, 0);
        } finally {
            proc_terminate($process);
            foreach ($pipes as $pipe) {
                fclose($pipe);
            }
            proc_close($process);
        }

        self::assertSame(0, $default['cError']['code']);
        self::assertStringContainsStringIgnoringCase('certificate', $default['cError']['msg']);
        self::assertNull($default['response']);
        self::assertSame('TLS-HELLO', $optedOut, 'The explicit opt-out is the only way through.');
    }

    /**
     * Spawns a one-shot HTTPS server with a freshly generated self-signed certificate. It serves
     * two connections (a handshake the client aborts counts as one) or gives up after 10s.
     *
     * @return array{0: resource, 1: array<int, resource>, 2: int}
     */
    private function startSelfSignedTlsServer(): array
    {
        if (!extension_loaded('openssl')) {
            self::markTestSkipped('ext-openssl is needed to build the TLS fixture.');
        }

        $conf = self::$fixtureDir . DIRECTORY_SEPARATOR . 'tls-openssl.cnf';
        file_put_contents($conf, "[req]\ndistinguished_name = dn\n[dn]\n");
        // An explicit config: Windows PHP builds ship without a default openssl.cnf.
        $options = ['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, 'digest_alg' => 'sha256', 'config' => $conf];

        $key = openssl_pkey_new($options);
        $csr = $key === false ? false : openssl_csr_new(['commonName' => '127.0.0.1'], $key, $options);
        $cert = $csr === false ? false : openssl_csr_sign($csr, null, $key, 1, $options);
        if ($cert === false || !openssl_x509_export($cert, $certPem) || !openssl_pkey_export($key, $keyPem, null, $options)) {
            while (openssl_error_string() !== false) {
            }
            self::markTestSkipped('Could not generate a self-signed certificate here.');
        }
        while (openssl_error_string() !== false) {
        }

        $pem = self::$fixtureDir . DIRECTORY_SEPARATOR . 'tls-server.pem';
        $script = self::$fixtureDir . DIRECTORY_SEPARATOR . 'tls-server.php';
        $portFile = self::$fixtureDir . DIRECTORY_SEPARATOR . 'tls-port-' . bin2hex(random_bytes(4));
        file_put_contents($pem, $certPem . $keyPem);
        file_put_contents($script, <<<'SRV'
        <?php
        [, $pem, $portFile] = $argv;
        $context = stream_context_create(['ssl' => ['local_cert' => $pem, 'verify_peer' => false]]);
        $server = stream_socket_server('ssl://127.0.0.1:0', $errno, $errstr, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
        $name = (string) stream_socket_get_name($server, false);
        file_put_contents($portFile, (string) (int) substr($name, (int) strrpos($name, ':') + 1));
        $deadline = microtime(true) + 10;
        for ($served = 0; $served < 2 && microtime(true) < $deadline; $served++) {
            $connection = @stream_socket_accept($server, 2);
            if ($connection === false) {
                continue;
            }
            stream_set_timeout($connection, 2);
            while (($line = fgets($connection)) !== false && rtrim($line) !== '') {
            }
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 9\r\nConnection: close\r\n\r\nTLS-HELLO");
            fclose($connection);
        }
        SRV);

        $process = proc_open([PHP_BINARY, $script, $pem, $portFile], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        self::assertIsResource($process, 'Could not spawn the TLS fixture.');

        $port = 0;
        for ($deadline = microtime(true) + 5; $port === 0 && microtime(true) < $deadline; usleep(20_000)) {
            $port = (int) @file_get_contents($portFile);
        }
        if ($port === 0) {
            proc_terminate($process);
            proc_close($process);
            self::markTestSkipped('The TLS fixture never reported a port.');
        }

        return [$process, $pipes, $port];
    }

    public function testCallWebServiceFollowsRedirectsByDefault(): void
    {
        $raw = $this->http()->callWebService(self::$baseUrl . '/redirect');

        self::assertSame('{"ok":true}', $raw);
    }

    // -------------------------------------------------- callWebService: uploads

    /**
     * Pins TWO path leaks. The legacy "@/path" CURLOPT_POSTFIELDS syntax (inert since
     * CURLOPT_SAFE_UPLOAD defaulted to true) uploaded nothing and put the path in the body; its
     * CURLFile replacement then announced the file under its ABSOLUTE LOCAL PATH, because
     * CURLFile's postname defaults to the path it was built from.
     *
     * The old version of this test asserted the path was absent from php://input — which PHP
     * leaves EMPTY for every multipart/form-data request, so that assertion could never fail.
     * $_FILES[...]['full_path'] is where a PHP receiver actually sees the announced filename.
     */
    public function testCallWebServiceUploadsFileAsMultipartUnderItsBasenameOnly(): void
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
        self::assertSame(basename($file), $echo['files']['avatar']['full_path']);
        self::assertStringNotContainsString(dirname($file), (string) $echo['files']['avatar']['full_path']);
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

    /**
     * Pins the fix for: cURL cannot express a nested array in multipart, and PHP's binding
     * silently dropped the inner key — ['meta' => ['id' => 7]] arrived as meta=7.
     */
    public function testCallWebServiceKeepsNestedFieldNamesInAMultipartBody(): void
    {
        $file = $this->makeTempFile('x', '.txt');

        $echo = $this->decodeEcho(
            $this->http()->callWebService(
                self::$baseUrl . '/echo',
                'POST',
                [],
                ['meta' => ['id' => 7, 'tags' => ['a', 'b']], 'flag' => true, 'skip' => null],
                ['doc' => $file]
            )
        );

        self::assertSame(['meta' => ['id' => '7', 'tags' => ['a', 'b']], 'flag' => '1'], $echo['post']);
    }

    /**
     * Pins the fix for: a \CURLFile placed in $postData (the idiomatic cURL way) was run through
     * http_build_query(), which serialises its public properties — the request body carried the
     * file's ABSOLUTE LOCAL PATH and nothing was uploaded.
     */
    public function testCallWebServiceUploadsACurlFileGivenInPostData(): void
    {
        $file = $this->makeTempFile('VIA-POSTDATA', '.bin');

        $echo = $this->decodeEcho(
            $this->http()->callWebService(
                self::$baseUrl . '/echo',
                'POST',
                [],
                ['doc' => new \CURLFile($file, 'text/plain', 'report.txt'), 'x' => '1']
            )
        );

        self::assertSame(['x' => '1'], $echo['post']);
        self::assertSame('report.txt', $echo['files']['doc']['name']);
        self::assertSame('text/plain', $echo['files']['doc']['type']);
        self::assertSame(hash('sha256', 'VIA-POSTDATA'), $echo['files']['doc']['sha']);
        self::assertStringNotContainsString(dirname($file), json_encode($echo));
    }

    /** A \CURLFile in $files is used as-is, so the caller can choose the announced name and type. */
    public function testCallWebServiceAcceptsACurlFileInFiles(): void
    {
        $file = $this->makeTempFile('X', '.tmp');

        $echo = $this->decodeEcho(
            $this->http()->callWebService(
                self::$baseUrl . '/echo',
                'POST',
                [],
                [],
                ['doc' => new \CURLFile($file, 'application/pdf', 'invoice.pdf')]
            )
        );

        self::assertSame('invoice.pdf', $echo['files']['doc']['name']);
        self::assertSame('application/pdf', $echo['files']['doc']['type']);
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

    /**
     * A quote or line break in a multipart FIELD NAME cannot break out of the Content-Disposition
     * header: libcurl percent-encodes them. Pinned so a libcurl or binding change that stops doing
     * so is noticed.
     */
    public function testCallWebServiceMultipartFieldNamesCannotInjectPartHeaders(): void
    {
        $file = $this->makeTempFile('DATA', '.txt');

        $echo = $this->decodeEcho(
            $this->http()->callWebService(
                self::$baseUrl . '/echo',
                'POST',
                [],
                ["k\"ey\r\nX-Evil: 1" => 'v'],
                ["fi\"le\r\nX-Evil: 1" => $file]
            )
        );

        self::assertSame(['k%22ey%0D%0AX-Evil:_1' => 'v'], $echo['post']);
        self::assertSame(['fi%22le%0D%0AX-Evil:_1'], array_keys($echo['files']));
    }

    /**
     * Pins the fix for: a path that resolved to nothing was SILENTLY SKIPPED, so the request went
     * out without the upload and the caller got a 2xx. '' resolved to the working DIRECTORY, a
     * directory made cURL abort with "operation aborted by callback", null raised a deprecation
     * and a NUL byte or an array escaped as a ValueError/TypeError.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function unusableUploadProvider(): array
    {
        return [
            'missing file'  => [sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'does-not-exist-' . bin2hex(random_bytes(6))],
            'empty string'  => [''],
            'a directory'   => [sys_get_temp_dir()],
            'null'          => [null],
            'NUL byte'      => ["a\0b"],
            'an array'      => [['x']],
        ];
    }

    #[DataProvider('unusableUploadProvider')]
    public function testCallWebServiceRejectsAnUploadThatIsNotAReadableFile(mixed $file): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("\$files['ghost']");

        $this->http()->callWebService(self::$baseUrl . '/echo', 'POST', [], ['a' => '1'], ['ghost' => $file]);
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
        $raw = $this->http()->callWebService(self::$baseUrl . '/slow', 'GET', [], [], [], false, [], 1);

        self::assertNotSame('', $raw, 'A failed transfer must never return an empty string.');

        $decoded = $this->decodeEnvelope($raw);
        self::assertSame(200, $decoded['cError']['code'], 'The status line was parsed before the transfer died.');
        self::assertNotSame('', $decoded['cError']['msg'], 'The cURL error message must be reported.');
        self::assertNull($decoded['response']);
    }

    public function testCallWebServiceReturnsErrorEnvelopeOnNon2xxStatus(): void
    {
        $decoded = $this->decodeEnvelope($this->http()->callWebService(self::$baseUrl . '/status?code=404'));

        self::assertSame(404, $decoded['cError']['code']);
        self::assertSame('', $decoded['cError']['msg'], 'The transfer itself succeeded, so there is no cURL error.');
        self::assertSame('status-body', $decoded['response'], 'A non-JSON body is handed back as a string.');
    }

    /**
     * A JSON error body is embedded as JSON, not as a string — and VERBATIM. The old version of
     * this test was titled "DecodesJsonBody" but hit an endpoint whose body is the plain text
     * "status-body", so it never exercised the JSON branch at all. That branch decoded and
     * re-encoded the body, which turned 12345678901234567890123 into 1.2345678901234568e+22.
     */
    public function testCallWebServiceErrorEnvelopeEmbedsAJsonBodyLosslessly(): void
    {
        $raw = $this->http()->callWebService(self::$baseUrl . '/json-error');

        self::assertStringContainsString('"response":{"id":12345678901234567890123,"errors":["bad"]}', $raw);

        $decoded = json_decode($raw, true, 512, JSON_BIGINT_AS_STRING);
        self::assertSame(422, $decoded['cError']['code']);
        self::assertSame(['id' => '12345678901234567890123', 'errors' => ['bad']], $decoded['response']);
    }

    public function testCallWebServiceReturnsZeroCodeEnvelopeWhenConnectionIsRefused(): void
    {
        $deadPort = self::findFreePort(); // free == nothing is listening on it

        $decoded = $this->decodeEnvelope(
            $this->http()->callWebService('http://127.0.0.1:' . $deadPort . '/nope', 'GET', [], [], [], false, [], 5)
        );

        self::assertSame(0, $decoded['cError']['code'], 'No status line was ever received.');
        self::assertNotSame('', $decoded['cError']['msg']);
        self::assertNull($decoded['response']);
    }

    public function testCallWebServiceSuccessfulBodyIsNotWrappedInEnvelope(): void
    {
        $raw = $this->http()->callWebService(self::$baseUrl . '/echo');

        self::assertStringNotContainsString('cError', $raw);
        self::assertSame('/echo', json_decode($raw, true)['uri']);
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

    /**
     * BEHAVIOUR CHANGE: across a redirect chain the FINAL response is inspected. The first hop's
     * header used to win, so asking a redirecting URL for its Content-Type described the 302, not
     * the resource the chain delivered.
     */
    public function testIsHeaderPresentInspectsTheFinalResponseOfARedirectChain(): void
    {
        self::assertSame('second', HTTP::isHeaderPresent('X-Hop', self::$baseUrl . '/redirect'));
        self::assertSame('application/json; charset=utf-8', HTTP::isHeaderPresent('Content-Type', self::$baseUrl . '/redirect'));
        self::assertFalse(
            HTTP::isHeaderPresent('X-Only-On-Redirect', self::$baseUrl . '/redirect'),
            'A header only the intermediate 302 carried is not a header of the final response.'
        );
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

    /**
     * Only http(s) is fetched. A dead ftp:// port would otherwise cost a connect attempt (seconds
     * on Windows) and warn; now nothing is attempted.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonHttpUrlProvider(): array
    {
        return [
            'ftp'        => ['ftp://127.0.0.1:1/x'],
            'file'       => ['file:///C:/Windows/win.ini'],
            'php filter' => ['php://filter/resource=' . __FILE__],
            'no scheme'  => ['127.0.0.1/headers'],
        ];
    }

    #[DataProvider('nonHttpUrlProvider')]
    public function testIsHeaderPresentIgnoresNonHttpUrls(string $url): void
    {
        $started = hrtime(true);

        self::assertFalse(HTTP::isHeaderPresent('Content-Type', $url));
        self::assertLessThan(0.5, (hrtime(true) - $started) / 1e9, 'No connection may be attempted.');
    }

    // ----------------------------------------------------------- sendStatusHeader

    /** Documented: a no-op under the CLI SAPI, where there is no response to write to. */
    public function testSendStatusHeaderIsNoOpUnderCli(): void
    {
        $before = headers_list();

        self::assertFalse(HTTP::sendStatusHeader(404));
        self::assertSame($before, headers_list());
        self::assertTrue(PHP_SAPI === 'cli' || defined('STDIN'), 'This test asserts the CLI guard.');
    }

    /**
     * Pins the fix for: 0 sent a 500 and TERMINATED the script with exit(0) — from a helper that
     * is supposed to set a header — and every other out-of-range code (-1, 42, 1000) produced a
     * malformed status line. Validation runs before the CLI guard, so it is reachable here.
     *
     * @return array<string, array{0: int}>
     */
    public static function outOfRangeStatusProvider(): array
    {
        return ['zero' => [0], 'negative' => [-1], 'below 100' => [99], 'above 599' => [600], 'huge' => [100000]];
    }

    #[DataProvider('outOfRangeStatusProvider')]
    public function testSendStatusHeaderRejectsCodesOutsideTheHttpRange(int $code): void
    {
        $this->expectException(\InvalidArgumentException::class);

        HTTP::sendStatusHeader($code);
    }

    public function testSendStatusHeaderSendsKnownStatusCode(): void
    {
        $headers = @get_headers(self::$baseUrl . '/status?code=404');

        self::assertIsArray($headers);
        self::assertStringContainsString('404 Not Found', $headers[0]);
    }

    /** 429 and 308 were missing from the table and went out as "Unknown". */
    public function testSendStatusHeaderKnowsModernStatusCodes(): void
    {
        $headers = @get_headers(self::$baseUrl . '/status?code=429');

        self::assertIsArray($headers);
        self::assertStringContainsString('429 Too Many Requests', $headers[0]);
    }

    public function testSendStatusHeaderSendsCustomReasonPhrase(): void
    {
        $headers = @get_headers(self::$baseUrl . '/status?code=404&msg=' . rawurlencode('Nope Nope'));

        self::assertIsArray($headers);
        self::assertStringContainsString('404 Nope Nope', $headers[0]);
    }

    /**
     * Pins the fix for: header() REFUSES a line containing a line break ("Header may not contain
     * more than a single header"), so a custom message with CR/LF sent NO status at all — the
     * response went out as 200 OK — while also raising a warning.
     */
    public function testSendStatusHeaderNeutralisesLineBreaksInTheReasonPhrase(): void
    {
        $url = self::$baseUrl . '/status?code=404&msg=' . rawurlencode("Nope\r\nX-Injected: 1");
        // ignore_errors: the http wrapper otherwise refuses to hand back the body of a 404.
        $body = @file_get_contents($url, false, stream_context_create(['http' => ['ignore_errors' => true]]));
        $headers = @get_headers($url);

        self::assertIsArray($headers);
        self::assertStringContainsString('404 Nope X-Injected: 1', $headers[0]);
        foreach ($headers as $header) {
            self::assertStringStartsNotWith('X-Injected', $header);
        }
        self::assertSame('status-body', $body, 'No warning may be raised either.');
    }

    /** Documented: an unrecognised-but-valid code still goes out, with the phrase "Unknown". */
    public function testSendStatusHeaderUsesUnknownPhraseForUnrecognisedCode(): void
    {
        $headers = @get_headers(self::$baseUrl . '/status?code=299');

        self::assertIsArray($headers);
        self::assertStringContainsString('299 Unknown', $headers[0]);
    }

    /**
     * Pins the fix for: once output had started, header() only warned "headers already sent" —
     * an exception under a strict handler. It now reports FALSE and stays silent.
     */
    public function testSendStatusHeaderReturnsFalseSilentlyOnceHeadersAreSent(): void
    {
        $body = @file_get_contents(self::$baseUrl . '/status-after-output');

        self::assertSame('early-output;returned=false', $body);
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

    /** A non-string $_SERVER entry (set by other code, never by a SAPI) used to be a TypeError. */
    public function testIsXmlHttpRequestFalseForNonStringValue(): void
    {
        $_SERVER['HTTP_X_REQUESTED_WITH'] = ['XMLHttpRequest'];

        self::assertFalse(HTTP::isXmlHttpRequest());
    }

    // ------------------------------------------------------ getClientIpAddresses

    /**
     * Pins the fix for: X-Forwarded-For carries a comma-separated proxy chain, and the old code
     * pushed the whole raw header as ONE element while documenting "List of IP addresses" — so
     * filter_var($ips[0], FILTER_VALIDATE_IP) returned false and an INET column write blew up.
     *
     * The chain is read nearest-hop first (right to left): the rightmost entry was appended by
     * the proxy closest to us, the leftmost is whatever the client claimed.
     */
    public function testGetClientIpAddressesSplitsForwardedForChainNearestHopFirst(): void
    {
        $_SERVER = ['HTTP_X_FORWARDED_FOR' => '203.0.113.7, 198.51.100.2, 10.0.0.1'];

        self::assertSame(['10.0.0.1', '198.51.100.2', '203.0.113.7'], HTTP::getClientIpAddresses());
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

    /**
     * BEHAVIOUR CHANGE, security: the client-controlled headers used to come FIRST, so the
     * natural `$ips[0]` was whatever an attacker put in Client-IP / X-Forwarded-For. The socket
     * peer — the one value the client cannot forge — is now element 0.
     */
    public function testGetClientIpAddressesPutsTheSocketPeerFirstAndSpoofableHeadersLast(): void
    {
        $_SERVER = [
            'HTTP_CLIENT_IP' => '6.6.6.6',
            'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 198.51.100.2',
            'REMOTE_ADDR' => '10.0.0.1',
        ];

        $ips = HTTP::getClientIpAddresses();

        self::assertSame('10.0.0.1', $ips[0], 'A forged header must never become element 0.');
        self::assertSame(['10.0.0.1', '198.51.100.2', '6.6.6.6'], $ips, 'De-duplicated, first occurrence wins.');
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

        $_SERVER = ['REMOTE_ADDR' => '', 'HTTP_X_FORWARDED_FOR' => ' , , ', 'HTTP_CLIENT_IP' => ['1.1.1.1']];
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

    /**
     * Pins the fix for: is_numeric() accepted "1e999" (INF) and "9", both of which outranked a
     * genuine q=1 entry, and "abc" silently meant q=1. A q above 1 is clamped; an unreadable q
     * disqualifies its entry.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function malformedQualityProvider(): array
    {
        return [
            'q above 1 is clamped, header order breaks the tie' => ['en,fr;q=9', 'en'],
            'exponent'                                           => ['fr;q=1e999,en', 'en'],
            'not a number'                                       => ['fr;q=abc,en;q=0.1', 'en'],
            'NAN'                                                => ['fr;q=NAN,en;q=0.5', 'en'],
            'negative'                                           => ['fr;q=-1,en;q=0.5', 'en'],
            'empty q'                                            => ['fr;q=,en;q=0.5', 'en'],
            'three decimals are fine'                            => ['en;q=0.001,fr;q=0.002', 'fr'],
        ];
    }

    #[DataProvider('malformedQualityProvider')]
    public function testGetBrowserLanguageRejectsMalformedQualityValues(string $header, string $expected): void
    {
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $header;

        self::assertSame($expected, HTTP::getBrowserLanguage());
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

    public function testResolveAndExitEncodesObjectAsJson(): void
    {
        $result = $this->runIsolated("HTTP::resolveAndExit((object) ['a' => 1]);");

        self::assertSame('', $result['stderr']);
        self::assertSame('{"a":1}', $result['stdout']);
    }

    /** An object is rendered the way json_encode() sees it: public properties only. */
    public function testResolveAndExitNeverOutputsNonPublicProperties(): void
    {
        $code = <<<'CODE'
        $user = new class { public $name = 'ana'; private $passwordHash = 'HASH-SECRET'; protected $token = 'TOKEN-SECRET'; };
        HTTP::resolveAndExit(['user' => $user], (bool) ($argv[1] ?? false));
        CODE;

        $json = $this->runIsolated($code);
        $xml = $this->runIsolated(str_replace("(\$argv[1] ?? false)", 'true', $code));

        self::assertSame('{"user":{"name":"ana"}}', $json['stdout']);
        self::assertSame('', $xml['stderr']);
        self::assertStringContainsString('<user><name>ana</name></user>', $xml['stdout']);
        self::assertStringNotContainsString('SECRET', $xml['stdout'], 'The XML branch leaked a private property.');
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

    /**
     * BEHAVIOUR CHANGE: a JSON object/array string is echoed VERBATIM. It used to be decoded and
     * re-encoded, which is lossy — an integer beyond PHP_INT_MAX came back as a float.
     */
    public function testResolveAndExitEchoesAJsonStringVerbatim(): void
    {
        $result = $this->runIsolated('HTTP::resolveAndExit(\'{"a":  1, "id": 12345678901234567890123}\');');

        self::assertSame('', $result['stderr']);
        self::assertSame('{"a":  1, "id": 12345678901234567890123}', $result['stdout']);
    }

    /** ...while the XML branch still decodes it, keeping a big integer's digits as text. */
    public function testResolveAndExitDecodesAJsonStringForXml(): void
    {
        $result = $this->runIsolated('HTTP::resolveAndExit(\'{"id": 12345678901234567890123}\', true);');

        self::assertSame('', $result['stderr']);
        self::assertStringContainsString('<root><id>12345678901234567890123</id></root>', $result['stdout']);
    }

    public function testResolveAndExitPreservesUnicodeUnescaped(): void
    {
        $result = $this->runIsolated("HTTP::resolveAndExit(['msg' => 'ação']);");

        self::assertSame('{"msg":"ação"}', $result['stdout']);
    }

    /**
     * Pins the fix for: json_encode() returned false on invalid UTF-8 and `echo false` printed
     * nothing, so the client got an EMPTY 200 under application/json.
     */
    public function testResolveAndExitSubstitutesInvalidUtf8InsteadOfSendingAnEmptyBody(): void
    {
        $result = $this->runIsolated('HTTP::resolveAndExit([\'s\' => "caf\xE9"]);');

        self::assertSame('', $result['stderr']);
        self::assertSame('{"s":"caf' . "\u{FFFD}" . '"}', $result['stdout']);
    }

    /** Data JSON cannot express at all is an exception with NOTHING written — not an empty 200. */
    public function testResolveAndExitThrowsBeforeAnyOutputOnUnencodableData(): void
    {
        $result = $this->runIsolated(
            "try { HTTP::resolveAndExit(['n' => NAN]); } catch (\\JsonException \$e) { fwrite(STDERR, 'caught'); exit(3); }"
        );

        self::assertSame('', $result['stdout']);
        self::assertSame('caught', $result['stderr']);
        self::assertSame(3, $result['exit']);
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
            'json scalar string is decoded' => ["'\"quoted\"'", 'quoted'],
            'json true string becomes 1' => ["'true'", '1'],
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

    /** Documented: a Content-Type the script already chose is left alone. */
    public function testResolveAndExitKeepsAContentTypeTheScriptAlreadySet(): void
    {
        $headers = @get_headers(self::$baseUrl . '/resolve?case=content-type-preset');

        self::assertIsArray($headers);
        $contentTypes = array_values(array_filter(
            $headers,
            static fn(string $h): bool => stripos($h, 'Content-Type:') === 0
        ));
        self::assertCount(1, $contentTypes);
        // Header names are case-insensitive, and PHP re-emits a script's Content-Type as
        // "Content-type: text/plain;charset=UTF-8".
        self::assertMatchesRegularExpression('#^content-type:\s*text/plain#i', $contentTypes[0]);
    }

    /**
     * Regression pin, not a fix pin: once output has started, headers_list() already carries
     * PHP's default Content-Type, so even the old code never reached header() here. The explicit
     * headers_sent() guard now makes that independent of the Content-Type check.
     */
    public function testResolveAndExitDoesNotWarnWhenOutputHasAlreadyStarted(): void
    {
        $body = @file_get_contents(self::$baseUrl . '/resolve?case=after-output');

        self::assertSame('early;{"a":1}', $body);
    }

    public function testResolveAndExitSubstitutesInvalidUtf8OverRealSapi(): void
    {
        $body = @file_get_contents(self::$baseUrl . '/resolve?case=invalid-utf8');

        self::assertSame('{"s":"caf' . "\u{FFFD}" . '"}', $body);
    }

    // ---------------------------------------------------------------- downloadFile

    /** Thin delegation: the file is streamed through File::downloadFile(). */
    public function testDownloadFileDelegatesToFileAndStreamsTheContent(): void
    {
        $file = $this->makeTempFile('DOWNLOAD-PAYLOAD', '.bin');

        $result = $this->runIsolated(
            'HTTP::downloadFile(' . var_export($file, true) . ", 'report.bin', false, false); echo '|done';"
        );

        self::assertSame('', $result['stderr']);
        self::assertSame('DOWNLOAD-PAYLOAD|done', $result['stdout']);
        self::assertFileExists($file, '$deleteAfterDownload was false.');
    }

    public function testDownloadFileThrowsForAMissingFile(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('does not exist or is not readable');

        HTTP::downloadFile(sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'missing-' . bin2hex(random_bytes(6)), 'x.bin', false, false);
    }

    /**
     * File::downloadFile() used to return SILENTLY — no headers, no body, no exception — when the
     * download name was missing, so the client got an empty 200. It now throws, before any output.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function missingDownloadNameProvider(): array
    {
        return ['null' => [null], 'empty' => [''], 'whitespace' => ['   ']];
    }

    #[DataProvider('missingDownloadNameProvider')]
    public function testDownloadFileRequiresADownloadName(?string $name): void
    {
        $file = $this->makeTempFile('PAYLOAD', '.bin');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectOutputString('');

        HTTP::downloadFile($file, $name, false, false);
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
