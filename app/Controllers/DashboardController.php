<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Request;
use App\Core\Response;
use App\Repositories\MailRepository;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('dashboard.view');
        $repo = new MailRepository();
        $user = $this->user();
        return $this->view('dashboard/index', [
            'title' => t('nav.dashboard'),
            'k' => $repo->kpis($user),
            'units' => $repo->byUnit($user),
            'urgent' => $repo->urgent($user),
        ]);
    }
}
