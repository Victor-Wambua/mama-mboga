<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\View;

use MamaMboga\Application\Service\ShopProfile;
use MamaMboga\Infrastructure\Http\Middleware\Csrf;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\Security\Session;

/** Renders a template with the per-request extras every page needs: CSRF token, flash messages, shop details. */
final class PageRenderer
{
    public function __construct(private readonly View $view, private readonly ShopProfile $shop)
    {
    }

    /** @param array<string, mixed> $data */
    public function render(Request $request, string $template, array $data = []): string
    {
        $session = $request->attribute('session');
        $shared = [
            'csrfToken' => '',
            'flash' => [],
            'isOwner' => false,
            'path' => $request->path,
            'shopName' => $this->shop->name(),
            'shopTagline' => $this->shop->tagline(),
        ];
        if ($session instanceof Session) {
            $shared['csrfToken'] = Csrf::token($session);
            $shared['flash'] = $session->pullFlash();
            $shared['isOwner'] = $session->get('owner') === true;
        }

        return $this->view->render($template, $data, $shared);
    }

    /** @param array<string, mixed> $data */
    public function html(Request $request, string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->render($request, $template, $data), $status);
    }
}
