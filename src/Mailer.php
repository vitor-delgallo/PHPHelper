<?php

namespace VD\PHPHelper;

class Mailer {
    /**
     * Sends an email using PHPMailer.
     *
     * @param array $configs Sender configuration.
     *                       REQUIRED: 'email' (the From address) and 'name' (the From display
     *                       name). 'email' may be omitted ONLY when 'user' is itself a valid
     *                       address, in which case 'user' becomes the From address.
     *                       REQUIRED unless $useConfig === 'no-config': 'pass'. 'user' (the SMTP
     *                       username) is optional — it defaults to 'email'.
     *                       REQUIRED unless $useConfig is one of the recognised provider shortcuts
     *                       (see $useConfig): 'host', 'port' and 'secure'.
     *                       'secure' is handed straight to PHPMailer's SMTPSecure: 'tls' negotiates
     *                       STARTTLS and 'ssl' opens an implicit-TLS connection. It may not be left
     *                       empty (the guard below rejects that), and ANY other non-empty value
     *                       means NO ENCRYPTION — 'user'/'pass' then travel in cleartext.
     *                       A missing required key makes this function return FALSE without
     *                       sending, throwing, or logging.
     * @param array $sendTo List of recipients, each ['email' => string (required), 'name' => string
     *                      (optional, the display name; defaults to '')]
     * @param string $subject Email subject
     * @param string $body Email body content (HTML). Required, must be non-empty, and is used
     *                     verbatim as the message body — UNLESS $template names a Laravel view that
     *                     actually exists, which is the only thing that can replace it.
     *
     * Optional parameters:
     * @param string|null $useConfig Optional shortcut, case-insensitive. The set is CLOSED:
     *                    'gmail' | 'office365' | 'yahoo' | 'hotmail' — fills in 'host', 'secure'
     *                    ('tls') and 'port' (587), OVERWRITING whatever $configs held for them;
     *                    'no-config' — not a provider: it is the only way to send with no
     *                    credentials. It forces $useAuth to FALSE and nulls 'user', 'pass', 'host',
     *                    'secure' and 'port', leaving PHPMailer on its own defaults (localhost:25,
     *                    no encryption).
     *                    Any OTHER non-empty value ('outlook', 'protonmail', …) is not a shortcut
     *                    and is silently ignored; 'host', 'secure' and 'port' then become required,
     *                    so omitting them makes every send return FALSE.
     * @param string|null $lang Language setting for the email
     * @param array $files File attachments, each ['file' => string path (required), 'name' => string
     *                     (optional, the attachment filename; defaults to '')]
     * @param array $stringFiles String-based attachments, each ['string' => string contents
     *                           (required), 'name' => string (optional, filename; defaults to '')]
     * @param int $priority Email priority (1: High, 2: Medium, 3: Low)
     * @param int $wrap Max characters per line
     * @param array $cc List of CC recipients — same shape as $sendTo
     * @param array $cco List of BCC recipients — same shape as $sendTo
     * @param array $reply List of reply-to addresses — same shape as $sendTo
     * @param array $headers Custom headers
     * @param string|null $template Name of a Laravel Blade view to render as the body, which
     *                    receives ['lang', 'debugMode', 'sendTo', 'subject', 'body']. It is NOT a
     *                    raw HTML body: pass HTML as $body. $body is used instead whenever
     *                    $template is null/empty, names no existing view, or the Laravel View
     *                    facade is not installed at all (this library does not depend on Laravel).
     * @param bool $confirm Request read confirmation
     * @param bool $useEmbeddedImages When TRUE, every <img> in the resolved body whose src is a
     *                    readable LOCAL file is attached to the message and its tag rewritten to
     *                    <img src="cid:attach-N">. An <img> whose src is empty, remote (http/https),
     *                    a data: URI, or an unreadable path is left in the body EXACTLY as the
     *                    caller wrote it: it is not embedded, and it does not fail the send.
     *                    SECURITY: an src is treated as a path to read and attach, with NO
     *                    allow-list, scheme check, or traversal check of any kind. Do NOT enable
     *                    this on a body built from untrusted input — <img src="/etc/passwd"> in an
     *                    attacker-controlled body attaches that file to the outgoing mail.
     * @param string $charset Charset (default: UTF-8)
     * @param bool $useAuth Whether to authenticate with $configs['user'] / $configs['pass'].
     *                      Governs the CREDENTIALS ONLY — 'host', 'port' and 'secure' are applied
     *                      to the transport either way whenever $isSMTP is TRUE.
     * @param bool $isSMTP Whether to deliver over SMTP. When FALSE, PHPMailer falls back to PHP's
     *                     mail() and EVERY transport key in $configs ('host', 'port', 'secure',
     *                     'user', 'pass') is ignored — they are still required by the guard below,
     *                     but nothing reads them.
     * @param bool $debugMode Enable debug output. Also passed to the $template view. NOTE: the
     *                        SMTP conversation is printed to STDOUT (PHPMailer's default), so this
     *                        must stay FALSE outside a console.
     *
     * @return bool TRUE on success. FALSE on a missing/invalid $configs key, on an empty $sendTo /
     *              $subject / $body, and on any PHPMailer failure — those are caught and swallowed
     *              here, so a FALSE carries no reason, no exception and no log line.
     *              CAUTION: only \PHPMailer\PHPMailer\Exception is caught. Any OTHER Throwable
     *              raised while the message is built PROPAGATES to the caller — including the
     *              ErrorException that a strict error handler (Laravel's, for one) makes out of a
     *              PHP warning. So this function can both return FALSE and throw.
     */
    public static function sendMail(
        array $configs,
        array $sendTo,
        string $subject,
        string $body,
        ?string $useConfig = null,
        ?string $lang = null,
        array $files = array(),
        array $stringFiles = array(),
        int $priority = 0,
        int $wrap = 0,
        array $cc = array(),
        array $cco = array(),
        array $reply = array(),
        array $headers = array(),
        ?string $template = null,
        bool $confirm = false,
        bool $useEmbeddedImages = false,
        string $charset = 'UTF-8',
        bool $useAuth = true,
        bool $isSMTP = true,
        bool $debugMode = false
    ): bool {
        if (!class_exists(\PHPMailer\PHPMailer\PHPMailer::class)) {
            return false;
        }

        if (!empty($useConfig)) {
            $useConfig = Str::strToLower($useConfig);
        }
        if (empty($configs['user']) && !empty($configs['email'])) {
            $configs['user'] = $configs['email'];
        }
        if (empty($configs['email']) && !empty($configs['user']) && self::validateMail($configs['user'])) {
            $configs['email'] = $configs['user'];
        }

        if (
            empty($configs) ||
            (
                (empty($useConfig) || !in_array($useConfig, ['no-config', 'gmail', 'office365', 'yahoo', 'hotmail'])) &&
                (empty($configs['secure']) || empty($configs['host']) || empty($configs['port']))
            ) ||
            (empty($configs['user']) && $useConfig !== 'no-config') ||
            (empty($configs['pass']) && $useConfig !== 'no-config') ||
            empty($configs['email']) ||
            empty($configs['name']) ||
            empty($sendTo) ||
            empty($subject) ||
            empty($body)
        ) {
            return false;
        }

        if (!empty($useConfig)) {
            switch ($useConfig) {
                case 'gmail':
                case 'office365':
                case 'yahoo':
                case 'hotmail':
                    $tempHost = $useConfig;
                    if ($useConfig === 'hotmail') $tempHost = 'live';
                    elseif ($useConfig === 'yahoo') $tempHost = 'mail.yahoo';

                    $configs['host'] = "smtp." . $tempHost . ".com";
                    $configs['secure'] = 'tls';
                    $configs['port'] = 587;
                    $tempHost = null;
                    break;
                case 'no-config':
                    $useAuth = false;
                    $configs['user'] = null;
                    $configs['pass'] = null;
                    $configs['host'] = null;
                    $configs['secure'] = null;
                    $configs['port'] = null;
                    break;
            }
        }

        $headers = URL::buildHttpHeaderArray($headers);
        $priority = empty($priority) || $priority < 0 ? 0 : $priority;
        $wrap = empty($wrap) || $wrap < 0 ? 0 : $wrap;

        // $body is the DEFAULT body, not a fallback that only exists under Laravel: rendering a
        // view is a pure enhancement layered on top. Gating the whole resolution on
        // class_exists(View::class) left $template null for every non-Laravel consumer of this
        // library, which sent the recipient an empty message and still returned TRUE.
        if (
            !empty($template) &&
            class_exists(\Illuminate\Support\Facades\View::class) &&
            \Illuminate\Support\Facades\View::exists($template)
        ) {
            $template = \Illuminate\Support\Facades\View::make($template, [
                'lang'      => $lang,
                'debugMode' => $debugMode,
                'sendTo'    => $sendTo,
                'subject'   => $subject,
                'body'      => $body,
            ])->render();
        } else {
            $template = $body;
        }

        $params = compact(
            'useEmbeddedImages', 'stringFiles', 'useConfig', 'useAuth', 'isSMTP', 'lang', 'confirm', 'wrap',
            'headers', 'priority', 'charset', 'cc', 'cco', 'reply', 'template', 'files', 'debugMode'
        );

        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $ret = false;
        try {
            if($params['isSMTP']) {
                $mail->SMTPDebug  = $params['debugMode'] ? 2 : FALSE;
                $mail->Mailer     = 'smtp';

                // WHERE we deliver is independent of WHETHER we authenticate. Gating these on
                // $useAuth meant an unauthenticated relay silently fell back to PHPMailer's own
                // defaults (localhost:25, no encryption) while the caller's explicitly supplied —
                // and guard-enforced — host/port/secure were dropped on the floor.
                if($configs['secure'] !== null) $mail->SMTPSecure = $configs['secure'];
                if($configs['host'] !== null)   $mail->Host       = $configs['host'];
                if($configs['port'] !== null)   $mail->Port       = $configs['port'];

                $mail->SMTPAuth   = $params['useAuth'];
                if($params['useAuth']) {
                    if($configs['user'] !== null)   $mail->Username   = $configs['user'];
                    if($configs['pass'] !== null)   $mail->Password   = $configs['pass'];
                }
            }
            foreach ($params['headers'] AS $header) {
                $mail->addCustomHeader($header);
            }
            if(!empty($params['wrap'])) {
                $mail->WordWrap = $params['wrap'];
            }
            if(!empty($params['priority'])) {
                switch ((int) $params['priority']) {
                    case 1:
                        $mail->Priority = 1;
                        break;
                    case 2:
                        $mail->Priority = 3;
                        break;
                    case 3:
                        $mail->Priority = 5;
                        break;
                }
            }
            if(!empty($params['lang'])) {
                $mail->setLanguage($params['lang']);
            }
            if(!empty($params['confirm'])) {
                $mail->ConfirmReadingTo = $configs['email'];
                $mail->AddCustomHeader("X-pmrqc: 1");
                $mail->AddCustomHeader("X-Confirm-Reading-To", $configs['email']);
                $mail->AddCustomHeader("Return-receipt-to", $configs['email']);
                $mail->AddCustomHeader("Disposition-Notification-To", ("<" . $configs['email'] . ">"));
            }

            // Display names are OPTIONAL by design: they are cosmetic, so a missing one degrades to
            // "" (PHPMailer's own default) instead of raising an undefined-key warning that a strict
            // error handler would rethrow — killing an otherwise valid send.
            $mail->setFrom($configs['email'], $configs['name'] ?? '');
            foreach($sendTo AS $to){
                $mail->addAddress($to['email'], $to['name'] ?? '');
            }
            foreach($params['cc'] AS $to){
                $mail->addCC($to['email'], $to['name'] ?? '');
            }
            foreach($params['cco'] AS $to){
                $mail->addBCC($to['email'], $to['name'] ?? '');
            }
            foreach($params['reply'] AS $to){
                $mail->addReplyTo($to['email'], $to['name'] ?? '');
            }
            foreach ($params['files'] AS $file){
                $mail->addAttachment($file['file'], $file['name'] ?? '');
            }
            foreach ($params['stringFiles'] AS $file){
                $mail->addStringAttachment($file['string'], $file['name'] ?? '');
            }

            $mail->CharSet = $params['charset'];

            $mail->isHTML(true);
            $mail->Subject = $subject;

            $mail->Body = $params['template'];
            if($params['useEmbeddedImages']){
                // The quantifier is LAZY: a greedy `[\w\W]{0,}` swallowed everything from the first
                // `<img` to the last `>`, so a two-image body collapsed into a single match and the
                // text between the images was silently deleted from the message.
                $imgRegex = '/<img([\w\W]*?)\/{0,1}>/i';

                $mail->Body = "";
                preg_match_all($imgRegex, $params['template'], $imgsBody);
                // Split into a LOCAL var: $params['template'] must stay a string for AltBody below.
                $bodyParts = preg_split($imgRegex, $params['template']);

                foreach ($bodyParts AS $keyPart => $partString){
                    $mail->Body .= $partString;

                    // $imgsBody[0] is the list of whole <img> matches; $imgsBody[1] is capture
                    // group 1. Indexing the OUTER array by the part number handed an ARRAY to
                    // getImageSrc(string) — an uncatchable-here TypeError on any body with an
                    // image. preg_split also yields one more part than there are tags, so the last
                    // part legitimately has no image after it.
                    if(!isset($imgsBody[0][$keyPart])) {
                        continue;
                    }

                    $imgTag = $imgsBody[0][$keyPart];
                    $cid    = "attach-" . $keyPart;
                    $src    = self::getImageSrc($imgTag);

                    $embedded = false;
                    if($src !== '') {
                        try {
                            $mail->addEmbeddedImage($src, $cid, $cid);
                            $embedded = true;
                        } catch (\PHPMailer\PHPMailer\Exception $e) {
                            // Not a readable local file (remote URL, data: URI, bad path).
                            $embedded = false;
                        }
                    }

                    // Point the tag at the attachment we just made, or — when it could not be
                    // embedded — put the caller's own tag back. Dropping it would silently strip
                    // the image out of the body; rethrowing would fail an otherwise valid send.
                    $mail->Body .= $embedded ? ('<img src="cid:' . $cid . '">') : $imgTag;
                }
            }

            $mail->AltBody = strip_tags($mail->Body);

            $ret = $mail->send();

            $mail->clearAllRecipients();
            $mail->clearAttachments();
            $mail->clearCustomHeaders();
            $mail->clearReplyTos();
        } catch (\PHPMailer\PHPMailer\Exception $e) {
        }

        return $ret;
    }

