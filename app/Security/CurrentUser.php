<?php

declare(strict_types=1);

namespace App\Security;

final class CurrentUser
{
    /** @param list<int> $scopeUnitIds unité de l'agent et unités rattachées */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly Role $role,
        public readonly ?int $unitId,
        public readonly ?string $unitName,
        public readonly int $clearance,
        public readonly array $scopeUnitIds,
    ) {
    }

    public function can(string $permission): bool
    {
        $perms = $this->role->permissions();
        return in_array('*', $perms, true) || in_array($permission, $perms, true);
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim($this->name)) ?: [];
        $letters = array_map(fn ($p) => mb_substr($p, 0, 1), array_slice($parts, 0, 2));
        return mb_strtoupper(implode('', $letters));
    }
}
