<?php

declare(strict_types=1);

namespace MamaMboga\Demo;

use MamaMboga\App;
use MamaMboga\Application\Collection\PaymentRequest;
use MamaMboga\Application\Command\PlaceOrder;
use MamaMboga\Application\Gateway\GatewayPayment;
use MamaMboga\Application\Messaging\ReminderMessage;
use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Application\Port\GatewayClient;
use MamaMboga\Application\Port\LedgerRepository;
use MamaMboga\Application\Port\OrderRepository;
use MamaMboga\Application\Port\PaymentRequestRepository;
use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Application\Port\Transaction;
use MamaMboga\Application\Seed\DemoSeeder;
use MamaMboga\Application\Service\LedgerService;
use MamaMboga\Application\Service\OrderService;
use MamaMboga\Application\Service\PaymentCollectionService;
use MamaMboga\Application\Service\PriceBoardService;
use MamaMboga\Application\Service\ShoppingListService;
use MamaMboga\Application\Service\ShopProfile;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Ledger\LedgerViolation;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Order\Order;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Domain\Parsing\OrderMessageParser;
use MamaMboga\Domain\Parsing\ParsedLine;
use MamaMboga\Infrastructure\Config\Config;
use MamaMboga\Infrastructure\Container\Container;
use MamaMboga\Infrastructure\Persistence\Database;
use MamaMboga\Infrastructure\Persistence\Migrator;

/**
 * The browser demo's only entry point into the real application code.
 *
 * It wires the repository's own container (services, SQLite repositories, migrations, seeder)
 * and answers JSON actions from the demo page. It replaces the HTTP layer, sessions, CSRF and
 * templates, which the demo does not use, and nothing else: parsing, prices, stock, orders,
 * the market list and the deni ledger all run through `src/` unchanged.
 */
final class Bridge
{
    public const STATEMENT_PLACEHOLDER = '(the real app puts a signed statement link here)';

    public function __construct(
        private readonly Container $c,
        private readonly Clock $shopClock,
        private readonly Clock $bookClock,
    ) {
    }

