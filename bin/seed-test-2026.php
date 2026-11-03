<?php

declare(strict_types=1);

/*
 * Données de TEST réalistes pour l'année 2026 : organigramme, comptes, correspondants, courriers entrants et sortants,
 * imputations, accusés, pièces PDF et chronologie scellée (valide avec l'APP_KEY du .env).
 *
 *   php bin/seed-test-2026.php --yes              génère (refuse si la base contient déjà des courriers)
 *   php bin/seed-test-2026.php --purge --yes      supprime TOUT ce que ce script a créé, fichiers compris
 *   options : --mails=400 (courriers entrants)  --env=chemin/.env (autre base)  --allow-existing
 *
 * Tout ce qui est créé est inscrit dans la table test_seed : la purge ne touche à rien d'autre.
 * Comptes de test : *@test.lettie.local, mot de passe commun Lettie-Test-2026.
 */

use App\Core\Database;
use App\Core\Env;
use App\Domain\EventChain;
use App\Domain\MailReference;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

$opts = getopt('', ['yes', 'purge', 'allow-existing', 'mails:', 'env:']);
Env::load($opts['env'] ?? $root . '/.env');
date_default_timezone_set(Env::get('APP_TIMEZONE', 'Africa/Lubumbashi'));

$key = (string) Env::get('APP_KEY', '');
if ($key === '') {
    exit("APP_KEY manquante dans le .env : la chronologie ne pourrait pas être scellée.\n");
}
if (!isset($opts['yes'])) {
    exit("Base visée : " . Env::get('DB_NAME') . " sur " . Env::get('DB_HOST') . ".\nRelancez avec --yes pour confirmer.\n");
}
$pdo = Database::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS test_seed (entity VARCHAR(40) NOT NULL, id BIGINT UNSIGNED NOT NULL, PRIMARY KEY (entity, id)) ENGINE=InnoDB');
$uploads = $root . '/storage/uploads';

if (isset($opts['purge'])) {
    purge($pdo, $uploads);
    exit(0);
}

if ($pdo->query('SELECT COUNT(*) FROM test_seed')->fetchColumn()) {
    exit("Des données de test existent déjà : lancez d'abord --purge --yes.\n");
}
if (!isset($opts['allow-existing']) && $pdo->query('SELECT COUNT(*) FROM mails')->fetchColumn()) {
    exit("La base contient déjà des courriers : les références de test s'y mêleraient. Option --allow-existing pour forcer.\n");
}
$dg = $pdo->query("SELECT id FROM units WHERE code = 'DG'")->fetchColumn();
if (!$dg) {
    exit("Unité DG introuvable : importez d'abord database/install-vps.sql.\n");
}

mt_srand(2026);
$now = time();
$start = strtotime('2026-01-02 08:00:00');
$end = min($now, strtotime('2026-12-31 17:00:00'));
$nbIn = max(10, (int) ($opts['mails'] ?? 400));

$track = function (string $entity, int $id) use ($pdo): void {
    $pdo->prepare('INSERT INTO test_seed (entity, id) VALUES (?, ?)')->execute([$entity, $id]);
};
$pick = fn (array $a) => $a[array_rand($a)];
$chance = fn (float $p): bool => mt_rand() / mt_getrandmax() < $p;
$at = fn (int $ts): string => date('Y-m-d H:i:s', $ts);
// Heure ouvrée suivante : les agents ne travaillent ni la nuit ni le week-end
$work = function (int $ts): int {
    for ($i = 0; $i < 10; $i++) {
        $h = (int) date('G', $ts);
        $w = (int) date('N', $ts);
        if ($w >= 6) { $ts = strtotime(date('Y-m-d 08:00:00', $ts) . ' +' . (8 - $w) . ' day') + mt_rand(0, 3600); continue; }
        if ($h < 8) { $ts = strtotime(date('Y-m-d 08:00:00', $ts)) + mt_rand(0, 5400); continue; }
        if ($h >= 17) { $ts = strtotime(date('Y-m-d 08:00:00', $ts) . ' +1 day') + mt_rand(0, 5400); continue; }
        return $ts;
    }
    return $ts;
};
$later = fn (int $ts, float $minDays, float $maxDays): int => $work($ts + (int) (($minDays + (mt_rand() / mt_getrandmax()) * ($maxDays - $minDays)) * 86400));

