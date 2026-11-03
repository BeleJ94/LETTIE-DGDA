<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;
use App\Core\View;
use App\Domain\DomainException;
use App\Domain\MailAccess;
use App\Repositories\MailRepository;
use App\Repositories\OrgRepository;
use App\Services\MailService;

/** Registre et dossier électronique des correspondances. */
final class MailController extends Controller
{
    private const CHANNELS = ['hand', 'post', 'email', 'courier', 'other'];
    private const PRIORITIES = ['normal', 'urgent', 'very_urgent'];

    public function incoming(Request $request): Response
    {
        return $this->register('incoming', ['direction' => 'in'], 'mail-open', ['counts' => (new MailRepository())->incomingCounts($this->user())]);
    }

    public function outgoing(Request $request): Response
    {
        return $this->register('outgoing', ['direction' => 'out'], 'send', ['counts' => (new MailRepository())->outgoingCounts($this->user())]);
    }

    public function directory(Request $request): Response
    {
        $this->authorize('mail.directory');
        return $this->register('directory', ['view' => 'directory'], 'book-open', ['stats' => (new MailRepository())->directoryCounts($this->user())]);
    }

    public function archives(Request $request): Response
    {
        $folder = (int) $request->query('folder');
        $fixed = ['status' => 'closed'] + ($folder > 0 ? ['folder' => $folder] : []);
        return $this->register('archives', $fixed, 'archive', ['folders' => (new MailRepository())->archiveFolders($this->user()), 'folder' => $folder]);
    }

    private function register(string $page, array $fixed, string $icon, array $extra = []): Response
    {
        $this->authorize('mail.view');
        $org = new OrgRepository();
        return $this->view('mails/index', ['title' => t('nav.' . $page), 'page' => $page, 'fixed' => $fixed, 'icon' => $icon, 'units' => $org->units(), 'correspondents' => $org->correspondents()] + $extra);
    }

    public function data(Request $request): Response
    {
        $this->authorize('mail.view');
        return (new MailRepository())->datatable($request, $this->user());
    }

    /** Détail d'un indicateur, pour la modale : limité à ce que l'utilisateur peut lire. */
    public function drill(Request $request): Response
    {
        $this->authorize('mail.view');
        $rows = (new MailRepository())->drill($this->user(), (string) $request->query('key'), (int) $request->query('param'));
        if ($rows === null) {
            throw new HttpException(404);
        }
        foreach ($rows as &$r) {
            $r['href'] = url('/mails/' . (int) $r['id']);
            $r['priority'] = t('mail.priority.' . $r['priority']);
            $r['status'] = t('mail.status.' . $r['status']);
        }
        unset($r);
        $columns = [['key' => 'reference', 'title' => t('mail.col.reference'), 'type' => 'text'], ['key' => 'event_at', 'title' => t('mail.col.date'), 'type' => 'datetime'],
            ['key' => 'subject', 'title' => t('mail.col.subject'), 'type' => 'text'], ['key' => 'correspondent', 'title' => t('mail.col.correspondent'), 'type' => 'text'],
            ['key' => 'unit', 'title' => t('mail.col.unit'), 'type' => 'text'], ['key' => 'priority', 'title' => t('mail.col.priority'), 'type' => 'text'],
            ['key' => 'due_on', 'title' => t('mail.col.due'), 'type' => 'date'], ['key' => 'status', 'title' => t('mail.col.status'), 'type' => 'text']];
        return Response::json(['columns' => $columns, 'rows' => $rows]);
    }

    public function create(Request $request): Response
    {
        $this->authorize('mail.register');
        return $this->form(null, $request->query('direction') === 'out' ? 'out' : 'in');
    }

    private function form(?array $mail, string $direction): Response
    {
        return $this->view('mails/form', [
            'title' => $mail ? $mail['reference'] : t($direction === 'in' ? 'mail.new_in' : 'mail.new_out'),
            'm' => $mail, 'direction' => $direction, 'correspondents' => (new OrgRepository())->correspondents(true),
        ]);
    }

    public function store(Request $request): Response
    {
        $this->authorize('mail.register');
        $input = $request->all();
        $direction = ($input['direction'] ?? '') === 'out' ? 'out' : 'in';
        [$data, $errors] = $this->parse($input);
        $back = '/mails/new?direction=' . $direction;
        if ($errors) {
            return $this->backWithErrors($back, $errors, $input);
        }
        try {
            $id = (new MailService())->register($data + ['direction' => $direction], self::files('files'));
        } catch (DomainException $e) {
            return $this->domainError($back, $e, $input);
        }
        return $this->success('/mails/' . $id, 'mail.registered');
    }

