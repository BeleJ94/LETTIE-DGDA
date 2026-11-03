<?php

declare(strict_types=1);

namespace App\Domain;

/**
 * Scellement de la chronologie : toute altération d'une ligne casse la chaîne à partir de celle-ci.
 * Le sceau est signé avec une clé absente de la base et couvre aussi l'auteur, l'unité et l'adresse IP.
 * Les lignes antérieures à $sealFrom gardent leur ancienne empreinte : la frontière étant hors base, elle ne peut pas être déplacée depuis celle-ci.
 */
final class EventChain
{
    public const GENESIS = '0000000000000000000000000000000000000000000000000000000000000000';

    /** Empreinte historique, conservée pour vérifier les lignes déjà écrites. */
    public static function hash(string $prev, int $mailId, string $action, ?string $detail, string $userName, string $at): string
    {
        return hash('sha256', implode("\x1f", [$prev, $mailId, $action, (string) $detail, $userName, $at]));
    }

    public static function seal(string $key, string $prev, int $mailId, string $action, ?string $detail, string $userName, string $at, int|string|null $userId, ?string $unitName, ?string $ip): string
    {
        return hash_hmac('sha256', implode("\x1f", [$prev, $mailId, $action, (string) $detail, $userName, $at, (string) $userId, (string) $unitName, (string) $ip]), $key);
    }

    /** @param list<array<string,mixed>> $events lignes complètes de mail_events, par id croissant */
    public static function verify(array $events, string $key, int $sealFrom = 0): bool
    {
        $prev = self::GENESIS;
        foreach ($events as $ev) {
            $expected = (int) $ev['id'] < $sealFrom
                ? self::hash($prev, (int) $ev['mail_id'], $ev['action'], $ev['detail'], $ev['user_name'], $ev['created_at'])
                : self::seal($key, $prev, (int) $ev['mail_id'], $ev['action'], $ev['detail'], $ev['user_name'], $ev['created_at'], $ev['user_id'], $ev['unit_name'], $ev['ip']);
            if ($ev['prev_hash'] !== $prev || !hash_equals($expected, $ev['hash'])) {
                return false;
            }
            $prev = $ev['hash'];
        }
        return true;
    }
}
