<?php
/** Accusé de réception A4 remis à l'expéditeur. @var array $m @var string $qr */
use App\Core\Env;
?>
<article class="doc">
    <header class="doc__head">
        <div class="doc__company"><div><?= org_logo('doc__logo') ?><p><?= e(Env::get('ORG_CITY')) ?></p></div></div>
        <div class="doc__title"><h1><?= e(t('ack.title')) ?></h1><p><strong><?= e($m['reference']) ?></strong></p></div>
    </header>
    <p><?= e($m['correspondent']) ?><br><?= e($m['correspondent_address'] ?? '') ?></p>
    <p><?= e(t('ack.body', ['org' => (string) Env::get('ORG_NAME', 'DGDA')])) ?></p>
    <p><strong><?= e(t('mail.col.reference')) ?> :</strong> <?= e($m['reference']) ?></p>
    <p><strong><?= e(t('mail.field.event_at')) ?> :</strong> <?= e(fmt_date($m['event_at'], true)) ?></p>
    <p><strong><?= e(t('mail.field.external_ref')) ?> :</strong> <?= e($m['external_ref'] ?: '—') ?></p>
    <p><strong><?= e(t('mail.field.subject')) ?> :</strong> <?= (int) $m['confidentiality'] > 0 ? e(t('ack.subject_hidden')) : e($m['subject']) ?></p>
    <div style="display:flex;justify-content:space-between;margin-top:18mm">
        <div style="width:36mm"><?= $qr ?><p style="font-size:8pt"><?= e(t('ack.scan')) ?></p></div>
        <p><?= e(t('ack.stamp')) ?></p>
    </div>
</article>
