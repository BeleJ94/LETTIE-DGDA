<?php
/** Page publique du QR code : aucune donnée sur l'objet ni le correspondant. @var array $m */
use App\Core\I18n;
?>
<!doctype html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title><?= e($title) ?> · Lettie-DGDA</title>
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="layout-minimal">
<main class="minimal__panel" style="margin:0 auto;min-height:100vh">
    <div class="login-card">
        <div class="login-card__org"><?= org_logo() ?></div>
        <h1 class="login__title"><?= e(t('track.title')) ?></h1>
        <dl class="dl">
            <dt><?= e(t('mail.col.reference')) ?></dt><dd><strong><?= e($m['reference']) ?></strong></dd>
            <dt><?= e(t('track.received')) ?></dt><dd><?= e(fmt_date($m['event_at'], true)) ?></dd>
            <dt><?= e(t('track.status')) ?></dt><dd><?= e(t('mail.status.' . $m['status'])) ?></dd>
            <?php if ($m['direction'] === 'in'): ?><dt><?= e(t('track.ack')) ?></dt><dd><?= e(t($m['ack_at'] ? 'track.ack_yes' : 'track.ack_no')) ?></dd><?php endif ?>
        </dl>
    </div>
</main>
</body>
</html>