    /**
     * Validates an email address.
     *
     * @param string|null $email The email address to validate
     * @return bool TRUE if the email is valid, FALSE otherwise
     */
    public static function validateMail(?string $email): bool {
        if (empty($email)) return false;

        $isValid = preg_match(
            '/^(?:[\w\!\#\$\%\&\'\*\+\-\/\=\?\^\`\{\|\}\~]+\.)*[\w\!\#\$\%\&\'\*\+\-\/\=\?\^\`\{\|\}\~]+@(?:(?:(?:[a-zA-Z0-9_](?:[a-zA-Z0-9_\-](?!\.)){0,61}[a-zA-Z0-9_-]?\.)+[a-zA-Z0-9_](?:[a-zA-Z0-9_\-](?!$)){0,61}[a-zA-Z0-9_]?)|(?:\[(?:(?:[01]?\d{1,2}|2[0-4]\d|25[0-5])\.){3}(?:[01]?\d{1,2}|2[0-4]\d|25[0-5])\]))$/',
            $email
        );

        // Returns true if regex matched successfully
        if ($isValid !== 0 && $isValid !== false) return true;

        return false;
    }

    /**
     * Retrieves the `src` attribute from an HTML <img> tag.
     *
     * Never throws: malformed HTML, markup with no <img>, and an empty/blank string all return ''.
     * If $imgTag holds more than one <img>, the FIRST one's src is returned.
     *
     * The src is returned RAW — it is not validated, resolved, or sanitised, and it is not checked
     * against any scheme or path allow-list. Whatever the HTML said is what you get.
     *
     * @param string $imgTag HTML <img> tag to extract the src from
     * @return string The value of the src attribute, or an empty string if not found
     */
    public static function getImageSrc(string $imgTag): string {
        // DOMDocument::loadHTML() raises a ValueError on an empty string, and `@` does NOT suppress
        // a ValueError — it is an exception, not a diagnostic. Returning '' here is what the
        // documented total-string contract above already promises for "no src found".
        if (trim($imgTag) === '') {
            return '';
        }

        $document = new \DOMDocument();
        @$document->loadHTML($imgTag); // suppress warnings for malformed HTML

        $xpath = new \DOMXPath($document);
        return $xpath->evaluate("string(//img/@src)");
    }
}