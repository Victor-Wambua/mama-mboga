<?php

declare(strict_types=1);

namespace MamaMboga\Application\Seed;

use MamaMboga\Domain\Money\Unit;

/** The produce of the fictional "Mama Njeri Greens", with the names customers really use. */
final class DemoCatalogue
{
    /**
     * @return list<array{key: string, en: string, sw: string, aliases: list<string>, unit: Unit, price: int, qty: int, special?: string}>
     */
    public static function items(): array
    {
        return [
            ['key' => 'kale', 'en' => 'Kale', 'sw' => 'Sukuma wiki', 'aliases' => ['sukuma', 'sukumawiki', 'collards', 'collard greens'], 'unit' => Unit::Bunch, 'price' => 20, 'qty' => 60],
            ['key' => 'tomato', 'en' => 'Tomatoes', 'sw' => 'Nyanya', 'aliases' => ['tomato'], 'unit' => Unit::Kg, 'price' => 120, 'qty' => 40, 'special' => 'Ripe, good for stew'],
            ['key' => 'onion', 'en' => 'Onions', 'sw' => 'Vitunguu', 'aliases' => ['kitunguu', 'onion', 'red onion'], 'unit' => Unit::Kg, 'price' => 110, 'qty' => 40],
            ['key' => 'garlic', 'en' => 'Garlic', 'sw' => 'Kitunguu saumu', 'aliases' => ['saumu', 'kitunguu swaumu'], 'unit' => Unit::Heap, 'price' => 50, 'qty' => 15],
            ['key' => 'spring-onion', 'en' => 'Spring onions', 'sw' => 'Kitunguu maji', 'aliases' => ['scallion', 'scallions'], 'unit' => Unit::Bunch, 'price' => 20, 'qty' => 20],
            ['key' => 'potato', 'en' => 'Irish potatoes', 'sw' => 'Viazi ulaya', 'aliases' => ['irish', 'potato', 'irish potato'], 'unit' => Unit::Kg, 'price' => 90, 'qty' => 60],
            ['key' => 'sweet-potato', 'en' => 'Sweet potatoes', 'sw' => 'Viazi vitamu', 'aliases' => ['sweet potato'], 'unit' => Unit::Kg, 'price' => 80, 'qty' => 25],
            ['key' => 'banana', 'en' => 'Ripe bananas', 'sw' => 'Ndizi', 'aliases' => ['banana', 'ndizi mbivu'], 'unit' => Unit::Piece, 'price' => 10, 'qty' => 120],
            ['key' => 'matoke', 'en' => 'Cooking bananas', 'sw' => 'Matoke', 'aliases' => ['ndizi mbichi', 'green bananas'], 'unit' => Unit::Heap, 'price' => 100, 'qty' => 20],
            ['key' => 'carrot', 'en' => 'Carrots', 'sw' => 'Karoti', 'aliases' => ['carrot'], 'unit' => Unit::Kg, 'price' => 100, 'qty' => 30],
            ['key' => 'cabbage', 'en' => 'Cabbage', 'sw' => 'Kabichi', 'aliases' => ['kabeji', 'cabbages'], 'unit' => Unit::Piece, 'price' => 60, 'qty' => 25],
            ['key' => 'spinach', 'en' => 'Spinach', 'sw' => 'Mchicha', 'aliases' => [], 'unit' => Unit::Bunch, 'price' => 20, 'qty' => 40],
            ['key' => 'coriander', 'en' => 'Coriander', 'sw' => 'Dhania', 'aliases' => ['cilantro'], 'unit' => Unit::Bunch, 'price' => 10, 'qty' => 50],
            ['key' => 'green-pepper', 'en' => 'Green pepper', 'sw' => 'Pilipili hoho', 'aliases' => ['hoho', 'capsicum', 'bell pepper'], 'unit' => Unit::Piece, 'price' => 20, 'qty' => 50],
            ['key' => 'chilli', 'en' => 'Chilli', 'sw' => 'Pilipili kali', 'aliases' => ['chili', 'hot pepper'], 'unit' => Unit::Heap, 'price' => 50, 'qty' => 20],
            ['key' => 'mango', 'en' => 'Mangoes', 'sw' => 'Maembe', 'aliases' => ['embe', 'mango'], 'unit' => Unit::Piece, 'price' => 30, 'qty' => 80, 'special' => 'Sweet, from Embu'],
            ['key' => 'avocado', 'en' => 'Avocados', 'sw' => 'Parachichi', 'aliases' => ['avocado', 'avo'], 'unit' => Unit::Piece, 'price' => 40, 'qty' => 60],
            ['key' => 'orange', 'en' => 'Oranges', 'sw' => 'Machungwa', 'aliases' => ['chungwa', 'orange'], 'unit' => Unit::Heap, 'price' => 80, 'qty' => 20],
            ['key' => 'lemon', 'en' => 'Lemons', 'sw' => 'Ndimu', 'aliases' => ['limau', 'lemon'], 'unit' => Unit::Heap, 'price' => 50, 'qty' => 20],
            ['key' => 'watermelon', 'en' => 'Watermelon', 'sw' => 'Tikiti maji', 'aliases' => ['tikiti'], 'unit' => Unit::Piece, 'price' => 150, 'qty' => 12],
            ['key' => 'managu', 'en' => 'African nightshade', 'sw' => 'Managu', 'aliases' => ['black nightshade'], 'unit' => Unit::Bunch, 'price' => 30, 'qty' => 30],
        ];
    }
}
