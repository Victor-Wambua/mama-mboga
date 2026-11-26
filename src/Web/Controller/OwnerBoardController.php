<?php

declare(strict_types=1);

namespace MamaMboga\Web\Controller;

use MamaMboga\Application\Port\Clock;
use MamaMboga\Application\Service\OrderService;
use MamaMboga\Application\Service\PriceBoardService;
use MamaMboga\Application\Service\SlotPlanner;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Money\Unit;
use MamaMboga\Infrastructure\Http\Request;
use MamaMboga\Infrastructure\Http\Response;
use MamaMboga\Infrastructure\View\PageRenderer;

/** The morning routine: today's items and prices, delivery windows, and the item catalogue. */
final class OwnerBoardController extends BaseController
{
    public function __construct(
        PageRenderer $pages,
        Clock $clock,
        private readonly PriceBoardService $board,
        private readonly SlotPlanner $planner,
        private readonly OrderService $orders,
    ) {
        parent::__construct($pages, $clock);
    }

    public function index(Request $request): Response
    {
        $today = $this->today();
        $entries = $this->board->board($today);
        $onBoard = array_map(static fn (StockPrice $s): int => $s->itemId, $entries);
        $slots = $this->planner->ensure($today);
        $booked = $this->orders->slotLoad($today);

        return $this->page($request, 'owner/board', [
            'entries' => $entries,
            'available' => array_values(array_filter($this->board->items(), static fn ($i): bool => !in_array($i->id, $onBoard, true))),
            'units' => Unit::cases(),
            'slots' => $slots,
            'booked' => $booked,
            'errors' => [],
            'dateLabel' => $this->clock->now()->format('l j F'),
        ]);
    }

    /** Save every row of the board in one go. */
    public function save(Request $request): Response
    {
        $today = $this->today();
        $rows = $request->body['rows'] ?? [];
        $errors = [];
        $saved = 0;
        foreach (is_array($rows) ? $rows : [] as $entryId => $row) {
            $entry = $this->board->entry((int) $entryId);
            if ($entry === null || !is_array($row)) {
                continue;
            }
            try {
                $this->board->setEntry(
                    $today,
                    $entry->itemId,
                    (string) ($row['unit'] ?? ''),
                    (string) ($row['price'] ?? ''),
                    (string) ($row['available'] ?? ''),
                    isset($row['special']),
                    (string) ($row['note'] ?? ''),
                );
                $saved++;
            } catch (ValidationException $e) {
                $errors[] = $entry->nameEn . ': ' . implode(' ', $e->errors);
            }
        }

        foreach ($errors as $message) {
            $this->flash($request, 'error', $message);
        }
        if ($errors === []) {
            $this->flash($request, 'ok', "Saved {$saved} " . ($saved === 1 ? 'item' : 'items') . '.');
        }

        return Response::redirect('/owner/board');
    }

    public function add(Request $request): Response
    {
        try {
            $itemId = (int) $request->input('item_id');
            if ($itemId === 0) {
                $item = $this->board->saveItem(null, $request->input('name_en'), $request->input('name_sw'), $request->input('aliases'), $request->input('unit'));
                $itemId = (int) $item->id;
            }
            $this->board->setEntry(
                $this->today(),
                $itemId,
                $request->input('unit'),
                $request->input('price'),
                $request->input('available', '0'),
                $request->input('special') !== '',
                $request->input('note'),
            );
            $this->flash($request, 'ok', 'Added to today\'s list.');
        } catch (ValidationException $e) {
            $this->flash($request, 'error', implode(' ', $e->errors));
        } catch (\PDOException) {
            $this->flash($request, 'error', 'An item with that English name already exists. Pick it from the list instead.');
        }

        return Response::redirect('/owner/board');
    }

    public function copy(Request $request): Response
    {
        try {
            $n = $this->board->copyPrevious($this->today());
            $this->flash($request, 'ok', $n === 0 ? 'Everything from the last list is already on today\'s list.' : "Copied {$n} items from the last list. Check the prices, then save.");
        } catch (ValidationException $e) {
            $this->flash($request, 'error', implode(' ', $e->errors));
        }

        return Response::redirect('/owner/board');
    }

    public function remove(Request $request): Response
    {
        $this->board->removeEntry((int) $request->param('id'));
        $this->flash($request, 'ok', 'Removed from today\'s list.');

        return Response::redirect('/owner/board');
    }

    public function slots(Request $request): Response
    {
        $caps = $request->inputArray('cap');
        foreach ($caps as $slotId => $cap) {
            if (ctype_digit($cap)) {
                $this->planner->setCapacity((int) $slotId, (int) $cap);
            }
        }
        $this->flash($request, 'ok', 'Delivery slots updated.');

        return Response::redirect('/owner/board');
    }

    public function items(Request $request): Response
    {
        return $this->page($request, 'owner/items', ['items' => $this->board->items(), 'units' => Unit::cases()]);
    }

    public function updateItem(Request $request): Response
    {
        try {
            $this->board->saveItem(
                (int) $request->param('id'),
                $request->input('name_en'),
                $request->input('name_sw'),
                $request->input('aliases'),
                $request->input('unit'),
            );
            $this->flash($request, 'ok', 'Item saved.');
        } catch (ValidationException $e) {
            $this->flash($request, 'error', implode(' ', $e->errors));
        }

        return Response::redirect('/owner/items');
    }
}
