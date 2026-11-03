<?php

declare(strict_types=1);

namespace App\Domain;

/** Cycle de vie d'une correspondance, de la réception à la clôture du dossier. */
final class MailWorkflow
{
    private const TRANSITIONS = [
        'registered' => ['assigned', 'in_progress', 'closed'],
        'assigned' => ['assigned', 'in_progress', 'answered', 'closed'],
        'in_progress' => ['assigned', 'answered', 'closed'],
        'answered' => ['closed'],
        'draft' => ['sent', 'cancelled'],
        'sent' => ['closed'],
        'closed' => [],
        'cancelled' => [],
    ];

    public static function can(string $from, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$from] ?? [], true);
    }

    /** @throws DomainException transition interdite */
    public static function assert(string $from, string $to): void
    {
        if (!self::can($from, $to)) {
            throw new DomainException('mail.error.transition');
        }
    }

    /** Un courrier entrant clôturé sans réponse doit porter un motif. */
    public static function closeNeedsNote(string $direction, string $status): bool
    {
        return $direction === 'in' && $status !== 'answered';
    }

    /** Dossier terminé : plus aucune modification. */
    public static function isFinal(string $status): bool
    {
        return in_array($status, ['closed', 'cancelled'], true);
    }

    public static function isLate(?string $dueOn, string $status, string $today): bool
    {
        return $dueOn !== null && $dueOn < $today && !in_array($status, ['answered', 'closed', 'sent', 'cancelled'], true);
    }
}
