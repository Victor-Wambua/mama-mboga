<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Domain;

use MamaMboga\Tests\Support\ParserCaseRunner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ParserCaseTableTest extends TestCase
{
    /** @return iterable<string, array{string, list<string>, array<string, string>}> */
    public static function tuned(): iterable
    {
        foreach (ParserCaseRunner::load('tuned') as $case) {
            yield 'A: ' . $case[0] => [$case[1], $case[2], $case[3] ?? []];
        }
    }

    /** @return iterable<string, array{string, list<string>, array<string, string>}> */
    public static function fresh(): iterable
    {
        foreach (ParserCaseRunner::load('fresh') as $case) {
            yield 'B: ' . $case[0] => [$case[1], $case[2], $case[3] ?? []];
        }
    }

    /**
     * @param list<string>          $expected
     * @param array<string, string> $extra
     */
    #[DataProvider('tuned')]
    #[DataProvider('fresh')]
    public function testCase(string $message, array $expected, array $extra): void
    {
        $result = ParserCaseRunner::check($message, $expected, $extra);

        self::assertTrue($result['ok'], sprintf("%s\nwanted %s\n   got %s", $result['why'], json_encode($expected), json_encode($result['got'])));
    }
}
