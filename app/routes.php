<?php

declare(strict_types=1);

use App\Controllers\AdminController;
use App\Controllers\AuthController;
use App\Controllers\ContextController;
use App\Controllers\DashboardController;
use App\Controllers\MailController;
use App\Core\Router;

return function (Router $r): void {
    $r->get('/login', [AuthController::class, 'showLogin'], null);
    $r->post('/login', [AuthController::class, 'login'], null);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->post('/context/locale', [ContextController::class, 'locale'], null);
    // Lien du QR code : état du courrier, sans objet ni correspondant
    $r->get('/track/{token}', [MailController::class, 'track'], null);

    $r->get('/', [DashboardController::class, 'index']);

    $r->get('/mails/incoming', [MailController::class, 'incoming']);
    $r->get('/mails/outgoing', [MailController::class, 'outgoing']);
    $r->get('/mails/directory', [MailController::class, 'directory']);
    $r->get('/mails/archives', [MailController::class, 'archives']);
    $r->get('/mails/data', [MailController::class, 'data']);
    $r->get('/mails/drill', [MailController::class, 'drill']);
    $r->get('/mails/new', [MailController::class, 'create']);
    $r->post('/mails', [MailController::class, 'store']);
    $r->get('/mails/{id}', [MailController::class, 'show']);
    $r->get('/mails/{id}/edit', [MailController::class, 'edit']);
    $r->post('/mails/{id}', [MailController::class, 'update']);
    $r->post('/mails/{id}/assign', [MailController::class, 'assign']);
    $r->post('/mails/{id}/take', [MailController::class, 'take']);
    $r->post('/mails/{id}/ack', [MailController::class, 'acknowledge']);
    $r->post('/mails/{id}/reply', [MailController::class, 'reply']);
    $r->post('/mails/{id}/send', [MailController::class, 'send']);
    $r->post('/mails/{id}/close', [MailController::class, 'close']);
    $r->post('/mails/{id}/cancel', [MailController::class, 'cancel']);
    $r->post('/mails/{id}/documents', [MailController::class, 'addDocument']);
    $r->get('/mails/{id}/slip', [MailController::class, 'slip']);
    $r->get('/mails/{id}/receipt', [MailController::class, 'receipt']);
    $r->get('/documents/{id}', [MailController::class, 'document']);

    $r->get('/correspondents', [AdminController::class, 'correspondents']);
    $r->post('/correspondents', [AdminController::class, 'saveCorrespondent']);
    $r->post('/correspondents/quick', [AdminController::class, 'quickCorrespondent']);
    $r->post('/correspondents/{id}', [AdminController::class, 'saveCorrespondent']);
    $r->get('/units', [AdminController::class, 'units']);
    $r->post('/units', [AdminController::class, 'saveUnit']);
    $r->post('/units/{id}', [AdminController::class, 'saveUnit']);
    $r->get('/users', [AdminController::class, 'users']);
    $r->post('/users', [AdminController::class, 'saveUser']);
    $r->post('/users/{id}', [AdminController::class, 'saveUser']);
    $r->get('/activity', [AdminController::class, 'activity']);
    $r->get('/settings/mail', [AdminController::class, 'mailSettings']);
    $r->post('/settings/mail', [AdminController::class, 'saveMailSettings']);
    $r->post('/settings/mail/test', [AdminController::class, 'testMail']);
    $r->get('/mail-log', [AdminController::class, 'mailLog']);
};
