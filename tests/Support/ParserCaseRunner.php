<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Support;

use MamaMboga\Domain\Parsing\OrderMessageParser;
use MamaMboga\Domain\Parsing\ParsedOrder;

/** Runs the shorthand case tables and scores them. Shared by PHPUnit and `bin/console parser:accuracy`. */
final class ParserCaseRunner
{
    /** @return list<array{0: string, 1: string, 2: list<string>, 3?: array<string, string>}> */
    public static function load(string $name): array
    {
        /** @var list<array{0: string, 1: string, 2: list<string>, 3?: array<string, string>}> $cases */
        $cases = require __DIR__ . '/../Fixtures/parser_cases_' . $name . '.php';

        return $cases;
    }

    /**
     * Describe what the parser produced in the table's shorthand.
     *
     * @return list<string>
     */
    public static function describe(ParsedOrder $parsed): array
    {
        $ids = array_flip(DemoBoard::ids());
        $out = [];
        foreach ($parsed->lines as $line) {
            $key = $line->item !== null ? ($ids[$line->item->itemId] ?? '??') : '?';
            $qty = $line->quantity?->decimal() ?? '-';
            $out[] = $key === '?'
                ? "?:{$qty}!"
                : "{$key}:{$qty}:{$line->item?->unit->value}" . ($line->needsReview() ? '!' : '');
        }

        return $out;
    }

    /**
     * @param list<string>          $expected
     * @param array<string, string> $extra
     * @return array{ok: bool, got: list<string>, why: string}
     */
    public static function check(string $message, array $expected, array $extra = []): array
    {
        $parsed = (new OrderMessageParser())->parse($message, DemoBoard::stock(), ['njeri', 'greens']);
        $got = self::describe($parsed);
        $why = '';
        $ok = $got === $expected;
        if (!$ok) {
            $why = 'lines differ';
        }
        if ($ok && isset($extra['time']) && $parsed->timeHint !== $extra['time']) {
            $ok = false;
            $why = 'time hint ' . var_export($parsed->timeHint, true);
        }
        if ($ok && isset($extra['phone']) && $parsed->phone?->e164 !== $extra['phone']) {
            $ok = false;
            $why = 'phone ' . var_export($parsed->phone?->e164, true);
        }

        return ['ok' => $ok, 'got' => $got, 'why' => $why];
    }
}
