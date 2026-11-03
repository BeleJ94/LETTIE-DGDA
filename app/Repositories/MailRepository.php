<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Core\DataTable;
use App\Core\Env;
use App\Core\Request;
use App\Core\Response;
use App\Domain\EventChain;
use App\Domain\UnitTree;
use App\Security\CurrentUser;

final class MailRepository
{
    public const SORTABLE = [
        'reference' => 'm.reference', 'event_at' => 'm.event_at', 'correspondent' => 'c.name', 'subject' => 'm.subject',
        'unit' => 'u.name', 'priority' => 'm.priority', 'due_on' => 'm.due_on', 'status' => 'm.status',
    ];
    private const FROM = ' FROM mails m JOIN correspondents c ON c.id = m.correspondent_id JOIN units u ON u.id = m.unit_id ';
    private const OPEN = "('registered','assigned','in_progress','draft')";

    /** Condition SQL de lecture. Uniquement des entiers issus de la session, donc sans paramètre lié. */
    public static function readSql(CurrentUser $user): string
    {
        $level = 'm.confidentiality <= ' . $user->clearance;
        if ($user->role->isGlobal()) {
            return $level;
        }
        $ids = implode(',', $user->scopeUnitIds ?: [0]);
        return "($level AND (m.created_by = {$user->id} OR m.unit_id IN ($ids) OR m.registered_unit_id IN ($ids)
            OR EXISTS (SELECT 1 FROM mail_assignments a WHERE a.mail_id = m.id AND a.to_unit_id IN ($ids))))";
    }

    /** Répertoire central : tout est listé, mais objet et correspondant restent masqués au-delà de l'habilitation. */
    public function datatable(Request $request, CurrentUser $user): Response
    {
        $q = $request->allQuery();
        $directory = ($q['view'] ?? '') === 'directory' && $user->can('mail.directory');
        $read = self::readSql($user);
        $scope = $directory ? '1 = 1' : $read;
        $table = new DataTable($request, self::SORTABLE, 'm.event_at DESC');

        $where = [$scope];
        $params = [];
        foreach (['direction' => 'm.direction', 'priority' => 'm.priority', 'unit_id' => 'm.unit_id', 'confidentiality' => 'm.confidentiality'] as $key => $col) {
            if (($q[$key] ?? '') !== '') {
                $where[] = "$col = ?";
                $params[] = $q[$key];
            }
        }
        // Le correspondant d'un courrier masqué ne doit pas se déduire d'un filtre
        if (($q['correspondent_id'] ?? '') !== '') {
            $where[] = "($read AND m.correspondent_id = ?)";
            $params[] = $q['correspondent_id'];
        }
        if ((int) ($q['folder'] ?? 0) > 0) {
            $units = Database::pdo()->query('SELECT id, parent_id, kind FROM units')->fetchAll();
            $where[] = 'm.unit_id IN (' . implode(',', UnitTree::folderUnits($units, (int) $q['folder']) ?: [0]) . ')';
        }
        $status = (string) ($q['status'] ?? '');
        if ($status === 'open') {
            $where[] = 'm.status IN ' . self::OPEN;
        } elseif ($status === 'late') {
            $where[] = 'm.due_on < CURDATE() AND m.status IN ' . self::OPEN;
        } elseif ($status === 'no_ack') {
            $where[] = "m.direction = 'in' AND m.ack_at IS NULL AND m.status <> 'closed'";
        } elseif ($status === 'no_proof') {
            $where[] = "m.direction = 'out' AND m.status = 'sent' AND NOT EXISTS (SELECT 1 FROM mail_documents d WHERE d.mail_id = m.id AND d.kind = 'proof')";
        } elseif ($status !== '') {
            $where[] = 'm.status = ?';
            $params[] = $status;
        }
        if (($q['from'] ?? '') !== '') {
            $where[] = 'm.event_at >= ?';
            $params[] = $q['from'];
        }
        if (($q['to'] ?? '') !== '') {
            $where[] = 'm.event_at < DATE_ADD(?, INTERVAL 1 DAY)';
            $params[] = $q['to'];
        }
        $base = implode(' AND ', $where);
        if ($table->search !== '') {
            // La recherche n'atteint le contenu que des courriers lisibles : le masquage ne doit pas être contournable
            $base .= " AND (m.reference LIKE ? OR ($read AND (m.subject LIKE ? OR c.name LIKE ? OR m.external_ref LIKE ?)))";
            $like = '%' . $table->search . '%';
            array_push($params, $like, $like, $like, $like);
        }
        // Tri : un courrier masqué ne doit pas se laisser deviner par sa position
        $order = $table->orderBy;
        if ($directory && preg_match('/^(m\.subject|c\.name) (ASC|DESC)$/', $order, $sort)) {
            $order = "IF($read, {$sort[1]}, NULL) {$sort[2]}";
        }
        $pdo = Database::pdo();
        $total = (int) $pdo->query('SELECT COUNT(*) FROM mails m WHERE ' . $scope)->fetchColumn();
        $stmt = $pdo->prepare('SELECT COUNT(*)' . self::FROM . 'WHERE ' . $base);
        $stmt->execute($params);
        $filtered = (int) $stmt->fetchColumn();
        $stmt = $pdo->prepare("SELECT m.id, m.direction, m.reference, m.event_at, m.priority, m.confidentiality, m.due_on, m.status, m.entry_point,
                m.ack_at, u.name unit, ($read) readable,
                IF($read, m.subject, NULL) subject, IF($read, c.name, NULL) correspondent, IF($read, m.external_ref, NULL) external_ref"
            . self::FROM . 'WHERE ' . $base . ' ORDER BY ' . $order . $table->limitSql());
        $stmt->execute($params);
        return $table->response($total, $filtered, $stmt->fetchAll());
    }

    public function find(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT m.*, c.name correspondent, c.email correspondent_email, c.address correspondent_address, u.name unit, r.name registered_unit, us.name creator
            FROM mails m JOIN correspondents c ON c.id = m.correspondent_id JOIN units u ON u.id = m.unit_id
            JOIN units r ON r.id = m.registered_unit_id JOIN users us ON us.id = m.created_by WHERE m.id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /** Suivi public : jamais d'objet ni de correspondant. */
    public function findByToken(string $token): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT reference, direction, event_at, status, ack_at FROM mails WHERE track_token = ?');
        $stmt->execute([$token]);
        return $stmt->fetch() ?: null;
    }

    /** @return list<int> unités imputées sur ce courrier ou sur le courrier auquel il répond */
    public function assignedUnits(int $mailId): array
    {
        $stmt = Database::pdo()->prepare('SELECT to_unit_id FROM mail_assignments WHERE mail_id = ? OR mail_id = (SELECT parent_id FROM mails WHERE id = ?)');
        $stmt->execute([$mailId, $mailId]);
        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    public function assignments(int $mailId): array
    {
        $stmt = Database::pdo()->prepare('SELECT a.*, f.name from_unit, t.name to_unit, us.name author FROM mail_assignments a
            JOIN units f ON f.id = a.from_unit_id JOIN units t ON t.id = a.to_unit_id JOIN users us ON us.id = a.assigned_by WHERE a.mail_id = ? ORDER BY a.id');
        $stmt->execute([$mailId]);
        return $stmt->fetchAll();
    }

    /** Le dossier : le courrier racine et ses réponses. */
    public function family(array $mail): array
    {
        $root = (int) ($mail['parent_id'] ?? $mail['id']);
        $stmt = Database::pdo()->prepare('SELECT id, direction, reference, subject, status, sent_at, event_at, confidentiality, unit_id, registered_unit_id, created_by FROM mails WHERE id = ? OR parent_id = ? ORDER BY id');
        $stmt->execute([$root, $root]);
        return $stmt->fetchAll();
    }

    /** Toutes les pièces du dossier électronique (courrier entrant et réponses). */
    public function documents(array $family): array
    {
        $ids = implode(',', array_map(fn ($m) => (int) $m['id'], $family));
        return Database::pdo()->query("SELECT d.*, m.reference, us.name uploader FROM mail_documents d JOIN mails m ON m.id = d.mail_id
            JOIN users us ON us.id = d.uploaded_by WHERE d.mail_id IN ($ids) ORDER BY d.id")->fetchAll();
    }

    public function document(int $id): ?array
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM mail_documents WHERE id = ?');
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    public function events(array $family): array
    {
        $ids = implode(',', array_map(fn ($m) => (int) $m['id'], $family));
        return Database::pdo()->query("SELECT e.*, m.reference FROM mail_events e JOIN mails m ON m.id = e.mail_id WHERE e.mail_id IN ($ids) ORDER BY e.id")->fetchAll();
    }

    public function chainValid(array $family): bool
    {
        $stmt = Database::pdo()->prepare('SELECT * FROM mail_events WHERE mail_id = ? ORDER BY id');
        foreach ($family as $m) {
            $stmt->execute([$m['id']]);
            if (!EventChain::verify($stmt->fetchAll(), (string) Env::get('APP_KEY', ''), (int) Env::get('EVENT_SEAL_FROM', '0'))) {
                return false;
            }
        }
        return true;
    }

    /** @return list<array<string,mixed>>|null lignes d'un indicateur, null si la clé est inconnue */
    public function drill(CurrentUser $user, string $key, int $param): ?array
    {
        $month = "DATE_FORMAT(CURDATE(), '%Y-%m-01')";
        $folder = $key === 'folder'
            ? implode(',', UnitTree::folderUnits(Database::pdo()->query('SELECT id, parent_id, kind FROM units')->fetchAll(), $param) ?: [0]) : '0';
        $where = [
            'dir_total' => '1 = 1',
            'dir_incoming' => "m.direction = 'in'",
            'dir_outgoing' => "m.direction = 'out'",
            'dir_open' => 'm.status IN ' . self::OPEN,
            'dir_closed' => "m.status = 'closed'",
            'folder' => "m.status = 'closed' AND m.unit_id IN ($folder)",
            'open_in' => "m.direction = 'in' AND m.status IN ('registered','assigned','in_progress')",
            'to_assign' => "m.status = 'registered'",
            'no_ack' => "m.direction = 'in' AND m.ack_at IS NULL AND m.status <> 'closed'",
            'late' => 'm.due_on < CURDATE() AND m.status IN ' . self::OPEN,
            'drafts' => "m.status = 'draft'",
            'month_in' => "m.direction = 'in' AND m.event_at >= $month",
            'month_closed' => "m.status = 'closed' AND m.closed_at >= $month",
            'unit' => 'm.status IN ' . self::OPEN . ' AND m.unit_id = ' . $param,
        ][$key] ?? null;
        if ($where === null) {
            return null;
        }
        $limit = DataTable::EXPORT_CAP;
        return Database::pdo()->query('SELECT m.id, m.reference, m.event_at, m.subject, c.name correspondent, u.name unit, m.priority, m.due_on, m.status'
            . self::FROM . 'WHERE ' . self::readSql($user) . " AND $where ORDER BY m.due_on IS NULL, m.due_on, m.event_at DESC LIMIT $limit")->fetchAll();
    }

    /** Blocs du répertoire : des totaux seulement, jamais de contenu. */
    public function directoryCounts(CurrentUser $user): array
    {
        $read = self::readSql($user);
        return Database::pdo()->query("SELECT COUNT(*) total,
            COALESCE(SUM(m.direction = 'in'), 0) incoming,
            COALESCE(SUM(m.direction = 'out'), 0) outgoing,
            COALESCE(SUM(m.status IN " . self::OPEN . "), 0) open,
            COALESCE(SUM(m.status = 'closed'), 0) closed,
            COALESCE(SUM(NOT ($read)), 0) masked
            FROM mails m")->fetch();
    }

    /** Compteurs des onglets du courrier sortant. */
    public function outgoingCounts(CurrentUser $user): array
    {
        $read = self::readSql($user);
        return Database::pdo()->query("SELECT
            COALESCE(SUM(m.status = 'draft'), 0) draft,
            COALESCE(SUM(m.status = 'sent'), 0) sent,
            COALESCE(SUM(m.status = 'sent' AND NOT EXISTS (SELECT 1 FROM mail_documents d WHERE d.mail_id = m.id AND d.kind = 'proof')), 0) no_proof,
            COALESCE(SUM(m.status = 'closed'), 0) closed
            FROM mails m WHERE m.direction = 'out' AND $read")->fetch();
    }

    /** Compteurs des onglets du courrier entrant. */
    public function incomingCounts(CurrentUser $user): array
    {
        $read = self::readSql($user);
        return Database::pdo()->query("SELECT
            COALESCE(SUM(m.status IN ('registered','assigned','in_progress')), 0) open,
            COALESCE(SUM(m.status = 'registered'), 0) registered,
            COALESCE(SUM(m.ack_at IS NULL AND m.status <> 'closed'), 0) no_ack,
            COALESCE(SUM(m.due_on < CURDATE() AND m.status IN ('registered','assigned','in_progress')), 0) late
            FROM mails m WHERE m.direction = 'in' AND $read")->fetch();
    }

    /** Dossiers d'archives par sous-direction, limités à ce que l'utilisateur peut lire. */
    public function archiveFolders(CurrentUser $user): array
    {
        $pdo = Database::pdo();
        $units = $pdo->query('SELECT id, parent_id, kind, name FROM units')->fetchAll();
        $names = array_column($units, 'name', 'id');
        $read = self::readSql($user);
        $folders = [];
        foreach ($pdo->query("SELECT m.unit_id, COUNT(*) total FROM mails m WHERE m.status = 'closed' AND $read GROUP BY m.unit_id")->fetchAll() as $row) {
            $id = UnitTree::folder($units, (int) $row['unit_id']);
            $folders[$id] ??= ['id' => $id, 'name' => $names[$id], 'total' => 0];
            $folders[$id]['total'] += (int) $row['total'];
        }
        usort($folders, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $folders;
    }

    /** Indicateurs du tableau de bord, limités à ce que l'utilisateur peut lire. */
    public function kpis(CurrentUser $user): array
    {
        $read = self::readSql($user);
        return Database::pdo()->query("SELECT
            COALESCE(SUM(m.direction = 'in' AND m.status IN ('registered','assigned','in_progress')), 0) open_in,
            COALESCE(SUM(m.status = 'registered'), 0) to_assign,
            COALESCE(SUM(m.direction = 'in' AND m.ack_at IS NULL AND m.status <> 'closed'), 0) no_ack,
            COALESCE(SUM(m.due_on < CURDATE() AND m.status IN " . self::OPEN . "), 0) late,
            COALESCE(SUM(m.status = 'draft'), 0) drafts,
            COALESCE(SUM(m.direction = 'in' AND m.event_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) month_in,
            COALESCE(SUM(m.status = 'closed' AND m.closed_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')), 0) month_closed
            FROM mails m WHERE $read")->fetch();
    }

    public function byUnit(CurrentUser $user): array
    {
        $read = self::readSql($user);
        return Database::pdo()->query('SELECT u.id, u.name unit, COUNT(*) total,
            SUM(m.status IN ' . self::OPEN . ') open_count,
            SUM(m.due_on < CURDATE() AND m.status IN ' . self::OPEN . ") late
            FROM mails m JOIN units u ON u.id = m.unit_id WHERE $read GROUP BY u.id, u.name ORDER BY open_count DESC, u.name LIMIT 10")->fetchAll();
    }

    public function urgent(CurrentUser $user): array
    {
        $read = self::readSql($user);
        return Database::pdo()->query("SELECT m.id, m.reference, m.subject, m.due_on, m.priority, m.status, u.name unit
            FROM mails m JOIN units u ON u.id = m.unit_id
            WHERE $read AND m.status IN ('registered','assigned','in_progress') AND (m.due_on IS NOT NULL OR m.priority <> 'normal')
            ORDER BY m.due_on IS NULL, m.due_on, m.event_at LIMIT 8")->fetchAll();
    }
}
