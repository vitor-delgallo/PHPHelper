<?php

namespace VD\PHPHelper\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
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

        if ($this->tmpDir !== '' && is_dir($this->tmpDir)) {
            foreach ((array) glob($this->tmpDir . DIRECTORY_SEPARATOR . '*') as $file) {
                @unlink($file);
            }
            @rmdir($this->tmpDir);
        }
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
     * 'secure' => 'none' is deliberate: the docblock says any non-empty value other than 'tls'/'ssl'
     * means no encryption, and the sink speaks plaintext.
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
            'UTF-8',
            false // $useAuth
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
            true // $useEmbeddedImages
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
            true
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
            true
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
            true
        );

        self::assertTrue($ok);
        self::assertStringContainsString('<p>no images here</p>', $this->sinkCapture()['eml']);
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

    public function testSendMailAppliesTheRequestedCharset(): void
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
            'UTF-8'
        ));

        self::assertStringContainsString('charset=utf-8', strtolower($this->sinkCapture()['eml']));
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
}
