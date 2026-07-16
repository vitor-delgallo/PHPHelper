<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use VD\PHPHelper\Mailer;

/**
 * Contract tests for VD\PHPHelper\Mailer.
 *
 * Delivery is exercised against a throwaway SMTP sink: a tiny PHP script, spawned as a child
 * process, bound to 127.0.0.1 on an OS-assigned port. It speaks just enough SMTP for PHPMailer and
 * dumps the session + the raw DATA blob to temp files, so a test can assert what was ACTUALLY put
 * on the wire. Nothing leaves the loopback interface and no real mail is sent.
 */
final class MailerTest extends TestCase
{
    /**
     * The sink. Responds 220 / EHLO+AUTH / 334 / 235 / 250 / 354, logs every command line to
     * "<log>" and everything between DATA and the terminating "." to "<log>.eml".
     */
    private const SINK_SOURCE = <<<'SINK'
<?php
$log = $argv[1];
$srv = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
if (!$srv) { file_put_contents($log, "ERR $errstr\n"); exit(1); }
$name = stream_socket_get_name($srv, false);
file_put_contents($log . '.port', (string) (int) substr($name, strrpos($name, ':') + 1));
$conn = @stream_socket_accept($srv, 5);
if (!$conn) { file_put_contents($log, "NOACCEPT\n"); exit(1); }
$session = '';
$data    = '';
$inData  = false;
$expect  = null;
fwrite($conn, "220 sink ESMTP\r\n");
while (($line = fgets($conn, 8192)) !== false) {
    if ($inData) {
        if (rtrim($line, "\r\n") === '.') { $inData = false; fwrite($conn, "250 OK queued\r\n"); continue; }
        $data .= $line;
        continue;
    }
    $session .= $line;
    $cmd = strtoupper(substr(trim($line), 0, 4));
    if ($expect === 'user')  { $expect = 'pass'; fwrite($conn, "334 UGFzc3dvcmQ6\r\n"); continue; }
    if ($expect === 'pass' || $expect === 'plain') { $expect = null; fwrite($conn, "235 2.7.0 ok\r\n"); continue; }
    if ($cmd === 'EHLO') { fwrite($conn, "250-sink\r\n250-AUTH LOGIN PLAIN\r\n250 HELP\r\n"); continue; }
    if ($cmd === 'HELO') { fwrite($conn, "250 sink\r\n"); continue; }
    if ($cmd === 'AUTH') { $expect = (stripos($line, 'PLAIN') !== false) ? 'plain' : 'user'; fwrite($conn, "334 VXNlcm5hbWU6\r\n"); continue; }
    if ($cmd === 'DATA') { $inData = true; fwrite($conn, "354 go\r\n"); continue; }
    if ($cmd === 'QUIT') { fwrite($conn, "221 bye\r\n"); break; }
    fwrite($conn, "250 OK\r\n");
}
file_put_contents($log, $session);
file_put_contents($log . '.eml', $data);
fclose($conn);
exit(0);
SINK;

