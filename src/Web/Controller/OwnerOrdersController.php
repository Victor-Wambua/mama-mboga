<?php

declare(strict_types=1);

namespace MamaMboga\Web\Controller;

use MamaMboga\Application\Messaging\OrderMessage;
use MamaMboga\Application\Messaging\WhatsAppLink;
use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Service\OrderService;
use MamaMboga\Application\Service\ShopProfile;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Infrastructure\Http\HttpException;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\View\PageRenderer;

final class OwnerOrdersController extends BaseController
{
    public function __construct(
        PageRenderer $pages,
        Clock $clock,
        private readonly OrderService $orders,
        private readonly SlotPlanner $planner,
        private readonly ShopProfile $shop,
    ) {
        parent::__construct($pages, $clock);
    }

    public function index(Request $request): Response
    {
        $date = $this->dateParam($request);
        $orders = $this->orders->forDay($date);
        $slots = [];
        foreach ($this->planner->ensure($date) as $slot) {
            $slots[(int) $slot->id] = $slot;
        }

        $filter = OrderStatus::tryFrom($request->query('status'));
        $counts = [];
        foreach (OrderStatus::cases() as $status) {
            $counts[$status->value] = 0;
        }
        foreach ($orders as $order) {
            $counts[$order->status->value]++;
        }
        $shown = $filter === null
            ? $orders
            : array_values(array_filter($orders, static fn ($o): bool => $o->status === $filter));

        return $this->page($request, 'owner/orders', [
            'date' => $date,
            'isToday' => $date === $this->today(),
            'orders' => $shown,
            'counts' => $counts,
            'filter' => $filter,
            'slots' => $slots,
        ]);
    }

    public function show(Request $request): Response
    {
        $order = $this->orders->find((int) $request->param('id')) ?? throw new HttpException(404);
        $slots = $this->planner->ensure($order->boardDate);
        $slot = null;
        foreach ($slots as $candidate) {
            if ($candidate->id === $order->slotId) {
                $slot = $candidate;
            }
        }

        return $this->page($request, 'owner/order', [
            'order' => $order,
            'slot' => $slot,
            'customerWhatsapp' => WhatsAppLink::to($order->customerPhone->e164, OrderMessage::statusUpdate($order, $this->shop->name())),
        ]);
    }

    public function status(Request $request): Response
    {
        $id = (int) $request->param('id');
        $to = OrderStatus::tryFrom($request->input('status'));
        $back = $this->safeNext($request->input('back'), '/owner');

        try {
            if ($to === null) {
                throw ValidationException::field('status', 'Unknown status.');
            }
            $order = $this->orders->changeStatus($id, $to, $request->input('paid_with', 'cash'));
            $this->flash($request, 'ok', "{$order->reference} is now " . strtolower($order->status->label($order->fulfilment)) . '.');
        } catch (ValidationException $e) {
            $this->flash($request, 'error', implode(' ', $e->errors));
        }

        return Response::redirect($back);
    }

    private function dateParam(Request $request): string
    {
        $date = $request->query('date');

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false ? $date : $this->today();
    }
}
