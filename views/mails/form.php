<?php
/** Enregistrement ou correction d'une correspondance. @var array|null $m @var string $direction @var array $correspondents */
use App\Core\View;
use App\Domain\MailAccess;
use App\Security\Auth;

$user = Auth::require();
$in = $direction === 'in';
echo View::partial('partials/page_header', ['icon' => $in ? 'mail-open' : 'send', 'heading' => $title, 'meta' => $m ? null : ($user->unitName ?? '')]);
$at = old('event_at', $m ? date('Y-m-d\TH:i', strtotime($m['event_at'])) : date('Y-m-d\TH:i'));
$sel = fn (string $f, $v, $def = '') => (string) old($f, $m[$f] ?? $def) === (string) $v ? 'selected' : '';
$checked = fn (string $f, $v, $def = '') => (string) old($f, $m[$f] ?? $def) === (string) $v ? 'checked' : '';
?>
<div class="mail-layout" data-mail-form>
<section class="card">
    <form method="post" action="<?= e(url($m ? '/mails/' . (int) $m['id'] : '/mails')) ?>" enctype="multipart/form-data" class="form">
        <?= csrf_field() ?>
        <input type="hidden" name="direction" value="<?= e($direction) ?>">
        <fieldset class="form-section">
            <legend><span class="form-step">1</span> <?= e(t('mail.section.identity')) ?></legend>
            <div class="form-grid">
                <div class="field">
                    <label for="correspondent_id" class="required"><?= e(t('mail.field.correspondent_id')) ?></label>
                    <div class="combo" data-corr-combo data-url="<?= e(url('/correspondents/quick')) ?>" data-can-create="<?= $user->can('correspondents.manage') ? '1' : '' ?>">
                        <div class="input-lead" data-combo-wrap hidden><?= icon('search') ?><input type="text" data-combo-input role="combobox" aria-expanded="false" aria-autocomplete="list" aria-controls="corr-list" autocomplete="off" placeholder="<?= e(t('mail.corr_search')) ?>"></div>
                        <ul class="combo__list" id="corr-list" role="listbox" data-combo-list hidden></ul>
                        <select id="correspondent_id" name="correspondent_id" required<?= error_attr('correspondent_id') ?>>
                            <option value=""><?= e(t('common.choose')) ?></option>
                            <?php foreach ($correspondents as $c): ?><option value="<?= (int) $c['id'] ?>" data-email="<?= e($c['email'] ?? '') ?>" <?= $sel('correspondent_id', $c['id']) ?>><?= e($c['name']) ?></option><?php endforeach ?>
                        </select>
                        <div class="combo__new" data-combo-new hidden>
                            <strong><?= e(t('corr.new')) ?></strong>
                            <div class="field"><label class="required"><?= e(t('corr.col.name')) ?></label><input data-new="name" maxlength="190"></div>
                            <div class="form-grid">
                                <div class="field"><label><?= e(t('corr.col.kind')) ?></label><select data-new="kind"><?php foreach (['company', 'administration', 'person', 'other'] as $k): ?><option value="<?= $k ?>"><?= e(t('corr.kind.' . $k)) ?></option><?php endforeach ?></select></div>
                                <div class="field"><label><?= e(t('corr.col.phone')) ?></label><input data-new="phone" maxlength="60"></div>
                            </div>
                            <div class="field"><label><?= e(t('corr.col.email')) ?></label><input data-new="email" type="email" maxlength="190"><p class="hint"><?= e(t('mail.corr_email_hint')) ?></p></div>
                            <p class="error" data-new-error hidden></p>
                            <div class="combo__actions"><button type="button" class="btn btn--ghost btn--sm" data-new-cancel><?= e(t('common.cancel')) ?></button><button type="button" class="btn btn--primary btn--sm" data-new-save><?= icon('check') ?> <?= e(t('mail.corr_create')) ?></button></div>
                        </div>
                        <p class="hint" data-combo-note hidden></p>
                    </div>
                    <?= error_msg('correspondent_id') ?>
                </div>
                <div class="field">
                    <label for="external_ref"><?= e(t('mail.field.external_ref')) ?></label>
                    <input id="external_ref" name="external_ref" maxlength="100" value="<?= e(old('external_ref', $m['external_ref'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label for="event_at" class="required"><?= e(t('mail.field.event_at')) ?></label>
                    <input id="event_at" name="event_at" type="datetime-local" required value="<?= e($at) ?>"<?= error_attr('event_at') ?>>
                    <?= error_msg('event_at') ?>
                </div>
                <div class="field">
                    <label for="letter_date"><?= e(t('mail.field.letter_date')) ?></label>
                    <input id="letter_date" name="letter_date" type="date" value="<?= e(old('letter_date', $m['letter_date'] ?? '')) ?>">
                </div>
                <div class="field">
                    <label for="channel"><?= e(t('mail.field.channel')) ?></label>
                    <select id="channel" name="channel">
                        <?php foreach (['hand', 'post', 'email', 'courier', 'other'] as $c): ?><option value="<?= $c ?>" <?= $sel('channel', $c) ?>><?= e(t('mail.channel.' . $c)) ?></option><?php endforeach ?>
                    </select>
                </div>
            </div>
        </fieldset>
        <fieldset class="form-section">
            <legend><span class="form-step">2</span> <?= e(t('mail.section.content')) ?></legend>
            <div class="field">
                <label for="subject" class="required"><?= e(t('mail.field.subject')) ?></label>
                <input id="subject" name="subject" maxlength="255" required placeholder="<?= e(t('mail.subject_hint')) ?>" value="<?= e(old('subject', $m['subject'] ?? '')) ?>"<?= error_attr('subject') ?>>
                <?= error_msg('subject') ?>
            </div>
            <div class="field">
                <label for="summary"><?= e(t('mail.field.summary')) ?></label>
                <textarea id="summary" name="summary" rows="3" data-autogrow><?= e(old('summary', $m['summary'] ?? '')) ?></textarea>
            </div>
        </fieldset>
        <fieldset class="form-section">
            <legend><span class="form-step">3</span> <?= e(t('mail.section.handling')) ?></legend>
            <div class="form-grid">
                <div class="field">
                    <span class="field__label"><?= e(t('mail.field.priority')) ?></span>
                    <div class="choice-pills" role="radiogroup" aria-label="<?= e(t('mail.field.priority')) ?>">
                        <?php foreach (['normal', 'urgent', 'very_urgent'] as $p): ?><label class="choice-pill choice-pill--<?= $p ?>"><input type="radio" name="priority" value="<?= $p ?>" <?= $checked('priority', $p, 'normal') ?>><span><?= e(t('mail.priority.' . $p)) ?></span></label><?php endforeach ?>
                    </div>
                </div>
                <div class="field">
                    <span class="field__label"><?= e(t('mail.field.confidentiality')) ?></span>
                    <div class="choice-pills" role="radiogroup" aria-label="<?= e(t('mail.field.confidentiality')) ?>">
                        <?php foreach (MailAccess::LEVELS as $i => $l): if ($i > $user->clearance) { continue; } ?><label class="choice-pill choice-pill--level<?= $i ?>"><input type="radio" name="confidentiality" value="<?= $i ?>" <?= $checked('confidentiality', $i, '0') ?>><span><?= e(t('mail.level.' . $l)) ?></span></label><?php endforeach ?>
                    </div>
                    <p class="hint"><?= e(t('mail.level_hint')) ?></p>
                </div>
                <div class="field">
                    <label for="due_on"><?= e(t('mail.field.due_on')) ?></label>
                    <input id="due_on" name="due_on" type="date" value="<?= e(old('due_on', $m['due_on'] ?? '')) ?>">
                    <div class="due-shortcuts"><?php foreach ([3, 7, 15, 30] as $n): ?><button type="button" class="chip chip--link" data-due-days="<?= $n ?>"><?= e(t('mail.due_in', ['n' => $n])) ?></button><?php endforeach ?></div>
                </div>
            </div>
        </fieldset>
        <?php if (!$m): ?>
        <fieldset class="form-section">
            <legend><span class="form-step">4</span> <?= e(t('mail.section.files')) ?></legend>
            <div class="field">
                <label class="dropzone" data-dropzone>
                    <input name="files[]" type="file" multiple accept=".pdf,.jpg,.jpeg,.png,.webp" data-mail-files>
                    <?= icon('upload-cloud') ?>
                    <strong><?= e(t('mail.drop')) ?></strong>
                    <span class="hint"><?= e(t('mail.files_hint')) ?></span>
                </label>
                <ul class="file-list" data-file-list hidden></ul>
                <div class="file-viewer" data-file-viewer hidden></div>
                <p class="error" data-file-error hidden></p>
            </div>
        </fieldset>
        <?php endif ?>
        <div class="form-actions">
            <a class="btn btn--ghost" href="<?= e(url($m ? '/mails/' . (int) $m['id'] : ($in ? '/mails/incoming' : '/mails/outgoing'))) ?>"><?= e(t('common.cancel')) ?></a>
            <button class="btn btn--primary"><?= icon('save') ?> <?= e(t($m ? 'common.save' : 'mail.save_new')) ?></button>
        </div>
    </form>
