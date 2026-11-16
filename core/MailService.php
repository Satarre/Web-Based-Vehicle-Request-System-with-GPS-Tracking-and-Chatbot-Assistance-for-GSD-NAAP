<?php
declare(strict_types=1);
/**
 * core/MailService.php — SMTP mailer built on PHPMailer.
 * Credentials come from app_settings (MIS settings page) with environment
 * overrides (ENV_SMTP_*). Nothing is hardcoded here; secrets never logged.
 * Returns false when mail is disabled/unconfigured or delivery fails.
 */

class MailService
{
    /** Last delivery error (for admin diagnostics only; never contains the password). */
    private static string $lastError = '';

    /** True when an SMTP host is configured (setting or env). */
    public static function enabled(PDO $pdo): bool
    {
        return self::cfg($pdo, 'host') !== '';
    }

    /** True when any ENV_SMTP_* constant is defined (env overrides the DB). */
    public static function envOverride(): bool
    {
        foreach (['HOST', 'PORT', 'SECURE', 'USER', 'PASS', 'FROM', 'FROM_NAME'] as $k) {
            if (defined('ENV_SMTP_' . $k)) {
                return true;
            }
        }
        return false;
    }

    /** Human-readable reason the last send failed, or '' when it succeeded. */
    public static function lastError(): string
    {
        return self::$lastError;
    }

    /** Resolve one SMTP setting: env override wins over app_settings. */
    private static function cfg(PDO $pdo, string $key): string
    {
        $envConst = 'ENV_SMTP_' . strtoupper($key);
        if (defined($envConst)) {
            return (string) constant($envConst);
        }
        $settingKey = 'SMTP_' . strtoupper($key);
        return (string) Settings::get($settingKey, '');
    }

    /**
     * Branded HTML email shell. Inline styles only — email clients ignore
     * stylesheets. Includes system name, message, and a security footer.
     * Text-only header: an embedded logo shows as an attachment in some clients.
     */
    public static function template(string $heading, string $messageHtml): array
    {
        $html = <<<HTML
<div style="font-family:'Segoe UI',Arial,Helvetica,sans-serif;background:#f1f5f9;margin:0;padding:24px 12px;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;margin:0 auto;">
    <tr>
      <td style="background:#0f2a43;border-radius:10px 10px 0 0;padding:20px 28px;">
        <p style="margin:0;color:#ffffff;font-size:15px;font-weight:700;">NAAP GSD Digital Trip Ticket System</p>
        <p style="margin:6px 0 0;color:#94a3b8;font-size:12px;">General Services Department</p>
      </td>
    </tr>
    <tr>
      <td style="background:#ffffff;border:1px solid #e2e8f0;border-top:0;padding:28px;color:#0f172a;">
        <h2 style="margin:0 0 16px;font-size:19px;color:#0f2a43;">{$heading}</h2>
        {$messageHtml}
      </td>
    </tr>
    <tr>
      <td style="background:#f8fafc;border:1px solid #e2e8f0;border-top:0;border-radius:0 0 10px 10px;padding:16px 28px;color:#64748b;font-size:11.5px;line-height:1.6;">
        This is an automated security message from the NAAP GSD Digital Trip Ticket System.<br>
        If you did not request this, contact the system administrator immediately.<br>
        Please do not share codes or passwords with anyone. Our staff will never ask for them.
      </td>
    </tr>
  </table>
</div>
HTML;

        $text = strip_tags(str_replace(['<br>', '</p>', '</tr>', '</td>', '</h2>'], ["\n", "\n", "\n", "\n", "\n\n"], $html));
        $text = html_entity_decode($text, ENT_QUOTES, 'UTF-8');
        return [$html, $text];
    }

    /**
     * Send one HTML email with plain-text fallback. Never throws.
     * Returns true only when the message was accepted by the SMTP server.
     */
    public static function send(PDO $pdo, string $toEmail, string $toName, string $subject, string $html, string $text): bool
    {
        self::$lastError = '';
        if (!self::enabled($pdo)) {
            self::$lastError = 'SMTP is not configured.';
            return false;
        }
        $host = self::cfg($pdo, 'host');
        $user = self::cfg($pdo, 'user');
        $pass = self::cfg($pdo, 'pass');
        $from = self::cfg($pdo, 'from') !== '' ? self::cfg($pdo, 'from') : $user;
        $fromName = self::cfg($pdo, 'from_name');
        if ($fromName === '') {
            $fromName = 'NAAP GSD System';
        }
        $port = (int) self::cfg($pdo, 'port');
        if ($port < 1) {
            $port = 587;
        }

        try {
            $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
            $mail->isSMTP();
            $mail->Host = $host;
            $mail->Port = $port;
            // PHPMailer's default is 300s — a wrong host must not hang the page.
            $mail->Timeout = 10;
            $secure = strtolower(self::cfg($pdo, 'secure'));
            $mail->SMTPSecure = in_array($secure, ['ssl', 'tls'], true) ? $secure : '';
            $mail->SMTPAuth = ($user !== '');
            $mail->Username = $user;
            $mail->Password = $pass;
            $mail->CharSet = 'UTF-8';
            $mail->setFrom($from !== '' ? $from : $user, $fromName);
            $mail->addAddress($toEmail, $toName);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = $text;
            $ok = $mail->send();
            if (!$ok) {
                self::$lastError = $mail->ErrorInfo !== '' ? $mail->ErrorInfo : 'SMTP server rejected the message.';
            }
            return $ok;
        } catch (\Throwable $e) {
            $info = ($mail ?? null) instanceof \PHPMailer\PHPMailer\PHPMailer && $mail->ErrorInfo !== ''
                ? $mail->ErrorInfo
                : $e->getMessage();
            self::$lastError = $info;
            error_log('[MailService] delivery failed for ' . $toEmail . ': ' . $info);
            return false;
        }
    }

    /** Convenience: send a branded security notification to an account. */
    public static function notify(PDO $pdo, array $account, string $subject, string $heading, string $bodyHtml): bool
    {
        [$html, $text] = self::template($heading, $bodyHtml);
        return self::send($pdo, $account['email'], $account['full_name'], $subject, $html, $text);
    }
}