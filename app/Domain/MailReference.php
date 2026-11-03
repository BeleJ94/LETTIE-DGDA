<?php

declare(strict_types=1);

namespace App\Domain;

final class MailReference
{
    /** Ex. DGDA/ARR/2026/000123 (arrivée), DGDA/DEP/2026/000045 (départ). */
    public static function format(string $org, string $direction, int $year, int $number): string
    {
        return sprintf('%s/%s/%d/%06d', $org, $direction === 'in' ? 'ARR' : 'DEP', $year, $number);
    }
}