    private string $tmpDir = '';
    private string $sinkLog = '';
    /** @var resource|null */
    private $sinkProc = null;
    /** @var array<int, resource> */
    private array $sinkPipes = [];

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'vdmailer-' . bin2hex(random_bytes(8));
        mkdir($this->tmpDir);
    }

    protected function tearDown(): void
    {
        $this->killSink();

        if ($this->tmpDir !== '') {
            self::removeTree($this->tmpDir);
        }
    }

    /**
     * Removes a directory and everything under it.
     *
     * A symlink is UNLINKED, never followed and never descended into: the allow-list tests plant
     * links that point OUT of the fixture on purpose, and a cleanup that recursed through one would
     * delete the very thing the test aimed it at.
     */
    private static function removeTree(string $dir): void
    {
        if (is_link($dir)) {
            @unlink($dir);
            return;
        }
        if (!is_dir($dir)) {
            @unlink($dir);
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $entry;
            if (is_link($path)) {
                // A Windows link to a directory is a junction/dir-symlink: rmdir, not unlink.
                if (!@unlink($path)) {
                    @rmdir($path);
                }
                continue;
            }
            if (is_dir($path)) {
                self::removeTree($path);
                continue;
            }
            @unlink($path);
        }

        @rmdir($dir);
    }

    // ---------------------------------------------------------------- sink plumbing

    /** Spawns the sink and returns the port it is listening on. */
    private function startSink(): int
    {
        $script = $this->tmpDir . DIRECTORY_SEPARATOR . 'sink.php';
        file_put_contents($script, self::SINK_SOURCE);
        $this->sinkLog = $this->tmpDir . DIRECTORY_SEPARATOR . 'session';

        $proc = proc_open(
            [PHP_BINARY, $script, $this->sinkLog],
            [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']],
            $this->sinkPipes
        );
        self::assertIsResource($proc, 'could not spawn the SMTP sink');
        $this->sinkProc = $proc;
        fclose($this->sinkPipes[0]);

        $portFile = $this->sinkLog . '.port';
        $deadline = microtime(true) + 5.0;
        while (microtime(true) < $deadline) {
            if (is_file($portFile)) {
                $port = (int) file_get_contents($portFile);
                if ($port > 0) {
                    return $port;
                }
            }
            usleep(10000);
        }

        self::fail('the SMTP sink never reported a port');
    }

    /**
     * Waits (bounded) for the sink to finish, then returns what it captured.
     *
     * @return array{session: string, eml: string}
     */
    private function sinkCapture(): array
    {
        $deadline = microtime(true) + 8.0;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($this->sinkProc);
            if (!$status['running']) {
                break;
            }
            usleep(10000);
        }
        $this->killSink();

        return [
            'session' => is_file($this->sinkLog) ? (string) file_get_contents($this->sinkLog) : '',
            'eml'     => is_file($this->sinkLog . '.eml') ? (string) file_get_contents($this->sinkLog . '.eml') : '',
        ];
    }

    private function killSink(): void
    {
        if (!is_resource($this->sinkProc)) {
            return;
        }
        foreach ($this->sinkPipes as $key => $pipe) {
            if ($key !== 0 && is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $status = proc_get_status($this->sinkProc);
        if ($status['running']) {
            proc_terminate($this->sinkProc);
        }
        proc_close($this->sinkProc);
        $this->sinkProc  = null;
        $this->sinkPipes = [];
    }

    /**
     * A complete, valid $configs pointed at the sink.
     *
     * 'secure' => 'none' is deliberate: the sink speaks plaintext, and 'none' is now the EXPLICIT —
     * and only — way to ask for that. It used to be just one of infinitely many junk values that
     * happened to mean "no encryption", which is exactly the hole that made a typo a credential
     * leak; see testSendMailRefusesATypoInSecureInsteadOfDowngradingToCleartext.
     */
    private function configs(int $port): array
    {
        return [
            'email'  => 'from@example.com',
            'name'   => 'From Name',
            'user'   => 'smtp-user',
            'pass'   => 'secret',
            'host'   => '127.0.0.1',
            'port'   => $port,
            'secure' => 'none',
        ];
    }

    /** A $configs that would be valid if the named key were not removed. Never reaches the network. */
    private function configsWithout(string $key): array
    {
        $configs = $this->configs(1);
        unset($configs[$key]);

        return $configs;
    }

    private function makePng(string $filename = 'dot.png'): string
    {
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . $filename;
        // Smallest valid 1x1 PNG.
        file_put_contents($path, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        return $path;
    }

    // ---------------------------------------------------------------- $configs guard

    public function testSendMailReturnsFalseWhenConfigsIsEmpty(): void
    {
        self::assertFalse(Mailer::sendMail([], [['email' => 'to@example.com']], 'Subject', 'Body'));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function requiredConfigKeyProvider(): array
    {
        return [
            'name is required'   => ['name'],
            'pass is required'   => ['pass'],
            'host is required'   => ['host'],
            'port is required'   => ['port'],
            'secure is required' => ['secure'],
        ];
    }

    /**
     * The docblock promises a bare FALSE — not an exception — for a missing required $configs key.
     *
     */
    #[DataProvider('requiredConfigKeyProvider')]
    public function testSendMailReturnsFalseWhenARequiredConfigKeyIsMissing(string $key): void
    {
        self::assertFalse(Mailer::sendMail(
            $this->configsWithout($key),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>'
        ));
    }

    /** 'user' is documented as OPTIONAL: it defaults to 'email'. Dropping it must NOT fail the guard. */
    public function testSendMailTreatsUserAsOptionalAndDefaultsItToEmail(): void
    {
        $port = $this->startSink();
        $configs = $this->configs($port);
        unset($configs['user']);

        self::assertTrue(Mailer::sendMail(
            $configs,
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>'
        ));

        $capture = $this->sinkCapture();
        // 'user' fell back to 'email', so that is what was sent as the AUTH username.
        self::assertStringContainsString(base64_encode('from@example.com'), $capture['session']);
    }

    /** 'email' may be omitted ONLY when 'user' is itself a valid address — then it becomes the From. */
    public function testSendMailDerivesEmailFromUserWhenUserIsAValidAddress(): void
    {
        $port = $this->startSink();
        $configs = $this->configs($port);
        unset($configs['email']);
        $configs['user'] = 'sender@example.com';

        self::assertTrue(Mailer::sendMail(
            $configs,
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>'
        ));

        self::assertStringContainsString('From: From Name <sender@example.com>', $this->sinkCapture()['eml']);
    }

    /** ...and a 'user' that is NOT an address cannot stand in for a missing 'email'. */
    public function testSendMailReturnsFalseWhenEmailIsMissingAndUserIsNotAnAddress(): void
    {
        $configs = $this->configs(1);
        unset($configs['email']);
        $configs['user'] = 'not-an-address';

        self::assertFalse(Mailer::sendMail(
            $configs,
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>'
        ));
    }

    // ---------------------------------------------------------------- $configs['secure'] (closed set)

    /**
     * FINDING (fixed): the guard rejected an EMPTY 'secure' but handed ANY other string straight to
     * PHPMailer's SMTPSecure, where everything that is not 'tls'/'ssl' silently means NO encryption.
     * So the only way to say "no encryption" was a junk value — and a one-letter typo ('tsl' for
     * 'tls') was indistinguishable from one. The send SUCCEEDED and put 'user'/'pass' on the wire in
     * the clear, which is about as quiet as a credential leak gets.
     *
     * The sink is what makes this a real proof: it speaks plaintext and logs every command, so
     * before the fix this test observes a TRUE return AND the password sitting in the session log.
     * Now the typo is refused before a socket is ever opened.
     */
    public function testSendMailRefusesATypoInSecureInsteadOfDowngradingToCleartext(): void
    {
        $port = $this->startSink();
        $configs = $this->configs($port);
        $configs['secure'] = 'tsl'; // the typo

        self::assertFalse(
            Mailer::sendMail($configs, [['email' => 'to@example.com', 'name' => 'To']], 'Subject', '<p>Body</p>'),
            'a typo in secure must fail the send, not silently downgrade it'
        );

        $session = $this->sinkCapture()['session'];
        self::assertStringNotContainsString(base64_encode('secret'), $session, 'the password reached the wire in the clear');
        self::assertStringNotContainsString(base64_encode('smtp-user'), $session);
        self::assertStringNotContainsString('MAIL FROM', $session, 'nothing at all should have been sent');
    }

    /**
     * The accepted set is CLOSED: 'tls' | 'ssl' | 'none'. Anything else returns FALSE, so cleartext
     * is never reachable by accident — it costs the caller the exact word 'none'.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function invalidSecureProvider(): array
    {
        return [
            'typo for tls'      => ['tsl'],
            'typo for ssl'      => ['sll'],
            'empty string'      => [''],
            'blank string'      => ['   '],
            'spelled out'       => ['starttls'],
            'protocol version'  => ['tlsv1.2'],
            'boolean-ish true'  => ['true'],
            'boolean-ish no'    => ['no'],
            'junk'              => ['whatever'],
            'not a string'      => [true],
            'null'              => [null],
        ];
    }

    #[DataProvider('invalidSecureProvider')]
    public function testSendMailReturnsFalseForASecureOutsideTheAcceptedSet(mixed $secure): void
    {
        $configs = $this->configs(1);
        $configs['secure'] = $secure;

        self::assertFalse(Mailer::sendMail(
            $configs,
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>'
        ));
    }

    /** 'none' is the explicit cleartext opt-out, matched case-insensitively with the edges trimmed. */
    public function testSendMailAcceptsTheExplicitCleartextOptOutCaseInsensitively(): void
    {
        $port = $this->startSink();
        $configs = $this->configs($port);
        $configs['secure'] = '  NoNe  ';

        self::assertTrue(Mailer::sendMail(
            $configs,
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>explicitly cleartext</p>'
        ));

        self::assertStringContainsString('<p>explicitly cleartext</p>', $this->sinkCapture()['eml']);
    }

    /**
     * 'tls'/'ssl' stay accepted by the guard. They cannot reach this plaintext sink — PHPMailer
     * fails the handshake and sendMail returns FALSE — so what this pins is that they are ACCEPTED
     * values (unlike 'tsl', which never gets far enough to try) and that a failed handshake is a
     * clean FALSE rather than an escaping exception.
     *
     * @return array<string, array{0: string}>
     */
    public static function acceptedEncryptionProvider(): array
    {
        return [
            'starttls' => ['tls'],
            'smtps'    => ['SSL'], // also pins case-insensitivity
        ];
    }

    #[DataProvider('acceptedEncryptionProvider')]
    public function testSendMailAcceptsTheDocumentedEncryptionKeywords(string $secure): void
    {
        $configs = $this->configs(1);
        $configs['secure'] = $secure;

        // Port 1 refuses instantly: the value passed the guard and died at the connection, which is
        // a different failure from the guard's flat refusal — and still a bool.
        self::assertFalse(Mailer::sendMail(
            $configs,
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>'
        ));
    }

    // ---------------------------------------------------------------- other guard branches

    public function testSendMailReturnsFalseWhenSendToIsEmpty(): void
    {
        self::assertFalse(Mailer::sendMail($this->configs(1), [], 'Subject', '<p>Body</p>'));
    }

    public function testSendMailReturnsFalseWhenSubjectIsEmpty(): void
    {
        self::assertFalse(Mailer::sendMail($this->configs(1), [['email' => 'to@example.com']], '', '<p>Body</p>'));
    }

    public function testSendMailReturnsFalseWhenBodyIsEmpty(): void
    {
        self::assertFalse(Mailer::sendMail($this->configs(1), [['email' => 'to@example.com']], 'Subject', ''));
    }

    // ---------------------------------------------------------------- $useConfig (closed set)

    /**
     * FINDING: $useConfig was documented as an open set of "common providers ('gmail', 'yahoo',
     * etc.)". It is CLOSED. An unrecognised value is not a shortcut, so host/secure/port go back to
     * being required — and omitting them, exactly as a caller relying on the advertised shortcut
     * would, silently returns FALSE on every send.
     */
    public function testSendMailReturnsFalseForAnUnrecognisedUseConfigWithoutOwnHostConfig(): void
    {
        $configs = $this->configs(1);
        unset($configs['host'], $configs['port'], $configs['secure']);

        self::assertFalse(Mailer::sendMail(
            $configs,
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>',
            'outlook'
        ));
    }

    /** ...and an unrecognised value is otherwise IGNORED: the caller's own host config still rules. */
    public function testSendMailIgnoresAnUnrecognisedUseConfigWhenOwnHostConfigIsSupplied(): void
    {
        $port = $this->startSink();

        self::assertTrue(Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>',
            'protonmail'
        ));

        self::assertStringContainsString('Subject: Subject', $this->sinkCapture()['eml']);
    }

    // ---------------------------------------------------------------- $body / $template

    /**
     * FINDING: $body only ever reached the message from inside
     * `if (class_exists(Illuminate\Support\Facades\View::class))`. Laravel is not installed here —
     * as it is not for any plain-Composer consumer of this library — so $template stayed null,
     * `$mail->Body = null`, and the recipient got an EMPTY email while sendMail returned TRUE.
     *
     * Laravel is genuinely absent from this test run, so this test is the non-Laravel consumer.
     */
    public function testSendMailUsesBodyAsTheMessageBodyWithoutLaravelInstalled(): void
    {
        self::assertFalse(
            class_exists(\Illuminate\Support\Facades\View::class),
            'this test is meaningless if Laravel is installed'
        );

        $port = $this->startSink();
        $body = '<p>Vitor, this exact body must reach the wire.</p>';

        self::assertTrue(Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            $body
        ));

        self::assertStringContainsString($body, $this->sinkCapture()['eml']);
    }

    /** A $template naming no existing view (and with no View facade at all) falls back to $body. */
    public function testSendMailFallsBackToBodyWhenTemplateNamesNoExistingView(): void
    {
        $port = $this->startSink();
        $body = '<p>fallback body wins</p>';

        self::assertTrue(Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            $body,
            null,
            null,
            [],
            [],
            0,
            0,
            [],
            [],
            [],
            [],
            'emails.no_such_view'
        ));

        self::assertStringContainsString($body, $this->sinkCapture()['eml']);
    }

    /** The AltBody is derived from the delivered body, so it must carry the body's text. */
    public function testSendMailDerivesAltBodyFromTheDeliveredBody(): void
    {
        $port = $this->startSink();

        self::assertTrue(Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>plain text marker</p>'
        ));

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString('text/plain', $eml);
        self::assertStringContainsString('plain text marker', $eml);
    }

    /**
     * FINDING (fixed): View::make()->render() sat OUTSIDE every try block, so a Blade compile error
     * — an ErrorException/ViewException, which the PHPMailer-only catch would not have held anyway —
     * escaped a function whose signature promises bool.
     *
     * Laravel is not installed, so the facade is faked. That has to happen in a SEPARATE PROCESS:
     * defining Illuminate\Support\Facades\View in the shared one would make class_exists() true for
     * every other test, and testSendMailUsesBodyAsTheMessageBodyWithoutLaravelInstalled explicitly
     * asserts the opposite.
     */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSendMailReturnsFalseWhenTheTemplateFailsToRender(): void
    {
        self::assertFalse(class_exists(\Illuminate\Support\Facades\View::class), 'the facade must start absent');

        eval('namespace Illuminate\Support\Facades; class View {
            public static function exists($view) { return true; }
            public static function make($view, $data = []) { throw new \RuntimeException("Blade compile error"); }
        }');

        // Port 1 is never reached: rendering fails first. No exception may escape.
        self::assertFalse(Mailer::sendMail(
            $this->configs(1),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>',
            null,
            null,
            [],
            [],
            0,
            0,
            [],
            [],
            [],
            [],
            'emails.broken'
        ));
    }

    /** The happy path of that same resolution: a view that renders becomes the body. */
    #[RunInSeparateProcess]
    #[PreserveGlobalState(false)]
    public function testSendMailUsesTheRenderedViewAsTheBodyWhenTheTemplateExists(): void
    {
        eval('namespace Illuminate\Support\Facades; class View {
            public static function exists($view) { return $view === "emails.welcome"; }
            public static function make($view, $data = []) { return new class($data) {
                public function __construct(private array $data) {}
                public function render() { return "<p>RENDERED:" . $this->data["subject"] . "</p>"; }
            }; }
        }');

        $port = $this->startSink();

        self::assertTrue(Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Hello',
            '<p>raw body that the template replaces</p>',
            null,
            null,
            [],
            [],
            0,
            0,
            [],
            [],
            [],
            [],
            'emails.welcome'
        ));

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString('<p>RENDERED:Hello</p>', $eml);
        self::assertStringNotContainsString('raw body that the template replaces', $eml);
    }

    // ---------------------------------------------------------------- $useAuth

    /**
     * FINDING: SMTPSecure/Host/Port were assigned ONLY inside `if ($params['useAuth'])`, while
     * $useAuth was documented as governing "password authentication". So an unauthenticated relay —
     * whose host/port/secure the guard REQUIRES — had its transport config dropped, and PHPMailer
     * quietly fell back to localhost:25.
     *
     * Without the fix nothing ever arrives at the sink, because the message goes to localhost:25.
     */
    public function testSendMailAppliesHostAndPortEvenWhenUseAuthIsFalse(): void
    {
        $port = $this->startSink();

        self::assertTrue(Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>unauthenticated relay</p>',
            useAuth: false
        ));

        $capture = $this->sinkCapture();
        // It reached the CONFIGURED host/port...
        self::assertStringContainsString('<p>unauthenticated relay</p>', $capture['eml']);
        // ...and $useAuth === false still means no credentials, which is all it ever promised.
        self::assertStringNotContainsString('AUTH', $capture['session']);
        self::assertStringNotContainsString(base64_encode('secret'), $capture['session']);
    }

    /** The default ($useAuth = true) authenticates with the configured credentials. */
    public function testSendMailAuthenticatesWithConfiguredCredentialsByDefault(): void
    {
        $port = $this->startSink();

        self::assertTrue(Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>'
        ));

        $session = $this->sinkCapture()['session'];
        self::assertStringContainsString('AUTH LOGIN', $session);
        self::assertStringContainsString(base64_encode('smtp-user'), $session);
        self::assertStringContainsString(base64_encode('secret'), $session);
    }

    // ---------------------------------------------------------------- $useEmbeddedImages

    /**
     * FINDING: the loop indexed $imgsBody — whose outer index is the CAPTURE GROUP — by the
     * preg_split PART number, handing an ARRAY to getImageSrc(string). That is a TypeError, which
     * is an \Error and therefore sails straight past the PHPMailer-only catch, so ANY body with an
     * <img> in it killed the request instead of returning bool.
     *
     * A second TypeError sat right behind it: $params['template'] was overwritten with the
     * preg_split ARRAY and then handed to strip_tags(string) for the AltBody.
     */
    public function testSendMailEmbedsALocalImageAndRewritesItsTagToACid(): void
    {
        $port = $this->startSink();
        $png  = $this->makePng();

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>before</p><img src="' . $png . '"><p>after</p>',
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $this->tmpDir // the png lives here
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString('<img src="cid:attach-0">', $eml);
        self::assertStringContainsString('Content-ID: <attach-0>', $eml);
        // The surrounding markup survives the split/rejoin.
        self::assertStringContainsString('<p>before</p>', $eml);
        self::assertStringContainsString('<p>after</p>', $eml);
        // The local path must NOT be left in the body once it has been embedded.
        self::assertStringNotContainsString('<img src="' . $png . '">', $eml);
    }

    /**
     * The greedy `[\w\W]{0,}` matched from the FIRST `<img` to the LAST `>`, so a two-image body
     * collapsed into one match and the text between the images was silently deleted.
     */
    public function testSendMailEmbedsEveryImageAndKeepsTheTextBetweenThem(): void
    {
        $port  = $this->startSink();
        $first  = $this->makePng('one.png');
        $second = $this->makePng('two.png');

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<img src="' . $first . '">MIDDLE-TEXT<img src="' . $second . '">',
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $this->tmpDir
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString('<img src="cid:attach-0">', $eml);
        self::assertStringContainsString('<img src="cid:attach-1">', $eml);
        self::assertStringContainsString('MIDDLE-TEXT', $eml);
        self::assertStringContainsString('Content-ID: <attach-0>', $eml);
        self::assertStringContainsString('Content-ID: <attach-1>', $eml);
    }

    /**
     * REGRESSION. The lazy `[\w\W]*?` that replaced the greedy quantifier expands ONE CHARACTER AT A
     * TIME, and every one of those steps counts against pcre.backtrack_limit (1,000,000 by default).
     * A single <img> carrying an ordinary inline base64 logo is enough to exhaust it, at which point
     * preg_match_all() and preg_split() both return FALSE — quietly. The FALSE was never checked, so
     * it reached `foreach (false)` AFTER Body had already been reset to "", and the message went out
     * EMPTY while sendMail() still reported success.
     *
     * The regex must therefore be linear, and a failed scan must never be able to blank the body.
     */
    public function testSendMailDeliversTheBodyWhenAnInlineImageExceedsThePcreBacktrackLimit(): void
    {
        $port = $this->startSink();

        // ~600KB of base64 in one src: an unremarkable inline logo, and 600K lazy steps.
        $dataUri = 'data:image/png;base64,' . str_repeat('A', 600000);
        $body    = '<p>Your invoice</p><img src="' . $dataUri . '"><p>Total: 42</p>';

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            $body,
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $this->tmpDir
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        self::assertNotSame('', trim($eml), 'the message was delivered EMPTY');
        // The body the caller wrote must actually be on the wire.
        self::assertStringContainsString('Your invoice', $eml);
        self::assertStringContainsString('Total: 42', $eml);
    }

    /**
     * The same defect, in the shape that does not need 600KB: proof the scan is linear rather than
     * merely under the limit for this input.
     */
    public function testSendMailScansABodyOfManyImagesWithoutExhaustingPcre(): void
    {
        $port = $this->startSink();
        $png  = $this->makePng();

        $body = '<p>start</p>';
        for ($i = 0; $i < 200; $i++) {
            $body .= '<img src="' . $png . '">TEXT-' . $i;
        }
        $body .= '<p>end</p>';

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            $body,
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $this->tmpDir
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString('start', $eml);
        self::assertStringContainsString('end', $eml);
        // Every separator between two images survives the split/rejoin.
        self::assertStringContainsString('TEXT-0', $eml);
        self::assertStringContainsString('TEXT-199', $eml);
    }

    /**
     * A '>' inside a QUOTED attribute does not end the tag — HTML says so, and the old regex did
     * not. It matched up to that first '>', embedded a truncated tag, and spilled the rest of the
     * attribute ("height\">TAIL") into the delivered body as visible text.
     */
    public function testSendMailDoesNotEndAnImgTagAtAGreaterThanInsideAQuotedAttribute(): void
    {
        $port = $this->startSink();
        $png  = $this->makePng();

        $tag  = '<img src="' . $png . '" alt="width > height">';
        $body = '<p>x</p>' . $tag . 'TAIL';

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            $body,
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $this->tmpDir
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        // The whole tag was recognised, so it is replaced whole...
        self::assertStringContainsString('<img src="cid:attach-0">', $eml);
        // ...and no remnant of the attribute leaked into the body as text.
        self::assertStringNotContainsString('height', $eml);
        self::assertStringContainsString('TAIL', $eml);
    }

    /**
     * The mojibake of getImageSrc(), end to end: an image whose FILENAME is not ASCII. The src was
     * parsed as ISO-8859-1, so the path handed to the allow-list named a file that does not exist,
     * and a perfectly ordinary "ação.png" logo silently never embedded.
     */
    public function testSendMailEmbedsAnImageWhoseFilenameIsNotAscii(): void
    {
        $name = 'ação-日本.png';
        $png  = $this->makePng($name);
        // The filesystem must really hold that name, or this proves nothing about the parser.
        if (!is_file($png) || $png !== $this->tmpDir . DIRECTORY_SEPARATOR . $name) {
            self::markTestSkipped('this filesystem did not store the non-ASCII filename verbatim');
        }

        $port = $this->startSink();
        $ok   = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>x</p><img src="' . $name . '">', // relative: resolved against the base dir
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $this->tmpDir
        );

        self::assertTrue($ok);
        self::assertStringContainsString('<img src="cid:attach-0">', $this->sinkCapture()['eml']);
    }

    /**
     * A src that is not a readable local file is documented as being left exactly as written —
     * not embedded, not dropped, and NOT a failed send. addEmbeddedImage() throws on an
     * inaccessible path, which would otherwise have taken the whole message down.
     *
     * @return array<string, array{0: string}>
     */
    public static function unembeddableSrcProvider(): array
    {
        return [
            'remote url'      => ['https://example.com/logo.png'],
            'data uri'        => ['data:image/gif;base64,R0lGODlhAQABAAAAACw='],
            'missing file'    => ['/nonexistent/path/to/nowhere.png'],
            'no src at all'   => [''],
        ];
    }

    #[DataProvider('unembeddableSrcProvider')]
    public function testSendMailLeavesAnUnembeddableImageTagUntouchedAndStillSends(string $src): void
    {
        $port = $this->startSink();
        $tag  = $src === '' ? '<img alt="none">' : '<img src="' . $src . '">';

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>x</p>' . $tag,
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $this->tmpDir
        );

        self::assertTrue($ok, 'an unembeddable image must not fail the send');

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString($tag, $eml);
        self::assertStringNotContainsString('cid:attach-', $eml);
    }

    /** A body with no <img> at all must survive the embedding path untouched. */
    public function testSendMailWithUseEmbeddedImagesAndNoImagesLeavesTheBodyIntact(): void
    {
        $port = $this->startSink();

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>no images here</p>',
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $this->tmpDir
        );

        self::assertTrue($ok);
        self::assertStringContainsString('<p>no images here</p>', $this->sinkCapture()['eml']);
    }

    // ------------------------------------------------- $useEmbeddedImages: the allow-list

    /**
     * Builds the exfiltration fixture: an allow-listed base dir holding one legitimate image, and an
     * off-limits file OUTSIDE it that no <img> may ever reach. It stands in for the /etc/passwd of
     * the original report — the point is only that it is a readable file the caller never
     * allow-listed.
     *
     * @return array{base: string, png: string, offLimits: string}
     */
    private function makeAllowListFixture(): array
    {
        $base = $this->tmpDir . DIRECTORY_SEPARATOR . 'allowed';
        mkdir($base);
        file_put_contents($base . DIRECTORY_SEPARATOR . 'logo.png', base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='
        ));

        $outOfBounds = $this->tmpDir . DIRECTORY_SEPARATOR . 'off-limits.txt';
        file_put_contents($outOfBounds, self::OFF_LIMITS_MARKER);

        return [
            'base'      => $base,
            'png'       => $base . DIRECTORY_SEPARATOR . 'logo.png',
            'offLimits' => $outOfBounds,
        ];
    }

    /** Contents of the out-of-bounds file. If this ever reaches the wire, the allow-list failed. */
    private const OFF_LIMITS_MARKER = 'CONTENTS-THAT-MUST-NEVER-BE-MAILED';

    /** Asserts the off-limits file is absent from $eml in every encoding an attachment could use. */
    private function assertNotExfiltrated(string $eml): void
    {
        self::assertStringNotContainsString(
            self::OFF_LIMITS_MARKER,
            $eml,
            'the out-of-bounds file was mailed out verbatim'
        );
        self::assertStringNotContainsString(
            base64_encode(self::OFF_LIMITS_MARKER),
            $eml,
            'the out-of-bounds file was mailed out as a base64 attachment'
        );
        self::assertStringNotContainsString(
            chunk_split(base64_encode(self::OFF_LIMITS_MARKER)),
            $eml
        );
    }

    /**
     * Cleans up the extra directory the allow-list fixture makes, which tearDown()'s flat glob
     * cannot remove.
     */
    protected function tearDownAllowListFixture(): void
    {
        $base = $this->tmpDir . DIRECTORY_SEPARATOR . 'allowed';
        if (is_dir($base)) {
            foreach ((array) glob($base . DIRECTORY_SEPARATOR . '*') as $file) {
                @unlink($file);
            }
            @rmdir($base);
        }
    }

    /**
     * FINDING (fixed): the src of every <img> was passed straight to addEmbeddedImage() with NO
     * allow-list, scheme check, or traversal check. That made $useEmbeddedImages an arbitrary file
     * read on any attacker-influenced body — and worse than an LFI, because the file is mailed OUT.
     *
     * ../ is the classic escape, and it must not work even though the traversal is spelled inside
     * an otherwise legitimate-looking path under the allow-listed directory. Containment is checked
     * AFTER realpath() collapses the ../, so what is compared is where the file REALLY is.
     */
    public function testSendMailRefusesToEmbedAFileReachedByTraversingOutOfTheBaseDir(): void
    {
        $port    = $this->startSink();
        $fixture = $this->makeAllowListFixture();

        // Starts inside the allow-list, climbs out. realpath() resolves it to the off-limits file.
        $traversal = $fixture['base'] . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'off-limits.txt';
        self::assertFileExists($traversal, 'the traversal must really resolve, or this proves nothing');

        $tag = '<img src="' . $traversal . '">';
        $ok  = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>x</p>' . $tag,
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $fixture['base']
        );

        self::assertTrue($ok, 'a rejected image must not fail the send');

        $eml = $this->sinkCapture()['eml'];
        $this->assertNotExfiltrated($eml);
        // Rejected means "left exactly as the caller wrote it", not "embedded" and not "dropped".
        self::assertStringContainsString($tag, $eml);
        self::assertStringNotContainsString('cid:attach-', $eml);

        $this->tearDownAllowListFixture();
    }

    /** The plain shape of the same attack: an absolute path that simply is not under the base dir. */
    public function testSendMailRefusesToEmbedAnAbsolutePathOutsideTheBaseDir(): void
    {
        $port    = $this->startSink();
        $fixture = $this->makeAllowListFixture();

        $tag = '<img src="' . $fixture['offLimits'] . '">';
        $ok  = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>x</p>' . $tag,
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $fixture['base']
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        $this->assertNotExfiltrated($eml);
        self::assertStringContainsString($tag, $eml);
        self::assertStringNotContainsString('cid:attach-', $eml);

        $this->tearDownAllowListFixture();
    }

    /**
     * THE SIBLING-PREFIX ESCAPE — the case the containment guard exists for, and the one nothing
     * tested. Every other allow-list test here passes with the guard's trailing separator deleted;
     * this one does not.
     *
     * Containment is a string-prefix test, and "/srv/imgs-evil/x.png" starts with "/srv/imgs".
     * Comparing against the base plus DIRECTORY_SEPARATOR is the entire defence: it demands the next
     * character be a separator, so a sibling directory that merely begins with the base dir's name
     * is outside, exactly as it looks.
     */
    public function testSendMailRefusesToEmbedFromASiblingDirSharingTheBaseDirNamePrefix(): void
    {
        $port = $this->startSink();

        $base = $this->tmpDir . DIRECTORY_SEPARATOR . 'imgs';
        $evil = $this->tmpDir . DIRECTORY_SEPARATOR . 'imgs-evil'; // NOT under $base — a sibling
        mkdir($base);
        mkdir($evil);
        file_put_contents($evil . DIRECTORY_SEPARATOR . 'x.png', self::OFF_LIMITS_MARKER);

        // The prefix really does collide, or this test proves nothing.
        self::assertStringStartsWith($base, $evil);

        $tag = '<img src="' . $evil . DIRECTORY_SEPARATOR . 'x.png">';
        $ok  = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>x</p>' . $tag,
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $base
        );

        self::assertTrue($ok, 'a rejected image must not fail the send');

        $eml = $this->sinkCapture()['eml'];
        $this->assertNotExfiltrated($eml);
        self::assertStringNotContainsString('cid:attach-', $eml);
    }

    /**
     * A symlink INSIDE the allow-list pointing OUT of it. The docblock promises "neither ../ nor a
     * symlink can escape", and containment is checked after realpath() has followed the link — so
     * what is compared is where the file truly is, not the innocent name inside the base dir.
     */
    public function testSendMailRefusesToEmbedThroughASymlinkLeavingTheBaseDir(): void
    {
        $base      = $this->tmpDir . DIRECTORY_SEPARATOR . 'imgs';
        $offLimits = $this->tmpDir . DIRECTORY_SEPARATOR . 'off-limits.txt';
        mkdir($base);
        file_put_contents($offLimits, self::OFF_LIMITS_MARKER);

        $link = $base . DIRECTORY_SEPARATOR . 'innocent.png';
        if (!@symlink($offLimits, $link)) {
            // Windows needs SeCreateSymbolicLinkPrivilege (admin or Developer Mode).
            self::markTestSkipped('this platform/user cannot create symlinks');
        }

        // The link really resolves out of the base dir, or this test proves nothing.
        self::assertSame(realpath($offLimits), realpath($link));

        $port = $this->startSink();
        $tag  = '<img src="' . $link . '">';
        $ok   = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>x</p>' . $tag,
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $base
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        $this->assertNotExfiltrated($eml);
        self::assertStringNotContainsString('cid:attach-', $eml);
    }

    /** A file genuinely inside the base dir is still embedded — the allow-list allows, not just denies. */
    public function testSendMailEmbedsAFileInsideTheBaseDir(): void
    {
        $port    = $this->startSink();
        $fixture = $this->makeAllowListFixture();

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<img src="' . $fixture['png'] . '">',
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $fixture['base']
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString('<img src="cid:attach-0">', $eml);
        self::assertStringContainsString('Content-ID: <attach-0>', $eml);

        $this->tearDownAllowListFixture();
    }

    /** ...and a RELATIVE src is resolved against the base dir, not the process CWD. */
    public function testSendMailResolvesARelativeSrcAgainstTheBaseDir(): void
    {
        $port    = $this->startSink();
        $fixture = $this->makeAllowListFixture();

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<img src="logo.png">',
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $fixture['base']
        );

        self::assertTrue($ok);
        self::assertStringContainsString('<img src="cid:attach-0">', $this->sinkCapture()['eml']);

        $this->tearDownAllowListFixture();
    }

    /** ...and a relative traversal out of the base dir is refused just like an absolute one. */
    public function testSendMailRefusesARelativeTraversalOutOfTheBaseDir(): void
    {
        $port    = $this->startSink();
        $fixture = $this->makeAllowListFixture();

        $tag = '<img src="../off-limits.txt">';
        $ok  = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>x</p>' . $tag,
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $fixture['base']
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        $this->assertNotExfiltrated($eml);
        self::assertStringContainsString($tag, $eml);

        $this->tearDownAllowListFixture();
    }

    /**
     * Deny-by-default: asking to embed without naming an allow-listed directory is a refusal, not a
     * best-effort "embed whatever the body points at". A silent no-op would be worse — it would send
     * a message whose images are all broken and still report success.
     *
     * @return array<string, array{0: string|null}>
     */
    public static function unusableBaseDirProvider(): array
    {
        return [
            'null'             => [null],
            'empty string'     => [''],
            'blank string'     => ['   '],
            'no such dir'      => ['/no/such/directory/anywhere'],
        ];
    }

    #[DataProvider('unusableBaseDirProvider')]
    public function testSendMailReturnsFalseWhenEmbeddingIsRequestedWithoutAUsableBaseDir(?string $baseDir): void
    {
        self::assertFalse(Mailer::sendMail(
            $this->configs(1),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<img src="logo.png">',
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $baseDir
        ));
    }

    /** A FILE is not a directory: it cannot be an allow-list. */
    public function testSendMailReturnsFalseWhenTheBaseDirIsAFile(): void
    {
        $png = $this->makePng();

        self::assertFalse(Mailer::sendMail(
            $this->configs(1),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<img src="dot.png">',
            useEmbeddedImages: true,
            embeddedImagesBaseDir: $png
        ));
    }

    /** $embeddedImagesBaseDir is inert when embedding is off — it must not become a new way to fail. */
    public function testSendMailIgnoresTheBaseDirWhenEmbeddingIsDisabled(): void
    {
        $port = $this->startSink();

        self::assertTrue(Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>',
            useEmbeddedImages: false,                          // OFF...
            embeddedImagesBaseDir: '/no/such/directory/at/all' // ...never looked at
        ));

        self::assertStringContainsString('<p>Body</p>', $this->sinkCapture()['eml']);
    }

    // ---------------------------------------------------------------- recipient / attachment keys

    /**
     * Regression pin for the 'nome' -> 'name' rename. Every documented English key must be the one
     * the code actually reads, across all five collections at once.
     */
    public function testSendMailReadsTheDocumentedNameKeyForEveryRecipientKind(): void
    {
        $port = $this->startSink();

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To Person']],
            'Subject',
            '<p>Body</p>',
            null,
            null,
            [],
            [],
            0,
            0,
            [['email' => 'cc@example.com', 'name' => 'Cc Person']],
            [['email' => 'bcc@example.com', 'name' => 'Bcc Person']],
            [['email' => 'reply@example.com', 'name' => 'Reply Person']]
        );

        self::assertTrue($ok);

        $capture = $this->sinkCapture();
        $eml     = $capture['eml'];
        self::assertStringContainsString('From: From Name <from@example.com>', $eml);
        self::assertStringContainsString('To: To Person <to@example.com>', $eml);
        self::assertStringContainsString('Cc: Cc Person <cc@example.com>', $eml);
        self::assertStringContainsString('Reply-To: Reply Person <reply@example.com>', $eml);
        // Bcc is never a header, but it must still be an envelope recipient.
        self::assertStringNotContainsString('Bcc Person', $eml);
        self::assertStringContainsString('RCPT TO:<bcc@example.com>', $capture['session']);
    }

    /** 'name' is documented as OPTIONAL everywhere; omitting it must not warn and must not fail. */
    public function testSendMailAcceptsRecipientsWithNoNameKey(): void
    {
        $port = $this->startSink();

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com']],
            'Subject',
            '<p>Body</p>',
            null,
            null,
            [],
            [],
            0,
            0,
            [['email' => 'cc@example.com']],
            [['email' => 'bcc@example.com']],
            [['email' => 'reply@example.com']]
        );

        self::assertTrue($ok);
        self::assertStringContainsString('To: to@example.com', $this->sinkCapture()['eml']);
    }

    /** $files uses the documented ['file' => path, 'name' => filename] shape. */
    public function testSendMailAttachesAFileUsingTheDocumentedKeys(): void
    {
        $port = $this->startSink();
        $path = $this->tmpDir . DIRECTORY_SEPARATOR . 'source.txt';
        file_put_contents($path, 'attached file contents');

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>',
            null,
            null,
            [['file' => $path, 'name' => 'renamed.txt']]
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString('renamed.txt', $eml);
        self::assertStringContainsString(base64_encode('attached file contents'), $eml);
    }

    /** $stringFiles uses the documented ['string' => contents, 'name' => filename] shape. */
    public function testSendMailAttachesAStringUsingTheDocumentedKeys(): void
    {
        $port = $this->startSink();

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>',
            null,
            null,
            [],
            [['string' => 'id,name' . "\n" . '1,ana', 'name' => 'report.csv']]
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString('report.csv', $eml);
        self::assertStringContainsString(base64_encode('id,name' . "\n" . '1,ana'), $eml);
    }

    // ---------------------------------------------------------------- misc documented options

    public function testSendMailAppliesPriorityAndCustomHeadersAndReadConfirmation(): void
    {
        $port = $this->startSink();

        $ok = Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>Body</p>',
            null,
            null,
            [],
            [],
            1, // high priority
            0,
            [],
            [],
            [],
            ['X-Campaign' => 'july'],
            null,
            true // $confirm
        );

        self::assertTrue($ok);

        $eml = $this->sinkCapture()['eml'];
        self::assertStringContainsString('X-Priority: 1', $eml);
        self::assertStringContainsString('X-Campaign: july', $eml);
        self::assertStringContainsString('Disposition-Notification-To: <from@example.com>', $eml);
    }

    /**
     * The charset asked for is the charset sent — proven with a NON-default value, because asserting
     * the default 'UTF-8' arrives passes just as happily when $charset is dropped on the floor.
     *
     * It is passed POSITIONALLY on purpose. $charset is the 18th parameter, and this pins it there:
     * when $embeddedImagesBaseDir was inserted mid-signature next to the $useEmbeddedImages it reads
     * nicely beside, it pushed $charset/$useAuth/$isSMTP/$debugMode one slot right, and every
     * positional caller silently handed its charset to a parameter that ignored it. Named arguments
     * elsewhere in this file cannot catch that; only a positional call can.
     */
    public function testSendMailAppliesTheRequestedCharsetGivenPositionally(): void
    {
        $port = $this->startSink();

        self::assertTrue(Mailer::sendMail(
            $this->configs($port),
            [['email' => 'to@example.com', 'name' => 'To']],
            'Subject',
            '<p>acentuação</p>',
            null,
            null,
            [],
            [],
            0,
            0,
            [],
            [],
            [],
            [],
            null,
            false,
            false,
            'ISO-8859-1' // the 18th argument, and it must land on $charset
        ));

        self::assertStringContainsString('charset=iso-8859-1', strtolower($this->sinkCapture()['eml']));
    }

    /**
     * The parameter ORDER is part of the contract for every positional caller, so it is pinned
     * whole. $embeddedImagesBaseDir belongs at the END: a new parameter is APPENDED, never slotted
     * in beside the one it relates to, however much better that reads.
     */
    public function testSendMailParameterOrderIsTheDocumentedOne(): void
    {
        $names = array_map(
            static fn (\ReflectionParameter $p): string => $p->getName(),
            (new \ReflectionMethod(Mailer::class, 'sendMail'))->getParameters()
        );

        self::assertSame([
            'configs', 'sendTo', 'subject', 'body',
            'useConfig', 'lang', 'files', 'stringFiles', 'priority', 'wrap',
            'cc', 'cco', 'reply', 'headers', 'template', 'confirm', 'useEmbeddedImages',
            'charset', 'useAuth', 'isSMTP', 'debugMode',
            'embeddedImagesBaseDir',
        ], $names);
    }

    // ---------------------------------------------------------------- validateMail

    /**
     * @return array<string, array{0: string}>
     */
    public static function validEmailProvider(): array
    {
        return [
            'simple'            => ['a@b.com'],
            'dotted local part' => ['first.last@sub.domain.co.uk'],
            'plus tag'          => ['user+tag@example.com'],
            'ip literal'        => ['user@[192.168.0.1]'],
            'mixed case'        => ['UPPER@Example.COM'],
            'short tld'         => ['a@b.c'],
        ];
    }

    #[DataProvider('validEmailProvider')]
    public function testValidateMailAcceptsValidAddresses(string $email): void
    {
        self::assertTrue(Mailer::validateMail($email));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function invalidEmailProvider(): array
    {
        return [
            'empty string'     => [''],
            'no at sign'       => ['no-at'],
            'no domain'        => ['a@'],
            'no local part'    => ['@b.com'],
            'space in local'   => ['a b@c.com'],
            'no dot in domain' => ['x@b'],
            'leading hyphen'   => ['a@-b.com'],
            'double dot'       => ['a@b..com'],
        ];
    }

    #[DataProvider('invalidEmailProvider')]
    public function testValidateMailRejectsInvalidAddresses(string $email): void
    {
        self::assertFalse(Mailer::validateMail($email));
    }

    /** The signature is ?string, and the docblock says null is simply invalid — not a crash. */
    public function testValidateMailReturnsFalseForNull(): void
    {
        self::assertFalse(Mailer::validateMail(null));
    }

    // ---------------------------------------------------------------- getImageSrc

    public function testGetImageSrcReturnsTheSrcAttribute(): void
    {
        self::assertSame('/tmp/a.png', Mailer::getImageSrc('<img src="/tmp/a.png">'));
    }

    public function testGetImageSrcHandlesSingleQuotesAndSelfClosingTags(): void
    {
        self::assertSame('b.png', Mailer::getImageSrc("<img src='b.png'/>"));
    }

    public function testGetImageSrcIsCaseInsensitive(): void
    {
        self::assertSame('C:/x/y.png', Mailer::getImageSrc('<IMG SRC="C:/x/y.png">'));
    }

    public function testGetImageSrcReturnsFirstSrcWhenSeveralImagesArePresent(): void
    {
        self::assertSame('a.png', Mailer::getImageSrc('<img src="a.png"><img src="b.png">'));
    }

    public function testGetImageSrcReturnsEmptyStringWhenTagHasNoSrc(): void
    {
        self::assertSame('', Mailer::getImageSrc('<img alt="nothing">'));
    }

    public function testGetImageSrcReturnsEmptyStringForMarkupWithoutAnImage(): void
    {
        self::assertSame('', Mailer::getImageSrc('<p>not an image</p>'));
    }

    /**
     * FINDING: the docblock promises a total string contract ("or an empty string if not found")
     * and declares no @throws, but DOMDocument::loadHTML() raises a ValueError on an empty string —
     * and `@` cannot suppress a ValueError, because it is an exception, not a diagnostic.
     *
     * @return array<string, array{0: string}>
     */
    public static function blankImgTagProvider(): array
    {
        return [
            'empty string'    => [''],
            'spaces'          => ['   '],
            'newline and tab' => ["\n\t"],
        ];
    }

    #[DataProvider('blankImgTagProvider')]
    public function testGetImageSrcReturnsEmptyStringForBlankInputInsteadOfThrowing(string $input): void
    {
        self::assertSame('', Mailer::getImageSrc($input));
    }

    public function testGetImageSrcDoesNotValidateOrSanitiseTheSrc(): void
    {
        // Documented explicitly: the src comes back raw, whatever it is.
        self::assertSame('javascript:alert(1)', Mailer::getImageSrc('<img src="javascript:alert(1)">'));
        self::assertSame('../../etc/passwd', Mailer::getImageSrc('<img src="../../etc/passwd">'));
    }

    /**
     * DOMDocument::loadHTML() assumes ISO-8859-1 when the markup declares no encoding, so every
     * non-ASCII src came back mojibake'd — "ação.png" as "aÃ§Ã£o.png" — and could never match the
     * file it names. UTF-8 is this library's charset everywhere else; it must be this parser's too.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonAsciiSrcProvider(): array
    {
        return [
            'latin accents'   => ['logotipo-ação.png'],
            'cjk'             => ['日本語.png'],
            'diacritics+path' => ['café/naïve.png'],
            'cyrillic'        => ['логотип.png'],
            'emoji'           => ['logo-🚀.png'],
        ];
    }

    #[DataProvider('nonAsciiSrcProvider')]
    public function testGetImageSrcReturnsANonAsciiSrcUnmangled(string $src): void
    {
        self::assertSame($src, Mailer::getImageSrc('<img src="' . $src . '">'));
    }
}
