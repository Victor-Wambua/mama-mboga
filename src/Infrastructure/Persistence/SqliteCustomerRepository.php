<?php

declare(strict_types=1);

namespace MamaMboga\Infrastructure\Persistence;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Domain\Customer\Customer;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;

final class SqliteCustomerRepository implements CustomerRepository
{
    public function __construct(private readonly Database $db, private readonly Clock $clock)
    {
    }

    public function find(int $id): ?Customer
    {
        $row = $this->db->one('SELECT * FROM customers WHERE id = ?', [$id]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function findByPhone(PhoneNumber $phone): ?Customer
    {
        $row = $this->db->one('SELECT * FROM customers WHERE phone = ?', [$phone->e164]);

        return $row === null ? null : $this->hydrate($row);
    }

    public function all(): array
    {
        return array_map($this->hydrate(...), $this->db->all('SELECT * FROM customers ORDER BY name'));
    }

    public function save(Customer $customer): Customer
    {
        if ($customer->id === null) {
            $this->db->execute(
                'INSERT INTO customers (name, phone, credit_limit_kes, created_at) VALUES (?, ?, ?, ?)',
                [$customer->name, $customer->phone->e164, $customer->creditLimit->amount, $this->clock->now()->format('c')],
            );

            return new Customer($this->db->lastId(), $customer->name, $customer->phone, $customer->creditLimit);
        }
        $this->db->execute(
            'UPDATE customers SET name = ?, credit_limit_kes = ? WHERE id = ?',
            [$customer->name, $customer->creditLimit->amount, $customer->id],
        );

        return $customer;
    }

    /** @param array<string, mixed> $r */
    private function hydrate(array $r): Customer
    {
        return new Customer((int) $r['id'], (string) $r['name'], PhoneNumber::parse((string) $r['phone']), Money::kes((int) $r['credit_limit_kes']));
    }
}
