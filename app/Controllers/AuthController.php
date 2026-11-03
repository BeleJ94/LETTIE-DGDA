<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Security\Auth;
use App\Services\ActivityLogger;

final class AuthController extends Controller
{
    public function showLogin(Request $request): Response
    {
        if (Auth::user() !== null) {
            return Response::redirect('/');
        }
        return $this->view('auth/login', ['title' => t('auth.title')], 'minimal');
    }

    public function login(Request $request): Response
    {
        $email = (string) $request->input('email', '');
        // Freinage contre la force brute, en base : il ne dépend pas du cookie de session.
        $pdo = Database::pdo();
        $key = [mb_substr(mb_strtolower($email), 0, 190), (string) ($_SERVER['REMOTE_ADDR'] ?? '')];
        $count = $pdo->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND ip = ? AND created_at > NOW() - INTERVAL 1 MINUTE');
        $count->execute($key);
        if ((int) $count->fetchColumn() >= 5) {
            Session::flash('error', t('auth.throttled'));
            return Response::redirect('/login');
        }
        if (!Auth::attempt($email, (string) $request->input('password', ''))) {
            $pdo->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 1 DAY');
            $pdo->prepare('INSERT INTO login_attempts (email, ip) VALUES (?, ?)')->execute($key);
            Session::flash('error', t('auth.failed'));
            Session::flash('_old', ['email' => $email]);
            return Response::redirect('/login');
        }
        $pdo->prepare('DELETE FROM login_attempts WHERE email = ? AND ip = ?')->execute($key);
        ActivityLogger::log('user', Auth::user()?->id, 'login');
        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        Auth::logout();
        return Response::redirect('/login');
    }
}
