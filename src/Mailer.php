<?php

namespace VD\PHPHelper;

class Mailer {
    /**
     * Sends an email using PHPMailer.
     *
     * @param array $configs Configuration for email sender (e.g., host, user, pass, etc.)
     * @param array $sendTo List of recipients with 'name' and 'email'
     * @param string $subject Email subject
     * @param string $body Email body content
     *
     * Optional parameters:
     * @param string|null $useConfig Shortcut config for common providers ('gmail', 'yahoo', etc.)
     * @param string|null $lang Language setting for the email
     * @param array $files File attachments ['name', 'file']
     * @param array $stringFiles Array of string-based attachments ['name', 'string']
     * @param int $priority Email priority (1: High, 2: Medium, 3: Low)
     * @param int $wrap Max characters per line
     * @param array $cc List of CC recipients
     * @param array $cco List of BCC recipients
     * @param array $reply List of reply-to addresses
     * @param array $headers Custom headers
     * @param string|null $template Template name or raw HTML body
     * @param bool $confirm Request read confirmation
     * @param bool $useEmbeddedImages Whether to embed images in the email body
     * @param string $charset Charset (default: UTF-8)
     * @param bool $useAuth Whether to use password authentication
     * @param bool $isSMTP Whether to use SMTP
     * @param bool $debugMode Enable debug output
     *
     * @return bool TRUE on success, FALSE on failure
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
            empty($configs['nome']) ||
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

            $mail->setFrom($configs['email'], $configs['nome']);
            foreach($sendTo AS $to){
                $mail->addAddress($to['email'], $to['nome']);
            }
            foreach($params['cc'] AS $to){
                $mail->addCC($to['email'], $to['nome']);
            }
            foreach($params['cco'] AS $to){
                $mail->addBCC($to['email'], $to['nome']);
            }
            foreach($params['reply'] AS $to){
                $mail->addReplyTo($to['email'], $to['nome']);
            }
            foreach ($params['files'] AS $file){
                $mail->addAttachment($file['file'], $file['nome']);
            }
            foreach ($params['stringFiles'] AS $file){
                $mail->addStringAttachment($file['string'], $file['nome']);
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