<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Validator;
use App\Domain\DomainException;
use App\Repositories\OrgRepository;
use App\Security\Role;
use App\Services\ActivityLogger;
use App\Services\Mailer;
use App\Services\MailSettings;

/** Référentiels : correspondants, organisation, utilisateurs, journal. */
final class AdminController extends Controller
{
    public function correspondents(Request $request): Response
    {
        $this->authorize('correspondents.manage');
        return $this->view('admin/correspondents', ['title' => t('nav.correspondents'), 'rows' => (new OrgRepository())->correspondents()]);
    }

    public function saveCorrespondent(Request $request): Response
    {
        $this->authorize('correspondents.manage');
        $input = $request->all();
        $v = new Validator($input, ['name' => 'required|max:190', 'email' => 'max:190', 'phone' => 'max:60', 'address' => 'max:255']);
        if ($v->errors()) {
            return $this->backWithErrors('/correspondents', $v->errors(), $input);
        }
        $kind = in_array($input['kind'] ?? '', ['administration', 'company', 'person', 'other'], true) ? $input['kind'] : 'other';
        $values = [trim($input['name']), $kind, self::nul($input['email'] ?? null), self::nul($input['phone'] ?? null), self::nul($input['address'] ?? null)];
        $id = isset($request->params['id']) ? $request->intParam('id') : 0;
        $pdo = Database::pdo();
        if ($id) {
            $pdo->prepare('UPDATE correspondents SET name = ?, kind = ?, email = ?, phone = ?, address = ?, active = ? WHERE id = ?')->execute([...$values, isset($input['active']) ? 1 : 0, $id]);
        } else {
            $pdo->prepare('INSERT INTO correspondents (name, kind, email, phone, address) VALUES (?,?,?,?,?)')->execute($values);
            $id = (int) $pdo->lastInsertId();
        }
        ActivityLogger::log('correspondent', $id, 'save', null, ['name' => $values[0]]);
        // Depuis le formulaire d'enregistrement : retour sur ce formulaire, le nouveau correspondant y est disponible
        return $this->success(($input['back'] ?? '') === 'mail-in' ? '/mails/new?direction=in' : '/correspondents', 'common.saved');
    }

