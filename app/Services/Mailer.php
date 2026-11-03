<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Domain\DomainException;
use App\Security\Auth;
use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;

/** Envoi SMTP : chaque tentative, réussie ou non, est inscrite au suivi des e-mails. */
final class Mailer
{
    /** @param array<string,string> $attachments nom affiché → chemin du fichier */
    public static function send(string $to, string $subject, string $body, string $kind, ?int $mailId = null, array $attachments = []): void
    {
        $cfg = MailSettings::current();
        if ($cfg['host'] === '') {
            throw new DomainException('mail.error.no_smtp');
        }
        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = $cfg['host'];
            $m->Port = $cfg['port'];
            $m->Timeout = 15;
            $m->SMTPAuth = $cfg['username'] !== '';
            $m->Username = $cfg['username'];
            $m->Password = $cfg['password'];
            $m->SMTPSecure = match ($cfg['encryption']) {
                'tls' => PHPMailer::ENCRYPTION_STARTTLS,
                'ssl' => PHPMailer::ENCRYPTION_SMTPS,
                default => '',
            };
            $m->SMTPAutoTLS = $cfg['encryption'] !== 'none';
            $m->CharSet = 'UTF-8';
            $m->setFrom($cfg['from_email'], $cfg['from_name']);
            $m->addAddress($to);
            foreach ($attachments as $name => $path) {
                $m->addAttachment($path, $name);
            }
            $m->Subject = $subject;
            $m->Body = $body;
            $m->send();
        } catch (MailerException $e) {
            error_log($e->getMessage());
            self::log($mailId, $kind, $to, $subject, 'failed', $m->ErrorInfo ?: $e->getMessage());
            throw new DomainException('mail.error.smtp');
        }
        self::log($mailId, $kind, $to, $subject, 'sent', null);
    }

    private static function log(?int $mailId, string $kind, string $to, string $subject, string $status, ?string $error): void
    {
        Database::pdo()->prepare('INSERT INTO email_log (mail_id, kind, recipient, subject, status, error, user_id) VALUES (?,?,?,?,?,?,?)')
            ->execute([$mailId, $kind, mb_substr($to, 0, 190), mb_substr($subject, 0, 255), $status, $error !== null ? mb_substr($error, 0, 500) : null, Auth::user()?->id]);
    }
}