    public function show(Request $request): Response
    {
        [$mail, $repo] = $this->readable($request);
        $user = $this->user();
        // Consultation d'un courrier sensible : tracée dans la chronologie
        if ((int) $mail['confidentiality'] > 0) {
            (new MailService())->event((int) $mail['id'], 'consulted');
        }
        // Une pièce liée dépasse parfois l'habilitation ou le périmètre : elle reste hors de la fiche
        $family = array_values(array_filter($repo->family($mail), fn ($f) => (int) $f['id'] === (int) $mail['id'] || $this->canRead($repo, $f)));
        return $this->view('mails/show', [
            'title' => $mail['reference'],
            'm' => $mail,
            'family' => $family,
            'documents' => $repo->documents($family),
            'events' => $repo->events($family),
            'chainOk' => $repo->chainValid($family),
            'assignments' => $repo->assignments((int) $mail['id']),
            'units' => (new OrgRepository())->units(true),
            'canAct' => MailAccess::canAct($user, $mail),
        ]);
    }

    public function edit(Request $request): Response
    {
        $this->authorize('mail.register');
        [$mail] = $this->readable($request);
        return $this->form($mail, $mail['direction']);
    }

    public function update(Request $request): Response
    {
        $this->authorize('mail.register');
        [$mail] = $this->readable($request);
        $input = $request->all();
        [$data, $errors] = $this->parse($input);
        $back = '/mails/' . $mail['id'] . '/edit';
        if ($errors) {
            return $this->backWithErrors($back, $errors, $input);
        }
        return $this->act($back, fn ($s) => $s->update($mail, $data), '/mails/' . $mail['id'], 'mail.updated', $input);
    }

    public function assign(Request $request): Response
    {
        $this->authorize('mail.assign');
        [$mail] = $this->readable($request);
        $input = $request->all();
        $unit = (new OrgRepository())->unit((int) ($input['to_unit_id'] ?? 0));
        $path = '/mails/' . $mail['id'];
        if (!$unit || !$unit['active']) {
            return $this->backWithErrors($path, ['to_unit_id' => t('validation.required')], []);
        }
        $kind = ($input['kind'] ?? '') === 'copy' ? 'copy' : 'action';
        return $this->act($path, fn ($s) => $s->assign($mail, (int) $unit['id'], $kind, self::nul(trim((string) ($input['instruction'] ?? ''))), self::nul($input['due_on'] ?? null)), $path, 'mail.assigned');
    }

    public function take(Request $request): Response
    {
        $this->authorize('mail.reply');
        [$mail] = $this->readable($request);
        $path = '/mails/' . $mail['id'];
        return $this->act($path, fn ($s) => $s->take($mail), $path, 'mail.taken');
    }

    public function acknowledge(Request $request): Response
    {
        $this->authorize('mail.register');
        [$mail] = $this->readable($request);
        $channel = $request->input('channel') === 'email' ? 'email' : 'print';
        $path = '/mails/' . $mail['id'];
        return $this->act($path, fn ($s) => $s->acknowledge($mail, $channel), $path, 'mail.acknowledged');
    }

    public function reply(Request $request): Response
    {
        $this->authorize('mail.reply');
        [$mail] = $this->readable($request);
        $input = $request->all();
        $path = '/mails/' . $mail['id'];
        $subject = trim((string) ($input['subject'] ?? ''));
        if ($subject === '') {
            return $this->backWithErrors($path, ['subject' => t('validation.required')], $input);
        }
        try {
            $id = (new MailService())->reply($mail, [
                'subject' => mb_substr($subject, 0, 255), 'summary' => self::nul(trim((string) ($input['summary'] ?? ''))),
                'event_at' => date('Y-m-d H:i:s'), 'channel' => 'hand',
            ], self::files('files'));
        } catch (DomainException $e) {
            return $this->domainError($path, $e);
        }
        return $this->success('/mails/' . $id, 'mail.reply_created');
    }

    public function send(Request $request): Response
    {
        $this->authorize('mail.reply');
        [$mail] = $this->readable($request);
        $channel = in_array($request->input('channel'), self::CHANNELS, true) ? (string) $request->input('channel') : 'hand';
        $path = '/mails/' . $mail['id'];
        return $this->act($path, fn ($s) => $s->send($mail, $channel, $_FILES['proof'] ?? null), $path, 'mail.sent');
    }

    public function close(Request $request): Response
    {
        $this->authorize('mail.close');
        [$mail] = $this->readable($request);
        $path = '/mails/' . $mail['id'];
        return $this->act($path, fn ($s) => $s->close($mail, self::nul(mb_substr(trim((string) $request->input('note')), 0, 500))), $path, 'mail.closed');
    }

    public function cancel(Request $request): Response
    {
        $this->authorize('mail.reply');
        [$mail] = $this->readable($request);
        $path = '/mails/' . $mail['id'];
        return $this->act($path, fn ($s) => $s->cancel($mail, self::nul(mb_substr(trim((string) $request->input('note')), 0, 500))), $path, 'mail.cancelled');
    }

    public function addDocument(Request $request): Response
    {
        $this->authorize('mail.reply');
        [$mail] = $this->readable($request);
        $kind = (string) $request->input('kind');
        $path = '/mails/' . $mail['id'];
        if (!in_array($kind, MailService::DOC_KINDS, true) || empty($_FILES['file'])) {
            return $this->domainError($path, new DomainException('mail.error.upload'));
        }
        return $this->act($path, fn ($s) => $s->addDocument($mail, $kind, $_FILES['file']), $path, 'mail.document_added');
    }

