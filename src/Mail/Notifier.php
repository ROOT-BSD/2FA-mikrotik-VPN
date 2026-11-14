<?php

declare(strict_types=1);

namespace VpnGw\Mail;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;
use VpnGw\Config;
use VpnGw\Log\AuditLogger;

/**
 * E-mail notifications. A failed e-mail never blocks access; it is only logged.
 */
final class Notifier
{
    public function __construct(
        private readonly Config $cfg,
        private readonly AuditLogger $audit,
    ) {
    }

    public function enabled(): bool
    {
        return $this->cfg->get('SMTP_HOST') !== '' && $this->cfg->get('SMTP_FROM') !== '';
    }

    public function accessGranted(?string $to, string $username, string $ip, \DateTimeImmutable $until): void
    {
        $body = "Для облікового запису {$username} відкрито VPN-доступ з IP {$ip} до {$until->format('H:i d.m.Y')}.\n\n"
            . "Якщо це були не ви — негайно повідомте: {$this->cfg->get('IT_CONTACT')}.\n\n"
            . "Лист надіслано автоматично порталом VPN-GW.";
        $this->send($to, 'VPN-доступ відкрито', $body, $username);
    }

    public function otpCode(?string $to, string $username, #[\SensitiveParameter] string $code): bool
    {
        $body = "Код підтвердження для входу у VPN-GW: {$code}\n\nКод дійсний 5 хвилин.\n"
            . "Якщо ви не входили на портал — повідомте: {$this->cfg->get('IT_CONTACT')}.";
        return $this->send($to, 'Код підтвердження VPN-GW', $body, $username);
    }

    private function send(?string $to, string $subject, string $body, string $username): bool
    {
        if (!$this->enabled() || $to === null || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->audit->event('mail_skipped', $username, null, ['subject' => $subject]);
            return false;
        }
        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP();
            $mail->Host = $this->cfg->get('SMTP_HOST');
            $mail->Port = $this->cfg->int('SMTP_PORT');
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mail->SMTPAutoTLS = true;
            $mail->Timeout = 10;
            if ($this->cfg->get('SMTP_USER') !== '') {
                $mail->SMTPAuth = true;
                $mail->Username = $this->cfg->get('SMTP_USER');
                $mail->Password = $this->cfg->get('SMTP_PASS');
            }
            $ssl = ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false];
            if ($this->cfg->get('SMTP_CA_FILE') !== '') {
                $ssl['cafile'] = $this->cfg->get('SMTP_CA_FILE');
            }
            $mail->SMTPOptions = ['ssl' => $ssl];
            $mail->CharSet = PHPMailer::CHARSET_UTF8;
            $mail->setFrom($this->cfg->get('SMTP_FROM'), 'VPN-GW');
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->Body = $body;
            $mail->isHTML(false);
            $mail->send();
            $this->audit->event('mail_sent', $username, null, ['subject' => $subject]);
            return true;
        } catch (MailException $e) {
            $this->audit->event('mail_error', $username, null, ['error' => $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()]);
            return false;
        }
    }
}