$pdo->beginTransaction();
try {
    // ---------- Organigramme de test (codes TST-…) ----------
    $units = [];
    $unit = function (?int $parent, string $kind, string $code, string $name, int $center = 0) use ($pdo, $track, &$units): int {
        $pdo->prepare('INSERT INTO units (parent_id, kind, code, name, is_mail_center) VALUES (?,?,?,?,?)')->execute([$parent, $kind, 'TST-' . $code, $name, $center]);
        $id = (int) $pdo->lastInsertId();
        $track('unit', $id);
        return $units[$code] = $id;
    };
    $unit((int) $dg, 'service', 'COUR', 'Service courrier central', 1);
    $sd = [
        'SDAF' => ['Sous-direction Administrative et Financière', ['RH' => 'Service des Ressources Humaines', 'FIN' => 'Service Financier']],
        'SDT' => ['Sous-direction Technique', ['TARIF' => 'Bureau Tarif et Valeur', 'ORIG' => 'Bureau Origine']],
        'SDCX' => ['Sous-direction du Contentieux', ['LIT' => 'Service des Litiges', 'REC' => 'Service du Recouvrement']],
        'SDRE' => ['Sous-direction des Régimes Économiques', ['ENT' => 'Bureau Entrepôts', 'TRA' => 'Bureau Transit']],
    ];
    foreach ($sd as $code => [$name, $children]) {
        $parent = $unit((int) $dg, 'sous_direction', $code, $name);
        foreach ($children as $c => $n) {
            $unit($parent, $c === 'RH' || $c === 'FIN' || $c === 'LIT' || $c === 'REC' ? 'service' : 'bureau', $c, $n);
        }
    }
    $unitName = array_column($pdo->query('SELECT id, name FROM units')->fetchAll(), 'name', 'id');

    // ---------- Comptes de test ----------
    $hash = password_hash('Lettie-Test-2026', PASSWORD_DEFAULT);
    $users = [];
    $user = function (string $name, string $login, string $role, string $unitCode, int $clearance) use ($pdo, $track, $hash, $units, &$users): void {
        $pdo->prepare('INSERT INTO users (name, email, password_hash, role, unit_id, clearance) VALUES (?,?,?,?,?,?)')
            ->execute([$name, $login . '@test.lettie.local', $hash, $role, $units[$unitCode], $clearance]);
        $id = (int) $pdo->lastInsertId();
        $track('user', $id);
        $users[$login] = ['id' => $id, 'name' => $name, 'unit' => $units[$unitCode]];
    };
    $user('Agnès Mukendi', 'courrier', 'central', 'COUR', 1);
    $user('Patrick Ilunga', 'courrier2', 'central', 'COUR', 1);
    $user('Chantal Kabeya', 'sdaf', 'chief', 'SDAF', 2);
    $user('Didier Mwamba', 'sdt', 'chief', 'SDT', 1);
    $user('Josée Tshibangu', 'sdcx', 'chief', 'SDCX', 2);
    $user('Serge Kalala', 'sdre', 'chief', 'SDRE', 1);
    $user('Grâce Nsimba', 'agent.rh', 'agent', 'RH', 0);
    $user('Fabrice Kasongo', 'agent.tarif', 'agent', 'TARIF', 1);
    $user('Ruth Mbuyi', 'agent.lit', 'agent', 'LIT', 1);
    $user('Olivier Banza', 'agent.tra', 'agent', 'TRA', 0);
    $user('Marie Lukusa', 'audit', 'auditor', 'COUR', 2);
    $chiefOf = ['SDAF' => 'sdaf', 'SDT' => 'sdt', 'SDCX' => 'sdcx', 'SDRE' => 'sdre'];
    $agentOf = ['SDAF' => 'agent.rh', 'SDT' => 'agent.tarif', 'SDCX' => 'agent.lit', 'SDRE' => 'agent.tra'];

    // ---------- Correspondants fictifs ----------
    $corrRows = [
        ['Ministère des Finances', 'administration', 'secretariat@finances-test.cd'], ['Banque Centrale du Congo', 'administration', null],
        ['OGEFREM', 'administration', 'courrier@ogefrem-test.cd'], ['Direction Générale des Impôts', 'administration', null],
        ['Gouvernorat du Haut-Katanga', 'administration', 'cabinet@hautkatanga-test.cd'], ['Office Congolais de Contrôle', 'administration', null],
        ['Katanga Mining Logistics SARL', 'company', 'direction@kml-test.cd'], ['Lubumbashi Transit Services', 'company', 'contact@lts-test.cd'],
        ['Copperbelt Freight SA', 'company', 'douane@cbfreight-test.cd'], ['Kasumbalesa Logistique SARL', 'company', null],
        ['Grands Lacs Import-Export', 'company', 'info@glie-test.cd'], ['Société Minière du Lualaba', 'company', 'juridique@sml-test.cd'],
        ['Afritrans Congo SARL', 'company', null], ['Kolwezi Cargo SARL', 'company', 'operations@kolwezicargo-test.cd'],
        ['Brasseries du Katanga', 'company', 'achats@brasseries-test.cd'], ['Pharmacie Centrale de Lubumbashi', 'company', null],
        ['Cimenterie du Sud SA', 'company', 'admin@cimsud-test.cd'], ['Agro-Industries Kipushi', 'company', null],
        ['Cabinet Maître Kabongo', 'other', 'cabinet@kabongo-avocats-test.cd'], ['Chambre de Commerce du Katanga', 'other', 'secretariat@cck-test.cd'],
        ['Jean-Pierre Mutombo', 'person', null], ['Esther Kanku', 'person', 'e.kanku@mail-test.cd'],
        ['Albert Lumbala', 'person', null], ['Sylvie Ngoy', 'person', 'sylvie.ngoy@mail-test.cd'],
    ];
    $corrs = [];
    foreach ($corrRows as [$name, $kind, $email]) {
        $pdo->prepare('INSERT INTO correspondents (name, kind, email, address) VALUES (?,?,?,?)')->execute([$name, $kind, $email, 'Lubumbashi, Haut-Katanga']);
        $id = (int) $pdo->lastInsertId();
        $track('correspondent', $id);
        $corrs[] = ['id' => $id, 'name' => $name, 'email' => $email];
    }

    // ---------- Courriers ----------
    $subjects = [
        'SDT' => ['Demande de révision de la valeur en douane — déclaration %s', 'Réclamation sur le classement tarifaire de marchandises importées', 'Demande d\'avis de classement pour équipements miniers', 'Contestation de la valeur transactionnelle déclarée', 'Demande d\'attestation d\'origine pour exportation de cuivre'],
        'SDCX' => ['Recours contre le procès-verbal de constatation n° %s', 'Demande de mainlevée de marchandises saisies', 'Mise en demeure de paiement des droits éludés', 'Proposition de transaction sur infraction douanière', 'Demande de remise gracieuse de pénalités'],
        'SDRE' => ['Demande d\'agrément d\'entrepôt sous douane', 'Prorogation du régime d\'admission temporaire', 'Demande d\'apurement du transit n° %s', 'Ouverture d\'un magasin sous douane à Kasumbalesa', 'Régularisation d\'un transit en souffrance'],
        'SDAF' => ['Demande de stage au sein de la DGDA', 'Transmission des états financiers du trimestre', 'Demande de mise à disposition de personnel', 'Invitation à une réunion de concertation', 'Facture de prestation de services informatiques', 'Demande de remboursement de frais de mission'],
    ];
    $instructions = ['Pour examen et proposition de réponse', 'Traiter en priorité', 'Pour suite à donner', 'Vérifier le dossier et me faire rapport', 'Pour avis technique sous huitaine', 'Répondre au correspondant'];
    $closeNotes = ['Classé sans suite : demande retirée par le correspondant', 'Traité par réunion, sans réponse écrite', 'Doublon d\'un courrier déjà traité', 'Pour information, aucune suite à donner'];

    $mails = []; // [direction, ts, data…] avant attribution des références
    for ($i = 0; $i < $nbIn; $i++) {
        $ts = $work(mt_rand($start, $end - 3600));
        $sdCode = $pick(array_keys($sd));
        $corr = $pick($corrs);
        $central = $chance(0.7);
        $mails[] = [
            'direction' => 'in', 'ts' => $ts, 'sd' => $sdCode, 'corr' => $corr, 'central' => $central,
            'subject' => sprintf($pick($subjects[$sdCode]), mt_rand(1000, 9999) . '/2026'),
            'priority' => $chance(0.12) ? 'very_urgent' : ($chance(0.22) ? 'urgent' : 'normal'),
            'level' => $chance(0.04) ? 2 : ($chance(0.1) ? 1 : 0),
            'channel' => $pick(['hand', 'hand', 'post', 'email', 'courier']),
            'external_ref' => $chance(0.75) ? strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $corr['name']), 0, 3)) . '/' . mt_rand(10, 999) . '/26' : null,
        ];
    }
    usort($mails, fn ($a, $b) => $a['ts'] <=> $b['ts']);

    $seq = ['in' => 0, 'out' => 0];
    $org = (string) Env::get('ORG_SHORT', 'DGDA');
    $insertMail = $pdo->prepare('INSERT INTO mails (direction, reference, track_token, parent_id, correspondent_id, external_ref, letter_date, event_at, channel, subject, summary,
        priority, confidentiality, entry_point, registered_unit_id, unit_id, due_on, status, ack_at, ack_channel, ack_by, sent_at, closed_at, closed_by, close_note, created_by, created_at, updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $insertEvent = $pdo->prepare('INSERT INTO mail_events (mail_id, action, detail, user_id, user_name, unit_name, ip, created_at, prev_hash, hash) VALUES (?,?,?,?,?,?,?,?,?,?)');
    $insertAssign = $pdo->prepare('INSERT INTO mail_assignments (mail_id, kind, from_unit_id, to_unit_id, instruction, due_on, assigned_by, created_at) VALUES (?,?,?,?,?,?,?,?)');
    $insertDoc = $pdo->prepare('INSERT INTO mail_documents (mail_id, kind, original_name, stored_name, mime, size, sha256, uploaded_by, created_at) VALUES (?,?,?,?,?,?,?,?,?)');

    // Les chaînes sont écrites à la fin, dans l'ordre chronologique global (ids croissants = temps croissant)
    $events = [];
    $event = function (int $mailId, int $ts, string $action, ?string $detail, array $u) use (&$events, $unitName): void {
        $events[] = [$ts, $mailId, $action, $detail, $u['id'], $u['name'], $unitName[$u['unit']]];
    };
    $doc = function (int $mailId, string $kind, string $name, string $reference, string $title, int $ts, array $u) use ($insertDoc, $uploads, $track, $pdo, $event): void {
        $bytes = pdf($title, $reference);
        $sub = date('Y/m', $ts);
        if (!is_dir("$uploads/$sub") && !mkdir("$uploads/$sub", 0750, true) && !is_dir("$uploads/$sub")) {
            throw new RuntimeException("Dossier $uploads/$sub non accessible en écriture");
        }
        $stored = $sub . '/test-' . bin2hex(random_bytes(12)) . '.pdf';
        file_put_contents("$uploads/$stored", $bytes);
        $insertDoc->execute([$mailId, $kind, $name, $stored, 'application/pdf', strlen($bytes), hash('sha256', $bytes), $u['id'], date('Y-m-d H:i:s', $ts)]);
        $track('document', (int) $pdo->lastInsertId());
        $event($mailId, $ts, 'document_added', t_doc($kind) . ' — ' . $name, $u);
    };
    $ref = function (string $direction, int $ts) use (&$seq, $org): string {
        return MailReference::format($org, $direction, (int) date('Y', $ts), ++$seq[$direction]);
    };

    // Les sortants sont numérotés à leur date : on planifie tout, puis on numérote par ordre chronologique
    $planned = [];
    foreach ($mails as $m) {
        $sdCode = $m['sd'];
        $chief = $users[$chiefOf[$sdCode]];
        $agent = $users[$agentOf[$sdCode]];
        $reg = $m['central'] ? $users[$pick(['courrier', 'courrier2'])] : $chief;
        $t = $m['ts'];
        $due = $chance(0.7) ? date('Y-m-d', $t + 86400 * $pick([7, 15, 15, 30])) : null;
        $p = $m + ['reg' => $reg, 'chief' => $chief, 'agent' => $agent, 'due' => $due, 'steps' => []];
        $p['letter_date'] = date('Y-m-d', $t - 86400 * mt_rand(1, 12));
        // Déroulé simulé, arrêté à aujourd'hui : le statut découle de ce qui a eu le temps d'arriver
        $s = &$p['steps'];
        $s['registered'] = $t;
        if ($chance(0.85)) { $s['ack'] = $later($t, 0, 2); }
        $s['assigned'] = $later($t, 0.2, 3);
        if ($chance(0.15)) { $s['copy'] = $later($s['assigned'], 0, 1); }
        $s['taken'] = $later($s['assigned'], 0.5, 4);
        if ($chance(0.08)) {
            $s['closed_noreply'] = $later($s['taken'], 2, 20);
        } else {
            $s['reply_drafted'] = $later($s['taken'], 2, 18);
            $s['sent'] = $later($s['reply_drafted'], 0.5, 5);
            if ($chance(0.9)) { $s['closed'] = $later($s['sent'], 1, 15); }
        }
        unset($s);
        $p['steps'] = array_filter($p['steps'], fn ($ts) => $ts <= $now);
        $planned[] = $p;
    }
    $numbering = [];
    foreach ($planned as $k => $p) {
        $numbering[] = ['in', $p['ts'], $k];
        if (isset($p['steps']['reply_drafted'])) {
            $numbering[] = ['out', $p['steps']['reply_drafted'], $k];
        }
    }
    usort($numbering, fn ($a, $b) => $a[1] <=> $b[1]);
    foreach ($numbering as [$dir, $ts, $k]) {
        $planned[$k][$dir === 'in' ? 'ref_in' : 'ref_out'] = $ref($dir, $ts);
    }

    $stats = ['in' => 0, 'out' => 0, 'closed' => 0, 'open' => 0];
    foreach ($planned as $p) {
        $s = $p['steps'];
        $last = max($s);
        $status = match (true) {
            isset($s['closed']) || isset($s['closed_noreply']) => 'closed',
            isset($s['sent']) => 'answered',
            isset($s['taken']) => 'in_progress',
            isset($s['assigned']) => 'assigned',
            default => 'registered',
        };
        $sdUnit = $units[$p['sd']];
        $unitId = isset($s['assigned']) ? $sdUnit : ($p['central'] ? $units['COUR'] : $sdUnit);
        $closedAt = $s['closed'] ?? $s['closed_noreply'] ?? null;
        $closer = $p['chief'];
        $insertMail->execute([
            'in', $p['ref_in'], bin2hex(random_bytes(16)), null, $p['corr']['id'], $p['external_ref'], $p['letter_date'], $at($p['ts']), $p['channel'], $p['subject'],
            $chance(0.6) ? 'Courrier reçu de ' . $p['corr']['name'] . ', à instruire par la ' . $sd[$p['sd']][0] . '.' : null,
            $p['priority'], $p['level'], $p['central'] ? 'central' : 'direct', $p['central'] ? $units['COUR'] : $sdUnit, $unitId, $p['due'], $status,
            isset($s['ack']) ? $at($s['ack']) : null, isset($s['ack']) ? ($p['corr']['email'] && $chance(0.6) ? 'email' : 'print') : null, isset($s['ack']) ? $p['reg']['id'] : null,
            null, $closedAt ? $at($closedAt) : null, $closedAt ? $closer['id'] : null, isset($s['closed_noreply']) ? $pick($closeNotes) : null,
            $p['reg']['id'], $at($p['ts']), $at($last),
        ]);
        $id = (int) $pdo->lastInsertId();
        $track('mail', $id);
        $stats['in']++;
        $stats[$status === 'closed' ? 'closed' : 'open']++;

        $event($id, $p['ts'], 'registered', $p['central'] ? 'Reçu au service courrier central' : 'Reçu directement par l\'unité', $p['reg']);
        $doc($id, 'original', 'Courrier-' . str_replace('/', '-', $p['ref_in']) . '.pdf', $p['ref_in'], $p['subject'], $p['ts'] + 60, $p['reg']);
        if (isset($s['ack'])) {
            $event($id, $s['ack'], 'acknowledged', 'Accusé imprimé et remis', $p['reg']);
            $doc($id, 'ack', 'Accuse-' . str_replace('/', '-', $p['ref_in']) . '.pdf', $p['ref_in'], 'Accusé de réception', $s['ack'] + 30, $p['reg']);
        }
        if (isset($s['assigned'])) {
            $from = $p['central'] ? $units['COUR'] : $sdUnit;
            $who = $p['central'] ? $p['reg'] : $p['chief'];
            $instr = $pick($instructions);
            $insertAssign->execute([$id, 'action', $from, $sdUnit, $instr, $p['due'], $who['id'], $at($s['assigned'])]);
            $track('assignment', (int) $pdo->lastInsertId());
            $event($id, $s['assigned'], 'assigned', $unitName[$sdUnit] . ' — ' . $instr, $who);
        }
        if (isset($s['copy'])) {
            $other = $units[$pick(array_diff(array_keys($sd), [$p['sd']]))];
            $insertAssign->execute([$id, 'copy', $sdUnit, $other, 'Pour information', null, $p['chief']['id'], $at($s['copy'])]);
            $track('assignment', (int) $pdo->lastInsertId());
            $event($id, $s['copy'], 'copied', $unitName[$other] . ' — Pour information', $p['chief']);
        }
        if (isset($s['taken'])) {
            $event($id, $s['taken'], 'taken', null, $p['agent']);
        }
        if (isset($s['reply_drafted'])) {
            $outStatus = isset($s['closed']) ? 'closed' : (isset($s['sent']) ? 'sent' : 'draft');
            $outLast = max(array_intersect_key($s, array_flip(['reply_drafted', 'sent', 'closed'])));
            $insertMail->execute([
                'out', $p['ref_out'], bin2hex(random_bytes(16)), $id, $p['corr']['id'], $p['external_ref'], null, $at($s['reply_drafted']), $pick(['hand', 'post', 'email', 'courier']),
                'Réf. ' . $p['ref_in'] . ' — Réponse', null, $p['priority'], $p['level'], $p['central'] ? 'central' : 'direct', $sdUnit, $sdUnit, null, $outStatus,
                null, null, null, isset($s['sent']) ? $at($s['sent']) : null, isset($s['closed']) ? $at($s['closed']) : null, isset($s['closed']) ? $closer['id'] : null, null,
                $p['agent']['id'], $at($s['reply_drafted']), $at($outLast),
            ]);
            $out = (int) $pdo->lastInsertId();
            $track('mail', $out);
            $stats['out']++;
            $event($out, $s['reply_drafted'], 'registered', 'Reçu directement par l\'unité', $p['agent']);
            $doc($out, 'reply', 'Reponse-' . str_replace('/', '-', $p['ref_out']) . '.pdf', $p['ref_out'], 'Réponse à ' . $p['ref_in'], $s['reply_drafted'] + 120, $p['agent']);
            $event($id, $s['reply_drafted'] + 180, 'reply_drafted', null, $p['agent']);
            if (isset($s['sent'])) {
                $event($out, $s['sent'], 'sent', 'Remise en main propre', $p['agent']);
                if ($chance(0.8)) {
                    $doc($out, 'proof', 'Decharge-' . str_replace('/', '-', $p['ref_out']) . '.pdf', $p['ref_out'], 'Décharge de réception', $s['sent'] + 300, $p['agent']);
                }
                $event($id, $s['sent'] + 60, 'answered', $p['ref_out'], $p['agent']);
            }
            if (isset($s['closed'])) {
                $event($out, $s['closed'], 'closed', null, $closer);
            }
        }
        if ($closedAt) {
            $event($id, $closedAt + 30, 'closed', isset($s['closed_noreply']) ? 'Clôturé sans réponse' : null, $closer);
        }
    }

    // ---------- Chronologie : ordre global, chaîne par courrier, sceau HMAC ----------
    usort($events, fn ($a, $b) => $a[0] <=> $b[0]);
    $prev = [];
    foreach ($events as [$ts, $mailId, $action, $detail, $uid, $uname, $unitLabel]) {
        $p = $prev[$mailId] ?? EventChain::GENESIS;
        $when = $at($ts);
        $hash = EventChain::seal($key, $p, $mailId, $action, $detail, $uname, $when, $uid, $unitLabel, null);
        $insertEvent->execute([$mailId, $action, $detail, $uid, $uname, $unitLabel, null, $when, $p, $hash]);
        $prev[$mailId] = $hash;
    }
    foreach ($seq as $dir => $n) {
        if ($n) {
            $pdo->prepare('INSERT INTO mail_sequences (direction, year, last_no) VALUES (?, 2026, ?) ON DUPLICATE KEY UPDATE last_no = GREATEST(last_no, VALUES(last_no))')->execute([$dir, $n]);
        }
    }
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    foreach (glob($uploads . '/2026/*/test-*.pdf') ?: [] as $f) {
        @unlink($f);
    }
    fwrite(STDERR, 'Échec, rien n\'a été conservé : ' . $e->getMessage() . "\n");
    exit(1);
}

