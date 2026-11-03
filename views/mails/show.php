<?php
/**
 * Dossier électronique d'une correspondance : fiche, pièces, imputations et chronologie.
 * Seules les actions possibles au statut courant sont proposées.
 * @var array $m @var array $family @var array $documents @var array $events @var bool $chainOk @var array $assignments @var array $units @var bool $canAct
 */
use App\Core\View;
use App\Domain\MailAccess;
use App\Domain\MailWorkflow;
use App\Security\Auth;

$user = Auth::require();
$id = (int) $m['id'];
$in = $m['direction'] === 'in';
$open = !MailWorkflow::isFinal($m['status']);
$hasProof = in_array('proof', array_column(array_filter($documents, fn ($d) => (int) $d['mail_id'] === $id), 'kind'), true);
$modal = fn (string $tpl, string $ic, string $label, string $class = 'btn') => '<button type="button" class="' . $class . '" data-open="#' . $tpl . '" data-title="' . e($label) . '">' . icon($ic) . ' ' . e($label) . '</button>';
$level = MailAccess::LEVELS[(int) $m['confidentiality']];

// Parcours du dossier : ce qui est fait, ce qui reste
$status = $m['status'];
$kinds = array_column($documents, 'kind');
$past = fn (string ...$s) => in_array($status, $s, true);
$steps = $in ? [
    ['mail.step.received', true],
    ['mail.step.ack', (bool) $m['ack_at']],
    ['mail.step.assigned', (bool) $assignments || $past('assigned', 'in_progress', 'answered', 'closed')],
    ['mail.step.processing', $past('in_progress', 'answered', 'closed')],
    ['mail.step.answered', $past('answered') || ($past('closed') && in_array('reply', $kinds, true))],
    ['mail.step.closed', $past('closed')],
] : [
    ['mail.step.drafted', true],
    ['mail.step.sent', $past('sent', 'closed')],
    ['mail.step.proof', $hasProof],
    ['mail.step.closed', $past('closed')],
];
$current = null;
foreach ($steps as $i => [, $done]) {
    if (!$done) { $current = $i; break; }
}
// Une seule action mise en avant : celle qui fait avancer le dossier
$next = null;
if ($canAct && $open) {
    $hasReply = in_array('reply', array_column(array_filter($documents, fn ($d) => (int) $d['mail_id'] === $id), 'kind'), true);
    $next = match (true) {
        $in && !$m['ack_at'] && $user->can('mail.register') => ['ack', $modal('tpl-ack', 'mail-check', t('mail.action.ack'), 'btn btn--primary')],
        $status === 'registered' && $user->can('mail.assign') => ['assign', $modal('tpl-assign', 'forward', t('mail.action.assign'), 'btn btn--primary')],
        $past('registered', 'assigned', 'in_progress') && $user->can('mail.reply') => ['reply', $modal('tpl-reply', 'reply', t('mail.action.reply'), 'btn btn--primary')],
        $status === 'answered' && $user->can('mail.close') => ['close', $modal('tpl-close', 'archive', t('mail.action.close'), 'btn btn--primary')],
        $status === 'draft' && !$hasReply && $user->can('mail.reply') => ['add_reply', $modal('tpl-doc', 'paperclip', t('mail.action.add_doc'), 'btn btn--primary')],
        $status === 'draft' && $user->can('mail.reply') => ['send', $modal('tpl-send', 'send', t('mail.action.send'), 'btn btn--primary')],
        $status === 'sent' && !$hasProof && $user->can('mail.reply') => ['add_proof', $modal('tpl-doc', 'paperclip', t('mail.action.add_doc'), 'btn btn--primary')],
        $status === 'sent' && $user->can('mail.close') => ['close_out', $modal('tpl-close', 'archive', t('mail.action.close'), 'btn btn--primary')],
        default => null,
    };
}
$checklist = $in ? [['original', 'mail.doc.original'], ['ack', 'mail.doc.ack'], ['reply', 'mail.doc.reply'], ['proof', 'mail.doc.proof']] : [['reply', 'mail.doc.reply'], ['proof', 'mail.doc.proof']];
?>
<section class="hero">
    <div class="hero__icon"><?= icon($in ? 'mail-open' : 'send') ?></div>
    <div class="hero__body">
        <div class="hero__line">
            <h1 class="hero__code"><?= e($m['reference']) ?></h1>
            <?= View::partial('partials/status_badge', ['key' => 'mail.status.' . $m['status'], 'status' => $m['status']]) ?>
            <?php if ($level !== 'normal'): ?><span class="badge badge--breakdown"><?= e(t('mail.level.' . $level)) ?></span><?php endif ?>
            <?php if ($m['priority'] !== 'normal'): ?><span class="badge badge--waiting_part"><?= e(t('mail.priority.' . $m['priority'])) ?></span><?php endif ?>
        </div>
        <?php if ($m['external_ref']): ?><p class="hero__extref"><?= icon('hash') ?> <span><?= e(t('mail.field.external_ref')) ?></span> <strong><?= e($m['external_ref']) ?></strong></p><?php endif ?>
        <p class="hero__name"><?= e($m['subject']) ?></p>
        <p class="hero__tags">
            <span class="tag"><?= icon('contact') ?> <?= e($m['correspondent']) ?></span>
            <span class="tag"><?= icon('network') ?> <?= e($m['unit']) ?></span>
            <span class="tag"><?= icon('calendar-clock') ?> <?= e(fmt_date($m['event_at'], true)) ?></span>
        </p>
    </div>
    <div class="hero__actions">
        <?php if ($in): ?>
            <a class="btn"<?= preview_attrs(url("/mails/$id/slip"), t('mail.action.slip'), null, 'html') ?>><?= icon('qr-code') ?> <?= e(t('mail.action.slip')) ?></a>
        <?php endif ?>
        <?php if ($canAct && $user->can('mail.register')): ?>
            <?php if ($open): ?><a class="btn" href="<?= e(url("/mails/$id/edit")) ?>"><?= icon('pencil') ?> <?= e(t('common.edit')) ?></a><?php endif ?>
            <?php if ($in && !$m['ack_at'] && $open): ?><?= $modal('tpl-ack', 'mail-check', t('mail.action.ack')) ?><?php endif ?>
        <?php endif ?>
        <?php if ($in && $m['ack_at']): ?>
            <a class="btn"<?= preview_attrs(url("/mails/$id/receipt"), t('mail.action.receipt'), null, 'html') ?>><?= icon('printer') ?> <?= e(t('mail.action.receipt')) ?></a>
        <?php endif ?>
        <?php if ($canAct): ?>
            <?php if ($user->can('mail.assign') && $open): ?><?= $modal('tpl-assign', 'forward', t('mail.action.assign')) ?><?php endif ?>
            <?php if ($user->can('mail.reply') && MailWorkflow::can($m['status'], 'in_progress')): ?>
                <form method="post" action="<?= e(url("/mails/$id/take")) ?>" class="inline"><?= csrf_field() ?><button class="btn"><?= icon('hand') ?> <?= e(t('mail.action.take')) ?></button></form>
            <?php endif ?>
            <?php if ($in && $user->can('mail.reply') && in_array($m['status'], ['registered', 'assigned', 'in_progress'], true)): ?>
                <?= $modal('tpl-reply', 'reply', t('mail.action.reply')) ?>
            <?php endif ?>
            <?php if ($user->can('mail.reply') && MailWorkflow::can($m['status'], 'sent')): ?>
                <?= $modal('tpl-send', 'send', t('mail.action.send')) ?>
            <?php endif ?>
            <?php if ($user->can('mail.reply') && MailWorkflow::can($m['status'], 'cancelled')): ?>
                <?= $modal('tpl-cancel', 'x', t('mail.action.cancel')) ?>
            <?php endif ?>
            <?php if ($user->can('mail.close') && MailWorkflow::can($m['status'], 'closed')): ?>
                <?= $modal('tpl-close', 'archive', t('mail.action.close')) ?>
            <?php endif ?>
        <?php endif ?>
    </div>
