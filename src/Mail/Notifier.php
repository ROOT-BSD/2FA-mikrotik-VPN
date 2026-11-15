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

    /** Test message from the admin panel. Returns null on success or the SMTP error. */
    public function test(string $to, string $admin): ?string
    {
        if (!$this->enabled()) {
            return 'Пошту вимкнено: не задано SMTP-сервер або адресу відправника.';
        }
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            return 'Некоректна адреса отримувача.';
        }
        $error = $this->deliver($to, 'Тестовий лист VPN-GW', "Це тестовий лист з панелі адміністратора VPN-GW.\nНадіслав: {$admin}.\n\nЯкщо ви його отримали — пошту налаштовано правильно.");
        $this->audit->event($error === null ? 'mail_sent' : 'mail_error', $admin, null, ['subject' => 'test'] + ($error === null ? [] : ['error' => $error]));
        return $error;
    }

    private function send(?string $to, string $subject, string $body, string $username): bool
    {
        if (!$this->enabled() || $to === null || filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->audit->event('mail_skipped', $username, null, ['subject' => $subject]);
            return false;
        }
        $error = $this->deliver($to, $subject, $body);
        if ($error === null) {
            $this->audit->event('mail_sent', $username, null, ['subject' => $subject]);
            return true;
        }
        $this->audit->event('mail_error', $username, null, ['error' => $error]);
        return false;
    }

    /** @return string|null error message, null when the message was accepted by the server */
    private function deliver(string $to, string $subject, string $body): ?string
    {
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
            return null;
        } catch (MailException $e) {
            return $mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage();
        }
    }
}
