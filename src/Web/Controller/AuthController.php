<?php

declare(strict_types=1);

namespace MamaMboga\Web\Controller;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\SettingsRepository;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\View\PageRenderer;

final class AuthController extends BaseController
{
    public function __construct(PageRenderer $pages, Clock $clock, private readonly SettingsRepository $settings)
    {
        parent::__construct($pages, $clock);
    }

    public function form(Request $request): Response
    {
        if ($this->session($request)->get('owner') === true) {
            return Response::redirect('/owner');
        }

        return $this->page($request, 'owner/login', [
            'next' => $this->safeNext($request->query('next'), '/owner'),
            'configured' => $this->settings->get('owner_pin_hash') !== '',
            'failed' => false,
        ]);
    }

    public function login(Request $request): Response
    {
        $hash = $this->settings->get('owner_pin_hash');
        $next = $this->safeNext($request->input('next'), '/owner');
        $secret = $request->input('pin');

        if ($hash !== '' && $secret !== '' && password_verify($secret, $hash)) {
            $session = $this->session($request);
            $session->regenerate();
            $session->put('owner', true);

            return Response::redirect($next);
        }

        return $this->page($request, 'owner/login', ['next' => $next, 'configured' => $hash !== '', 'failed' => true], 422);
    }

    public function logout(Request $request): Response
    {
        $this->session($request)->destroy();

        return Response::redirect('/');
    }
}