</section>

<?php if ($status !== 'cancelled'): ?>
<ol class="mail-steps" style="--steps:<?= count($steps) ?>" aria-label="<?= e(t('mail.steps')) ?>">
    <?php foreach ($steps as $i => [$label, $done]): ?>
    <li class="mail-step<?= $done ? ' is-done' : ($i === $current ? ' is-current' : '') ?>"<?= $i === $current ? ' aria-current="step"' : '' ?>><span class="mail-step__dot"><?= $done ? icon('check') : $i + 1 ?></span><span><?= e(t($label)) ?></span></li>
    <?php endforeach ?>
</ol>
<?php endif ?>
<?php if ($next): ?>
<section class="mail-nextstep">
    <span class="mail-nextstep__icon"><?= icon('arrow-right') ?></span>
    <div><strong><?= e(t('mail.next.title')) ?></strong><p><?= e(t('mail.next.' . $next[0])) ?></p></div>
    <?= $next[1] ?>
</section>
<?php elseif ($open && !$canAct): ?>
<div class="alert alert--info"><?= icon('info') ?> <span><?= e(t('mail.next.readonly', ['unit' => $m['unit']])) ?></span></div>
<?php endif ?>
<div class="grid grid--2-1">
    <div>
        <section class="card">
            <div class="card__title">
                <h2><?= icon('folder-open', 'title-icon') ?> <?= e(t('mail.section.docs')) ?></h2>
                <?php if ($canAct && $open && $user->can('mail.reply')): ?><?= $modal('tpl-doc', 'paperclip', t('mail.action.add_doc'), 'btn btn--sm') ?><?php endif ?>
            </div>
            <?php if (!$documents): ?>
                <div class="empty-state"><?= icon('folder-open') ?><p><?= e(t('mail.no_docs')) ?></p></div>
            <?php else: ?>
            <div class="table-wrap"><table class="table table--compact">
                <thead><tr><th><?= e(t('mail.doc.kind')) ?></th><th><?= e(t('mail.doc.file')) ?></th><th><?= e(t('mail.col.reference')) ?></th><th><?= e(t('mail.col.date')) ?></th></tr></thead>
                <tbody>
                <?php foreach ($documents as $d): ?>
                    <tr>
                        <td><?= e(t('mail.doc.' . $d['kind'])) ?></td>
                        <td><a<?= preview_attrs(url('/documents/' . (int) $d['id']), $d['original_name'], $d['mime']) ?>><?= e($d['original_name']) ?></a><br><span class="muted" title="SHA-256 <?= e($d['sha256']) ?>"><?= e($d['uploader']) ?></span></td>
                        <td><?= e($d['reference']) ?></td>
                        <td><?= e(fmt_date($d['created_at'], true)) ?></td>
                    </tr>
                <?php endforeach ?>
                </tbody>
            </table></div>
            <?php endif ?>
        </section>

        <section class="card">
            <div class="card__title"><h2><?= icon('forward', 'title-icon') ?> <?= e(t('mail.section.assignments')) ?></h2></div>
            <?php if (!$assignments): ?>
                <p class="empty"><?= e(t('mail.no_assign')) ?></p>
            <?php else: ?>
            <ul class="doc-list">
                <?php foreach ($assignments as $a): ?>
                <li class="doc-list__item">
                    <div class="doc-list__head"><strong><?= e($a['from_unit']) ?> → <?= e($a['to_unit']) ?></strong><span class="muted"><?= e(t('mail.assign.' . $a['kind'])) ?></span></div>
                    <?php if ($a['instruction']): ?><p><?= e($a['instruction']) ?></p><?php endif ?>
                    <p class="muted"><?= e($a['author']) ?> · <?= e(fmt_date($a['created_at'], true)) ?><?= $a['due_on'] ? ' · ' . e(t('mail.field.due_on')) . ' ' . e(fmt_date($a['due_on'])) : '' ?></p>
                </li>
                <?php endforeach ?>
            </ul>
            <?php endif ?>
        </section>

        <section class="card">
            <div class="card__title"><h2><?= icon('history', 'title-icon') ?> <?= e(t('mail.section.timeline')) ?></h2>
                <span class="badge badge--<?= $chainOk ? 'available' : 'breakdown' ?>"><?= e(t($chainOk ? 'mail.chain_ok' : 'mail.chain_bad')) ?></span></div>
            <ol class="timeline">
                <?php foreach ($events as $ev): ?>
                <li class="timeline__item timeline__item--<?= e($ev['action']) ?>">
                    <strong><?= e(t('mail.event.' . $ev['action'])) ?></strong>
                    <?php if ($ev['detail']): ?><span><?= e($ev['detail']) ?></span><?php endif ?>
                    <span class="timeline__date"><?= e(date('d/m/Y H:i:s', strtotime($ev['created_at']))) ?> · <?= e($ev['user_name']) ?><?= $ev['unit_name'] ? ' · ' . e($ev['unit_name']) : '' ?><?= count($family) > 1 ? ' · ' . e($ev['reference']) : '' ?></span>
                </li>
                <?php endforeach ?>
            </ol>
        </section>
    </div>

    <div>
        <section class="card">
            <div class="card__title"><h2><?= icon('list-checks', 'title-icon') ?> <?= e(t('mail.checklist')) ?></h2></div>
            <ul class="mail-check">
                <?php foreach ($checklist as [$kind, $label]): $has = in_array($kind, $kinds, true); ?>
                <li class="<?= $has ? 'is-ok' : '' ?>"><?= icon($has ? 'circle-check' : 'circle') ?> <?= e(t($label)) ?></li>
                <?php endforeach ?>
            </ul>
        </section>
        <section class="card">
            <div class="card__title"><h2><?= icon('id-card', 'title-icon') ?> <?= e(t('mail.tab.summary')) ?></h2></div>
            <dl class="dl">
                <dt><?= e(t('mail.field.external_ref')) ?></dt><dd><?= e($m['external_ref'] ?: '—') ?></dd>
                <dt><?= e(t('mail.field.letter_date')) ?></dt><dd><?= e(fmt_date($m['letter_date'])) ?></dd>
                <dt><?= e(t('mail.field.channel')) ?></dt><dd><?= e(t('mail.channel.' . $m['channel'])) ?></dd>
                <dt><?= e(t('mail.received_by')) ?></dt><dd><?= e($m['registered_unit']) ?><br><span class="muted"><?= e(t('mail.entry.' . $m['entry_point'])) ?> · <?= e($m['creator']) ?></span></dd>
                <dt><?= e(t('mail.responsible')) ?></dt><dd><?= e($m['unit']) ?></dd>
                <dt><?= e(t('mail.field.due_on')) ?></dt><dd class="<?= MailWorkflow::isLate($m['due_on'], $m['status'], date('Y-m-d')) ? 'text-bad' : '' ?>"><?= e(fmt_date($m['due_on'])) ?></dd>
                <?php if ($in): ?><dt><?= e(t('mail.col.ack')) ?></dt><dd><?= $m['ack_at'] ? e(fmt_date($m['ack_at'], true)) . '<br><span class="muted">' . e(t('mail.ack_channel.' . $m['ack_channel'])) . '</span>' : '—' ?></dd><?php endif ?>
                <?php if ($m['close_note']): ?><dt><?= e(t('mail.close.note')) ?></dt><dd><?= e($m['close_note']) ?></dd><?php endif ?>
            </dl>
            <?php if ($m['summary']): ?><p class="prewrap muted"><?= e($m['summary']) ?></p><?php endif ?>
        </section>
        <?php if (count($family) > 1): ?>
        <section class="card">
            <div class="card__title"><h2><?= icon('link', 'title-icon') ?> <?= e(t('mail.section.family')) ?></h2></div>
            <ul class="doc-list">
                <?php foreach ($family as $f): ?>
                <li class="doc-list__item<?= (int) $f['id'] === $id ? ' doc-list__item--muted' : '' ?>">
                    <div class="doc-list__head"><a href="<?= e(url('/mails/' . (int) $f['id'])) ?>"><strong><?= e($f['reference']) ?></strong></a>
                        <?= View::partial('partials/status_badge', ['key' => 'mail.status.' . $f['status'], 'status' => $f['status']]) ?></div>
                    <p class="muted"><?= e(t('mail.direction.' . $f['direction'])) ?></p>
                </li>
                <?php endforeach ?>
            </ul>
        </section>
        <?php endif ?>
    </div>
