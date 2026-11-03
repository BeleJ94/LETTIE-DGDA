<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Référentiels : unités, utilisateurs, correspondants, journal. */
final class OrgRepository
{
    /** Unités dans l'ordre hiérarchique, avec la profondeur pour l'indentation. */
    public function units(bool $activeOnly = false): array
    {
        $rows = Database::pdo()->query('SELECT n.*, p.name parent, (SELECT COUNT(*) FROM users u WHERE u.unit_id = n.id AND u.active = 1) agents
            FROM units n LEFT JOIN units p ON p.id = n.parent_id ' . ($activeOnly ? 'WHERE n.active = 1 ' : '') . 'ORDER BY n.name')->fetchAll();
        $ids = array_map('intval', array_column($rows, 'id'));
        $byParent = [];
        foreach ($rows as $r) {
            // Parent inactif masqué : l'unité remonte en tête de branche
            $parent = $r['parent_id'] !== null && in_array((int) $r['parent_id'], $ids, true) ? (int) $r['parent_id'] : 0;
            $byParent[$parent][] = $r;
        }
        $out = [];
        $walk = function (int $parent, int $depth) use (&$walk, &$out, $byParent) {
            foreach ($byParent[$parent] ?? [] as $r) {
                $out[] = $r + ['depth' => $depth];
                $walk((int) $r['id'], $depth + 1);
            }
        };
        $walk(0, 0);
        return $out;
    }

    public function unit(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM units WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function users(): array
    {
        return Database::pdo()->query('SELECT u.*, n.name unit FROM users u LEFT JOIN units n ON n.id = u.unit_id ORDER BY u.name')->fetchAll();
    }

    public function correspondents(bool $activeOnly = false): array
    {
        return Database::pdo()->query('SELECT * FROM correspondents ' . ($activeOnly ? 'WHERE active = 1 ' : '') . 'ORDER BY name')->fetchAll();
    }

    public function emailLog(string $status): array
    {
        $stmt = Database::pdo()->prepare("SELECT l.*, m.reference, u.name user_name FROM email_log l LEFT JOIN mails m ON m.id = l.mail_id
            LEFT JOIN users u ON u.id = l.user_id WHERE (? = '' OR l.status = ?) ORDER BY l.id DESC LIMIT 300");
        $stmt->execute([$status, $status]);
        return $stmt->fetchAll();
    }

    public function emailStats(): array
    {
        return Database::pdo()->query("SELECT COUNT(*) total, COALESCE(SUM(status = 'sent'), 0) sent, COALESCE(SUM(status = 'failed'), 0) failed FROM email_log")->fetch();
    }

    public function activity(): array
    {
        return Database::pdo()->query('SELECT a.*, u.name user_name FROM activity_log a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.id DESC LIMIT 300')->fetchAll();
    }
}
