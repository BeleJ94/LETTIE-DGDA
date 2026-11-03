<?php

declare(strict_types=1);

/*
 * Menu par sections, dans l'ordre du traitement d'une correspondance.
 * Entrée : chemin, icône, clé de libellé, permission requise, pastille facultative (App\Services\NavBadges).
 */
return [
    ['id' => 'steering', 'section' => 'nav.section.steering', 'items' => [
        ['path' => '/', 'icon' => 'layout-dashboard', 'label' => 'nav.dashboard', 'perm' => 'dashboard.view'],
    ]],
    ['id' => 'mail', 'section' => 'nav.section.mail', 'items' => [
        ['path' => '/mails/incoming', 'icon' => 'mail-open', 'label' => 'nav.incoming', 'perm' => 'mail.view', 'badge' => 'open_in'],
        ['path' => '/mails/outgoing', 'icon' => 'send', 'label' => 'nav.outgoing', 'perm' => 'mail.view', 'badge' => 'drafts'],
        ['path' => '/mails/directory', 'icon' => 'book-open', 'label' => 'nav.directory', 'perm' => 'mail.directory'],
        ['path' => '/mails/archives', 'icon' => 'archive', 'label' => 'nav.archives', 'perm' => 'mail.view'],
    ]],
    ['id' => 'masterdata', 'section' => 'nav.section.masterdata', 'items' => [
        ['path' => '/correspondents', 'icon' => 'contact', 'label' => 'nav.correspondents', 'perm' => 'correspondents.manage'],
    ]],
    ['id' => 'admin', 'section' => 'nav.section.admin', 'collapsible' => true, 'items' => [
        ['path' => '/units', 'icon' => 'network', 'label' => 'nav.units', 'perm' => 'units.manage'],
        ['path' => '/users', 'icon' => 'users', 'label' => 'nav.users', 'perm' => 'users.manage'],
        ['path' => '/activity', 'icon' => 'history', 'label' => 'nav.activity', 'perm' => 'activity.view'],
        ['path' => '/settings/mail', 'icon' => 'settings', 'label' => 'nav.mail_settings', 'perm' => 'mail.settings'],
        ['path' => '/mail-log', 'icon' => 'mail-check', 'label' => 'nav.mail_log', 'perm' => 'mail.log'],
    ]],
];
