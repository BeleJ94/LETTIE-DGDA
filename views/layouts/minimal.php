<?php
/**
 * Gabarit minimal (connexion) : à gauche, présentation du logiciel (parcours d'un dossier, modules, points forts) ;
 * à droite, le panneau de connexion.
 * @var string $content @var string $title
 */

use App\Core\I18n;

$steps = [
    ['inbox', 'login.step.receive'],
    ['qr-code', 'login.step.register'],
    ['mail-check', 'login.step.ack'],
    ['forward', 'login.step.process'],
    ['send', 'login.step.reply'],
    ['archive', 'login.step.archive'],
];
$features = [
    ['building-2', 'login.feat.direct_title', 'login.feat.direct_text'],
    ['folder-open', 'login.feat.file_title', 'login.feat.file_text'],
    ['shield-check', 'login.feat.trace_title', 'login.feat.trace_text'],
];
?>
<!doctype html>
<html lang="<?= e(I18n::locale()) ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title ?? '') ?> · Lettie-DGDA</title>
    <link rel="icon" href="<?= e(asset('img/logo.png')) ?>" type="image/png">
    <link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="layout-minimal">
<div class="minimal">
    <aside class="minimal__visual">
        <div class="lv__bg" aria-hidden="true"></div>
        <div class="lv">
            <header class="lv__brand">
                <span class="brand-mark brand-mark--lg brand-mark--logo"><img src="<?= e(asset('img/logo.png')) ?>" alt=""></span>
                <span><strong>Lettie-DGDA</strong><small><?= e(t('login.product')) ?></small></span>
            </header>

            <div class="lv__hero">
                <h2 class="lv__headline"><?= e(t('login.headline')) ?></h2>
                <p class="lv__lead"><?= e(t('app.tagline')) ?></p>
            </div>

            <ol class="lv__flow" aria-label="<?= e(t('login.flow')) ?>">
                <?php foreach ($steps as $i => [$ic, $label]): ?>
                    <li style="--i: <?= $i ?>"><span class="lv__flow-icon"><?= icon($ic) ?></span><span><?= e(t($label)) ?></span></li>
                <?php endforeach ?>
            </ol>

            <ul class="lv__features">
                <?php foreach ($features as [$ic, $ttl, $txt]): ?>
                    <li>
                        <span class="lv__feat-icon"><?= icon($ic) ?></span>
                        <span><strong><?= e(t($ttl)) ?></strong><span><?= e(t($txt)) ?></span></span>
                    </li>
                <?php endforeach ?>
            </ul>
        </div>
    </aside>
    <main class="minimal__panel" id="main">
        <div class="login-card">
            <?= $content ?>
        </div>
        <p class="minimal__foot muted">© <?= date('Y') ?> Lettie-DGDA · BELEJ-CONSULTING</p>
    </main>
</div>
<script src="<?= e(asset('vendor/lucide/lucide.min.js')) ?>"></script>
<script src="<?= e(asset('js/minimal.js')) ?>"></script>
</body>
</html>
