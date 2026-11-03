<?php
/** @var array $rows */
use App\Core\View;

$form = function (?array $c) {
    ob_start(); ?>
    <form method="post" action="<?= e(url('/correspondents' . ($c ? '/' . (int) $c['id'] : ''))) ?>" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label class="required"><?= e(t('corr.col.name')) ?></label><input name="name" maxlength="190" required value="<?= e($c['name'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('corr.col.kind')) ?></label><select name="kind">
            <?php foreach (['administration', 'company', 'person', 'other'] as $k): ?><option value="<?= $k ?>" <?= ($c['kind'] ?? 'company') === $k ? 'selected' : '' ?>><?= e(t('corr.kind.' . $k)) ?></option><?php endforeach ?>
        </select></div>
        <div class="field"><label><?= e(t('corr.col.email')) ?></label><input name="email" type="email" maxlength="190" value="<?= e($c['email'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('corr.col.phone')) ?></label><input name="phone" maxlength="60" value="<?= e($c['phone'] ?? '') ?>"></div>
        <div class="field"><label><?= e(t('corr.col.address')) ?></label><input name="address" maxlength="255" value="<?= e($c['address'] ?? '') ?>"></div>
        <?php if ($c): ?><label class="switch"><input type="checkbox" name="active" value="1" <?= $c['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?></span></label><?php endif ?>
    </form>
    <?php return ob_get_clean();
};
echo View::partial('partials/page_header', ['icon' => 'contact', 'heading' => t('nav.correspondents'),
    'actions' => '<button type="button" class="btn btn--primary" data-open="#tpl-new" data-title="' . e(t('corr.new')) . '">' . icon('plus') . ' ' . e(t('corr.new')) . '</button>']);
?>
<section class="card">
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= e(t('corr.col.name')) ?></th><th><?= e(t('corr.col.kind')) ?></th><th><?= e(t('corr.col.email')) ?></th><th><?= e(t('corr.col.phone')) ?></th><th></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $c): ?>
            <tr>
                <td><strong><?= e($c['name']) ?></strong><?= $c['active'] ? '' : ' <span class="muted">(' . e(t('common.inactive')) . ')</span>' ?></td>
                <td><?= e(t('corr.kind.' . $c['kind'])) ?></td><td><?= e($c['email'] ?: '—') ?></td><td><?= e($c['phone'] ?: '—') ?></td>
                <td><button type="button" class="icon-btn" data-open="#tpl-<?= (int) $c['id'] ?>" data-title="<?= e($c['name']) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
                    <template id="tpl-<?= (int) $c['id'] ?>"><?= $form($c) ?></template></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</section>
<template id="tpl-new"><?= $form(null) ?></template>
