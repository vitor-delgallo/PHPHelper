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
     *                       'secure' must be EXACTLY one of three values (case-insensitive, outer
     *                       whitespace trimmed): 'tls' negotiates STARTTLS
     *                       (PHPMailer::ENCRYPTION_STARTTLS), 'ssl' opens an implicit-TLS
     *                       connection (PHPMailer::ENCRYPTION_SMTPS), and 'none' is the explicit,
     *                       deliberate opt-out that permits 'user'/'pass' to travel in cleartext.
     *                       ANYTHING else — a typo ('tsl'), an empty string, a non-string — returns
     *                       FALSE without sending. Cleartext is NOT reachable by accident: it costs
     *                       you the exact word 'none'.
     *                       Note that 'none' means "do not REQUIRE encryption", not "refuse it":
     *                       PHPMailer's SMTPAutoTLS still upgrades to STARTTLS opportunistically
     *                       when the server advertises it.
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
     *                    A view that EXISTS but fails to render is NOT one of those cases: it
     *                    returns FALSE rather than quietly mailing $body in the template's place.
     * @param bool $confirm Request read confirmation
     * @param bool $useEmbeddedImages When TRUE, every <img> in the resolved body whose src resolves
     *                    to a readable file INSIDE $embeddedImagesBaseDir is attached to the message
     *                    and its tag rewritten to <img src="cid:attach-N">. EVERY other <img> — an
     *                    empty src, any URI scheme (http:, https:, data:, file:, phar:, …), an
     *                    unreadable or non-existent path, or a path that resolves OUTSIDE the base
     *                    directory — is left in the body EXACTLY as the caller wrote it: nothing is
     *                    read, nothing is attached, and the send does not fail.
     *                    Tag detection understands quoted attributes, so a '>' inside src="…"/alt="…"
     *                    does not truncate the tag. If the scan itself fails (a PCRE limit), NOTHING
     *                    is embedded and the body is sent exactly as resolved — never emptied.
     *                    Requires $embeddedImagesBaseDir; see there.
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
     * @param string|null $embeddedImagesBaseDir The ONLY directory an embedded <img> src is allowed
     *                    to resolve to. REQUIRED when $useEmbeddedImages is TRUE: if it is null,
     *                    blank, or not an existing directory, the send returns FALSE without
     *                    sending, because "attach whatever the body points at" is not an offer this
     *                    function makes. Containment is enforced AFTER realpath(), so neither ../
     *                    nor a symlink can escape the allow-list — a src is only ever read when the
     *                    file it truly resolves to lies under this directory. Ignored entirely when
     *                    $useEmbeddedImages is FALSE.
     *                    It sits LAST, away from the $useEmbeddedImages it belongs to, on purpose: a
     *                    new parameter gets APPENDED. Slotting it next to its partner would have
     *                    shifted $charset/$useAuth/$isSMTP/$debugMode one place right for every
     *                    positional caller — a silent miscompile of working code, which is too high
     *                    a price for reading nicely in the signature.
     *
     * @return bool TRUE on success. FALSE on a missing/invalid $configs key (including a 'secure'
     *              outside {'tls','ssl','none'}), on $useEmbeddedImages without a usable
     *              $embeddedImagesBaseDir, on an empty $sendTo / $subject / $body, on a $template
     *              that fails to render, and on any PHPMailer failure — those are caught and
     *              swallowed here, so a FALSE carries no reason, no exception and no log line.
     *              CAUTION: outside the $template render (which is total: any Throwable from Blade
     *              becomes FALSE), only \PHPMailer\PHPMailer\Exception is caught. Any OTHER
     *              Throwable raised while the message is built PROPAGATES to the caller — including
     *              the ErrorException that a strict error handler (Laravel's, for one) makes out of
     *              a PHP warning. So this function can both return FALSE and throw.
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
        bool $debugMode = false,
        ?string $embeddedImagesBaseDir = null
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

        $isShortcut = !empty($useConfig) && in_array($useConfig, ['no-config', 'gmail', 'office365', 'yahoo', 'hotmail'], true);

        // A shortcut overwrites 'secure' itself further down, so it is the caller-supplied transport
        // — and ONLY that — whose encryption has to survive this. Validating against the exact
        // accepted set is the whole point: the old guard rejected an EMPTY 'secure' but waved
        // through every other string as "no encryption", which made a one-letter typo ('tsl') a
        // silent downgrade that put 'user'/'pass' on the wire in the clear. Cleartext now costs the
        // caller the explicit word 'none'.
        if (!$isShortcut) {
            $secure = self::normaliseSmtpSecure($configs['secure'] ?? null);
            if ($secure === null) {
                return false;
            }
            $configs['secure'] = $secure;
        }

        // Embedding reads files off the local disk and mails them OUT, so it is deny-by-default:
        // without an allow-listed base directory there is no set of files this may touch, and
        // "embed anything the body points at" is not on offer. Resolved ONCE here so the per-<img>
        // containment check compares two already-canonical paths.
        $imagesBaseDir = null;
        if ($useEmbeddedImages) {
            $resolvedBase = ($embeddedImagesBaseDir !== null && trim($embeddedImagesBaseDir) !== '' && !str_contains($embeddedImagesBaseDir, "\0"))
                ? realpath($embeddedImagesBaseDir)
                : false;
            if ($resolvedBase === false || !is_dir($resolvedBase)) {
                return false;
            }
            $imagesBaseDir = $resolvedBase;
        }

        if (
            empty($configs) ||
            (!$isShortcut && (empty($configs['host']) || empty($configs['port']))) ||
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
        if (!empty($template) && class_exists(\Illuminate\Support\Facades\View::class)) {
            // Resolving a view runs the CALLER'S Blade: a compile error, a missing @include or an
            // exception thrown inside the template is a failed send, not this function's problem to
            // rethrow. It sat outside every try, so it escaped a signature that promises bool.
            // Catching \Throwable (not just PHPMailer's Exception) is what makes that promise true —
            // a Blade error is an ErrorException/ViewException, which the PHPMailer-only catch below
            // would sail straight past.
            // FALSE rather than a silent fall back to $body: the caller asked for a specific
            // template, and quietly mailing something else in its place is a worse answer than
            // reporting the failure.
            try {
                $rendered = \Illuminate\Support\Facades\View::exists($template)
                    ? \Illuminate\Support\Facades\View::make($template, [
                        'lang'      => $lang,
                        'debugMode' => $debugMode,
                        'sendTo'    => $sendTo,
                        'subject'   => $subject,
                        'body'      => $body,
                    ])->render()
                    : null;
            } catch (\Throwable $e) {
                return false;
            }

            $template = $rendered ?? $body;
        } else {
            $template = $body;
        }

        $params = compact(
            'useEmbeddedImages', 'imagesBaseDir', 'stringFiles', 'useConfig', 'useAuth', 'isSMTP', 'lang', 'confirm',
            'wrap', 'headers', 'priority', 'charset', 'cc', 'cco', 'reply', 'template', 'files', 'debugMode'
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
                // Each alternative is mutually exclusive on its first character, so at any position
                // exactly one can match: the loop is deterministic and the possessive `*+` forbids
                // it from ever giving anything back. That is what makes this LINEAR.
                //
                // Both of its predecessors shipped a broken body, in opposite directions: greedy
                // `[\w\W]{0,}` ran from the first `<img` to the LAST `>`, eating the text between
                // two images; the lazy `[\w\W]*?` that replaced it expanded one character at a time,
                // and each step costs a backtrack — so a single ~500KB inline base64 logo exhausted
                // pcre.backtrack_limit (1M), both preg_ calls below returned FALSE, and the message
                // went out EMPTY.
                //
                // Recognising `"…"`/`'…'` as units is also what keeps a '>' inside an attribute
                // (alt="a > b") from truncating the tag and leaking the remainder into the body.
                $imgRegex = '/<img(?:[^>"\']|"[^"]*"|\'[^\']*\')*+>/i';

                // Split into a LOCAL var: $params['template'] must stay a string for AltBody below.
                $imgsBody  = [];
                $matched   = preg_match_all($imgRegex, $params['template'], $imgsBody);
                $bodyParts = $matched === false ? false : preg_split($imgRegex, $params['template']);

                // A preg_ function reports failure by RETURNING FALSE, quietly. Unchecked, that
                // false reached `foreach (false)` while Body had already been blanked — the empty
                // email above. Body is therefore blanked only once the scan is known to have
                // worked, and a failed scan simply embeds nothing: the resolved body still ships
                // intact, with its <img> tags exactly as the caller wrote them. That is the same
                // outcome this function already documents for every image it cannot embed.
                if($matched !== false && $bodyParts !== false){
                    $mail->Body = "";
                    foreach ($bodyParts AS $keyPart => $partString){
                        $mail->Body .= $partString;

                        // $imgsBody[0] is the list of whole <img> matches, and the regex captures
                        // nothing else. Indexing the OUTER array by the part number handed an ARRAY
                        // to getImageSrc(string) — an uncatchable-here TypeError on any body with an
                        // image. preg_split also yields one more part than there are tags, so the
                        // last part legitimately has no image after it.
                        if(!isset($imgsBody[0][$keyPart])) {
                            continue;
                        }

                        $imgTag = $imgsBody[0][$keyPart];
                        $cid    = "attach-" . $keyPart;

                        // The src decides only WHETHER a file we already vetted gets attached — it
                        // is never itself handed to the mailer. Passing the raw src to
                        // addEmbeddedImage() made every <img> an arbitrary-file-read primitive that
                        // mails the file OUT: <img src="/etc/passwd"> in an attacker-influenced body
                        // attached /etc/passwd to the outgoing message. Only a path that truly
                        // resolves inside the caller's allow-listed base directory survives
                        // resolveEmbeddableImage().
                        $path = self::resolveEmbeddableImage(self::getImageSrc($imgTag), $params['imagesBaseDir']);

                        $embedded = false;
                        if($path !== null) {
                            try {
                                $mail->addEmbeddedImage($path, $cid, $cid);
                                $embedded = true;
                            } catch (\PHPMailer\PHPMailer\Exception $e) {
                                $embedded = false;
                            }
                        }

                        // Point the tag at the attachment we just made, or — when it could not be
                        // embedded — put the caller's own tag back untouched, leaving it a plain
                        // reference the mail client may or may not fetch. Dropping it would silently
                        // strip the image out of the body; rethrowing would fail an otherwise valid
                        // send.
                        $mail->Body .= $embedded ? ('<img src="cid:' . $cid . '">') : $imgTag;
                    }
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
     * Normalises $configs['secure'] to the exact value PHPMailer's SMTPSecure accepts.
     *
     * The accepted set is CLOSED and matching is case-insensitive with the outer whitespace
     * trimmed: 'tls' (ENCRYPTION_STARTTLS), 'ssl' (ENCRYPTION_SMTPS), and 'none' — the explicit
     * cleartext opt-out, which maps to '' (PHPMailer's own "no required encryption" value).
     * Anything else, including a non-string and the empty string, is NULL: a typo must fail the
     * send rather than quietly downgrade the connection and leak the credentials.
     *
     * @param mixed $secure The raw $configs['secure'], entirely unvalidated
     * @return string|null The value for SMTPSecure, or NULL when $secure is not an accepted keyword
     */
    private static function normaliseSmtpSecure(mixed $secure): ?string {
        if (!is_string($secure)) {
            return null;
        }

        return match (Str::strToLower(trim($secure))) {
            \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS,
            \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS    => \PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_SMTPS,
            'none'                                              => '',
            default                                             => null,
        };
    }

    /**
     * Resolves an <img> src to a real, readable file INSIDE $baseDir — or NULL when it must not be
     * attached to an outgoing message.
     *
     * This is the allow-list that keeps $useEmbeddedImages from being an arbitrary file read. It
     * returns NULL — never a path — for a blank src, any URI scheme (http:, https:, data:, file:,
     * phar:, …), a path that does not resolve to a readable file, and, above all, a file that
     * resolves OUTSIDE $baseDir. Containment is verified only AFTER realpath() has collapsed every
     * ../ and followed every symlink, so no amount of traversal in the src can escape: it is the
     * FINAL location of the file that must satisfy the prefix, not the string the caller wrote.
     * The comparison is case-insensitive on Windows, matching that filesystem's own semantics.
     *
     * @param string $src The raw src exactly as getImageSrc() returned it — assumed hostile
     * @param string $baseDir An already realpath()'d, existing directory: the ONLY place an src may
     *                        resolve to. Passing an unresolved path here would defeat the check.
     * @return string|null The resolved absolute path, safe to attach; or NULL when $src is not
     *                     embeddable, in which case NOTHING about the file may be read or sent
     */
    private static function resolveEmbeddableImage(string $src, string $baseDir): ?string {
        $src = trim($src);
        // A NUL byte makes the path functions below throw a ValueError, which would escape past the
        // PHPMailer-only catch in sendMail().
        if ($src === '' || str_contains($src, "\0")) {
            return null;
        }

        // A URI scheme is not a path, and this is what keeps "data:"/"http:"/"phar:" out of
        // realpath(). Requiring 2+ characters before the colon is deliberate: it keeps a Windows
        // drive letter ("C:/img/logo.png") a PATH rather than a bogus "c:" scheme.
        if (preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]{1,}:#', $src) === 1) {
            return null;
        }

        $candidate = self::isAbsolutePath($src) ? $src : $baseDir . DIRECTORY_SEPARATOR . $src;
        $real      = realpath($candidate);
        if ($real === false || !is_file($real) || !is_readable($real)) {
            return null;
        }

        // Compare against the base WITH a trailing separator, so a sibling directory whose name
        // merely starts with the base ("/srv/assets-secret" vs "/srv/assets") cannot pass.
        $prefix = rtrim($baseDir, '\\/') . DIRECTORY_SEPARATOR;
        $inside = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($real, $prefix, strlen($prefix)) === 0
            : strncmp($real, $prefix, strlen($prefix)) === 0;

        return $inside ? $real : null;
    }

    /**
     * Tells whether $path is absolute, on either POSIX or Windows.
     *
     * TRUE for a leading '/' or '\' (including a UNC '\\server\share') and for a drive-letter root
     * such as 'C:\x' or 'C:/x'. A bare 'C:x' is NOT absolute — it is drive-relative.
     *
     * @param string $path The path to classify
     * @return bool TRUE when $path is absolute
     */
    private static function isAbsolutePath(string $path): bool {
        if ($path === '') {
            return false;
        }
        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        return preg_match('#^[a-zA-Z]:[\\\\/]#', $path) === 1;
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
     * $imgTag is parsed as UTF-8, which is this library's charset everywhere else and the only
     * encoding sendMail() ever hands it. HTML entities ARE decoded (&amp; comes back as &), because
     * that is what the markup MEANT; nothing else is transformed.
     *
     * The src is returned RAW — it is not validated, resolved, or sanitised, and it is not checked
     * against any scheme or path allow-list. Whatever the HTML said is what you get, so treat the
     * result as hostile: sendMail()'s $useEmbeddedImages does its own allow-list check before a src
     * is ever allowed to name a file, and does not trust this value.
     *
     * @param string $imgTag HTML <img> tag to extract the src from, encoded as UTF-8
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
        // libxml's HTML parser assumes ISO-8859-1 when the markup declares nothing, so every
        // non-ASCII src came back mojibake'd ("ação.png" -> "aÃ§Ã£o.png") and could never match the
        // file on disk. The meta charset is the in-band way to tell it otherwise; it is stripped
        // from consideration by the //img XPath below, so it cannot affect the result.
        // LIBXML_NONET is belt-and-braces: this parses hostile markup, and it must never be able to
        // reach the network to resolve anything.
        @$document->loadHTML(
            '<meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . $imgTag,
            LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING
        );

        $xpath = new \DOMXPath($document);
        return $xpath->evaluate("string(//img/@src)");
    }
}