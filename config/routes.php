<?php

declare(strict_types=1);

use MamaMboga\Infrastructure\Http\Middleware\Authenticate;
use MamaMboga\Infrastructure\Http\Router;
use MamaMboga\Web\Controller\AuthController;
use MamaMboga\Web\Controller\MenuController;
use MamaMboga\Web\Controller\OwnerBoardController;
use MamaMboga\Web\Controller\OwnerDeniController;
use MamaMboga\Web\Controller\OwnerListsController;
use MamaMboga\Web\Controller\OwnerOrdersController;
use MamaMboga\Web\Controller\OwnerPasteController;
use MamaMboga\Web\Controller\OwnerSettingsController;

return static function (Router $r): void {
    // Public: no login to browse or order.
    $r->get('/', [MenuController::class, 'index']);
    $r->post('/order', [MenuController::class, 'place'], ['MamaMboga\Infrastructure\Http\Middleware\RateLimit:order,12,600']);
    $r->get('/order/{reference:[A-Z0-9-]+}/{sig:[A-Za-z0-9_-]+}', [MenuController::class, 'confirmation']);
    $r->get('/s/{id:\d+}/{expires:\d+}/{sig:[A-Za-z0-9_-]+}', [MenuController::class, 'statement']);
    $r->get('/offline', [MenuController::class, 'offline']);
    $r->get('/healthz', [MenuController::class, 'health']);

    // Owner sign-in.
    $r->get('/owner/login', [AuthController::class, 'form']);
    $r->post('/owner/login', [AuthController::class, 'login'], ['MamaMboga\Infrastructure\Http\Middleware\RateLimit:login,8,900']);

    // Owner console.
    $r->group('/owner', [Authenticate::class], static function (Router $r): void {
        $r->post('/logout', [AuthController::class, 'logout']);

        $r->get('', [OwnerOrdersController::class, 'index']);
        $r->get('/orders/{id:\d+}', [OwnerOrdersController::class, 'show']);
        $r->post('/orders/{id:\d+}/status', [OwnerOrdersController::class, 'status']);

        $r->get('/board', [OwnerBoardController::class, 'index']);
        $r->post('/board', [OwnerBoardController::class, 'save']);
        $r->post('/board/add', [OwnerBoardController::class, 'add']);
        $r->post('/board/copy', [OwnerBoardController::class, 'copy']);
        $r->post('/board/slots', [OwnerBoardController::class, 'slots']);
        $r->post('/board/{id:\d+}/remove', [OwnerBoardController::class, 'remove']);
        $r->get('/items', [OwnerBoardController::class, 'items']);
        $r->post('/items/{id:\d+}', [OwnerBoardController::class, 'updateItem']);

        $r->get('/paste', [OwnerPasteController::class, 'form']);
        $r->post('/paste', [OwnerPasteController::class, 'parse']);
        $r->post('/paste/confirm', [OwnerPasteController::class, 'confirm']);

        $r->get('/shopping', [OwnerListsController::class, 'shopping']);
        $r->post('/shopping/check', [OwnerListsController::class, 'check']);
        $r->get('/run-sheet', [OwnerListsController::class, 'runSheet']);
        $r->get('/takings', [OwnerListsController::class, 'takings']);

        $r->get('/deni', [OwnerDeniController::class, 'index']);
        $r->post('/deni/customers', [OwnerDeniController::class, 'addCustomer']);
        $r->get('/deni/{id:\d+}', [OwnerDeniController::class, 'show']);
        $r->post('/deni/{id:\d+}/payment', [OwnerDeniController::class, 'payment']);
        $r->post('/deni/{id:\d+}/collect', [OwnerDeniController::class, 'collect']);
        $r->post('/deni/prompts/{rid:\d+}/check', [OwnerDeniController::class, 'checkPrompt']);
        $r->post('/deni/prompts/{rid:\d+}/review', [OwnerDeniController::class, 'reviewPrompt']);
        $r->post('/deni/{id:\d+}/charge', [OwnerDeniController::class, 'charge']);
        $r->post('/deni/{id:\d+}/credit-note', [OwnerDeniController::class, 'creditNote']);
        $r->post('/deni/{id:\d+}/limit', [OwnerDeniController::class, 'limit']);

        $r->get('/settings', [OwnerSettingsController::class, 'form']);
        $r->post('/settings', [OwnerSettingsController::class, 'save']);
        $r->post('/settings/pin', [OwnerSettingsController::class, 'pin'], ['MamaMboga\Infrastructure\Http\Middleware\RateLimit:pin,6,900']);
    });
};
