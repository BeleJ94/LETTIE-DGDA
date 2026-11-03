<?php
/** Registre commun : entrant, sortant, répertoire centralisé, archives. @var string $page @var array $fixed @var string $icon @var array $units @var array $correspondents */
use App\Core\View;
use App\Domain\MailAccess;
use App\Security\Auth;

$user = Auth::require();
$preset = (string) ($_GET['status'] ?? '');
$table = 'mails';
$title = t('nav.' . $page);
$actions = '<div class="dropdown"><button type="button" class="btn" data-menu-toggle aria-haspopup="true" aria-expanded="false">' . icon('download') . ' ' . e(t('common.export')) . '</button>'
    . '<div class="menu" data-menu role="menu" hidden>'
    . '<button type="button" class="menu__item" role="menuitem" data-export="excel" data-export-table="' . $table . '" data-export-title="' . e($title) . '">' . icon('file-spreadsheet') . ' Excel</button>'
    . '<button type="button" class="menu__item" role="menuitem" data-export="pdf" data-export-table="' . $table . '" data-export-title="' . e($title) . '">' . icon('file-text') . ' PDF</button>'
    . '</div></div>';
if ($user->can('mail.register') && in_array($page, ['incoming', 'outgoing'], true)) {
    $direction = $page === 'incoming' ? 'in' : 'out';
    $actions .= '<a class="btn btn--primary" href="' . e(url('/mails/new', ['direction' => $direction])) . '">' . icon('plus') . ' ' . e(t($direction === 'in' ? 'mail.new_in' : 'mail.new_out')) . '</a>';
}
echo View::partial('partials/page_header', ['icon' => $icon, 'heading' => $title, 'meta' => t('mail.meta.' . $page), 'actions' => $actions]);
$statuses = $page === 'outgoing' ? ['draft', 'sent', 'closed', 'cancelled'] : ['registered', 'assigned', 'in_progress', 'answered', 'closed'];
?>
<?php if ($page === 'archives' && $folders): ?>
<div class="kpis kpis--compact">
    <a class="kpi<?= !$folder ? ' kpi--hero' : '' ?>" href="<?= e(url('/mails/archives')) ?>" data-drill="dir_closed" data-drill-title="<?= e(t('nav.archives')) ?>"><span class="kpi__label"><?= e(t('common.all')) ?></span><span class="kpi__value"><?= array_sum(array_column($folders, 'total')) ?></span></a>
    <?php foreach ($folders as $f): ?>
    <a class="kpi<?= $folder === (int) $f['id'] ? ' kpi--hero' : '' ?>" href="<?= e(url('/mails/archives', ['folder' => (int) $f['id']])) ?>" data-drill="folder" data-drill-param="<?= (int) $f['id'] ?>" data-drill-title="<?= e($f['name']) ?>"><span class="kpi__label"><?= e($f['name']) ?></span><span class="kpi__value"><?= (int) $f['total'] ?></span></a>
    <?php endforeach ?>
</div>
<?php endif ?>
<?php if ($page === 'directory'): ?>
<div class="kpis dash-kpis">
    <?php foreach ([['total', 'layers'], ['incoming', 'mail-open'], ['outgoing', 'send'], ['open', 'loader'], ['closed', 'archive']] as [$key, $ic]): ?>
    <button type="button" class="kpi kpi--button dash-kpi" data-drill="dir_<?= $key ?>" data-drill-title="<?= e(t('dir.' . $key)) ?>">
        <span class="dash-kpi__icon"><?= icon($ic) ?></span><span class="kpi__value"><?= (int) $stats[$key] ?></span><span class="kpi__label"><?= e(t('dir.' . $key)) ?></span>
    </button>
    <?php endforeach ?>
    <div class="kpi dash-kpi"><span class="dash-kpi__icon"><?= icon('eye-off') ?></span><span class="kpi__value"><?= (int) $stats['masked'] ?></span><span class="kpi__label"><?= e(t('dir.masked')) ?></span></div>
