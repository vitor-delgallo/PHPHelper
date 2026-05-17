# Mailer Helper

Class: `VD\PHPHelper\Mailer`
Source file: `src/Mailer.php`

Use for email sending through PHPMailer and simple email validation.

## Methods

| Method | Use |
| --- | --- |
| `sendMail(array $configs, array $sendTo, string $subject, string $body, ?string $useConfig = null, ?string $lang = null, array $files = array(), array $stringFiles = array(), int $priority = 0, int $wrap = 0, array $cc = array(), array $cco = array(), array $reply = array(), array $headers = array(), ?string $template = null, bool $confirm = false, bool $useEmbeddedImages = false, string $charset = 'UTF-8', bool $useAuth = true, bool $isSMTP = true, bool $debugMode = false)` | Sends email with SMTP configs, attachments, CC, BCC, reply-to, headers, template, priority, and embedded images. |
| `validateMail(?string $email)` | Validates an email address. |
| `getImageSrc(string $imgTag)` | Extracts `src` from an image tag. |

## Cautions

- `sendMail` depends on `phpmailer/phpmailer`; do not install it without user's permission.
- Do not log SMTP users, passwords, tokens, or sensitive content.
- Validate and sanitize user data before building subject, body, attachments, and headers.
- If the project has its own response/queue helper, integrate carefully instead of sending directly from a view.
