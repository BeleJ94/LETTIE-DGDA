<?php
/** Organigramme : arbre repliable, recherche, ajout d'une unité directement sous son parent. @var array $rows */
use App\Core\View;
use App\Domain\UnitTree;

$kinds = ['direction' => 'landmark', 'sous_direction' => 'building-2', 'service' => 'users', 'bureau' => 'door-open'];
$next = ['direction' => 'sous_direction', 'sous_direction' => 'service', 'service' => 'bureau', 'bureau' => 'bureau'];
$counts = array_count_values(array_column($rows, 'kind'));
$hasCenter = (bool) array_filter($rows, fn ($r) => $r['is_mail_center'] && $r['active']);
$parents = array_flip(array_map('intval', array_filter(array_column($rows, 'parent_id'))));

/** @param array|null $u unité modifiée @param array|null $parent parent proposé pour une nouvelle unité */
$form = function (?array $u, ?array $parent = null) use ($rows, $kinds, $next) {
    // Une unité ne peut pas passer sous elle-même ni sous ses propres unités
    $excluded = $u ? UnitTree::subtree($rows, (int) $u['id']) : [];
    $selected = (int) ($u['parent_id'] ?? $parent['id'] ?? 0);
    $kind = $u['kind'] ?? ($parent ? $next[$parent['kind']] : 'direction');
    ob_start(); ?>
    <form method="post" action="<?= e(url('/units' . ($u ? '/' . (int) $u['id'] : ''))) ?>" class="form modal-form">
        <?= csrf_field() ?>
        <div class="field"><label class="required"><?= e(t('units.col.name')) ?></label><input name="name" maxlength="160" required value="<?= e($u['name'] ?? '') ?>" placeholder="<?= e(t('units.name_hint')) ?>"></div>
        <div class="field"><label class="required"><?= e(t('units.col.code')) ?></label><input name="code" maxlength="20" required value="<?= e($u['code'] ?? '') ?>" placeholder="SDAF" style="text-transform:uppercase"><p class="hint"><?= e(t('units.code_hint')) ?></p></div>
        <div class="field"><label><?= e(t('units.col.kind')) ?></label><select name="kind">
            <?php foreach (array_keys($kinds) as $k): ?><option value="<?= $k ?>" <?= $kind === $k ? 'selected' : '' ?>><?= e(t('units.kind.' . $k)) ?></option><?php endforeach ?>
        </select></div>
        <div class="field"><label><?= e(t('units.col.parent')) ?></label><select name="parent_id"><option value=""><?= e(t('units.no_parent')) ?></option>
            <?php foreach ($rows as $p): if (in_array((int) $p['id'], $excluded, true)) { continue; } ?><option value="<?= (int) $p['id'] ?>" <?= $selected === (int) $p['id'] ? 'selected' : '' ?>><?= str_repeat('— ', (int) $p['depth']) . e($p['name']) ?></option><?php endforeach ?>
        </select></div>
        <label class="switch switch--hint"><input type="checkbox" name="is_mail_center" value="1" <?= !empty($u['is_mail_center']) ? 'checked' : '' ?>><span><?= e(t('units.center')) ?><small><?= e(t('units.center_hint')) ?></small></span></label>
        <?php if ($u): ?><label class="switch switch--hint"><input type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>><span><?= e(t('common.active')) ?><small><?= e(t('units.active_hint')) ?></small></span></label><?php endif ?>
    </form>
    <?php return ob_get_clean();
};
echo View::partial('partials/page_header', ['icon' => 'network', 'heading' => t('nav.units'), 'meta' => t('units.meta'),
    'actions' => '<button type="button" class="btn btn--primary" data-open="#tpl-new" data-title="' . e(t('units.new')) . '">' . icon('plus') . ' ' . e(t('units.new')) . '</button>']);