</div>
<?php endif ?>
<section class="card card--table">
    <?php if (in_array($page, ['incoming', 'outgoing'], true)):
        $tabs = $page === 'incoming'
            ? ['open' => 'mail.filter.open', 'registered' => 'dash.to_assign', 'no_ack' => 'mail.filter.no_ack', 'late' => 'mail.filter.late']
            : ['draft' => 'mail.tab.to_send', 'sent' => 'mail.status.sent', 'no_proof' => 'mail.filter.no_proof', 'closed' => 'mail.status.closed'];
    ?>
    <div class="mail-tabs" data-status-tabs>
        <button type="button" data-set-filter="status" data-value="" data-no-scroll aria-pressed="<?= $preset === '' ? 'true' : 'false' ?>"><?= e(t('common.all')) ?></button>
        <?php foreach ($tabs as $key => $label): $n = (int) $counts[$key]; ?>
        <button type="button" class="<?= in_array($key, ['late', 'no_proof'], true) && $n ? 'is-alert' : '' ?>" data-set-filter="status" data-value="<?= $key ?>" data-no-scroll aria-pressed="<?= $preset === $key ? 'true' : 'false' ?>"><?= e(t($label)) ?> <span><?= $n ?></span></button>
        <?php endforeach ?>
    </div>
    <?php endif ?>
    <form class="filters filters--compact" data-filters-for="<?= $table ?>">
        <?php foreach ($fixed as $k => $v): ?><input type="hidden" data-filter="<?= e($k) ?>" value="<?= e($v) ?>"><?php endforeach ?>
        <?php if ($page === 'directory'): ?>
        <div class="field">
            <label for="f-direction"><?= e(t('dir.direction')) ?></label>
            <select id="f-direction" data-filter="direction">
                <option value=""><?= e(t('common.all')) ?></option>
                <option value="in"><?= e(t('mail.direction.in')) ?></option>
                <option value="out"><?= e(t('mail.direction.out')) ?></option>
            </select>
        </div>
        <?php endif ?>
        <?php if ($page !== 'archives'): ?>
        <div class="field">
            <label for="f-status"><?= e(t('mail.col.status')) ?></label>
            <select id="f-status" data-filter="status">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($page === 'outgoing' ? ['open', 'late', 'no_proof'] : ['open', 'late', 'no_ack'] as $s): ?><option value="<?= $s ?>"<?= $preset === $s ? ' selected' : '' ?>><?= e(t('mail.filter.' . $s)) ?></option><?php endforeach ?>
                <?php foreach ($statuses as $s): ?><option value="<?= $s ?>"<?= $preset === $s ? ' selected' : '' ?>><?= e(t('mail.status.' . $s)) ?></option><?php endforeach ?>
            </select>
        </div>
        <?php endif ?>
        <div class="field">
            <label for="f-unit"><?= e(t('mail.col.unit')) ?></label>
            <select id="f-unit" data-filter="unit_id">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach ($units as $u): ?><option value="<?= (int) $u['id'] ?>"><?= str_repeat('— ', (int) $u['depth']) . e($u['name']) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-priority"><?= e(t('mail.col.priority')) ?></label>
            <select id="f-priority" data-filter="priority">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (['normal', 'urgent', 'very_urgent'] as $p): ?><option value="<?= $p ?>"><?= e(t('mail.priority.' . $p)) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="field">
            <label for="f-level"><?= e(t('mail.field.confidentiality')) ?></label>
            <select id="f-level" data-filter="confidentiality">
                <option value=""><?= e(t('common.all')) ?></option>
                <?php foreach (MailAccess::LEVELS as $i => $l): ?><option value="<?= $i ?>"><?= e(t('mail.level.' . $l)) ?></option><?php endforeach ?>
            </select>
        </div>
        <div class="field field--dates">
            <label for="f-from"><?= e(t('mail.filter.period')) ?></label>
            <div class="date-range">
                <input id="f-from" type="date" data-filter="from" aria-label="<?= e(t('mail.filter.period')) ?>">
                <span class="muted">→</span>
                <input id="f-to" type="date" data-filter="to" aria-label="<?= e(t('mail.filter.period')) ?>">
            </div>
        </div>
        <div class="filters__meta">
            <div class="dropdown" data-columns-for="<?= $table ?>">
                <button type="button" class="btn btn--sm btn--ghost" data-menu-toggle aria-haspopup="true" aria-expanded="false"><?= icon('columns-3') ?> <?= e(t('common.columns')) ?> <span class="chip" data-columns-count></span></button>
                <div class="menu menu--columns" data-menu role="menu" hidden></div>
            </div>
            <span class="chip" data-filters-count hidden></span>
            <button type="button" class="btn btn--ghost btn--sm" data-filters-reset hidden><?= icon('rotate-ccw') ?> <?= e(t('common.reset')) ?></button>
        </div>
    </form>
    <div class="table-wrap">
        <table class="table" data-table="<?= $table ?>" data-source="<?= e(url('/mails/data')) ?>" data-row-link="<?= e(url('/mails')) ?>/{id}" style="width:100%"></table>
    </div>
</section>