$sealFrom = (int) Env::get('EVENT_SEAL_FROM', '0');
$firstEvent = (int) $pdo->query("SELECT MIN(e.id) FROM mail_events e JOIN test_seed t ON t.entity = 'mail' AND t.id = e.mail_id")->fetchColumn();
printf("Créés : %d courriers entrants (%d en cours, %d clôturés), %d sortants, %d événements.\n", $stats['in'], $stats['open'], $stats['closed'], $stats['out'], count($events));
echo "Comptes de test : courrier, sdaf, sdt, sdcx, sdre, agent.rh, agent.tarif, agent.lit, agent.tra, audit @test.lettie.local / Lettie-Test-2026\n";
if ($firstEvent < $sealFrom) {
    echo "ATTENTION : EVENT_SEAL_FROM ($sealFrom) dépasse les nouveaux événements : leurs chronologies s'afficheront « altérées ».\n";
}
echo "Pour tout retirer avant la mise en service : php bin/seed-test-2026.php --purge --yes\n";

function purge(PDO $pdo, string $uploads): void
{
    $ids = fn (string $entity) => array_map('intval', $pdo->query("SELECT id FROM test_seed WHERE entity = '$entity'")->fetchAll(PDO::FETCH_COLUMN));
    $in = fn (array $list) => implode(',', $list ?: [0]);
    $mails = $in($ids('mail'));
    $files = $pdo->query("SELECT stored_name FROM mail_documents WHERE mail_id IN ($mails)")->fetchAll(PDO::FETCH_COLUMN);
    // La chronologie refuse toute suppression : les déclencheurs sont levés le temps de la purge, puis remis
    $pdo->exec('DROP TRIGGER IF EXISTS mail_events_no_update');
    $pdo->exec('DROP TRIGGER IF EXISTS mail_events_no_delete');
    try {
        $pdo->beginTransaction();
        $pdo->exec("DELETE FROM mail_events WHERE mail_id IN ($mails)");
        $pdo->exec("DELETE FROM email_log WHERE mail_id IN ($mails)");
        $pdo->exec("DELETE FROM mail_documents WHERE mail_id IN ($mails)");
        $pdo->exec("DELETE FROM mail_assignments WHERE mail_id IN ($mails)");
        $pdo->exec("DELETE FROM mails WHERE parent_id IN ($mails)");
        $pdo->exec("DELETE FROM mails WHERE id IN ($mails)");
        $pdo->exec('DELETE FROM correspondents WHERE id IN (' . $in($ids('correspondent')) . ')');
        $users = $in($ids('user'));
        $pdo->exec("DELETE FROM login_attempts WHERE email LIKE '%@test.lettie.local'");
        $pdo->exec("UPDATE mail_settings SET updated_by = NULL WHERE updated_by IN ($users)");
        $pdo->exec("DELETE FROM activity_log WHERE user_id IN ($users)");
        $pdo->exec("DELETE FROM users WHERE id IN ($users)");
        // Unités : les plus profondes d'abord
        $pdo->exec('DELETE FROM units WHERE id IN (' . $in($ids('unit')) . ') AND parent_id IN (' . $in($ids('unit')) . ')');
        $pdo->exec('DELETE FROM units WHERE id IN (' . $in($ids('unit')) . ')');
        // Numérotation : reprend après le dernier courrier restant
        foreach (['in' => 'ARR', 'out' => 'DEP'] as $dir => $tag) {
            $last = (int) $pdo->query("SELECT COALESCE(MAX(CAST(SUBSTRING_INDEX(reference, '/', -1) AS UNSIGNED)), 0) FROM mails WHERE direction = '$dir' AND reference LIKE '%/$tag/2026/%'")->fetchColumn();
            $pdo->prepare('UPDATE mail_sequences SET last_no = ? WHERE direction = ? AND year = 2026')->execute([$last, $dir]);
        }
        $pdo->commit();
        // DDL : valide implicitement, donc après le commit
        $pdo->exec('DROP TABLE test_seed');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        fwrite(STDERR, 'Purge annulée : ' . $e->getMessage() . "\n");
    } finally {
        $pdo->exec("CREATE TRIGGER mail_events_no_update BEFORE UPDATE ON mail_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mail_events is append-only'");
        $pdo->exec("CREATE TRIGGER mail_events_no_delete BEFORE DELETE ON mail_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'mail_events is append-only'");
    }
    $n = 0;
    foreach ($files as $f) {
        if (str_contains($f, '/test-') && @unlink($uploads . '/' . $f)) {
            $n++;
        }
    }
    echo "Données de test supprimées ($n fichiers). Protections de la chronologie remises en place.\n";
}

