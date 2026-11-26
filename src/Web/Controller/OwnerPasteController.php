<?php

declare(strict_types=1);

namespace MamaMboga\Web\Controller;

use MamaMboga\Application\Command\PlaceOrder;
use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Service\OrderService;
use MamaMboga\Application\Service\PriceBoardService;
use MamaMboga\Application\Service\ShopProfile;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Parsing\OrderMessageParser;
use MamaMboga\Domain\Parsing\ParsedLine;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\View\PageRenderer;

/** Paste a WhatsApp order, check what the parser understood, fix the flagged lines, save. */
final class OwnerPasteController extends BaseController
{
    public function __construct(
        PageRenderer $pages,
        Clock $clock,
        private readonly OrderMessageParser $parser,
        private readonly PriceBoardService $board,
        private readonly OrderService $orders,
        private readonly SlotPlanner $planner,
        private readonly ShopProfile $shop,
    ) {
        parent::__construct($pages, $clock);
    }

    public function form(Request $request): Response
    {
        return $this->page($request, 'owner/paste', ['message' => '', 'hasBoard' => $this->board->board($this->today()) !== []]);
    }

    public function parse(Request $request): Response
    {
        $message = mb_substr($request->input('message'), 0, 4000);
        $board = $this->board->board($this->today());

        if (trim($message) === '') {
            $this->flash($request, 'error', 'Paste the customer\'s WhatsApp message first.');

            return Response::redirect('/owner/paste');
        }

        $parsed = $this->parser->parse($message, $board, $this->shop->nameWords());
        $draft = array_map(static fn (ParsedLine $line): array => [
            'raw' => $line->fragment,
            'entry' => $line->item?->entryId,
            'qty' => $line->quantity?->decimal() ?? '1',
            'flags' => array_map(static fn ($i): string => $i->message(), array_values(array_filter($line->issues, static fn ($i): bool => $i->isBlocking()))),
            'status' => $line->status->value,
            'alternatives' => array_map(static fn ($a): int => (int) $a->entryId, $line->alternatives),
            'review' => $line->needsReview(),
        ], $parsed->lines);

        return $this->review($request, $message, $draft, [
            'name' => '',
            'phone' => $parsed->phone?->local() ?? '',
            'address' => $parsed->addressHint ?? '',
            'fulfilment' => 'delivery',
            'slot' => '',
        ], [], 200, $parsed->timeHint, $parsed->ignored);
    }

    public function confirm(Request $request): Response
    {
        $rows = $request->body['line'] ?? [];
        $quantities = [];
        $draft = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            if (!is_array($row) || isset($row['drop'])) {
                continue;
            }
            $entry = (string) ($row['entry'] ?? '');
            $qty = trim((string) ($row['qty'] ?? ''));
            $draft[] = ['raw' => (string) ($row['raw'] ?? ''), 'entry' => ctype_digit($entry) ? (int) $entry : null, 'qty' => $qty, 'flags' => [], 'status' => 'edited', 'alternatives' => [], 'review' => false];
            if (ctype_digit($entry) && $qty !== '') {
                $key = (int) $entry;
                // The same item on two lines is added together.
                $existing = isset($quantities[$key]) ? Quantity::fromDecimal($quantities[$key]) : Quantity::zero();
                try {
                    $quantities[$key] = $existing->add(Quantity::fromDecimal($qty))->decimal();
                } catch (\InvalidArgumentException) {
                    $quantities[$key] = $qty;
                }
            }
        }

        $input = [
            'name' => $request->input('name'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'fulfilment' => $request->input('fulfilment', 'delivery'),
            'slot' => $request->input('slot'),
        ];
        $message = $request->input('message');

        try {
            $order = $this->orders->place(new PlaceOrder(
                $input['name'],
                $input['phone'],
                $input['fulfilment'],
                ctype_digit($input['slot']) ? (int) $input['slot'] : null,
                $input['address'],
                'From WhatsApp',
                $quantities,
                $request->input('payment') === 'deni',
                'whatsapp',
            ));
        } catch (ValidationException $e) {
            return $this->review($request, $message, $draft, $input, $e->errors, 422, null, []);
        }

        $this->flash($request, 'ok', "Order {$order->reference} saved for {$order->customerName}.");

        return Response::redirect('/owner/orders/' . $order->id);
    }

    /**
     * @param list<array<string, mixed>> $draft
     * @param array<string, string>      $input
     * @param array<string, string>      $errors
     * @param list<string>               $ignored
     */
    private function review(Request $request, string $message, array $draft, array $input, array $errors, int $status, ?string $timeHint, array $ignored): Response
    {
        $today = $this->today();
        $now = $this->clock->now();
        $booked = $this->orders->slotLoad($today);
        $slots = array_map(static fn ($slot): array => [
            'slot' => $slot,
            'left' => $slot->remaining($booked[(int) $slot->id] ?? 0),
            'open' => $slot->isBookable($booked[(int) $slot->id] ?? 0, $now),
        ], $this->planner->ensure($today));

        return $this->page($request, 'owner/paste-review', [
            'message' => $message,
            'draft' => $draft,
            'input' => $input,
            'errors' => $errors,
            'board' => $this->board->board($today),
            'slots' => $slots,
            'timeHint' => $timeHint,
            'ignored' => $ignored,
        ], $status);
    }
}
