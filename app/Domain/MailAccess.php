<?php

declare(strict_types=1);

namespace App\Domain;

use App\Security\CurrentUser;

/**
 * Droits sur une correspondance.
 * Le niveau de confidentialité prime sur tout : même l'administrateur ne lit pas au-delà de son habilitation.
 */
final class MailAccess
{
    public const LEVELS = [0 => 'normal', 1 => 'confidential', 2 => 'secret'];

    /**
     * @param array{confidentiality:int|string,unit_id:int|string,registered_unit_id:int|string,created_by:int|string} $mail
     * @param list<int> $assignedUnits unités destinataires d'une imputation ou d'une copie
     */
    public static function canRead(CurrentUser $user, array $mail, array $assignedUnits): bool
    {
        if ((int) $mail['confidentiality'] > $user->clearance) {
            return false;
        }
        return $user->role->isGlobal()
            || (int) $mail['created_by'] === $user->id
            || in_array((int) $mail['unit_id'], $user->scopeUnitIds, true)
            || in_array((int) $mail['registered_unit_id'], $user->scopeUnitIds, true)
            || (bool) array_intersect($assignedUnits, $user->scopeUnitIds);
    }

    /** Agir (traiter, répondre, clôturer) : unité responsable, ou courrier central ; une copie ne donne que la lecture. */
    public static function canAct(CurrentUser $user, array $mail): bool
    {
        if ((int) $mail['confidentiality'] > $user->clearance) {
            return false;
        }
        return ($user->role->isGlobal() && $user->can('mail.register'))
            || in_array((int) $mail['unit_id'], $user->scopeUnitIds, true);
    }
}