    /**
     * @param string $root repository root (where src/ and migrations/ live)
     * @param string $dbPath SQLite file, or ':memory:'
     */
    public static function boot(string $root, string $dbPath, ?\DateTimeImmutable $realNow = null): self
    {
        $shopClock = new MorningClock($realNow);
        $config = new Config($root, 'demo', false, str_repeat('d', 40), $dbPath, 'Africa/Nairobi', '');
        $c = App::container($config, $shopClock);

        // Same connection settings as Database::connect(), minus WAL: the file lives in memory in the browser.
        $pdo = new \PDO('sqlite:' . $dbPath, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $db = new Database($pdo);
        $c->instance(Database::class, $db);

        $ledgerClock = new LedgerClock($db, $realNow);
        $c->bind(LedgerService::class, static fn (Container $c): LedgerService => new LedgerService(
            $c->get(LedgerRepository::class),
            $c->get(CustomerRepository::class),
            $c->get(Transaction::class),
            $ledgerClock,
        ));

        $c->bind(PaymentCollectionService::class, static fn (Container $c): PaymentCollectionService => new PaymentCollectionService(
            $c->get(PaymentRequestRepository::class),
            $c->get(LedgerService::class),
            $c->get(CustomerRepository::class),
            $c->get(GatewayClient::class),
            $c->get(Transaction::class),
            $ledgerClock,
        ));

        $bridge = new self($c, $shopClock, $ledgerClock);
        $c->get(Migrator::class)->migrate();
        if ($db->scalar('SELECT COUNT(*) FROM items') === 0) {
            $c->get(DemoSeeder::class)->seed();
        }

        return $bridge;
    }

    /**
     * @param array<string, mixed> $args
     * @return array<string, mixed>
     */
    public function handle(string $action, array $args = []): array
    {
        try {
            $data = match ($action) {
                'snapshot' => $this->snapshot(),
                'setEntry' => $this->setEntry($args),
                'removeEntry' => $this->removeEntry($args),
                'copyPrevious' => $this->copyPrevious(),
                'addToBoard' => $this->addToBoard($args),
                'saveItem' => $this->saveItem($args),
                'parse' => $this->parse($args),
                'quote' => $this->quote($args),
                'placeOrder' => $this->placeOrder($args),
                'changeStatus' => $this->changeStatus($args),
                'markBought' => $this->markBought($args),
                'customer' => $this->customerDetail($this->int($args, 'customerId')),
                'addCustomer' => $this->addCustomer($args),
                'setLimit' => $this->setLimit($args),
                'payment' => $this->payment($args),
                'creditNote' => $this->creditNote($args),
                'charge' => $this->charge($args),
                'reminder' => $this->reminder($args),
                'collectOpen' => $this->collectOpen($args),
                'collectSent' => $this->collectSent($args),
                'collectReconcile' => $this->collectReconcile($args),
                'collectReview' => $this->collectReview($args),
                'collectDiscard' => $this->collectDiscard($args),
                default => throw new \InvalidArgumentException('Unknown action: ' . $action),
            };

            return ['ok' => true, 'data' => $data];
        } catch (ValidationException $e) {
            return ['ok' => false, 'errors' => $e->errors, 'message' => $e->getMessage()];
        } catch (LedgerViolation $e) {
            return ['ok' => false, 'errors' => ['amount' => $e->getMessage()], 'message' => $e->getMessage()];
        } catch (\DomainException|\InvalidArgumentException $e) {
            return ['ok' => false, 'errors' => [], 'message' => $e->getMessage()];
        }
    }

    public function today(): string
    {
        return $this->shopClock->now()->format('Y-m-d');
    }

    // ---- reads -----------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $today = $this->today();
        $boardService = $this->c->get(PriceBoardService::class);
        $board = $boardService->board($today);
        $onBoard = array_map(static fn (StockPrice $s): int => $s->itemId, $board);
        $orders = $this->c->get(OrderService::class)->forDay($today);
        $slotsBooked = $this->c->get(OrderService::class)->slotLoad($today);
        $slots = [];
        foreach ($this->c->get(SlotPlanner::class)->ensure($today) as $slot) {
            $booked = $slotsBooked[(int) $slot->id] ?? 0;
            $slots[] = [
                'id' => (int) $slot->id,
                'label' => $slot->label(),
                'left' => $slot->remaining($booked),
                'open' => $slot->isBookable($booked, $this->shopClock->now()),
            ];
        }
        $slotLabels = array_column($slots, 'label', 'id');

        $open = array_filter($orders, static fn (Order $o): bool => $o->status !== OrderStatus::Cancelled);

        return [
            'today' => $today,
            'shop' => $this->c->get(ShopProfile::class)->name(),
            'board' => array_map($this->entry(...), array_values(array_filter($board, static fn (StockPrice $s): bool => $s->entryId !== null))),
            'items' => array_map(static fn ($i): array => [
                'id' => (int) $i->id,
                'nameEn' => $i->nameEn,
                'nameSw' => $i->nameSw,
                'unit' => $i->defaultUnit->value,
                'onBoard' => in_array((int) $i->id, $onBoard, true),
            ], $this->c->get(PriceBoardRepository::class)->items()),
            'hasPrevious' => $this->c->get(PriceBoardRepository::class)->latestBoardDateBefore($today) !== null,
            'orders' => array_map(fn (Order $o): array => $this->order($o, $slotLabels), $orders),
            'orderTotal' => array_sum(array_map(static fn (Order $o): int => $o->total()->amount, $open)),
            'slots' => $slots,
            'shopping' => array_map(static fn (array $row): array => [
                'itemId' => $row['line']->itemId,
                'nameEn' => $row['line']->nameEn,
                'nameSw' => $row['line']->nameSw,
                'unit' => $row['line']->unit->value,
                'quantity' => $row['line']->quantity->decimal(),
                'orders' => $row['line']->orderCount,
                'bought' => $row['bought'],
            ], $this->c->get(ShoppingListService::class)->forDate($today)),
            'customers' => $this->customers(),
            'needsYou' => $this->needsYou(),
        ];
    }

