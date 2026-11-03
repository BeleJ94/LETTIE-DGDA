<?php
/** Tableau de bord : ce qui demande une action d'abord, la charge ensuite. @var array $k @var array $units @var array $urgent */
use App\Core\View;
use App\Domain\MailWorkflow;
use App\Security\Auth;

$user = Auth::require();
$today = new DateTimeImmutable('today');
$weekdays = ['dimanche', 'lundi', 'mardi', 'mercredi', 'jeudi', 'vendredi', 'samedi'];
$months = ['janvier', 'février', 'mars', 'avril', 'mai', 'juin', 'juillet', 'août', 'septembre', 'octobre', 'novembre', 'décembre'];
$dateLabel = $weekdays[(int) $today->format('w')] . ' ' . $today->format('j') . ' ' . $months[(int) $today->format('n') - 1] . ' ' . $today->format('Y');
$greeting = t((int) date('G') < 18 ? 'dash.hello' : 'dash.evening', ['name' => $user->name]);

$tiles = [
    ['late', 'alarm-clock', url('/mails/incoming', ['status' => 'late']), (int) $k['late'] ? ' kpi--alert' : ''],
    ['to_assign', 'forward', url('/mails/incoming', ['status' => 'registered']), (int) $k['to_assign'] ? ' kpi--warn' : ''],
    ['no_ack', 'mail-check', url('/mails/incoming', ['status' => 'no_ack']), (int) $k['no_ack'] ? ' kpi--warn' : ''],
    ['open_in', 'mail-open', url('/mails/incoming', ['status' => 'open']), ''],
    ['month_in', 'inbox', url('/mails/incoming'), ''],
    ['month_closed', 'archive', url('/mails/archives'), ''],
];
$actions = '';
if ($user->can('mail.register')) {
    $actions = '<a class="btn" href="' . e(url('/mails/new', ['direction' => 'out'])) . '">' . icon('send') . ' ' . e(t('dash.new_out')) . '</a>'
        . '<a class="btn btn--primary" href="' . e(url('/mails/new', ['direction' => 'in'])) . '">' . icon('plus') . ' ' . e(t('mail.new_in')) . '</a>';
}
echo View::partial('partials/page_header', ['icon' => 'layout-dashboard', 'heading' => $greeting, 'meta' => ucfirst($dateLabel) . ($user->unitName ? ' · ' . $user->unitName : ''), 'actions' => $actions]);

/** Échéance en clair : c'est le délai restant qui décide de l'action, pas la date. */
$deadline = function (?string $due) use ($today): array {
    if ($due === null) {
        return ['dash.due.none', [], 'neutral'];
    }
    $days = (int) $today->diff(new DateTimeImmutable($due))->format('%r%a');
    return match (true) {
        $days < 0 => ['dash.due.late', ['n' => -$days], 'breakdown'],
        $days === 0 => ['dash.due.today', [], 'waiting_part'],
        $days <= 3 => ['dash.due.soon', ['n' => $days], 'waiting_part'],
        default => ['dash.due.soon', ['n' => $days], 'neutral'],
    };
};
$maxOpen = max(1, ...array_map(fn ($u) => (int) $u['open_count'], $units ?: [['open_count' => 1]]));
$units = array_values(array_filter($units, fn ($u) => (int) $u['open_count'] > 0));
?>
<section class="kpis dash-kpis">
    <?php foreach ($tiles as [$key, $ic, $href, $tone]): ?>
        <a class="kpi kpi--button dash-kpi<?= $tone ?>" href="<?= e($href) ?>" data-drill="<?= e($key) ?>" data-drill-title="<?= e(t('dash.' . $key)) ?>">
            <span class="dash-kpi__icon"><?= icon($ic) ?></span>
            <span class="kpi__value"><?= (int) $k[$key] ?></span>
            <span class="kpi__label"><?= e(t('dash.' . $key)) ?></span>
        </a>
    <?php endforeach ?>
</section>
<div class="grid grid--2-1">
    <section class="card">
        <div class="card__title"><h2><?= icon('siren', 'title-icon') ?> <?= e(t('dash.urgent')) ?></h2>
            <a class="btn btn--ghost btn--sm" href="<?= e(url('/mails/incoming', ['status' => 'open'])) ?>"><?= e(t('common.see_all')) ?></a></div>
        <?php if (!$urgent): ?>
            <div class="empty-state empty-state--ok"><?= icon('circle-check') ?><p><?= e(t('dash.none')) ?></p></div>
        <?php else: ?>
        <ul class="dash-list">
            <?php foreach ($urgent as $m): [$dueKey, $dueParams, $dueTone] = $deadline($m['due_on']); ?>
            <li><a class="dash-item" href="<?= e(url('/mails/' . (int) $m['id'])) ?>">
                <span class="dash-item__body">
                    <strong><?= e($m['subject']) ?></strong>
                    <span class="muted"><?= e($m['reference']) ?> · <?= e($m['unit']) ?><?= $m['priority'] !== 'normal' ? ' · ' . e(t('mail.priority.' . $m['priority'])) : '' ?></span>
                </span>
                <span class="dash-item__meta">
                    <span class="badge badge--<?= $dueTone ?>"><?= e(t($dueKey, $dueParams)) ?></span>
                    <?= View::partial('partials/status_badge', ['key' => 'mail.status.' . $m['status'], 'status' => $m['status']]) ?>
                </span>
            </a></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
    </section>
    <section class="card">
        <div class="card__title"><h2><?= icon('network', 'title-icon') ?> <?= e(t('dash.by_unit')) ?></h2></div>
        <?php if (!$units): ?>
            <p class="empty"><?= e(t('dash.no_load')) ?></p>
        <?php else: ?>
        <ul class="dash-load">
            <?php foreach ($units as $u): ?>
            <li><button type="button" class="dash-load__btn" data-drill="unit" data-drill-param="<?= (int) $u['id'] ?>" data-drill-title="<?= e($u['unit']) ?>">
                <span class="dash-load__head"><span><?= e($u['unit']) ?></span><strong><?= (int) $u['open_count'] ?></strong></span>
                <span class="dash-load__track" aria-hidden="true"><span class="dash-load__bar" style="width:<?= round((int) $u['open_count'] / $maxOpen * 100) ?>%"></span></span>
                <?php if ((int) $u['late']): ?><span class="dash-load__late text-bad"><?= e(t('dash.unit_late', ['n' => (int) $u['late']])) ?></span><?php endif ?>
            </button></li>
            <?php endforeach ?>
        </ul>
        <?php endif ?>
    </section>
</div>
