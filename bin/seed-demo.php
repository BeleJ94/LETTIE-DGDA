<?php

declare(strict_types=1);

// Usage : php bin/seed-demo.php — organisation et comptes de DÉMONSTRATION sur une base vide. Mot de passe commun : Lettie-Demo-2026
// Les unités sont indicatives : l'organigramme réel de la DGDA reste à saisir dans « Organisation ».

use App\Core\Database;
use App\Core\Env;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
Env::load($root . '/.env');
$pdo = Database::pdo();
if ($pdo->query('SELECT COUNT(*) FROM units')->fetchColumn()) {
    fwrite(STDERR, "Base déjà initialisée : rien n'a été fait.\n");
    exit(1);
}

$unit = function (?int $parent, string $kind, string $code, string $name, int $center = 0) use ($pdo): int {
    $pdo->prepare('INSERT INTO units (parent_id, kind, code, name, is_mail_center) VALUES (?,?,?,?,?)')->execute([$parent, $kind, $code, $name, $center]);
    return (int) $pdo->lastInsertId();
};
$dg = $unit(null, 'direction', 'DG', 'Direction Générale');
$central = $unit($dg, 'service', 'COUR', 'Service courrier central', 1);
$sdaf = $unit($dg, 'sous_direction', 'SDAF', 'Sous-direction Administrative et Financière');
$sdrh = $unit($sdaf, 'service', 'RH', 'Service des Ressources Humaines');
$sdt = $unit($dg, 'sous_direction', 'SDT', 'Sous-direction Technique');
$unit($sdt, 'bureau', 'TARIF', 'Bureau Tarif et Valeur');

$hash = password_hash('Lettie-Demo-2026', PASSWORD_DEFAULT);
$user = $pdo->prepare('INSERT INTO users (name, email, password_hash, role, unit_id, clearance) VALUES (?,?,?,?,?,?)');
foreach ([
    ['Administrateur', 'admin@lettie.local', 'admin', $dg, 2],
    ['Agent Courrier', 'courrier@lettie.local', 'central', $central, 1],
    ['Chef SDAF', 'sdaf@lettie.local', 'chief', $sdaf, 2],
    ['Agent RH', 'rh@lettie.local', 'agent', $sdrh, 0],
    ['Chef SDT', 'sdt@lettie.local', 'chief', $sdt, 1],
    ['Auditeur', 'audit@lettie.local', 'auditor', $dg, 2],
] as $u) {
    $user->execute([$u[0], $u[1], $hash, $u[2], $u[3], $u[4]]);
}
$c = $pdo->prepare('INSERT INTO correspondents (name, kind, email) VALUES (?,?,?)');
foreach ([['Ministère des Finances', 'administration', null], ['Entreprise de démonstration SARL', 'company', 'contact@example.com'], ['Particulier de démonstration', 'person', null]] as $row) {
    $c->execute($row);
}
echo "Démo prête : admin@lettie.local / Lettie-Demo-2026\n";
