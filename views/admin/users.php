<?php
/** @var array $rows @var array $units */
use App\Core\View;
use App\Domain\MailAccess;
use App\Security\Role;

$form = function (?array $u) use ($units) {
    ob_start(); ?>
    <form method="post" action="<?= e(url('/users' . ($u ? '/' . (int) $u['id'] : ''))) ?>" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label class="required"><?= e(t('users.col.name')) ?></label><input name="name" maxlength="120" required value="<?= e($u['name'] ?? '') ?>"></div>
        <div class="field"><label class="required"><?= e(t('users.col.email')) ?></label><input name="email" type="email" maxlength="190" required value="<?= e($u['email'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('users.col.role')) ?></label><select name="role">
            <?php foreach (Role::cases() as $r): ?><option value="<?= $r->value ?>" <?= ($u['role'] ?? 'agent') === $r->value ? 'selected' : '' ?>><?= e(t('role.' . $r->value)) ?></option><?php endforeach ?>
        </select></div>
        <div class="field"><label><?= e(t('users.col.unit')) ?></label><select name="unit_id"><option value="">—</option>
            <?php foreach ($units as $n): ?><option value="<?= (int) $n['id'] ?>" <?= (int) ($u['unit_id'] ?? 0) === (int) $n['id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int) $n['depth']) . e($n['name']) ?></option><?php endforeach ?>
        </select></div>
        <div class="field"><label><?= e(t('users.col.clearance')) ?></label><select name="clearance">
            <?php foreach (MailAccess::LEVELS as $i => $l): ?><option value="<?= $i ?>" <?= (int) ($u['clearance'] ?? 0) === $i ? 'selected' : '' ?>><?= e(t('mail.level.' . $l)) ?></option><?php endforeach ?>
        </select></div>
        <div class="field"><label<?= $u ? '' : ' class="required"' ?>><?= e(t('users.password')) ?></label><input name="password" type="password" minlength="10" autocomplete="new-password"<?= $u ? '' : ' required' ?>><p class="hint"><?= e(t('users.password_hint')) ?></p></div>
        <?php if ($u): ?><label class="switch"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label><?php endif ?>
    </form>
    <?php return ob_get_clean();
};
echo View::partial('partials/page_header', ['icon' => 'users', 'heading' => t('nav.users'),
    'actions' => '<button type="button" class="btn btn--primary" data-open="#tpl-new" data-title="' . e(t('users.new')) . '">' . icon('plus') . ' ' . e(t('users.new')) . '</button>']);
?>
<section class="card">
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= e(t('users.col.name')) ?></th><th><?= e(t('users.col.role')) ?></th><th><?= e(t('users.col.unit')) ?></th><th><?= e(t('users.col.clearance')) ?></th><th><?= e(t('users.col.last_login')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $u): ?>
            <tr>
                <td><strong><?= e($u['name']) ?></strong><?= $u['active'] ? '' : ' <span class="muted">(' . e(t('common.inactive')) . ')</span>' ?><br><span class="muted"><?= e($u['email']) ?></span></td>
                <td><?= e(t('role.' . $u['role'])) ?></td><td><?= e($u['unit'] ?: '—') ?></td>
                <td><?= e(t('mail.level.' . MailAccess::LEVELS[(int) $u['clearance']])) ?></td>
                <td><?= e(fmt_date($u['last_login_at'], true)) ?></td>
                <td><button type="button" class="icon-btn" data-open="#tpl-<?= (int) $u['id'] ?>" data-title="<?= e($u['name']) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <template id="tpl-<?= (int) $u['id'] ?>"><?= $form($u) ?></template></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</section>
<template id="tpl-new"><?= $form(null) ?></template>
