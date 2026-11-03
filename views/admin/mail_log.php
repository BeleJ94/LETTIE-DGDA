<?php
/** @var array $rows @var array $stats @var string $status */
use App\Core\View;
use App\Security\Auth;

$actions = Auth::require()->can('mail.settings') ? '<a class="btn" href="' . e(url('/settings/mail')) . '">' . icon('settings') . ' ' . e(t('nav.mail_settings')) . '</a>' : '';
echo View::partial('partials/page_header', ['icon' => 'mail-check', 'heading' => t('nav.mail_log'), 'meta' => t('maillog.meta'), 'actions' => $actions]);
?>
<div class="kpis kpis--compact">
    <a class="kpi" href="<?= e(url('/mail-log')) ?>"><span class="kpi__label"><?= e(t('maillog.total')) ?></span><span class="kpi__value"><?= (int) $stats['total'] ?></span></a>
    <a class="kpi" href="<?= e(url('/mail-log', ['status' => 'sent'])) ?>"><span class="kpi__label"><?= e(t('maillog.status.sent')) ?></span><span class="kpi__value"><?= (int) $stats['sent'] ?></span></a>
    <a class="kpi<?= (int) $stats['failed'] > 0 ? ' kpi--alert' : '' ?>" href="<?= e(url('/mail-log', ['status' => 'failed'])) ?>"><span class="kpi__label"><?= e(t('maillog.status.failed')) ?></span><span class="kpi__value"><?= (int) $stats['failed'] ?></span></a>
</div>
<section class="card">
    <?php if (!$rows): ?>
        <p class="empty"><?= e(t('maillog.empty')) ?></p>
    <?php else: ?>
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= e(t('activity.col.date')) ?></th><th><?= e(t('maillog.col.status')) ?></th><th><?= e(t('maillog.col.kind')) ?></th><th><?= e(t('maillog.col.recipient')) ?></th><th><?= e(t('mail.col.reference')) ?></th><th><?= e(t('activity.col.user')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e(fmt_date($r['created_at'], true)) ?></td>
                <td><span class="badge badge--<?= $r['status'] === 'sent' ? 'available' : 'breakdown' ?>"><?= e(t('maillog.status.' . $r['status'])) ?></span>
                    <?php if ($r['error']): ?><br><span class="muted"><?= e($r['error']) ?></span><?php endif ?></td>
                <td><?= e(t('maillog.kind.' . $r['kind'])) ?></td>
                <td><?= e($r['recipient']) ?></td>
                <td><?php if ($r['mail_id']): ?><a href="<?= e(url('/mails/' . (int) $r['mail_id'])) ?>"><?= e($r['reference']) ?></a><?php else: ?>—<?php endif ?></td>
                <td><?= e($r['user_name'] ?? '—') ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
    <?php endif ?>
</section>
