<?php

declare(strict_types=1);

namespace MamaMboga\Web\Controller;

use MamaMboga\Application\Command\PlaceOrder;
use MamaMboga\Application\Messaging\OrderMessage;
use MamaMboga\Application\Messaging\WhatsAppLink;
use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Port\CustomerRepository;
use MamaMboga\Application\Port\SlotRepository;
use MamaMboga\Application\Service\LedgerService;
use MamaMboga\Application\Service\OrderService;
use MamaMboga\Application\Service\PriceBoardService;
use MamaMboga\Application\Service\ShopProfile;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Order\Order;
use MamaMboga\Infrastructure\Http\HttpException;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\View\PageRenderer;
use MamaMboga\Web\Links;

/** The public side: today's menu, placing an order, and the login-free links customers receive. */
final class MenuController extends BaseController
{
    public function __construct(
        PageRenderer $pages,
        Clock $clock,
        private readonly PriceBoardService $board,
        private readonly OrderService $orders,
        private readonly SlotPlanner $planner,
        private readonly SlotRepository $slots,
        private readonly ShopProfile $shop,
        private readonly Links $links,
        private readonly LedgerService $ledger,
        private readonly CustomerRepository $customers,
    ) {
        parent::__construct($pages, $clock);
    }

    public function index(Request $request): Response
    {
        return $this->renderMenu($request, [], [], 200);
    }

    public function place(Request $request): Response
    {
        $slot = $request->input('slot');
        $input = [
            'name' => $request->input('name'),
            'phone' => $request->input('phone'),
            'fulfilment' => $request->input('fulfilment', 'delivery'),
            'slot' => $slot,
            'address' => $request->input('address'),
            'note' => $request->input('note'),
            'payment' => $request->input('payment', 'pay'),
            'qty' => $request->inputArray('qty'),
        ];

        try {
            $order = $this->orders->place(new PlaceOrder(
                $input['name'],
                $input['phone'],
                $input['fulfilment'],
                ctype_digit($slot) ? (int) $slot : null,
                $input['address'],
                $input['note'],
                $input['qty'],
                $input['payment'] === 'deni',
            ));
        } catch (ValidationException $e) {
            return $this->renderMenu($request, $e->errors, $input, 422);
        }

        return Response::redirect($this->links->orderPath($order->reference));
    }

    public function confirmation(Request $request): Response
    {
        $reference = $request->param('reference');
        $order = $this->links->orderSignatureValid($reference, $request->param('sig')) ? $this->orders->findByReference($reference) : null;
        if (!$order instanceof Order) {
            throw new HttpException(404);
        }

        $slot = $order->slotId === null ? null : $this->slots->find($order->slotId);
        $whatsapp = WhatsAppLink::to($this->shop->whatsapp(), OrderMessage::forShop($order, $this->shop->name(), $slot?->label()));

        return $this->page($request, 'public/confirmation', ['order' => $order, 'slot' => $slot, 'whatsappUrl' => $whatsapp])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer');
    }

    public function statement(Request $request): Response
    {
        $id = (int) $request->param('id');
        $expires = (int) $request->param('expires');
        $now = $this->clock->now()->getTimestamp();
        if (!$this->links->statementValid($id, $expires, $request->param('sig'), $now) || $this->customers->find($id) === null) {
            throw new HttpException(404);
        }

        $customer = $this->customers->find($id);
        $ledger = $this->ledger->ledgerFor($id);

        return $this->page($request, 'public/statement', [
            'customer' => $customer,
            'rows' => array_reverse($ledger->statement()),
            'balance' => $ledger->balance(),
            'aging' => $ledger->aging($this->clock->now()),
        ])
            ->withHeader('Cache-Control', 'no-store')
            ->withHeader('Referrer-Policy', 'no-referrer')
            ->withHeader('X-Robots-Tag', 'noindex, nofollow');
    }

    public function offline(Request $request): Response
    {
        return $this->page($request, 'public/offline');
    }

    public function health(Request $request): Response
    {
        return Response::json(['status' => 'ok']);
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed>  $input
     */
    private function renderMenu(Request $request, array $errors, array $input, int $status): Response
    {
        $today = $this->today();
        $now = $this->clock->now();
        $slots = $this->planner->ensure($today);
        $booked = $this->orders->slotLoad($today);

        $slotViews = array_map(static fn ($slot): array => [
            'slot' => $slot,
            'left' => $slot->remaining($booked[(int) $slot->id] ?? 0),
            'open' => $slot->isBookable($booked[(int) $slot->id] ?? 0, $now),
        ], $slots);

        return $this->page($request, 'public/menu', [
            'board' => $this->board->board($today),
            'slots' => $slotViews,
            'errors' => $errors,
            'old' => $input,
            'dateLabel' => $now->format('l j F'),
        ], $status);
    }
}
