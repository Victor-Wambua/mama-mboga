<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

use MamaMboga\Domain\Customer\Customer;
use MamaMboga\Domain\Money\PhoneNumber;

interface CustomerRepository
{
    public function find(int $id): ?Customer;

    public function findByPhone(PhoneNumber $phone): ?Customer;

    /** @return list<Customer> */
    public function all(): array;

    public function save(Customer $customer): Customer;
}
