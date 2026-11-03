<?php

declare(strict_types=1);

namespace App\Domain;

/** Hiérarchie direction > sous-direction > service > bureau. */
final class UnitTree
{
    /**
     * @param list<array{id:int|string,parent_id:int|string|null}> $units
     * @return list<int> l'unité et toutes ses unités rattachées
     */
    public static function subtree(array $units, int $rootId): array
    {
        $children = [];
        foreach ($units as $u) {
            if ($u['parent_id'] !== null) {
                $children[(int) $u['parent_id']][] = (int) $u['id'];
            }
        }
        $out = [];
        $stack = [$rootId];
        while ($stack) {
            $id = array_pop($stack);
            if (!in_array($id, $out, true)) {
                $out[] = $id;
                array_push($stack, ...($children[$id] ?? []));
            }
        }
        return $out;
    }

    /**
     * Dossier d'archives d'une unité : sa sous-direction, sinon l'unité elle-même.
     * @param list<array{id:int|string,parent_id:int|string|null,kind:string}> $units
     */
    public static function folder(array $units, int $unitId): int
    {
        $byId = array_column($units, null, 'id');
        $id = $unitId;
        $guard = count($units);
        while (isset($byId[$id]) && $guard-- > 0) {
            if ($byId[$id]['kind'] === 'sous_direction') {
                return $id;
            }
            if ($byId[$id]['parent_id'] === null) {
                break;
            }
            $id = (int) $byId[$id]['parent_id'];
        }
        return $unitId;
    }

    /** @return list<int> unités classées dans ce dossier */
    public static function folderUnits(array $units, int $folderId): array
    {
        $ids = array_map(fn ($u) => (int) $u['id'], $units);
        return array_values(array_filter($ids, fn ($id) => self::folder($units, $id) === $folderId));
    }
}