    /** Fiche de circulation : suit le courrier papier, avec son QR code. */
    public function slip(Request $request): Response
    {
        [$mail, $repo] = $this->readable($request);
        return $this->printable('mails/slip', $mail, ['assignments' => $repo->assignments((int) $mail['id'])]);
    }

    public function receipt(Request $request): Response
    {
        [$mail] = $this->readable($request);
        return $this->printable('mails/receipt', $mail, []);
    }

    private function printable(string $view, array $mail, array $extra): Response
    {
        $qr = (new MailService())->qrSvg(MailService::trackUrl($mail));
        return Response::html(View::render($view, ['title' => $mail['reference'], 'm' => $mail, 'qr' => $qr] + $extra, 'print'));
    }

    public function document(Request $request): Response
    {
        $repo = new MailRepository();
        $doc = $this->orNotFound($repo->document($request->intParam('id')));
        $mail = $this->orNotFound($repo->find((int) $doc['mail_id']));
        if (!$this->canRead($repo, $mail)) {
            throw new HttpException(403);
        }
        $path = MailService::dir() . '/' . $doc['stored_name'];
        if (!is_file($path)) {
            throw new HttpException(404);
        }
        if ((int) $mail['confidentiality'] > 0) {
            (new MailService())->event((int) $mail['id'], 'document_opened', $doc['original_name']);
        }
        $disposition = $request->query('download') ? 'attachment' : 'inline';
        header('X-Content-Type-Options: nosniff');
        return new Response((string) file_get_contents($path), 200, [
            'Content-Type' => $doc['mime'],
            'Content-Disposition' => $disposition . '; filename="' . str_replace(['"', "\r", "\n"], '', $doc['original_name']) . '"',
        ]);
    }

    /** Page publique du QR code. */
    public function track(Request $request): Response
    {
        $mail = $this->orNotFound((new MailRepository())->findByToken($request->param('token')));
        return $this->view('mails/track', ['title' => t('track.title'), 'm' => $mail], null);
    }

    /** @return array{0:array,1:MailRepository} */
    private function readable(Request $request): array
    {
        $this->authorize('mail.view');
        $repo = new MailRepository();
        $mail = $this->orNotFound($repo->find($request->intParam('id')));
        if (!$this->canRead($repo, $mail)) {
            throw new HttpException(403);
        }
        return [$mail, $repo];
    }

    private function canRead(MailRepository $repo, array $mail): bool
    {
        return MailAccess::canRead($this->user(), $mail, $repo->assignedUnits((int) $mail['id']));
    }

    private function act(string $errorPath, callable $fn, string $path, string $key, array $old = []): Response
    {
        try {
            $fn(new MailService());
        } catch (DomainException $e) {
            return $this->domainError($errorPath, $e, $old);
        }
        return $this->success($path, $key);
    }

    /** @return array{0:array<string,mixed>,1:array<string,string>} */
    private function parse(array $input): array
    {
        $v = new Validator($input, ['subject' => 'required|max:255', 'correspondent_id' => 'required|int', 'event_at' => 'required', 'letter_date' => 'date', 'due_on' => 'date', 'external_ref' => 'max:100']);
        $errors = $v->errors();
        $at = strtotime((string) ($input['event_at'] ?? ''));
        if ($at === false && !isset($errors['event_at'])) {
            $errors['event_at'] = t('validation.date');
        }
        return [[
            'correspondent_id' => (int) ($input['correspondent_id'] ?? 0),
            'external_ref' => self::nul(trim((string) ($input['external_ref'] ?? ''))),
            'letter_date' => self::nul($input['letter_date'] ?? null),
            'event_at' => $at !== false ? date('Y-m-d H:i:s', $at) : null,
            'channel' => in_array($input['channel'] ?? '', self::CHANNELS, true) ? $input['channel'] : 'hand',
            'subject' => trim((string) ($input['subject'] ?? '')),
            'summary' => self::nul(trim((string) ($input['summary'] ?? ''))),
            'priority' => in_array($input['priority'] ?? '', self::PRIORITIES, true) ? $input['priority'] : 'normal',
            'confidentiality' => array_key_exists((int) ($input['confidentiality'] ?? 0), MailAccess::LEVELS) ? (int) ($input['confidentiality'] ?? 0) : 0,
            'due_on' => self::nul($input['due_on'] ?? null),
        ], $errors];
    }

    /** Champ fichier multiple → liste d'entrées $_FILES. */
    private static function files(string $name): array
    {
        $f = $_FILES[$name] ?? null;
        if (!$f || !is_array($f['name'])) {
            return [];
        }
        $out = [];
        foreach (array_keys($f['name']) as $i) {
            $out[] = ['name' => $f['name'][$i], 'tmp_name' => $f['tmp_name'][$i], 'size' => $f['size'][$i], 'error' => $f['error'][$i]];
        }
        return $out;
    }
}
