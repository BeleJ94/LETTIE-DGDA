<?php
/** Copie PDF de l'accusé, versée au dossier et jointe à l'e-mail. @var array $m @var string $qr */
use App\Core\Env;
?><!doctype html>
<html lang="fr"><head><meta charset="utf-8">
<style>
    body { font-family: DejaVu Sans, sans-serif; font-size: 11pt; color: #1a1a1a; }
    h1 { font-size: 16pt; margin: 24pt 0 4pt; }
    .org { font-size: 10pt; color: #555; }
    .logo { width: 70mm; }
    .qr { width: 32mm; height: 32mm; }
    .small { font-size: 8pt; color: #555; }
    td { vertical-align: top; }
</style></head>
<body>
    <img class="logo" src="data:image/png;base64,<?= base64_encode((string) file_get_contents(dirname(__DIR__, 2) . '/public/assets/img/dgda-logo.png')) ?>" alt="">
    <div class="org"><?= e(Env::get('ORG_CITY')) ?></div>
    <h1><?= e(t('ack.title')) ?></h1>
    <p><strong><?= e($m['reference']) ?></strong></p>
    <p><?= e($m['correspondent']) ?><br><?= e($m['correspondent_address'] ?? '') ?></p>
    <p><?= e(t('ack.body', ['org' => (string) Env::get('ORG_NAME', 'DGDA')])) ?></p>
    <p><strong><?= e(t('mail.field.event_at')) ?> :</strong> <?= e(fmt_date($m['event_at'], true)) ?></p>
    <p><strong><?= e(t('mail.field.external_ref')) ?> :</strong> <?= e($m['external_ref'] ?: '—') ?></p>
    <p><strong><?= e(t('mail.field.subject')) ?> :</strong> <?= (int) $m['confidentiality'] > 0 ? e(t('ack.subject_hidden')) : e($m['subject']) ?></p>
    <table style="width:100%;margin-top:18mm"><tr>
        <td><img class="qr" src="<?= e($qr) ?>" alt=""><div class="small"><?= e(t('ack.scan')) ?></div></td>
        <td style="text-align:right"><?= e(t('ack.stamp')) ?></td>
    </tr></table>
</body></html>
