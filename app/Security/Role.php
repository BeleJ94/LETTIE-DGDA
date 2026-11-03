<?php

declare(strict_types=1);

namespace App\Security;

enum Role: string
{
    case Admin = 'admin';
    case Central = 'central';
    case Chief = 'chief';
    case Agent = 'agent';
    case Auditor = 'auditor';

    /** @return list<string> */
    public function permissions(): array
    {
        return match ($this) {
            self::Admin => ['*'],
            // Service courrier central : enregistre, impute, accuse réception, voit le répertoire global
            self::Central => ['dashboard.view', 'mail.view', 'mail.register', 'mail.assign', 'mail.reply', 'mail.close', 'mail.directory', 'correspondents.manage'],
            // Chef de sous-direction, service ou bureau : reçoit, impute et clôture dans son périmètre
            self::Chief => ['dashboard.view', 'mail.view', 'mail.register', 'mail.assign', 'mail.reply', 'mail.close', 'correspondents.manage'],
            self::Agent => ['dashboard.view', 'mail.view', 'mail.register', 'mail.reply', 'correspondents.manage'],
            // Contrôle et audit : lecture du répertoire et du journal, sans aucune action
            self::Auditor => ['dashboard.view', 'mail.view', 'mail.directory', 'activity.view', 'mail.log'],
        };
    }

    /** Vue sur toutes les unités : les niveaux de confidentialité continuent de s'appliquer. */
    public function isGlobal(): bool
    {
        return in_array($this, [self::Admin, self::Central, self::Auditor], true);
    }
}
