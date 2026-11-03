<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\MailRepository;
use App\Security\CurrentUser;

/** Pastilles du menu : compteurs de travail à faire. */
final class NavBadges
{
    private static ?array $kpis = null;

    public static function get(string $key, CurrentUser $user): int
    {
        self::$kpis ??= (new MailRepository())->kpis($user);
        return (int) (self::$kpis[$key] ?? 0);
    }
}
