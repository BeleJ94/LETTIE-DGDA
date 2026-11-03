<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\Env;
use App\Domain\DomainException;
use App\Domain\EventChain;
use App\Domain\MailAccess;
use App\Domain\MailReference;
use App\Domain\MailWorkflow;
use App\Security\Auth;
use App\Core\View;
use Dompdf\Dompdf;

/** Toutes les opérations sur une correspondance : chacune écrit sa ligne de chronologie dans la même transaction. */
final class MailService
{
    public const MAX_BYTES = 20 * 1024 * 1024;
    public const MIME = ['application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    public const DOC_KINDS = ['original', 'annex', 'ack', 'reply', 'proof'];
    private const EDIT_FIELDS = ['correspondent_id', 'external_ref', 'letter_date', 'event_at', 'channel', 'subject', 'summary', 'priority', 'confidentiality', 'due_on'];

    public static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/uploads';
    }

    /**
     * Enregistrement au service courrier central ou directement par une unité.
     * @param array<string,mixed> $data
     * @param list<array<string,mixed>> $files pièces du courrier
     */
    public function register(array $data, array $files): int
    {
        $user = Auth::require();
        if ($user->unitId === null) {
            throw new DomainException('mail.error.no_unit');
        }
        if ((int) $data['confidentiality'] > $user->clearance) {
            throw new DomainException('mail.error.clearance');
        }
        $stored = $this->storeAll($files);
        return $this->cleaningUp($stored, fn () => Database::transaction(function () use ($data, $user, $stored) {
            $pdo = Database::pdo();
            $center = $pdo->prepare('SELECT is_mail_center FROM units WHERE id = ?');
            $center->execute([$user->unitId]);
            $entry = $center->fetchColumn() ? 'central' : 'direct';
            $direction = $data['direction'];
            $pdo->prepare('INSERT INTO mails (direction, reference, track_token, parent_id, correspondent_id, external_ref, letter_date, event_at, channel, subject, summary, priority, confidentiality,
                    entry_point, registered_unit_id, unit_id, due_on, status, created_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$direction, $this->nextReference($direction), bin2hex(random_bytes(16)), $data['parent_id'] ?? null, $data['correspondent_id'], $data['external_ref'], $data['letter_date'],
                    $data['event_at'], $data['channel'], $data['subject'], $data['summary'], $data['priority'], $data['confidentiality'],
                    $entry, $user->unitId, $data['unit_id'] ?? $user->unitId, $data['due_on'], $direction === 'in' ? 'registered' : 'draft', $user->id]);
            $id = (int) $pdo->lastInsertId();
            $this->event($id, 'registered', t('mail.entry.' . $entry));
            foreach ($stored as $doc) {
                $this->attach($id, $direction === 'in' ? 'original' : 'reply', $doc);
            }
            ActivityLogger::log('mail', $id, 'create', null, ['direction' => $direction, 'entry' => $entry]);
            return $id;
        }));
    }

    public function update(array $mail, array $data): void
    {
        $this->guard($mail);
        if (MailWorkflow::isFinal($mail['status'])) {
            throw new DomainException('mail.error.closed');
        }
        if ((int) $data['confidentiality'] > Auth::require()->clearance) {
            throw new DomainException('mail.error.clearance');
        }
        Database::transaction(function () use ($mail, $data) {
            $values = array_map(fn ($f) => $data[$f], self::EDIT_FIELDS);
            $set = implode(', ', array_map(fn ($f) => "$f = ?", self::EDIT_FIELDS));
            Database::pdo()->prepare("UPDATE mails SET $set WHERE id = ?")->execute([...$values, $mail['id']]);
            $changed = array_filter(self::EDIT_FIELDS, fn ($f) => (string) $data[$f] !== (string) $mail[$f]);
            $this->event((int) $mail['id'], 'edited', implode(', ', array_map(fn ($f) => t('mail.field.' . $f), $changed)));
            ActivityLogger::log('mail', (int) $mail['id'], 'update', $mail, $data);
        });
    }

    /** Imputation : « action » transfère la responsabilité, « copie » donne seulement la lecture. */
    public function assign(array $mail, int $toUnit, string $kind, ?string $instruction, ?string $dueOn): void
    {
        $this->guard($mail);
        $user = Auth::require();
        if ($kind === 'action') {
            MailWorkflow::assert($mail['status'], 'assigned');
        } elseif (MailWorkflow::isFinal($mail['status'])) {
            throw new DomainException('mail.error.closed');
        }
        Database::transaction(function () use ($mail, $toUnit, $kind, $instruction, $dueOn, $user) {
            $pdo = Database::pdo();
            $pdo->prepare('INSERT INTO mail_assignments (mail_id, kind, from_unit_id, to_unit_id, instruction, due_on, assigned_by) VALUES (?,?,?,?,?,?,?)')
                ->execute([$mail['id'], $kind, $user->unitId ?? $mail['unit_id'], $toUnit, $instruction, $dueOn, $user->id]);
            if ($kind === 'action') {
                $pdo->prepare("UPDATE mails SET unit_id = ?, status = 'assigned', due_on = COALESCE(?, due_on) WHERE id = ?")->execute([$toUnit, $dueOn, $mail['id']]);
                // Le projet de réponse suit le dossier vers sa nouvelle unité
                $pdo->prepare("UPDATE mails SET unit_id = ? WHERE parent_id = ? AND status = 'draft'")->execute([$toUnit, $mail['id']]);
            }
            $name = $pdo->prepare('SELECT name FROM units WHERE id = ?');
            $name->execute([$toUnit]);
            $this->event((int) $mail['id'], $kind === 'action' ? 'assigned' : 'copied', $name->fetchColumn() . ($instruction ? ' — ' . $instruction : ''));
        });
    }

    public function take(array $mail): void
    {
        $this->guard($mail);
        MailWorkflow::assert($mail['status'], 'in_progress');
        Database::transaction(function () use ($mail) {
            Database::pdo()->prepare("UPDATE mails SET status = 'in_progress' WHERE id = ?")->execute([$mail['id']]);
            $this->event((int) $mail['id'], 'taken');
        });
    }

    /** Accusé de réception : envoyé par e-mail ou imprimé et remis ; une seule fois par courrier. */
    public function acknowledge(array $mail, string $channel): void
    {
        $this->guard($mail);
        if ($mail['direction'] !== 'in') {
            throw new DomainException('mail.error.transition');
        }
        if ($mail['ack_at'] !== null) {
            throw new DomainException('mail.error.ack_done');
        }
        // Réservation atomique : deux soumissions simultanées ne produisent qu'un seul accusé
        $pdo = Database::pdo();
        $claim = $pdo->prepare('UPDATE mails SET ack_at = NOW(), ack_channel = ?, ack_by = ? WHERE id = ? AND ack_at IS NULL');
        $claim->execute([$channel, Auth::require()->id, $mail['id']]);
        if ($claim->rowCount() === 0) {
            throw new DomainException('mail.error.ack_done');
        }
        $doc = null;
        try {
            $doc = $this->ackDocument($mail);
            if ($channel === 'email') {
                $this->sendAck($mail, $doc);
            }
        } catch (\Throwable $e) {
            $pdo->prepare('UPDATE mails SET ack_at = NULL, ack_channel = NULL, ack_by = NULL WHERE id = ?')->execute([$mail['id']]);
            if ($doc !== null) {
                @unlink(self::dir() . '/' . $doc['stored']);
            }
            throw $e;
        }
        Database::transaction(function () use ($mail, $channel, $doc) {
            $this->attach((int) $mail['id'], 'ack', $doc);
            $this->event((int) $mail['id'], 'acknowledged', t('mail.ack_channel.' . $channel) . ($channel === 'email' ? ' — ' . $mail['correspondent_email'] : ''));
        });
    }

    /** Projet de réponse rattaché au courrier entrant : hérite de l'unité et du niveau de confidentialité. */
    public function reply(array $mail, array $data, array $files): int
    {
        $this->guard($mail);
        if ($mail['direction'] !== 'in' || !in_array($mail['status'], ['registered', 'assigned', 'in_progress'], true)) {
            throw new DomainException('mail.error.transition');
        }
        $draft = Database::pdo()->prepare("SELECT COUNT(*) FROM mails WHERE parent_id = ? AND status = 'draft'");
        $draft->execute([$mail['id']]);
        if ($draft->fetchColumn()) {
            throw new DomainException('mail.error.reply_exists');
        }
        return Database::transaction(function () use ($mail, $data, $files) {
            if ($mail['status'] !== 'in_progress') {
                $this->take($mail);
            }
            $id = $this->register($data + ['direction' => 'out', 'parent_id' => $mail['id'], 'unit_id' => $mail['unit_id'], 'correspondent_id' => $mail['correspondent_id'],
                'confidentiality' => $mail['confidentiality'], 'external_ref' => $mail['external_ref'], 'letter_date' => null, 'due_on' => null, 'priority' => $mail['priority']], $files);
            $this->event((int) $mail['id'], 'reply_drafted');
            return $id;
        });
    }

    /** Envoi : exige la réponse officielle au dossier. Le courrier entrant passe alors à « répondu ». */
    public function send(array $mail, string $channel, ?array $proof): void
    {
        $this->guard($mail);
        MailWorkflow::assert($mail['status'], 'sent');
        $has = Database::pdo()->prepare("SELECT COUNT(*) FROM mail_documents WHERE mail_id = ? AND kind = 'reply'");
        $has->execute([$mail['id']]);
        if (!$has->fetchColumn()) {
            throw new DomainException('mail.error.reply_doc');
        }
        $stored = $proof ? $this->storeAll([$proof]) : [];
        Database::transaction(function () use ($mail, $channel, $stored) {
            $pdo = Database::pdo();
            $pdo->prepare("UPDATE mails SET status = 'sent', sent_at = NOW(), channel = ? WHERE id = ?")->execute([$channel, $mail['id']]);
            $this->event((int) $mail['id'], 'sent', t('mail.channel.' . $channel));
            foreach ($stored as $doc) {
                $this->attach((int) $mail['id'], 'proof', $doc);
            }
            if ($mail['parent_id'] !== null) {
                $parent = $pdo->prepare("UPDATE mails SET status = 'answered' WHERE id = ? AND status IN ('assigned', 'in_progress')");
                $parent->execute([$mail['parent_id']]);
                if ($parent->rowCount() > 0) {
                    $this->event((int) $mail['parent_id'], 'answered', $mail['reference']);
                }
            }
        });
    }

    public function close(array $mail, ?string $note): void
    {
        $this->guard($mail);
        MailWorkflow::assert($mail['status'], 'closed');
        if (MailWorkflow::closeNeedsNote($mail['direction'], $mail['status']) && trim((string) $note) === '') {
            throw new DomainException('mail.error.close_note');
        }
        Database::transaction(function () use ($mail, $note) {
            Database::pdo()->prepare("UPDATE mails SET status = 'closed', closed_at = NOW(), closed_by = ?, close_note = ? WHERE id = ?")
                ->execute([Auth::require()->id, $note ?: null, $mail['id']]);
            $this->event((int) $mail['id'], 'closed', $note);
        });
    }

    /** Projet de réponse abandonné : conservé et tracé, le courrier entrant peut recevoir une nouvelle réponse. */
    public function cancel(array $mail, ?string $note): void
    {
        $this->guard($mail);
        MailWorkflow::assert($mail['status'], 'cancelled');
        if (trim((string) $note) === '') {
            throw new DomainException('mail.error.cancel_note');
        }
        Database::transaction(function () use ($mail, $note) {
            Database::pdo()->prepare("UPDATE mails SET status = 'cancelled', closed_at = NOW(), closed_by = ?, close_note = ? WHERE id = ?")
                ->execute([Auth::require()->id, $note, $mail['id']]);
            $this->event((int) $mail['id'], 'cancelled', $note);
        });
    }

    public function addDocument(array $mail, string $kind, array $file): void
    {
        $this->guard($mail);
        if (MailWorkflow::isFinal($mail['status'])) {
            throw new DomainException('mail.error.closed');
        }
        $stored = $this->storeAll([$file]);
        if (!$stored) {
            throw new DomainException('mail.error.upload');
        }
        Database::transaction(function () use ($mail, $kind, $stored) {
            $this->attach((int) $mail['id'], $kind, $stored[0]);
        });
    }

    /** Ligne de chronologie scellée ; le verrou sur le courrier sérialise la chaîne. */
    public function event(int $mailId, string $action, ?string $detail = null): void
    {
        Database::transaction(function () use ($mailId, $action, $detail) {
            $pdo = Database::pdo();
            $pdo->prepare('SELECT id FROM mails WHERE id = ? FOR UPDATE')->execute([$mailId]);
            $last = $pdo->prepare('SELECT hash FROM mail_events WHERE mail_id = ? ORDER BY id DESC LIMIT 1');
            $last->execute([$mailId]);
            $prev = $last->fetchColumn() ?: EventChain::GENESIS;
            $user = Auth::require();
            $at = date('Y-m-d H:i:s');
            $detail = $detail !== null ? mb_substr($detail, 0, 500) : null;
            $ip = $_SERVER['REMOTE_ADDR'] ?? null;
            $pdo->prepare('INSERT INTO mail_events (mail_id, action, detail, user_id, user_name, unit_name, ip, created_at, prev_hash, hash) VALUES (?,?,?,?,?,?,?,?,?,?)')
                ->execute([$mailId, $action, $detail, $user->id, $user->name, $user->unitName, $ip, $at, $prev,
                    EventChain::seal((string) Env::get('APP_KEY', ''), $prev, $mailId, $action, $detail, $user->name, $at, $user->id, $user->unitName, $ip)]);
        });
    }

    public function qrSvg(string $url): string
    {
        return (new \chillerlan\QRCode\QRCode(new \chillerlan\QRCode\QROptions(['outputBase64' => false])))->render($url);
    }

    public static function trackUrl(array $mail): string
    {
        return rtrim((string) Env::get('APP_URL', ''), '/') . '/track/' . $mail['track_token'];
    }

    /** Transaction annulée : les fichiers déjà déposés ne restent pas orphelins. */
    private function cleaningUp(array $stored, callable $fn): mixed
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            foreach ($stored as $doc) {
                @unlink(self::dir() . '/' . $doc['stored']);
            }
            throw $e;
        }
    }

    private function guard(array $mail): void
    {
        if (!MailAccess::canAct(Auth::require(), $mail)) {
            throw new DomainException('mail.error.no_right');
        }
    }

    private function nextReference(string $direction): string
    {
        $year = (int) date('Y');
        $pdo = Database::pdo();
        $pdo->prepare('INSERT INTO mail_sequences (direction, year, last_no) VALUES (?, ?, LAST_INSERT_ID(1))
            ON DUPLICATE KEY UPDATE last_no = LAST_INSERT_ID(last_no + 1)')->execute([$direction, $year]);
        return MailReference::format((string) Env::get('ORG_SHORT', 'DGDA'), $direction, $year, (int) $pdo->lastInsertId());
    }

    /** Fichiers hors racine web, type déterminé par le contenu, empreinte conservée. */
    private function storeAll(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
                throw new DomainException('mail.error.upload');
            }
            if ($file['size'] > self::MAX_BYTES) {
                throw new DomainException('mail.error.size');
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']) ?: '';
            $ext = self::MIME[$mime] ?? throw new DomainException('mail.error.type');
            $sub = date('Y/m');
            $dir = self::dir() . '/' . $sub;
            if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('Dossier de stockage inaccessible');
            }
            $name = $sub . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
            $sha = hash_file('sha256', $file['tmp_name']);
            if (!move_uploaded_file($file['tmp_name'], self::dir() . '/' . $name)) {
                throw new DomainException('mail.error.upload');
            }
            $out[] = ['name' => mb_substr(basename($file['name']), 0, 255), 'stored' => $name, 'mime' => $mime, 'size' => $file['size'], 'sha256' => $sha];
        }
        return $out;
    }

    private function attach(int $mailId, string $kind, array $doc): void
    {
        Database::pdo()->prepare('INSERT INTO mail_documents (mail_id, kind, original_name, stored_name, mime, size, sha256, uploaded_by) VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$mailId, $kind, $doc['name'], $doc['stored'], $doc['mime'], $doc['size'], $doc['sha256'], Auth::require()->id]);
        $this->event($mailId, 'document_added', t('mail.doc.' . $kind) . ' — ' . $doc['name']);
    }

    /** Copie PDF de l'accusé, versée au dossier électronique. */
    private function ackDocument(array $mail): array
    {
        $qr = (new \chillerlan\QRCode\QRCode())->render(self::trackUrl($mail));
        $pdf = new Dompdf();
        $pdf->loadHtml(View::render('mails/ack_pdf', ['m' => $mail, 'qr' => $qr], null), 'UTF-8');
        $pdf->setPaper('A4');
        $pdf->render();
        $bytes = (string) $pdf->output();
        $sub = date('Y/m');
        $dir = self::dir() . '/' . $sub;
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException('Dossier de stockage inaccessible');
        }
        $stored = $sub . '/' . bin2hex(random_bytes(16)) . '.pdf';
        file_put_contents(self::dir() . '/' . $stored, $bytes);
        return ['name' => 'Accuse-' . str_replace('/', '-', $mail['reference']) . '.pdf', 'stored' => $stored, 'mime' => 'application/pdf', 'size' => strlen($bytes), 'sha256' => hash('sha256', $bytes)];
    }

    private function sendAck(array $mail, array $doc): void
    {
        if (!$mail['correspondent_email']) {
            throw new DomainException('mail.error.no_email');
        }
        // Aucun objet dans l'e-mail : seule la référence circule hors du système
        Mailer::send(
            $mail['correspondent_email'],
            t('ack.email_subject', ['ref' => $mail['reference']]),
            t('ack.email_body', ['ref' => $mail['reference'], 'date' => fmt_date($mail['event_at'], true), 'url' => self::trackUrl($mail), 'org' => (string) Env::get('ORG_NAME', 'DGDA')]),
            'ack',
            (int) $mail['id'],
            [$doc['name'] => self::dir() . '/' . $doc['stored']],
        );
    }
}