</div>

<template id="tpl-assign">
    <form method="post" action="<?= e(url("/mails/$id/assign")) ?>" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label class="required"><?= e(t('mail.assign.to')) ?></label>
            <select name="to_unit_id" required><option value=""></option>
                <?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>"><?= str_repeat('— ', (int) $u['depth']) . e($u['name']) ?></option><?php endforeach ?>
            </select></div>
        <div class="field"><label><?= e(t('mail.assign.kind')) ?></label>
            <select name="kind"><option value="action"><?= e(t('mail.assign.action')) ?></option><option value="copy"><?= e(t('mail.assign.copy')) ?></option></select></div>
        <div class="field"><label><?= e(t('mail.assign.instruction')) ?></label><textarea name="instruction" rows="2" maxlength="500"></textarea></div>
        <div class="field"><label><?= e(t('mail.field.due_on')) ?></label><input name="due_on" type="date"></div>
    </form>
</template>
<template id="tpl-ack">
    <form method="post" action="<?= e(url("/mails/$id/ack")) ?>" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label><?= e(t('mail.ack.channel')) ?></label>
            <select name="channel"><option value="print"><?= e(t('mail.ack_channel.print')) ?></option><option value="email"><?= e(t('mail.ack_channel.email')) ?></option></select></div>
    </form>