    /** Création depuis la saisie d'un courrier : un nom déjà connu renvoie la fiche existante plutôt qu'un doublon. */
    public function quickCorrespondent(Request $request): Response
    {
        $this->authorize('correspondents.manage');
        $name = trim((string) $request->input('name'));
        $email = trim((string) $request->input('email'));
        if ($name === '' || mb_strlen($name) > 190) {
            return Response::json(['ok' => false, 'message' => t('validation.required')], 422);
        }
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return Response::json(['ok' => false, 'message' => t('validation.email')], 422);
        }
        $pdo = Database::pdo();
        $found = $pdo->prepare('SELECT id, name, email FROM correspondents WHERE name = ? AND active = 1 LIMIT 1');
        $found->execute([$name]);
        if ($row = $found->fetch()) {
            return Response::json(['ok' => true, 'id' => (int) $row['id'], 'name' => $row['name'], 'email' => $row['email'], 'existing' => true]);
        }
        $kind = in_array($request->input('kind'), ['administration', 'company', 'person', 'other'], true) ? $request->input('kind') : 'company';
        $pdo->prepare('INSERT INTO correspondents (name, kind, email, phone) VALUES (?,?,?,?)')
            ->execute([$name, $kind, self::nul($email), self::nul(mb_substr(trim((string) $request->input('phone')), 0, 60))]);
        $id = (int) $pdo->lastInsertId();
        ActivityLogger::log('correspondent', $id, 'save', null, ['name' => $name]);
        return Response::json(['ok' => true, 'id' => $id, 'name' => $name, 'email' => $email ?: null, 'existing' => false]);
    }

    public function units(Request $request): Response
    {
        $this->authorize('units.manage');
        return $this->view('admin/units', ['title' => t('nav.units'), 'rows' => (new OrgRepository())->units()]);
    }

    public function saveUnit(Request $request): Response
    {
        $this->authorize('units.manage');
        $input = $request->all();
        $v = new Validator($input, ['code' => 'required|max:20', 'name' => 'required|max:160']);
        if ($v->errors()) {
            return $this->backWithErrors('/units', $v->errors(), $input);
        }
        $id = isset($request->params['id']) ? $request->intParam('id') : 0;
        $parent = ($input['parent_id'] ?? '') !== '' ? (int) $input['parent_id'] : null;
        $kind = in_array($input['kind'] ?? '', ['direction', 'sous_direction', 'service', 'bureau'], true) ? $input['kind'] : 'service';
        // Une unité ne peut pas être rattachée à elle-même ni à l'une de ses unités
        $tree = Database::pdo()->query('SELECT id, parent_id FROM units')->fetchAll();
        if ($id && $parent !== null && in_array($parent, \App\Domain\UnitTree::subtree($tree, $id), true)) {
            return $this->backWithErrors('/units', ['parent_id' => t('units.error.cycle')], $input, t('units.error.cycle'));
        }
        $values = [$parent, $kind, mb_strtoupper(trim($input['code'])), trim($input['name']), isset($input['is_mail_center']) ? 1 : 0];
        $pdo = Database::pdo();
        try {
            if ($id) {
                $pdo->prepare('UPDATE units SET parent_id = ?, kind = ?, code = ?, name = ?, is_mail_center = ?, active = ? WHERE id = ?')->execute([...$values, isset($input['active']) ? 1 : 0, $id]);
            } else {
                $pdo->prepare('INSERT INTO units (parent_id, kind, code, name, is_mail_center) VALUES (?,?,?,?,?)')->execute($values);
                $id = (int) $pdo->lastInsertId();
            }
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return $this->backWithErrors('/units', ['code' => t('validation.unique')], $input, t('validation.unique'));
        }
        ActivityLogger::log('unit', $id, 'save', null, ['code' => $values[2], 'name' => $values[3]]);
        return $this->success('/units', 'common.saved');
    }

    public function users(Request $request): Response
    {
        $this->authorize('users.manage');
        $org = new OrgRepository();
        return $this->view('admin/users', ['title' => t('nav.users'), 'rows' => $org->users(), 'units' => $org->units(true)]);
    }

    public function saveUser(Request $request): Response
    {
        $this->authorize('users.manage');
        $input = $request->all();
        $id = isset($request->params['id']) ? $request->intParam('id') : 0;
        $v = new Validator($input, ['name' => 'required|max:120', 'email' => 'required|max:190']);
        $errors = $v->errors();
        $password = (string) ($input['password'] ?? '');
        if (!filter_var($input['email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = t('validation.email');
        }
        if ((!$id && strlen($password) < 10) || ($password !== '' && strlen($password) < 10)) {
            $errors['password'] = t('users.error.password');
        }
        if ($errors) {
            return $this->backWithErrors('/users', $errors, $input, reset($errors));
        }
        $role = Role::tryFrom((string) ($input['role'] ?? ''))?->value ?? 'agent';
        $unit = ($input['unit_id'] ?? '') !== '' ? (int) $input['unit_id'] : null;
        $clearance = max(0, min(2, (int) ($input['clearance'] ?? 0)));
        $email = mb_strtolower(trim($input['email']));
        $values = [trim($input['name']), $email, $role, $unit, $clearance];
        $pdo = Database::pdo();
        try {
            if ($id) {
                // Un compte ne peut pas se désactiver lui-même
                $active = isset($input['active']) || $id === $this->user()->id ? 1 : 0;
                $pdo->prepare('UPDATE users SET name = ?, email = ?, role = ?, unit_id = ?, clearance = ?, active = ? WHERE id = ?')->execute([...$values, $active, $id]);
                if ($password !== '') {
                    $pdo->prepare('UPDATE users SET password_hash = ? WHERE id = ?')->execute([password_hash($password, PASSWORD_DEFAULT), $id]);
                }
            } else {
                $pdo->prepare('INSERT INTO users (name, email, role, unit_id, clearance, password_hash) VALUES (?,?,?,?,?,?)')->execute([...$values, password_hash($password, PASSWORD_DEFAULT)]);
                $id = (int) $pdo->lastInsertId();
            }
        } catch (\PDOException $e) {
            if ($e->getCode() !== '23000') {
                throw $e;
            }
            return $this->backWithErrors('/users', ['email' => t('validation.unique')], $input, t('validation.unique'));
        }
        ActivityLogger::log('user', $id, 'save', null, ['email' => $email, 'role' => $role, 'unit_id' => $unit, 'clearance' => $clearance]);
        return $this->success('/users', 'common.saved');
    }

    public function activity(Request $request): Response
    {
        $this->authorize('activity.view');
        return $this->view('admin/activity', ['title' => t('nav.activity'), 'rows' => (new OrgRepository())->activity()]);
    }

    public function mailSettings(Request $request): Response
    {
        $this->authorize('mail.settings');
        return $this->view('admin/mail_settings', ['title' => t('nav.mail_settings'), 's' => MailSettings::current(), 'stats' => ['me' => $this->user()->email]]);
    }

    public function saveMailSettings(Request $request): Response
    {
        $this->authorize('mail.settings');
        $input = $request->all();
        $v = new Validator($input, ['host' => 'required|max:190', 'from_email' => 'required|max:190', 'from_name' => 'required|max:190', 'username' => 'max:190']);
        $errors = $v->errors();
        $port = (int) ($input['port'] ?? 0);
        if ($port < 1 || $port > 65535) {
            $errors['port'] = t('settings.error.port');
        }
        if (!filter_var($input['from_email'] ?? '', FILTER_VALIDATE_EMAIL)) {
            $errors['from_email'] = t('validation.email');
        }
        if ($errors) {
            return $this->backWithErrors('/settings/mail', $errors, $input);
        }
        $data = ['host' => trim($input['host']), 'port' => $port, 'encryption' => in_array($input['encryption'] ?? '', ['tls', 'ssl', 'none'], true) ? $input['encryption'] : 'tls',
            'username' => trim((string) ($input['username'] ?? '')), 'from_email' => trim($input['from_email']), 'from_name' => trim($input['from_name'])];
        $password = isset($input['clear_password']) ? '' : self::nul((string) ($input['password'] ?? ''));
        try {
            MailSettings::save($data, $password, $this->user()->id);
        } catch (DomainException $e) {
            return $this->domainError('/settings/mail', $e);
        }
        // Le mot de passe n'entre jamais au journal
        ActivityLogger::log('mail_settings', 1, 'save', null, $data);
        return $this->success('/settings/mail', 'common.saved');
    }

    public function testMail(Request $request): Response
    {
        $this->authorize('mail.settings');
        $to = trim((string) $request->input('to'));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return $this->backWithErrors('/settings/mail', ['to' => t('validation.email')], [], t('validation.email'));
        }
        try {
            Mailer::send($to, t('settings.test_subject'), t('settings.test_body'), 'test');
        } catch (DomainException $e) {
            return $this->domainError('/mail-log', $e);
        }
        return $this->success('/mail-log', 'settings.test_ok');
    }

    public function mailLog(Request $request): Response
    {
        $this->authorize('mail.log');
        $status = in_array($request->query('status'), ['sent', 'failed'], true) ? (string) $request->query('status') : '';
        $org = new OrgRepository();
        return $this->view('admin/mail_log', ['title' => t('nav.mail_log'), 'rows' => $org->emailLog($status), 'stats' => $org->emailStats(), 'status' => $status]);
    }
}
