<?php

declare(strict_types=1);

namespace MamaMboga\Application\Service;

use MamaMboga\Application\Port\SettingsRepository;

/** The shop's public details, kept in the settings table. */
final class ShopProfile
{
    public function __construct(private readonly SettingsRepository $settings)
    {
    }

    public function name(): string
    {
        return $this->settings->get('shop_name', 'My Greengrocer');
    }

    public function tagline(): string
    {
        return $this->settings->get('shop_tagline', 'Fresh from the market, priced every morning.');
    }

    /** Digits only, no plus sign: what wa.me expects. */
    public function whatsapp(): string
    {
        return $this->settings->get('shop_whatsapp', '254700000001');
    }

    /**
     * Words of the shop name, used to ignore "Hi Njeri" greetings when parsing.
     *
     * @return list<string>
     */
    public function nameWords(): array
    {
        preg_match_all('/\p{L}+/u', $this->name(), $m);

        return $m[0];
    }
}
