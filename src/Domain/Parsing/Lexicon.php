<?php

declare(strict_types=1);

namespace MamaMboga\Domain\Parsing;

/**
 * The words the parser knows: Swahili and English numerals, unit spellings
 * and the chatter that surrounds an order ("naomba", "please", "asante").
 */
final class Lexicon
{
    /** @var array<string, int> */
    public const NUMBERS = [
        'moja' => 1, 'mbili' => 2, 'tatu' => 3, 'nne' => 4, 'tano' => 5,
        'sita' => 6, 'saba' => 7, 'nane' => 8, 'tisa' => 9, 'kumi' => 10,
        // Noun-class agreement: "mikungu mitatu", "vipande vinne", "marundo matano".
        'viwili' => 2, 'miwili' => 2, 'mawili' => 2, 'wawili' => 2,
        'vitatu' => 3, 'mitatu' => 3, 'matatu' => 3, 'watatu' => 3,
        'vinne' => 4, 'minne' => 4, 'manne' => 4, 'wanne' => 4,
        'vitano' => 5, 'mitano' => 5, 'matano' => 5, 'watano' => 5,
        'ishirini' => 20, 'thelathini' => 30, 'arobaini' => 40, 'hamsini' => 50,
        'one' => 1, 'two' => 2, 'three' => 3, 'four' => 4, 'five' => 5,
        'six' => 6, 'seven' => 7, 'eight' => 8, 'nine' => 9, 'ten' => 10,
        'twelve' => 12, 'fifteen' => 15, 'twenty' => 20,
    ];

    /** Swahili tens that combine with "na <1-9>": "ishirini na tano" = 25. */
    public const TENS = ['kumi' => 10, 'ishirini' => 20, 'thelathini' => 30, 'arobaini' => 40, 'hamsini' => 50];

    public const UNITS_1_9 = ['moja' => 1, 'mbili' => 2, 'tatu' => 3, 'nne' => 4, 'tano' => 5, 'sita' => 6, 'saba' => 7, 'nane' => 8, 'tisa' => 9];

    /** @var array<string, string> spelling => kg|g|bunch|piece|heap|other */
    public const UNITS = [
        'kg' => 'kg', 'kgs' => 'kg', 'kilo' => 'kg', 'kilos' => 'kg', 'kilogram' => 'kg', 'kilograms' => 'kg', 'kgm' => 'kg',
        'g' => 'g', 'gm' => 'g', 'gms' => 'g', 'gram' => 'g', 'grams' => 'g',
        'bunch' => 'bunch', 'bunches' => 'bunch', 'bundle' => 'bunch', 'bundles' => 'bunch', 'mkungu' => 'bunch', 'mikungu' => 'bunch', 'mche' => 'bunch', 'miche' => 'bunch',
        'piece' => 'piece', 'pieces' => 'piece', 'pc' => 'piece', 'pcs' => 'piece', 'kipande' => 'piece', 'vipande' => 'piece',
        // Packaging the shop does not sell by: recognised so it is not mistaken for an item.
        'packet' => 'other', 'packets' => 'other', 'pkt' => 'other', 'pkts' => 'other', 'tin' => 'other', 'tins' => 'other', 'tray' => 'other', 'trays' => 'other',
        'crate' => 'other', 'crates' => 'other', 'bag' => 'other', 'bags' => 'other', 'sack' => 'other', 'sacks' => 'other', 'gunia' => 'other', 'debe' => 'other',
        'bottle' => 'other', 'bottles' => 'other', 'litre' => 'other', 'litres' => 'other', 'liter' => 'other', 'liters' => 'other', 'ltr' => 'other', 'dozen' => 'other',
        'heap' => 'heap', 'heaps' => 'heap', 'rundo' => 'heap', 'marundo' => 'heap', 'lundo' => 'heap',
    ];

    /** Words that carry no item or quantity information. */
    public const STOPWORDS = [
        'of', 'ya', 'wa', 'za', 'la', 'the', 'a', 'an', 'some', 'any', 'x', 'tu', 'ni', 'is', 'are', 'it',
        'please', 'pls', 'plz', 'tafadhali', 'naomba', 'nataka', 'nahitaji', 'nipe', 'nipatie', 'tuma', 'leta', 'niletee', 'leteni',
        'kindly', 'need', 'want', 'order', 'orders', 'i', 'me', 'my', 'we', 'us', 'our', 'to', 'for', 'from', 'can', 'could', 'would', 'get', 'give', 'send', 'bring',
        'deliver', 'delivery', 'delivered', 'address', 'home', 'nyumbani', 'location', 'at', 'by', 'before', 'after', 'kabla', 'baada',
        'leo', 'today', 'kesho', 'tomorrow', 'tonight', 'jioni', 'asubuhi', 'mchana', 'usiku', 'morning', 'afternoon', 'evening', 'night',
        'hi', 'hello', 'hey', 'habari', 'mambo', 'sasa', 'niaje', 'poa', 'asante', 'thanks', 'thank', 'you', 'shukrani', 'mama', 'mum', 'baba', 'boss', 'dear', 'sir', 'madam', 'bro', 'sis',
        'fresh', 'nzuri', 'safi', 'good', 'nice', 'big', 'kubwa', 'small', 'ndogo', 'ripe', 'quality',
        'name', 'jina', 'langu', 'yangu', 'simu', 'phone', 'number', 'namba', 'saa', 'na', 'sana', 'very', 'much', 'again', 'ok', 'okay', 'sawa', 'alright', 'yes', 'ndio', 'bob', 'bei', 'price', 'ksh', 'kes', 'sh', 'shillings', 'shilingi',
    ];
}
