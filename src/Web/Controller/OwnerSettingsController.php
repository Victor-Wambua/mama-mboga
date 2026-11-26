<?php

declare(strict_types=1);

namespace MamaMboga\Web\Controller;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\SettingsRepository;
use MamaMboga\Application\Service\ShopProfile;
use MamaMboga\Domain\Money\PhoneNumber;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\View\PageRenderer;

final class OwnerSettingsController extends BaseController
{
    public function __construct(
        PageRenderer $pages,
        Clock $clock,
        private readonly SettingsRepository $settings,
        private readonly ShopProfile $shop,
    ) {
        parent::__construct($pages, $clock);
    }

    public function form(Request $request): Response
    {
        return $this->page($request, 'owner/settings', [
            'name' => $this->shop->name(),
            'tagline' => $this->shop->tagline(),
            'whatsapp' => PhoneNumber::tryParse($this->shop->whatsapp())?->local() ?? $this->shop->whatsapp(),
            'capacity' => $this->settings->get('default_slot_capacity', '6'),
        ]);
    }

    public function save(Request $request): Response
    {
        $name = trim($request->input('name'));
        $phone = PhoneNumber::tryParse($request->input('whatsapp'));
        $capacity = $request->input('capacity');
        $errors = [];
        if (mb_strlen($name) < 2 || mb_strlen($name) > 60) {
            $errors[] = 'Shop name must be 2 to 60 letters.';
        }
        if ($phone === null) {
            $errors[] = 'Enter the shop\'s WhatsApp number as a Kenyan mobile, e.g. 0700 000 001.';
        }
        if (!ctype_digit($capacity) || (int) $capacity > 99) {
            $errors[] = 'Slot capacity must be a number from 0 to 99.';
        }
        if ($errors !== [] || $phone === null) {
            $this->flash($request, 'error', implode(' ', $errors));

            return Response::redirect('/owner/settings');
        }

        $this->settings->set('shop_name', $name);
        $this->settings->set('shop_tagline', mb_substr(trim($request->input('tagline')), 0, 120));
        $this->settings->set('shop_whatsapp', $phone->e164);
        $this->settings->set('default_slot_capacity', $capacity);
        $this->flash($request, 'ok', 'Settings saved.');

        return Response::redirect('/owner/settings');
    }

    public function pin(Request $request): Response
    {
        $hash = $this->settings->get('owner_pin_hash');
        $new = $request->input('new_pin');

        if ($hash === '' || !password_verify($request->input('current_pin'), $hash)) {
            $this->flash($request, 'error', 'The current PIN or password is not right.');
        } elseif (mb_strlen($new) < 6) {
            $this->flash($request, 'error', 'Use at least 6 characters for the new PIN or password.');
        } elseif ($new !== $request->input('confirm_pin')) {
            $this->flash($request, 'error', 'The two new values do not match.');
        } else {
            $this->settings->set('owner_pin_hash', password_hash($new, PASSWORD_DEFAULT));
            $this->flash($request, 'ok', 'PIN changed.');
        }

        return Response::redirect('/owner/settings');
    }
}
