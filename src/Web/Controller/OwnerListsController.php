<?php

declare(strict_types=1);

namespace MamaMboga\Web\Controller;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Service\OrderService;
use MamaMboga\Application\Service\ShoppingListService;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Application\Service\TakingsService;
use MamaMboga\Domain\Order\Fulfilment;
use MamaMboga\Domain\Order\Order;
use MamaMboga\Domain\Order\OrderStatus;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\View\PageRenderer;

/** Printable working lists: the market shopping list, the delivery run sheet, and the day's takings. */
final class OwnerListsController extends BaseController
{
    public function __construct(
        PageRenderer $pages,
        Clock $clock,
        private readonly ShoppingListService $shopping,
        private readonly OrderService $orders,
        private readonly SlotPlanner $planner,
        private readonly TakingsService $takings,
    ) {
        parent::__construct($pages, $clock);
    }

    public function shopping(Request $request): Response
    {
        $rows = $this->shopping->forDate($this->today());

        return $this->page($request, 'owner/shopping', [
            'rows' => $rows,
            'boughtCount' => count(array_filter($rows, static fn (array $r): bool => $r['bought'])),
            'dateLabel' => $this->clock->now()->format('l j F'),
        ]);
    }

    public function check(Request $request): Response
    {
        $itemId = (int) $request->input('item_id');
        $unit = $request->input('unit');
        $bought = $request->input('bought') === '1';
        if ($itemId > 0 && in_array($unit, ['kg', 'bunch', 'piece', 'heap'], true)) {
            $this->shopping->mark($this->today(), $itemId, $unit, $bought);
        }

        if ($request->header('x-requested-with') === 'fetch') {
            return Response::json(['ok' => true]);
        }

        return Response::redirect('/owner/shopping');
    }

    public function runSheet(Request $request): Response
    {
        $today = $this->today();
        $slots = $this->planner->ensure($today);
        $orders = array_filter($this->orders->forDay($today), static fn (Order $o): bool => $o->status !== OrderStatus::Cancelled);

        $groups = [];
        foreach ($slots as $slot) {
            $inSlot = array_values(array_filter($orders, static fn (Order $o): bool => $o->slotId === $slot->id));
            if ($inSlot !== []) {
                $groups[] = ['slot' => $slot, 'orders' => $inSlot];
            }
        }
        $pickups = array_values(array_filter($orders, static fn (Order $o): bool => $o->fulfilment === Fulfilment::Pickup));

        return $this->page($request, 'owner/run-sheet', [
            'groups' => $groups,
            'pickups' => $pickups,
            'dateLabel' => $this->clock->now()->format('l j F'),
        ]);
    }

    public function takings(Request $request): Response
    {
        $date = $request->query('date');
        $date = preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 && strtotime($date) !== false ? $date : $this->today();

        return $this->page($request, 'owner/takings', [
            'date' => $date,
            't' => $this->takings->forDate($date),
            'isToday' => $date === $this->today(),
            'dateLabel' => (new \DateTimeImmutable($date))->format('l j F Y'),
        ]);
    }
}