    /** @return list<array<string, mixed>> */
    private function customers(): array
    {
        $balances = $this->c->get(LedgerRepository::class)->balances();
        $now = $this->shopClock->now();
        $rows = [];
        foreach ($this->c->get(CustomerRepository::class)->all() as $customer) {
            $id = (int) $customer->id;
            $balance = $balances[$id] ?? Money::zero();
            $ledger = $this->c->get(LedgerService::class)->ledgerFor($id);
            $aging = $ledger->aging($now);
            $rows[] = [
                'id' => $id,
                'name' => $customer->name,
                'phone' => $customer->phone->local(),
                'limit' => $customer->creditLimit->amount,
                'balance' => $balance->amount,
                'available' => $ledger->available()->amount,
                'oldest' => $aging->oldestBucket(),
            ];
        }

        return $rows;
    }

    /** @return array<string, mixed> */
    private function customerDetail(int $customerId): array
    {
        $ledgers = $this->c->get(LedgerService::class);
        $customer = $this->c->get(CustomerRepository::class)->find($customerId) ?? throw ValidationException::field('customer', 'Customer not found.');
        $ledger = $ledgers->ledgerFor($customerId);
        $aging = $ledger->aging($this->shopClock->now());
        $rows = [];
        foreach ($ledger->statement() as $row) {
            $e = $row['entry'];
            $rows[] = [
                'type' => $e->type->value,
                'label' => $e->type->label(),
                'amount' => $e->amount->amount,
                'signed' => $e->signed()->amount,
                'method' => $e->method,
                'receipt' => $e->receiptCode,
                'note' => $e->note,
                'at' => $e->occurredAt->format('Y-m-d H:i'),
                'balance' => $row['balance']->amount,
            ];
        }

        return [
            'id' => $customerId,
            'name' => $customer->name,
            'phone' => $customer->phone->local(),
            'limit' => $customer->creditLimit->amount,
            'balance' => $ledger->balance()->amount,
            'available' => $ledger->available()->amount,
            'pendingDeni' => $this->c->get(OrderRepository::class)->pendingDeniTotal($customerId),
            'aging' => [
                'current' => $aging->current->amount,
                'days8to14' => $aging->days8to14->amount,
                'days15to30' => $aging->days15to30->amount,
                'over30' => $aging->over30->amount,
            ],
            'rows' => $rows,
            'prompts' => array_map($this->prompt(...), $this->c->get(PaymentCollectionService::class)->forCustomer($customerId)),
        ];
    }

    /**
     * @param array<string, mixed> $args message
     * @return array<string, mixed>
     */
    private function parse(array $args): array
    {
        $message = mb_substr($this->str($args, 'message'), 0, 4000);
        $board = $this->c->get(PriceBoardService::class)->board($this->today());
        $parsed = $this->c->get(OrderMessageParser::class)->parse($message, $board, $this->c->get(ShopProfile::class)->nameWords());

        return [
            'lines' => array_map(static function (ParsedLine $line): array {
                $total = null;
                if ($line->item !== null && $line->quantity !== null && $line->item->validAmount($line->quantity)) {
                    $total = $line->item->lineFor($line->quantity)->total()->amount;
                }

                return [
                    'raw' => $line->raw,
                    'fragment' => $line->fragment,
                    'status' => $line->status->value,
                    'entry' => $line->item?->entryId,
                    'qty' => $line->quantity?->decimal() ?? '1',
                    'writtenUnit' => $line->writtenUnit?->value,
                    'alternatives' => array_map(static fn (StockPrice $a): int => (int) $a->entryId, $line->alternatives),
                    'issues' => array_map(static fn ($i): array => ['code' => $i->value, 'message' => $i->message(), 'blocking' => $i->isBlocking()], $line->issues),
                    'review' => $line->needsReview(),
                    'total' => $total,
                ];
            }, $parsed->lines),
            'ignored' => $parsed->ignored,
            'phone' => $parsed->phone?->local() ?? '',
            'address' => $parsed->addressHint ?? '',
            'time' => $parsed->timeHint ?? '',
        ];
    }

