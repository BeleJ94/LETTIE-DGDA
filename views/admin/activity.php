<?php
/** @var array $rows */
use App\Core\View;

echo View::partial('partials/page_header', ['icon' => 'history', 'heading' => t('nav.activity'), 'meta' => t('activity.meta')]);
?>
<section class="card">
    <div class="table-wrap"><table class="table table--compact">
        <thead><tr><th><?= e(t('activity.col.date')) ?></th><th><?= e(t('activity.col.user')) ?></th><th><?= e(t('activity.col.action')) ?></th><th><?= e(t('activity.col.detail')) ?></th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td><?= e(fmt_date($r['created_at'], true)) ?></td><td><?= e($r['user_name'] ?? '—') ?></td>
                <td><strong><?= e($r['entity']) ?><?= $r['entity_id'] ? ' #' . (int) $r['entity_id'] : '' ?></strong><br><span class="muted"><?= e($r['action']) ?></span></td>
                <td class="diff"><?= e(mb_substr((string) $r['after_json'], 0, 200)) ?></td>
            </tr>
        <?php endforeach ?>
        </tbody>
    </table></div>
</section>
