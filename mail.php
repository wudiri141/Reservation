<?php

declare(strict_types=1);

require_once __DIR__ . '/phpmailer/src/Exception.php';
require_once __DIR__ . '/phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/phpmailer/src/SMTP.php';

use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;

function build_email_template(string $heading, string $intro, array $details, string $footerNote = ''): string
{
    $headingEscaped = htmlspecialchars($heading, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $introEscaped = htmlspecialchars($intro, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $rows = '';

    foreach ($details as $label => $value) {
        $rows .= sprintf(
            '<tr><td style="padding:12px 0;color:#6b7280;font-size:14px;vertical-align:top;width:150px;">%s</td><td style="padding:12px 0;color:#111827;font-size:14px;vertical-align:top;font-weight:600;">%s</td></tr>',
            htmlspecialchars((string) $label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        );
    }

    $footerHtml = $footerNote !== ''
        ? '<p style="margin:0;color:#6b7280;font-size:13px;line-height:1.6;">' . htmlspecialchars($footerNote, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>'
        : '';

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$heading}</title>
  </head>
  <body style="margin:0;padding:0;background:#f5f5f5;font-family:Arial,Helvetica,sans-serif;color:#111827;">
    <div style="max-width:640px;margin:0 auto;padding:32px 16px;">
      <div style="background:#ffffff;border:1px solid #e5e7eb;border-radius:20px;overflow:hidden;box-shadow:0 16px 40px rgba(15,23,42,.08);">
        <div style="padding:24px 28px;background:linear-gradient(135deg,#111827 0%,#1f2937 100%);color:#ffffff;">
          <div style="font-size:12px;letter-spacing:0.16em;text-transform:uppercase;opacity:.8;margin-bottom:8px;">THE SPACE</div>
          <h1 style="margin:0;font-size:28px;line-height:1.2;">{$headingEscaped}</h1>
        </div>
        <div style="padding:28px;">
          <p style="margin:0 0 20px;color:#374151;font-size:15px;line-height:1.7;">{$introEscaped}</p>
          <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin-bottom:24px;">
            {$rows}
          </table>
          {$footerHtml}
        </div>
        <div style="padding:16px 28px;background:#fafafa;border-top:1px solid #e5e7eb;color:#9ca3af;font-size:12px;line-height:1.6;">
          This is an automated email from THE SPACE reservation system.
        </div>
      </div>
    </div>
  </body>
</html>
HTML;
}

function send_email(string $to, string $subject, string $htmlBody): bool
{
    $smtp = app_config()['smtp'];

    $mail = new PHPMailer(true);

    try {
        $mail->isSMTP();
        $mail->Host = $smtp['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $smtp['username'];
        $mail->Password = $smtp['password'];
        $mail->SMTPSecure = $smtp['secure'];
        $mail->Port = (int) $smtp['port'];
        $mail->CharSet = 'UTF-8';
        $mail->Timeout = 30;

        if (!empty($smtp['debug'])) {
            $mail->SMTPDebug = SMTP::DEBUG_SERVER;
        }

        $mail->setFrom($smtp['from_email'], $smtp['from_name']);
        $mail->addAddress($to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;
        $mail->AltBody = trim(html_entity_decode(strip_tags(preg_replace('/<\s*br\s*\/?>/i', "\n", $htmlBody)), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

        return $mail->send();
    } catch (Exception $e) {
        error_log('Mail error: ' . $e->getMessage());
        return false;
    }
}
