<?php

declare(strict_types=1);

namespace MamaMboga\Application\Service;

use MamaMboga\Application\Port\PriceBoardRepository;
use MamaMboga\Application\Port\Transaction;
use MamaMboga\Application\ValidationException;
use MamaMboga\Domain\Catalogue\Item;
use MamaMboga\Domain\Catalogue\StockPrice;
use MamaMboga\Domain\Money\Money;
use MamaMboga\Domain\Money\Quantity;
use MamaMboga\Domain\Money\Unit;

/** The owner's morning routine: set today's items, prices and stock. */
final class PriceBoardService
{
    public function __construct(
        private readonly PriceBoardRepository $repo,
        private readonly Transaction $tx,
    ) {
    }

    /** @return list<StockPrice> */
    public function board(string $date): array
    {
        return $this->repo->board($date);
    }

    public function entry(int $entryId): ?StockPrice
    {
        return $this->repo->entry($entryId);
    }

    /**
     * Copy the most recent earlier board onto $date: same items, units, prices
     * and stock, specials switched off. Items already on $date are kept.
     *
     * @return int number of entries copied
     */
    public function copyPrevious(string $date): int
    {
        return $this->tx->run(function () use ($date): int {
            $from = $this->repo->latestBoardDateBefore($date);
            if ($from === null) {
                throw ValidationException::field('copy', 'There is no earlier price list to copy.');
            }
            $existing = array_map(static fn (StockPrice $s): int => $s->itemId, $this->repo->board($date));
            $copied = 0;
            foreach ($this->repo->board($from) as $entry) {
                if (in_array($entry->itemId, $existing, true)) {
                    continue;
                }
                $this->repo->saveEntry($date, $entry->itemId, $entry->unit, $entry->price, $entry->available, false, '');
                $copied++;
            }

            return $copied;
        });
    }

    /** Validate and upsert one board line from form input. */
    public function setEntry(string $date, int $itemId, string $unit, string $price, string $available, bool $special, string $note): void
    {
        $errors = [];
        $unitEnum = Unit::tryFromLabel($unit);
        if ($unitEnum === null) {
            $errors['unit'] = 'Choose a unit.';
        }
        if (preg_match('/^\d{1,6}$/', trim($price)) !== 1 || (int) $price < 1) {
            $errors['price'] = 'Price must be a whole number of shillings, more than 0.';
        }
        try {
            $qty = Quantity::fromDecimal($available);
            if ($unitEnum !== null && !$qty->isMultipleOf($unitEnum->increment())) {
                $errors['available'] = "Stock for {$unitEnum->label()} must be in steps of " . Quantity::milli($unitEnum->increment())->decimal() . '.';
            }
        } catch (\InvalidArgumentException) {
            $errors['available'] = 'Stock must be a number, 0 or more.';
        }
        if ($this->repo->item($itemId) === null) {
            $errors['item'] = 'Unknown item.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $this->repo->saveEntry($date, $itemId, $unitEnum ?? Unit::Kg, Money::kes((int) $price), $qty, $special, mb_substr(trim($note), 0, 60));
    }

    public function removeEntry(int $entryId): void
    {
        $this->repo->removeEntry($entryId);
    }

    /** @return list<Item> */
    public function items(): array
    {
        return $this->repo->items();
    }

    public function saveItem(?int $id, string $nameEn, string $nameSw, string $aliasesCsv, string $unit): Item
    {
        $errors = [];
        $nameEn = trim($nameEn);
        $nameSw = trim($nameSw);
        if ($nameEn === '' || mb_strlen($nameEn) > 60) {
            $errors['name_en'] = 'Enter the English name (up to 60 letters).';
        }
        if ($nameSw === '' || mb_strlen($nameSw) > 60) {
            $errors['name_sw'] = 'Enter the Swahili name (up to 60 letters).';
        }
        $unitEnum = Unit::tryFromLabel($unit);
        if ($unitEnum === null) {
            $errors['unit'] = 'Choose a unit.';
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        return $this->repo->saveItem(new Item($id, $nameEn, $nameSw, Item::splitAliases(mb_substr($aliasesCsv, 0, 300)), $unitEnum ?? Unit::Kg));
    }
}
