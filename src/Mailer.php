<?php

namespace VD\PHPHelper;

class Mailer {
    /**
     * Sends an email using PHPMailer.
     *
     * @param array $configs Sender configuration. REQUIRED keys: 'email' (the From address; 'user'
     *                       is used as a fallback when it is itself an address), 'name' (the From
     *                       display name), 'pass', and — unless $useConfig is set — 'host', 'port'
     *                       and 'secure'. A missing required key makes this function return FALSE
     *                       without sending, throwing, or logging.
     * @param array $sendTo List of recipients, each ['email' => string (required), 'name' => string
     *                      (optional, the display name; defaults to '')]
     * @param string $subject Email subject
     * @param string $body Email body content
     *
     * Optional parameters:
     * @param string|null $useConfig Shortcut config for common providers ('gmail', 'yahoo', etc.)
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
     * @param string|null $template Template name or raw HTML body
     * @param bool $confirm Request read confirmation
     * @param bool $useEmbeddedImages Whether to embed images in the email body
     * @param string $charset Charset (default: UTF-8)
     * @param bool $useAuth Whether to use password authentication
     * @param bool $isSMTP Whether to use SMTP
     * @param bool $debugMode Enable debug output
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

        if (class_exists(\Illuminate\Support\Facades\View::class)) {
            if (empty($template) || !\Illuminate\Support\Facades\View::exists($template)) {
                $template = $body;
            } else {
                $template = \Illuminate\Support\Facades\View::make($template, [
                    'lang'      => $lang,
                    'debugMode' => $debugMode,
                    'sendTo'    => $sendTo,
                    'subject'   => $subject,
                    'body'      => $body,
                ])->render();
            }
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

                $mail->SMTPAuth   = $params['useAuth'];
                if($params['useAuth']) {
                    if($configs['secure'] !== null) $mail->SMTPSecure = $configs['secure'];
                    if($configs['host'] !== null)   $mail->Host       = $configs['host'];
                    if($configs['user'] !== null)   $mail->Username   = $configs['user'];
                    if($configs['pass'] !== null)   $mail->Password   = $configs['pass'];
                    if($configs['port'] !== null)   $mail->Port       = $configs['port'];
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
                $mail->Body = "";
                preg_match_all('/<img([\w\W]{0,})\/{0,1}>/i', $params['template'], $imgsBody);
                $params['template'] = preg_split('/<img([\w\W]{0,})\/{0,1}>/i', $params['template']);
                foreach ($params['template'] AS $keyPart => $partString){
                    $mail->Body .= $partString;
                    if(!empty($imgsBody[$keyPart])) {
                        $mail->addEmbeddedImage(self::getImageSRC($imgsBody[$keyPart]), ("attach-" . $keyPart), ("attach-" . $keyPart));
                    }
                }
            }

            $mail->AltBody = strip_tags($params['template']);

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
     * @param string $imgTag HTML <img> tag to extract the src from
     * @return string The value of the src attribute, or an empty string if not found
     */
    public static function getImageSrc(string $imgTag): string {
        $document = new \DOMDocument();
        @$document->loadHTML($imgTag); // suppress warnings for malformed HTML

        $xpath = new \DOMXPath($document);
        return $xpath->evaluate("string(//img/@src)");
    }
}