    /**
     * Price and check the lines as edited by the owner, with the same rules an order is placed under.
     *
     * @param array<string, mixed> $args lines: list<{entry, qty}>
     * @return array<string, mixed>
     */
    private function quote(array $args): array
    {
        $board = [];
        foreach ($this->c->get(PriceBoardService::class)->board($this->today()) as $row) {
            if ($row->entryId !== null) {
                $board[$row->entryId] = $row;
            }
        }
        $lines = [];
        $total = 0;
        foreach ($this->list($args, 'lines') as $row) {
            $entry = $board[(int) ($row['entry'] ?? 0)] ?? null;
            $qtyText = trim((string) ($row['qty'] ?? ''));
            if ($entry === null) {
                $lines[] = ['total' => null, 'problem' => null];
                continue;
            }
            try {
                $qty = Quantity::fromDecimal($qtyText);
            } catch (\InvalidArgumentException) {
                $lines[] = ['total' => null, 'problem' => 'Quantity must be a number such as 2 or 1.5.'];
                continue;
            }
            $problem = null;
            if (!$entry->validAmount($qty)) {
                $problem = $qty->isZero()
                    ? 'Quantity must be more than 0.'
                    : "{$entry->nameEn} is sold in steps of " . Quantity::milli($entry->unit->increment())->decimal() . " {$entry->unit->label()}.";
            } elseif (!$entry->available->atLeast($qty)) {
                $problem = $entry->isSoldOut() ? "{$entry->nameEn} is sold out." : "Only {$entry->available->format($entry->unit)} left.";
            }
            $lineTotal = $problem === null ? $entry->lineFor($qty)->total()->amount : null;
            $total += $lineTotal ?? 0;
            $lines[] = ['total' => $lineTotal, 'problem' => $problem];
        }

        return ['lines' => $lines, 'total' => $total];
    }

