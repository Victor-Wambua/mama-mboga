<?php

declare(strict_types=1);

namespace MamaMboga\Application\Port;

interface Transaction
{
    /**
     * Run $work atomically; an exception rolls everything back. Nested calls join the outer transaction.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    public function run(callable $work): mixed;
}