function t_doc(string $kind): string
{
    return ['original' => 'Courrier', 'ack' => 'Accusé de réception', 'reply' => 'Réponse officielle', 'proof' => 'Preuve d\'envoi'][$kind] ?? $kind;
}

/** PDF d'une page, sans bibliothèque : de quoi ouvrir et prévisualiser une pièce de test. */
function pdf(string $title, string $reference): string
{
    $esc = fn (string $s) => str_replace(['\\', '(', ')'], ['\\\\', '\\(', '\\)'], (string) iconv('UTF-8', 'Windows-1252//TRANSLIT', $s));
    $text = "BT /F1 18 Tf 60 760 Td (" . $esc('DOCUMENT DE TEST — Lettie-DGDA') . ") Tj ET\n"
        . "BT /F1 13 Tf 60 720 Td (" . $esc($reference) . ") Tj ET\n"
        . "BT /F1 11 Tf 60 690 Td (" . $esc(mb_substr($title, 0, 90)) . ") Tj ET\n";
    $objs = [
        '<< /Type /Catalog /Pages 2 0 R >>',
        '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
        '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >>',
        '<< /Length ' . strlen($text) . " >>\nstream\n" . $text . 'endstream',
        '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>',
    ];
    $out = "%PDF-1.4\n";
    $offsets = [];
    foreach ($objs as $i => $o) {
        $offsets[] = strlen($out);
        $out .= ($i + 1) . " 0 obj\n$o\nendobj\n";
    }
    $xref = strlen($out);
    $out .= "xref\n0 " . (count($objs) + 1) . "\n0000000000 65535 f \n";
    foreach ($offsets as $o) {
        $out .= sprintf("%010d 00000 n \n", $o);
    }
    return $out . "trailer\n<< /Size " . (count($objs) + 1) . " /Root 1 0 R >>\nstartxref\n$xref\n%%EOF";
}