</section>
<aside class="mail-summary">
    <section class="card">
        <div class="card__title"><h2><?= icon('clipboard-list', 'title-icon') ?> <?= e(t('mail.recap')) ?></h2></div>
        <dl class="dl">
            <dt><?= e(t('mail.field.correspondent_id')) ?></dt><dd data-recap="correspondent">—</dd>
            <dt><?= e(t('mail.field.subject')) ?></dt><dd data-recap="subject">—</dd>
            <dt><?= e(t('mail.field.priority')) ?></dt><dd data-recap="priority">—</dd>
            <dt><?= e(t('mail.field.confidentiality')) ?></dt><dd data-recap="level">—</dd>
            <?php if (!$m): ?><dt><?= e(t('mail.section.files')) ?></dt><dd data-recap="files">0</dd><?php endif ?>
        </dl>
    </section>
    <?php if (!$m): ?>
    <section class="card mail-next">
        <div class="card__title"><h2><?= icon('sparkles', 'title-icon') ?> <?= e(t('mail.next_title')) ?></h2></div>
        <ul>
            <li><?= icon('qr-code') ?> <?= e(t('mail.next_ref')) ?></li>
            <li><?= icon($user->unitName ? 'building-2' : 'info') ?> <?= e(t('mail.next_unit', ['unit' => $user->unitName ?? '—'])) ?></li>
            <li><?= icon('history') ?> <?= e(t('mail.next_trace')) ?></li>
        </ul>
    </section>
    <?php endif ?>
</aside>
</div>
