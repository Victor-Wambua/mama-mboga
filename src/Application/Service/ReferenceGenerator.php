<?php

declare(strict_types=1);

namespace MamaMboga\Application\Service;

use MamaMboga\Application\Port\OrderRepository;

/** Short order references a customer can read out over the phone: MM-7K3Q. */
final class ReferenceGenerator
{
    // No 0/O or 1/I/L, which get misread when typed or shouted across a stall.
    private const ALPHABET = '23456789ABCDEFGHJKMNPQRSTUVWXYZ';

    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function next(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = 'MM-';
            for ($i = 0; $i < 4; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            if (!$this->orders->referenceExists($code)) {
                return $code;
            }
        }

        throw new \RuntimeException('Could not generate a unique order reference.');
    }
}
