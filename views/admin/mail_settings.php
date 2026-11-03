<?php
/** @var array $s @var array $stats */
use App\Core\View;

echo View::partial('partials/page_header', ['icon' => 'settings', 'heading' => t('nav.mail_settings'), 'meta' => t('settings.meta'),
    'actions' => '<a class="btn" href="' . e(url('/mail-log')) . '">' . icon('mail-check') . ' ' . e(t('nav.mail_log')) . '</a>']);
?>
<div class="grid grid--2-1">
    <section class="card">
        <form method="post" action="<?= e(url('/settings/mail')) ?>" class="form" autocomplete="off">
            <?= csrf_field() ?>
            <div class="field"><label class="required"><?= e(t('settings.host')) ?></label><input name="host" maxlength="190" required value="<?= e(old('host', $s['host'])) ?>" placeholder="smtp.example.com"><?= error_msg('host') ?></div>
            <div class="field"><label class="required"><?= e(t('settings.port')) ?></label><input name="port" type="number" min="1" max="65535" required value="<?= e(old('port', $s['port'])) ?>"><?= error_msg('port') ?></div>
            <div class="field"><label><?= e(t('settings.encryption')) ?></label><select name="encryption">
                <?php foreach (['tls', 'ssl', 'none'] as $enc): ?><option value="<?= $enc ?>" <?= old('encryption', $s['encryption']) === $enc ? 'selected' : '' ?>><?= e(t('settings.enc.' . $enc)) ?></option><?php endforeach ?>
            </select></div>
            <div class="field"><label><?= e(t('settings.username')) ?></label><input name="username" maxlength="190" autocomplete="off" value="<?= e(old('username', $s['username'])) ?>"></div>
            <div class="field"><label><?= e(t('settings.password')) ?></label><input name="password" type="password" autocomplete="new-password">
                <p class="hint"><?= e(t($s['has_password'] ? 'settings.password_keep' : 'settings.password_none')) ?></p></div>
            <?php if ($s['has_password']): ?><label class="switch"><input type="checkbox" name="clear_password" value="1"><span><?= e(t('settings.password_clear')) ?></span></label><?php endif ?>
            <div class="field"><label class="required"><?= e(t('settings.from_email')) ?></label><input name="from_email" type="email" maxlength="190" required value="<?= e(old('from_email', $s['from_email'])) ?>"><?= error_msg('from_email') ?></div>
            <div class="field"><label class="required"><?= e(t('settings.from_name')) ?></label><input name="from_name" maxlength="190" required value="<?= e(old('from_name', $s['from_name'])) ?>"><?= error_msg('from_name') ?></div>
            <button class="btn btn--primary"><?= icon('save') ?> <?= e(t('common.save')) ?></button>
        </form>
    </section>
    <div>
        <section class="card">
            <div class="card__title"><h2><?= icon('send', 'title-icon') ?> <?= e(t('settings.test')) ?></h2></div>
            <form method="post" action="<?= e(url('/settings/mail/test')) ?>" class="form">
                <?= csrf_field() ?>
                <div class="field"><label class="required"><?= e(t('settings.test_to')) ?></label><input name="to" type="email" required value="<?= e($stats['me']) ?>"></div>
                <p class="hint"><?= e(t('settings.test_hint')) ?></p>
                <button class="btn"><?= icon('send') ?> <?= e(t('settings.test_send')) ?></button>
            </form>
        </section>
    </div>
</div>
