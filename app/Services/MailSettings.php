<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Domain\DomainException;

/** Paramètres SMTP : ceux de l'administration, sinon ceux du .env. Le mot de passe est chiffré en base. */
final class MailSettings
{
    private const CIPHER = 'aes-256-gcm';

    /** @return array{host:string,port:int,encryption:string,username:string,password:string,from_email:string,from_name:string,has_password:bool} */
    public static function current(): array
    {
        $row = Database::pdo()->query('SELECT * FROM mail_settings WHERE id = 1')->fetch();
        if ($row) {
            $password = $row['password_enc'] ? self::decrypt($row['password_enc']) : '';
            return ['host' => $row['host'], 'port' => (int) $row['port'], 'encryption' => $row['encryption'], 'username' => $row['username'],
                'password' => $password, 'from_email' => $row['from_email'], 'from_name' => $row['from_name'], 'has_password' => $password !== ''];
        }
        $password = (string) Env::get('MAIL_PASS', '');
        return ['host' => (string) Env::get('MAIL_HOST', ''), 'port' => (int) Env::get('MAIL_PORT', '587'), 'encryption' => 'tls', 'username' => (string) Env::get('MAIL_USER', ''),
            'password' => $password, 'from_email' => (string) Env::get('MAIL_FROM', ''), 'from_name' => (string) Env::get('ORG_NAME', 'DGDA'), 'has_password' => $password !== ''];
    }

    /** @param array{host:string,port:int,encryption:string,username:string,from_email:string,from_name:string} $data $password null : inchangé */
    public static function save(array $data, ?string $password, int $userId): void
    {
        $enc = $password === null ? null : ($password === '' ? '' : self::encrypt($password));
        $sql = 'INSERT INTO mail_settings (id, host, port, encryption, username, password_enc, from_email, from_name, updated_by) VALUES (1,?,?,?,?,?,?,?,?)
            ON DUPLICATE KEY UPDATE host = VALUES(host), port = VALUES(port), encryption = VALUES(encryption), username = VALUES(username),
            password_enc = COALESCE(?, password_enc), from_email = VALUES(from_email), from_name = VALUES(from_name), updated_by = VALUES(updated_by)';
        Database::pdo()->prepare($sql)->execute([$data['host'], $data['port'], $data['encryption'], $data['username'], $enc, $data['from_email'], $data['from_name'], $userId, $enc]);
    }

    private static function key(): string
    {
        $key = (string) Env::get('APP_KEY', '');
        if ($key === '') {
            throw new DomainException('settings.error.no_key');
        }
        return hash('sha256', $key, true);
    }

    private static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return base64_encode($iv . $tag . $cipher);
    }

    private static function decrypt(string $stored): string
    {
        $raw = (string) base64_decode($stored, true);
        $plain = openssl_decrypt(substr($raw, 28), self::CIPHER, self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        if ($plain === false) {
            throw new DomainException('settings.error.decrypt');
        }
        return $plain;
    }
}
