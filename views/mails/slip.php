<?php
/** Fiche de circulation A4 : accompagne le courrier papier. @var array $m @var string $qr @var array $assignments */
use App\Core\Env;
use App\Domain\MailAccess;
?>
<article class="doc">
    <header class="doc__head">
        <div class="doc__company"><div><?= org_logo('doc__logo') ?><p><?= e(Env::get('ORG_CITY')) ?></p></div></div>
        <div class="doc__title"><h1><?= e(t('slip.title')) ?></h1><p><strong><?= e($m['reference']) ?></strong></p><p><?= e(t('mail.level.' . MailAccess::LEVELS[(int) $m['confidentiality']])) ?> · <?= e(t('mail.priority.' . $m['priority'])) ?></p></div>
    </header>
    <div style="display:flex;gap:18px;align-items:flex-start;margin-bottom:16px">
        <div style="flex:1">
            <p><strong><?= e(t('mail.field.subject')) ?> :</strong> <?= e($m['subject']) ?></p>
            <p><strong><?= e(t('mail.col.correspondent')) ?> :</strong> <?= e($m['correspondent']) ?></p>
            <p><strong><?= e(t('mail.field.external_ref')) ?> :</strong> <?= e($m['external_ref'] ?: '—') ?></p>
            <p><strong><?= e(t('mail.field.event_at')) ?> :</strong> <?= e(fmt_date($m['event_at'], true)) ?></p>
            <p><strong><?= e(t('mail.received_by')) ?> :</strong> <?= e($m['registered_unit']) ?></p>
            <p><strong><?= e(t('mail.field.due_on')) ?> :</strong> <?= e(fmt_date($m['due_on'])) ?></p>
        </div>
        <div style="width:34mm"><?= $qr ?></div>
    </div>
    <h2 style="font-size:12pt"><?= e(t('slip.instructions')) ?></h2>
    <table style="width:100%;border-collapse:collapse" border="1" cellpadding="6">
        <thead><tr><th><?= e(t('mail.col.date')) ?></th><th><?= e(t('mail.assign.to')) ?></th><th><?= e(t('mail.assign.instruction')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($assignments as $a): ?>
            <tr><td><?= e(fmt_date($a['created_at'])) ?></td><td><?= e($a['to_unit']) ?></td><td><?= e($a['instruction'] ?: '') ?></td></tr>
        <?php endforeach ?>
        <?php for ($i = count($assignments); $i < 5; $i++): ?><tr><td style="height:12mm"></td><td></td><td></td></tr><?php endfor ?>
        </tbody>
    </table>
</article>
