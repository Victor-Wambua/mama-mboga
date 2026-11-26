<?php

declare(strict_types=1);

namespace MamaMboga\Application\Service;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Application\Port\LedgerRepository;
use MamaMboga\Application\Port\Transaction;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Customer\Customer;
use MamaMboga\Domain\Ledger\EntryType;
use MamaMboga\Domain\Ledger\Ledger;
use MamaMboga\Domain\Ledger\LedgerEntry;
use MamaMboga\Domain\Ledger\LedgerViolation;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\PhoneNumber;

/** The deni book. Every write loads the ledger, appends through its invariants, then stores. */
final class LedgerService
{
    public function __construct(
        private readonly LedgerRepository $entries,
        private readonly CustomerRepository $customers,
        private readonly Transaction $tx,
        private readonly Clock $clock,
    ) {
    }

    public function ledgerFor(int $customerId): Ledger
    {
        $customer = $this->customers->find($customerId) ?? throw ValidationException::field('customer', 'Customer not found.');

        return Ledger::open($customerId, $customer->creditLimit, $this->entries->entriesFor($customerId));
    }

    /** @throws LedgerViolation */
    public function charge(int $customerId, Money $amount, ?int $orderId = null, string $note = ''): LedgerEntry
    {
        return $this->write($customerId, new LedgerEntry(null, $customerId, EntryType::Charge, $amount, $this->clock->now(), null, null, $orderId, $note));
    }

    /**
     * @param string $mpesaCode the M-Pesa confirmation code; ignored for cash, which gets a numbered receipt
     * @throws ValidationException
     */
    public function recordPayment(int $customerId, Money $amount, string $method, string $mpesaCode = '', string $note = ''): LedgerEntry
    {
        if (!in_array($method, ['cash', 'mpesa'], true)) {
            throw ValidationException::field('method', 'Choose cash or M-Pesa.');
        }
        if (!$amount->isPositive()) {
            throw ValidationException::field('amount', 'Enter an amount more than 0.');
        }

        return $this->tx->run(function () use ($customerId, $amount, $method, $mpesaCode, $note): LedgerEntry {
            $now = $this->clock->now();
            if ($method === 'mpesa') {
                $code = strtoupper(preg_replace('/\s+/', '', $mpesaCode) ?? '');
                if (preg_match('/^[A-Z0-9]{10}$/', $code) !== 1) {
                    throw ValidationException::field('receipt', 'Enter the 10-character M-Pesa code, e.g. SGH1A2B3C4.');
                }
            } else {
                $count = $this->entries->countPaymentsWithMethodOn($now->format('Y-m-d'), 'cash');
                $code = sprintf('CASH-%s-%03d', $now->format('Ymd'), $count + 1);
            }

            try {
                return $this->write($customerId, new LedgerEntry(null, $customerId, EntryType::Payment, $amount, $now, $method, $code, null, trim($note)));
            } catch (LedgerViolation $e) {
                throw ValidationException::field('amount', $e->getMessage());
            }
        });
    }

    /** @throws ValidationException */
    public function creditNote(int $customerId, Money $amount, string $reason): LedgerEntry
    {
        if (!$amount->isPositive()) {
            throw ValidationException::field('amount', 'Enter an amount more than 0.');
        }
        if (trim($reason) === '') {
            throw ValidationException::field('note', 'Say why (for example "returned wilted kale").');
        }
        try {
            return $this->write($customerId, new LedgerEntry(null, $customerId, EntryType::CreditNote, $amount, $this->clock->now(), null, null, null, trim($reason)));
        } catch (LedgerViolation $e) {
            throw ValidationException::field('amount', $e->getMessage());
        }
    }

    /** @throws ValidationException */
    public function setCreditLimit(int $customerId, int $limit): Customer
    {
        $customer = $this->customers->find($customerId) ?? throw ValidationException::field('customer', 'Customer not found.');
        if ($limit < 0 || $limit > 500000) {
            throw ValidationException::field('limit', 'Credit limit must be between 0 and 500,000.');
        }

        return $this->customers->save(new Customer($customer->id, $customer->name, $customer->phone, Money::kes($limit)));
    }

    public function addCustomer(string $name, string $phone, int $limit): Customer
    {
        $errors = [];
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            $errors['name'] = 'Enter a name (2 to 80 letters).';
        }
        $number = PhoneNumber::tryParse($phone);
        if ($number === null) {
            $errors['phone'] = 'Enter a Kenyan mobile number such as 0700 000 001.';
        } elseif ($this->customers->findByPhone($number) !== null) {
            $errors['phone'] = 'A customer with this number already exists.';
        }
        if ($limit < 0 || $limit > 500000) {
            $errors['limit'] = 'Credit limit must be between 0 and 500,000.';
        }
        if ($errors !== [] || $number === null) {
            throw new ValidationException($errors);
        }

        return $this->customers->save(new Customer(null, $name, $number, Money::kes($limit)));
    }

    /**
     * @return list<array{customer: Customer, balance: Money}> customers who owe or hold credit, biggest debt first
     */
    public function debtors(): array
    {
        $balances = $this->entries->balances();
        $rows = [];
        foreach ($this->customers->all() as $customer) {
            $balance = $balances[(int) $customer->id] ?? Money::zero();
            if ($balance->isPositive() || $customer->hasCredit()) {
                $rows[] = ['customer' => $customer, 'balance' => $balance];
            }
        }
        usort($rows, static fn (array $a, array $b): int => $b['balance']->amount <=> $a['balance']->amount);

        return $rows;
    }

    private function write(int $customerId, LedgerEntry $entry): LedgerEntry
    {
        return $this->tx->run(function () use ($customerId, $entry): LedgerEntry {
            $ledger = $this->ledgerFor($customerId);
            $ledger->append($entry); // throws LedgerViolation if any invariant breaks

            return $this->entries->append($entry);
        });
    }
}
