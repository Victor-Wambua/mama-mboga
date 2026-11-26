<?php

declare(strict_types=1);

namespace MamaMboga\Tests\Infrastructure;

use MamaMboga\Infrastructure\Container\Container;
use MamaMboga\Infrastructure\Container\ContainerException;
use PHPUnit\Framework\TestCase;

interface Greeter
{
    public function hi(): string;
}

final class English implements Greeter
{
    public function hi(): string
    {
        return 'hello';
    }
}

final class Door
{
    public function __construct(public readonly Greeter $greeter)
    {
    }
}

final class Lock
{
    public function __construct(public readonly Door $door, public readonly string $label = 'default', public readonly int $turns = 1)
    {
    }
}

final class A
{
    public function __construct(public readonly B $b)
    {
    }
}

final class B
{
    public function __construct(public readonly A $a)
    {
    }
}

final class NeedsScalar
{
    public function __construct(public readonly string $name)
    {
    }
}

final class ContainerTest extends TestCase
{
    public function testAutowiresConcreteClassesRecursively(): void
    {
        $c = new Container();
        $c->bind(Greeter::class, English::class);

        self::assertSame('hello', $c->get(Door::class)->greeter->hi());
    }

    public function testSharedInstancesAreReused(): void
    {
        $c = new Container();
        $c->bind(Greeter::class, English::class);

        self::assertSame($c->get(Door::class), $c->get(Door::class));
        self::assertSame($c->get(Greeter::class), $c->get(Door::class)->greeter);
    }

    public function testMakeBuildsFreshInstances(): void
    {
        $c = new Container();
        $c->bind(Greeter::class, English::class);

        self::assertNotSame($c->make(Door::class), $c->make(Door::class));
    }

    public function testInterfaceWithoutBindingFails(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->get(Door::class);
    }

    public function testFactoryBindingReceivesTheContainer(): void
    {
        $c = new Container();
        $c->bind(Greeter::class, static fn (Container $c): Greeter => new English());

        self::assertInstanceOf(English::class, $c->get(Greeter::class));
    }

    public function testInstanceBinding(): void
    {
        $c = new Container();
        $english = new English();
        $c->instance(Greeter::class, $english);

        self::assertSame($english, $c->get(Greeter::class));
    }

    public function testScalarArgumentsArePassedPositionallyAndDefaultsFillTheRest(): void
    {
        $c = new Container();
        $c->bind(Greeter::class, English::class);

        $lock = $c->make(Lock::class, ['front', 3]);
        self::assertSame('front', $lock->label);
        self::assertSame(3, $lock->turns);

        $default = $c->make(Lock::class);
        self::assertSame('default', $default->label);
    }

    public function testCircularDependencyIsDetected(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('Circular');
        (new Container())->get(A::class);
    }

    public function testUnresolvableScalarFails(): void
    {
        $this->expectException(ContainerException::class);
        (new Container())->get(NeedsScalar::class);
    }

    public function testUnknownNamesAreNotResolvable(): void
    {
        self::assertFalse((new Container())->has('Nope\\Missing'));
    }

    public function testInterfacesCannotBeInstantiated(): void
    {
        $this->expectException(ContainerException::class);
        $this->expectExceptionMessage('is an interface');
        (new Container())->make(Greeter::class);
    }
}