?>
<?php if ($rows && !$hasCenter): ?><div class="alert alert--info"><?= icon('info') ?> <span><?= e(t('units.no_center')) ?></span></div><?php endif ?>
<?php if (!$rows): ?>
<section class="card"><div class="empty-state"><?= icon('network') ?><p><?= e(t('units.empty')) ?></p></div></section>
<?php else: ?>
<div class="kpis kpis--compact">
    <?php foreach ($kinds as $k => $ic): ?>
    <div class="kpi"><span class="kpi__label"><?= e(t('units.plural.' . $k)) ?></span><span class="kpi__value"><?= (int) ($counts[$k] ?? 0) ?></span></div>
    <?php endforeach ?>
    <div class="kpi"><span class="kpi__label"><?= e(t('units.col.agents')) ?></span><span class="kpi__value"><?= array_sum(array_column($rows, 'agents')) ?></span></div>
</div>
<section class="card" data-unit-tree>
    <div class="unit-tools">
        <div class="input-lead"><?= icon('search') ?><input type="search" data-unit-search placeholder="<?= e(t('units.search')) ?>" aria-label="<?= e(t('units.search')) ?>"></div>
        <button type="button" class="btn btn--ghost btn--sm" data-unit-all="collapse"><?= icon('chevrons-down-up') ?> <?= e(t('units.collapse')) ?></button>
        <button type="button" class="btn btn--ghost btn--sm" data-unit-all="expand"><?= icon('chevrons-up-down') ?> <?= e(t('units.expand')) ?></button>
    </div>
    <ul class="unit-tree">
        <?php foreach ($rows as $u): $id = (int) $u['id']; $branch = isset($parents[$id]); ?>
        <li class="unit-row unit-row--<?= e($u['kind']) ?><?= $u['active'] ? '' : ' unit-row--off' ?>" style="--depth:<?= (int) $u['depth'] ?>" data-id="<?= $id ?>" data-parent="<?= (int) $u['parent_id'] ?>" data-text="<?= e(mb_strtolower($u['name'] . ' ' . $u['code'])) ?>">
            <?php if ($branch): ?><button type="button" class="unit-row__toggle" data-unit-toggle aria-expanded="true" aria-label="<?= e(t('units.toggle')) ?>"><?= icon('chevron-down') ?></button><?php else: ?><span class="unit-row__toggle"></span><?php endif ?>
            <span class="unit-row__icon"><?= icon($kinds[$u['kind']]) ?></span>
            <span class="unit-row__body">
                <strong><?= e($u['name']) ?></strong>
                <span class="muted"><?= e($u['code']) ?> · <?= e(t('units.kind.' . $u['kind'])) ?></span>
            </span>
            <?php if ($u['is_mail_center']): ?><span class="badge badge--maintenance"><?= e(t('units.center')) ?></span><?php endif ?>
            <?php if (!$u['active']): ?><span class="badge badge--neutral"><?= e(t('common.inactive')) ?></span><?php endif ?>
            <span class="unit-row__agents" title="<?= e(t('units.col.agents')) ?>"><?= icon('user') ?> <?= (int) $u['agents'] ?></span>
            <span class="unit-row__actions">
                <button type="button" class="icon-btn" data-open="#tpl-child-<?= $id ?>" data-title="<?= e(t('units.add_under', ['name' => $u['name']])) ?>" title="<?= e(t('units.add_child')) ?>" aria-label="<?= e(t('units.add_child')) ?>"><?= icon('plus') ?></button>
                <button type="button" class="icon-btn" data-open="#tpl-<?= $id ?>" data-title="<?= e($u['name']) ?>" title="<?= e(t('common.edit')) ?>" aria-label="<?= e(t('common.edit')) ?>"><?= icon('pencil') ?></button>
            </span>
            <template id="tpl-<?= $id ?>"><?= $form($u) ?></template>
            <template id="tpl-child-<?= $id ?>"><?= $form(null, $u) ?></template>
        </li>
        <?php endforeach ?>
    </ul>
    <p class="empty" data-unit-none hidden><?= e(t('units.no_match')) ?></p>
</section>
<?php endif ?>
<template id="tpl-new"><?= $form(null) ?></template>
