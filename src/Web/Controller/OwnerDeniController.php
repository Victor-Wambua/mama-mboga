<?php

declare(strict_types=1);

namespace MamaMboga\Web\Controller;

use MamaMboga\Application\Messaging\ReminderMessage;
use MamaMboga\Application\Messaging\WhatsAppLink;
use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Application\Service\LedgerService;
use MamaMboga\Application\Service\ShopProfile;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Infrastructure\Http\HttpException;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\View\PageRenderer;
use MamaMboga\Web\Links;

/** The deni book: who owes what, recording payments, reminders and statements. */
final class OwnerDeniController extends BaseController
{
    public function __construct(
        PageRenderer $pages,
        Clock $clock,
        private readonly LedgerService $ledger,
        private readonly CustomerRepository $customers,
        private readonly ShopProfile $shop,
        private readonly Links $links,
    ) {
        parent::__construct($pages, $clock);
    }

    public function index(Request $request): Response
    {
        $now = $this->clock->now();
        $rows = [];
        $outstanding = Money::zero();
        foreach ($this->ledger->debtors() as $row) {
            $ledger = $this->ledger->ledgerFor((int) $row['customer']->id);
            $rows[] = [...$row, 'aging' => $ledger->aging($now)];
            $outstanding = $outstanding->add($row['balance']);
        }

        return $this->page($request, 'owner/deni', ['rows' => $rows, 'outstanding' => $outstanding]);
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->param('id');
        $customer = $this->customers->find($id) ?? throw new HttpException(404);
        $ledger = $this->ledger->ledgerFor($id);
        $now = $this->clock->now();
        $aging = $ledger->aging($now);
        $statementUrl = $this->links->absolute($request, $this->links->statementPath($id, $now->getTimestamp()));

        return $this->page($request, 'owner/customer', [
            'customer' => $customer,
            'rows' => array_reverse($ledger->statement()),
            'balance' => $ledger->balance(),
            'available' => $ledger->available(),
            'aging' => $aging,
            'statementUrl' => $statementUrl,
            'reminderSw' => WhatsAppLink::to($customer->phone->e164, ReminderMessage::compose($customer->name, $ledger->balance(), $aging, $this->shop->name(), $statementUrl, 'sw')),
            'reminderEn' => WhatsAppLink::to($customer->phone->e164, ReminderMessage::compose($customer->name, $ledger->balance(), $aging, $this->shop->name(), $statementUrl, 'en')),
        ]);
    }

    public function addCustomer(Request $request): Response
    {
        try {
            $customer = $this->ledger->addCustomer($request->input('name'), $request->input('phone'), (int) $request->input('limit'));
            $this->flash($request, 'ok', "{$customer->name} added.");

            return Response::redirect('/owner/deni/' . $customer->id);
        } catch (ValidationException $e) {
            $this->flash($request, 'error', implode(' ', $e->errors));

            return Response::redirect('/owner/deni');
        }
    }

    public function payment(Request $request): Response
    {
        return $this->act($request, function (int $id) use ($request): string {
            $entry = $this->ledger->recordPayment(
                $id,
                Money::kes((int) $request->input('amount')),
                $request->input('method'),
                $request->input('receipt'),
                $request->input('note'),
            );

            return "Payment of {$entry->amount->format()} recorded. Receipt {$entry->receiptCode}.";
        });
    }

    public function charge(Request $request): Response
    {
        return $this->act($request, function (int $id) use ($request): string {
            if (trim($request->input('note')) === '') {
                throw ValidationException::field('note', 'Say what the charge is for (for example "notebook balance from February").');
            }
            try {
                $entry = $this->ledger->charge($id, Money::kes((int) $request->input('amount')), null, trim($request->input('note')));
            } catch (\DomainException|\InvalidArgumentException $e) {
                throw ValidationException::field('amount', $e->getMessage());
            }

            return "Charge of {$entry->amount->format()} added.";
        });
    }

    public function creditNote(Request $request): Response
    {
        return $this->act($request, function (int $id) use ($request): string {
            $entry = $this->ledger->creditNote($id, Money::kes((int) $request->input('amount')), $request->input('note'));

            return "Credit note of {$entry->amount->format()} added.";
        });
    }

    public function limit(Request $request): Response
    {
        return $this->act($request, function (int $id) use ($request): string {
            $customer = $this->ledger->setCreditLimit($id, (int) $request->input('limit'));

            return "Credit limit for {$customer->name} is now {$customer->creditLimit->format()}.";
        });
    }

    /** @param callable(int): string $action returns the success message */
    private function act(Request $request, callable $action): Response
    {
        $id = (int) $request->param('id');
        try {
            $this->flash($request, 'ok', $action($id));
        } catch (ValidationException $e) {
            $this->flash($request, 'error', implode(' ', $e->errors));
        }

        return Response::redirect('/owner/deni/' . $id);
    }
}