    // ---- price board -----------------------------------------------------------------------

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function setEntry(array $a): array
    {
        $this->c->get(PriceBoardService::class)->setEntry(
            $this->today(),
            $this->int($a, 'itemId'),
            $this->str($a, 'unit'),
            $this->str($a, 'price'),
            $this->str($a, 'available'),
            (bool) ($a['special'] ?? false),
            $this->str($a, 'note'),
        );

        return $this->snapshot();
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function removeEntry(array $a): array
    {
        $this->c->get(PriceBoardService::class)->removeEntry($this->int($a, 'entryId'));

        return $this->snapshot();
    }

    /** @return array<string, mixed> */
    private function copyPrevious(): array
    {
        $this->c->get(PriceBoardService::class)->copyPrevious($this->today());

        return $this->snapshot();
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function addToBoard(array $a): array
    {
        $item = $this->c->get(PriceBoardRepository::class)->item($this->int($a, 'itemId'));
        if ($item === null) {
            throw ValidationException::field('item', 'Unknown item.');
        }
        $this->c->get(PriceBoardService::class)->setEntry(
            $this->today(),
            (int) $item->id,
            $this->str($a, 'unit') ?: $item->defaultUnit->value,
            $this->str($a, 'price'),
            $this->str($a, 'available'),
            false,
            '',
        );

        return $this->snapshot();
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function saveItem(array $a): array
    {
        $this->c->get(PriceBoardService::class)->saveItem(null, $this->str($a, 'nameEn'), $this->str($a, 'nameSw'), $this->str($a, 'aliases'), $this->str($a, 'unit'));

        return $this->snapshot();
    }

    // ---- orders ----------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function placeOrder(array $a): array
    {
        $quantities = [];
        foreach ($this->list($a, 'lines') as $row) {
            $entry = (string) ($row['entry'] ?? '');
            $qty = trim((string) ($row['qty'] ?? ''));
            if (!ctype_digit($entry) || $qty === '') {
                continue;
            }
            $key = (int) $entry;
            // The same item on two lines is added together, as the owner console does.
            try {
                $existing = isset($quantities[$key]) ? Quantity::fromDecimal($quantities[$key]) : Quantity::zero();
                $quantities[$key] = $existing->add(Quantity::fromDecimal($qty))->decimal();
            } catch (\InvalidArgumentException) {
                $quantities[$key] = $qty;
            }
        }
        $slot = $this->str($a, 'slot');
        $order = $this->c->get(OrderService::class)->place(new PlaceOrder(
            $this->str($a, 'name'),
            $this->str($a, 'phone'),
            $this->str($a, 'fulfilment') ?: 'delivery',
            ctype_digit($slot) ? (int) $slot : null,
            $this->str($a, 'address'),
            'From WhatsApp',
            $quantities,
            ($a['payment'] ?? '') === 'deni',
            'whatsapp',
        ));

        return ['reference' => $order->reference, 'snapshot' => $this->snapshot()];
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function changeStatus(array $a): array
    {
        $to = OrderStatus::tryFrom($this->str($a, 'to')) ?? throw ValidationException::field('status', 'Unknown status.');
        $this->c->get(OrderService::class)->changeStatus($this->int($a, 'orderId'), $to, $this->str($a, 'settledVia') ?: 'cash');

        return $this->snapshot();
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function markBought(array $a): array
    {
        $this->c->get(ShoppingListService::class)->mark($this->today(), $this->int($a, 'itemId'), $this->str($a, 'unit'), (bool) ($a['bought'] ?? false));

        return $this->snapshot();
    }

    // ---- deni ------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function addCustomer(array $a): array
    {
        $this->c->get(LedgerService::class)->addCustomer($this->str($a, 'name'), $this->str($a, 'phone'), (int) $this->str($a, 'limit'));

        return $this->snapshot();
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function setLimit(array $a): array
    {
        $this->c->get(LedgerService::class)->setCreditLimit($this->int($a, 'customerId'), (int) $this->str($a, 'limit'));

        return $this->snapshot();
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function payment(array $a): array
    {
        $this->c->get(LedgerService::class)->recordPayment(
            $this->int($a, 'customerId'),
            Money::kes($this->amount($a)),
            $this->str($a, 'method'),
            $this->str($a, 'receipt'),
            $this->str($a, 'note'),
        );

        return $this->snapshot();
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function creditNote(array $a): array
    {
        $this->c->get(LedgerService::class)->creditNote($this->int($a, 'customerId'), Money::kes($this->amount($a)), $this->str($a, 'note'));

        return $this->snapshot();
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function charge(array $a): array
    {
        $amount = $this->amount($a);
        if ($amount < 1) {
            throw ValidationException::field('amount', 'Enter an amount more than 0.');
        }
        $this->c->get(LedgerService::class)->charge($this->int($a, 'customerId'), Money::kes($amount), null, trim($this->str($a, 'note')));

        return $this->snapshot();
    }

    /**
     * @param array<string, mixed> $a
     * @return array<string, mixed>
     */
    private function reminder(array $a): array
    {
        $id = $this->int($a, 'customerId');
        $customer = $this->c->get(CustomerRepository::class)->find($id) ?? throw ValidationException::field('customer', 'Customer not found.');
        $ledger = $this->c->get(LedgerService::class)->ledgerFor($id);
        $lang = $this->str($a, 'lang') === 'en' ? 'en' : 'sw';

        return ['text' => ReminderMessage::compose(
            $customer->name,
            $ledger->balance(),
            $ledger->aging($this->shopClock->now()),
            $this->c->get(ShopProfile::class)->name(),
            self::STATEMENT_PLACEHOLDER,
            $lang,
        )];
    }

    // ---- collect by prompt -----------------------------------------------------------------
    //
    // In the demo the page, not PHP, talks to the gateway (PHP in the browser has no network). PHP does the
    // rest with the app's own PaymentCollectionService: it checks the amount against the balance, makes the
    // short request id, and when the page hands it what the gateway reported, runs the real reconcile step,
    // which writes the deni book only for a clean `succeeded`.

    /** What one prompt-for-payment may be at most, with the public demo key (the gateway caps publishable keys at this). */
    public const MOCK_PROMPT_LIMIT = 1000;
    /** The flat charge for the opt-in sandbox variant; it stands in for the amount. */
    public const SANDBOX_CHARGE = 1;

    /**
     * @param array<string, mixed> $a customerId, amount (optional), mode (mock or sandbox)
     * @return array<string, mixed>
     */
    private function collectOpen(array $a): array
    {
        $customerId = $this->int($a, 'customerId');
        $mode = $this->str($a, 'mode') === 'sandbox' ? PaymentRequest::MODE_SANDBOX : PaymentRequest::MODE_MOCK;
        $text = trim($this->str($a, 'amount'));
        $amount = null;
        if ($text !== '') {
            $amount = $this->amount($a);
        } elseif ($mode === PaymentRequest::MODE_MOCK) {
            $owed = $this->c->get(LedgerService::class)->ledgerFor($customerId)->balance()->amount;
            $amount = $owed > 0 ? min($owed, self::MOCK_PROMPT_LIMIT) : null;
        }
        $request = $this->c->get(PaymentCollectionService::class)->open(
            $customerId,
            $amount,
            $mode,
            $mode === PaymentRequest::MODE_SANDBOX ? self::SANDBOX_CHARGE : null,
            $mode === PaymentRequest::MODE_MOCK ? self::MOCK_PROMPT_LIMIT : null,
        );

        return $this->prompt($request);
    }

    /**
     * The page created the payment at the gateway; link it to the request. A pending payment writes nothing.
     *
     * @param array<string, mixed> $a requestId, payment
     * @return array<string, mixed>
     */
    private function collectSent(array $a): array
    {
        return $this->prompt($this->c->get(PaymentCollectionService::class)->attach($this->int($a, 'requestId'), $this->gatewayPayment($a)));
    }

    /**
     * Run the app's reconcile step on a payment the page read from the gateway.
     *
     * @param array<string, mixed> $a requestId, payment
     * @return array<string, mixed>
     */
    private function collectReconcile(array $a): array
    {
        $payment = $this->gatewayPayment($a);
        $service = new PaymentCollectionService(
            $this->c->get(PaymentRequestRepository::class),
            $this->c->get(LedgerService::class),
            $this->c->get(CustomerRepository::class),
            new SuppliedGatewayClient($payment),
            $this->c->get(Transaction::class),
            $this->bookClock,
        );

        return $this->prompt($service->reconcile($this->int($a, 'requestId')));
    }

    /**
     * @param array<string, mixed> $a requestId, note
     * @return array<string, mixed>
     */
    private function collectReview(array $a): array
    {
        return $this->prompt($this->c->get(PaymentCollectionService::class)->markReviewed($this->int($a, 'requestId'), $this->str($a, 'note')));
    }

    /**
     * @param array<string, mixed> $a requestId, reason
     * @return array<string, mixed>
     */
    private function collectDiscard(array $a): array
    {
        return $this->prompt($this->c->get(PaymentCollectionService::class)->abandon($this->int($a, 'requestId'), $this->str($a, 'reason') ?: 'Not sent from this browser.'));
    }

    /** @param array<string, mixed> $a */
    private function gatewayPayment(array $a): GatewayPayment
    {
        $data = $a['payment'] ?? null;
        if (!is_array($data)) {
            throw ValidationException::field('payment', 'The page did not send a payment.');
        }
        try {
            return GatewayPayment::fromArray($data);
        } catch (\InvalidArgumentException $e) {
            throw ValidationException::field('payment', $e->getMessage());
        }
    }

    /** @return array<string, mixed> */
    private function prompt(PaymentRequest $p): array
    {
        return [
            'id' => (int) $p->id,
            'reference' => $p->reference,
            'customerId' => $p->customerId,
            'amount' => $p->amount,
            'charge' => $p->charge,
            'mode' => $p->mode,
            'idempotencyKey' => $p->idempotencyKey,
            'gatewayPaymentId' => $p->gatewayPaymentId,
            'status' => $p->status->value,
            'flags' => $p->flags,
            'receipt' => $p->receipt,
            'sent' => $p->sent(),
            'recorded' => $p->recorded(),
            'checkable' => $p->checkable(),
            'needsOwner' => $p->needsOwner(),
            'state' => $p->display()['state'],
            'label' => $p->display()['label'],
            'detail' => $p->display()['detail'],
            'at' => $p->createdAt->format('Y-m-d H:i'),
        ];
    }

    /** @return list<array<string, mixed>> prompt payments waiting for the owner, across customers */
    private function needsYou(): array
    {
        $customers = $this->c->get(CustomerRepository::class);
        $out = [];
        foreach ($this->c->get(PaymentCollectionService::class)->needingOwner() as $p) {
            $out[] = $this->prompt($p) + ['customerName' => $customers->find($p->customerId)->name ?? 'Customer'];
        }

        return $out;
    }

    // ---- shaping ---------------------------------------------------------------------------

    /** @return array<string, mixed> */
    private function entry(StockPrice $s): array
    {
        return [
            'entryId' => (int) $s->entryId,
            'itemId' => $s->itemId,
            'nameEn' => $s->nameEn,
            'nameSw' => $s->nameSw,
            'aliases' => $s->aliases,
            'unit' => $s->unit->value,
            'price' => $s->price->amount,
            'available' => $s->available->decimal(),
            'special' => $s->special,
            'note' => $s->specialNote,
        ];
    }

    /**
     * @param array<int, string> $slotLabels
     * @return array<string, mixed>
     */
    private function order(Order $o, array $slotLabels): array
    {
        return [
            'id' => (int) $o->id,
            'reference' => $o->reference,
            'status' => $o->status->value,
            'statusLabel' => $o->status->label($o->fulfilment),
            'next' => array_map(static fn (OrderStatus $s): array => ['value' => $s->value, 'label' => $s->label($o->fulfilment)], $o->status->next()),
            'customer' => $o->customerName,
            'phone' => $o->customerPhone->local(),
            'fulfilment' => $o->fulfilment->value,
            'slot' => $o->slotId === null ? null : ($slotLabels[$o->slotId] ?? null),
            'address' => $o->address,
            'payment' => $o->paymentMode->value,
            'settledVia' => $o->settledVia,
            'source' => $o->source,
            'total' => $o->total()->amount,
            'lines' => array_map(static fn ($l): array => [
                'nameEn' => $l->nameEn,
                'nameSw' => $l->nameSw,
                'unit' => $l->unit->value,
                'quantity' => $l->quantity->decimal(),
                'unitPrice' => $l->unitPrice->amount,
                'total' => $l->total()->amount,
            ], $o->lines),
        ];
    }

    // ---- argument helpers ------------------------------------------------------------------

    /** @param array<string, mixed> $a */
    private function str(array $a, string $key): string
    {
        $v = $a[$key] ?? '';

        return is_scalar($v) ? (string) $v : '';
    }

    /** @param array<string, mixed> $a */
    private function int(array $a, string $key): int
    {
        return (int) $this->str($a, $key);
    }

    /** @param array<string, mixed> $a */
    private function amount(array $a): int
    {
        $text = preg_replace('/[\s,]/', '', $this->str($a, 'amount')) ?? '';
        if (preg_match('/^\d{1,7}$/', $text) !== 1) {
            throw ValidationException::field('amount', 'Enter a whole number of shillings, more than 0.');
        }

        return (int) $text;
    }

    /**
     * @param array<string, mixed> $a
     * @return list<array<string, mixed>>
     */
    private function list(array $a, string $key): array
    {
        $v = $a[$key] ?? [];

        /** @var list<array<string, mixed>> */
        return is_array($v) ? array_values(array_filter($v, 'is_array')) : [];
    }
}