</template>
<template id="tpl-reply">
    <form method="post" action="<?= e(url("/mails/$id/reply")) ?>" enctype="multipart/form-data" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label class="required"><?= e(t('mail.field.subject')) ?></label><input name="subject" maxlength="255" required value="<?= e('Réf. ' . $m['reference']) ?>"></div>
        <div class="field"><label><?= e(t('mail.field.summary')) ?></label><textarea name="summary" rows="2"></textarea></div>
        <div class="field"><label><?= e(t('mail.reply.file')) ?></label><input name="files[]" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"><p class="hint"><?= e(t('mail.reply.hint')) ?></p></div>
    </form>
</template>
<template id="tpl-send">
    <form method="post" action="<?= e(url("/mails/$id/send")) ?>" enctype="multipart/form-data" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label><?= e(t('mail.send.channel')) ?></label>
            <select name="channel"><?php foreach (['hand', 'post', 'email', 'courier', 'other'] as $c): ?><option value="<?= $c ?>"><?= e(t('mail.channel.' . $c)) ?></option><?php endforeach ?></select></div>
        <div class="field"><label><?= e(t('mail.send.proof')) ?></label><input name="proof" type="file" accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
    </form>
</template>
<template id="tpl-close">
    <form method="post" action="<?= e(url("/mails/$id/close")) ?>" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label><?= e(t('mail.close.note')) ?></label><textarea name="note" rows="2" maxlength="500"></textarea><p class="hint"><?= e(t('mail.close.note_hint')) ?></p></div>
    </form>
</template>
<template id="tpl-cancel">
    <form method="post" action="<?= e(url("/mails/$id/cancel")) ?>" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label class="required"><?= e(t('mail.close.note')) ?></label><textarea name="note" rows="2" maxlength="500" required></textarea></div>
    </form>
</template>
<template id="tpl-doc">
    <form method="post" action="<?= e(url("/mails/$id/documents")) ?>" enctype="multipart/form-data" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label><?= e(t('mail.doc.kind')) ?></label>
            <select name="kind"><?php foreach (\App\Services\MailService::DOC_KINDS as $k): ?><option value="<?= $k ?>" <?= $k === ($in ? 'annex' : 'reply') ? 'selected' : '' ?>><?= e(t('mail.doc.' . $k)) ?></option><?php endforeach ?></select></div>
        <div class="field"><label class="required"><?= e(t('mail.doc.file')) ?></label><input name="file" type="file" required accept=".pdf,.jpg,.jpeg,.png,.webp"></div>
    </form>
</template>
