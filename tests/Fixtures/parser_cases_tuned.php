<?php

declare(strict_types=1);

/*
 * Parser regression table, batch A. These cases were written while building
 * and tuning the parser, so passing them proves it does not regress, not that
 * it generalises (batch B, parser_cases_fresh.php, was written afterwards).
 *
 * Expected lines are "key:quantity:unit" in order. A trailing "!" means the
 * line must be flagged for the owner to review. "?" is an unmatched or
 * ambiguous line (always flagged). Optional 'time' and 'phone' check the
 * delivery-time hint and phone number lifted out of the chatter.
 */

return [
    // Word order and spacing
    ['item then quantity', 'sukuma 2 bunch', ['kale:2:bunch']],
    ['quantity then item', '2 bunch sukuma', ['kale:2:bunch']],
    ['item and bare number', 'sukuma 2', ['kale:2:bunch']],
    ['bare number and item', '2 sukuma', ['kale:2:bunch']],
    ['glued kg', 'nyanya 1kg', ['tomato:1:kg']],
    ['glued kg before item', '1kg nyanya', ['tomato:1:kg']],
    ['spaced kg', 'nyanya 1 kg', ['tomato:1:kg']],
    ['unit before number', 'nyanya kilo 2', ['tomato:2:kg']],
    ['unit and number first', 'kilo 2 nyanya', ['tomato:2:kg']],
    ['kgs plural', 'nyanya 2 kgs', ['tomato:2:kg']],
    ['upper case', 'NYANYA 2KG', ['tomato:2:kg']],
    ['english with of', '2kg of tomatoes', ['tomato:2:kg']],
    ['article for one', 'a kilo of tomatoes', ['tomato:1:kg']],
    ['english order', 'tomatoes 2kg', ['tomato:2:kg']],
    ['times sign after', 'sukuma x2', ['kale:2:bunch']],
    ['times sign before', '2x sukuma', ['kale:2:bunch']],
    ['unicode times sign', 'sukuma × 3', ['kale:3:bunch']],
    ['hyphenated name', 'sukuma-wiki 2', ['kale:2:bunch']],
    ['glued two-word name', 'sukumawiki 2 bunch', ['kale:2:bunch']],
    ['two-word name', 'sukuma wiki 2', ['kale:2:bunch']],
    ['plural bunches', 'sukuma bunches 4', ['kale:4:bunch']],

    // Swahili numerals
    ['moja', 'sukuma moja', ['kale:1:bunch']],
    ['mbili', 'sukuma mbili', ['kale:2:bunch']],
    ['kilo moja', 'nyanya kilo moja', ['tomato:1:kg']],
    ['kilo mbili', 'nyanya kilo mbili', ['tomato:2:kg']],
    ['kilo tatu', 'nyanya kilo tatu', ['tomato:3:kg']],
    ['kilo moja na nusu', 'nyanya kilo moja na nusu', ['tomato:1.5:kg']],
    ['kilo mbili na nusu', 'vitunguu kilo mbili na nusu', ['onion:2.5:kg']],
    ['nusu kilo', 'nusu kilo nyanya', ['tomato:0.5:kg']],
    ['nusu kilo ya', 'nusu kilo ya nyanya', ['tomato:0.5:kg']],
    ['robo kilo', 'robo kilo ya nyanya', ['tomato:0.25:kg']],
    ['compound numeral 12', 'kumi na mbili ndizi', ['banana:12:piece']],
    ['compound numeral 25', 'ishirini na tano ndizi', ['banana:25:piece']],
    ['kumi', 'ndizi kumi', ['banana:10:piece']],
    ['noun class mi-', 'mikungu mitatu ya sukuma', ['kale:3:bunch']],
    ['noun class vi-', 'ndizi vipande vinne', ['banana:4:piece']],
    ['english number words', 'two kilos of onions', ['onion:2:kg']],
    ['mbili without unit', 'nipe nyanya mbili', ['tomato:2:kg']],
    ['mchicha mbili', 'mchicha mbili', ['spinach:2:bunch']],

    // Fractions, decimals, grams
    ['slash fraction', 'nyanya 1/2 kg', ['tomato:0.5:kg']],
    ['unicode half', 'nyanya 1½kg', ['tomato:1.5:kg']],
    ['decimal point', 'nyanya 1.5kg', ['tomato:1.5:kg']],
    ['decimal comma', 'nyanya 1,5 kg', ['tomato:1.5:kg']],
    ['mixed fraction', 'nyanya 1 1/2 kg', ['tomato:1.5:kg']],
    ['half in english', 'half a kilo of carrots', ['carrot:0.5:kg']],
    ['grams 500', 'nyanya 500g', ['tomato:0.5:kg']],
    ['grams 250', 'nyanya 250 grams', ['tomato:0.25:kg']],
    ['grams 750', 'nyanya 750g', ['tomato:0.75:kg']],

    // Typos and aliases
    ['stretched letters', 'nyanyaaa 2kg', ['tomato:2:kg']],
    ['english typo', 'tomatos 2kg', ['tomato:2:kg']],
    ['swahili typo', 'sukumma 3', ['kale:3:bunch']],
    ['transposed letters', 'karoit 2kg', ['carrot:2:kg']],
    ['plural vitunguu', 'vitunguu 2kg', ['onion:2:kg']],
    ['singular kitunguu', 'kitunguu 2kg', ['onion:2:kg']],
    ['garlic is not onion', 'kitunguu saumu 1', ['garlic:1:heap']],
    ['spring onion plural', 'vitunguu maji 2', ['spring-onion:2:bunch']],
    ['sweet potato', 'viazi vitamu 1kg', ['sweet-potato:1:kg']],
    ['irish potato', 'viazi ulaya 2kg', ['potato:2:kg']],
    ['irish short', 'irish 3kg', ['potato:3:kg']],
    ['potatoes english', 'potatoes 3kg', ['potato:3:kg']],
    ['pilipili hoho', 'pilipili hoho 3', ['green-pepper:3:piece']],
    ['hoho alone', 'hoho 3', ['green-pepper:3:piece']],
    ['capsicum', 'capsicum 2', ['green-pepper:2:piece']],
    ['pilipili kali', 'pilipili kali 1', ['chilli:1:heap']],
    ['mango plural', 'maembe 4', ['mango:4:piece']],
    ['mango singular', 'embe 4', ['mango:4:piece']],
    ['avocado', 'parachichi 3', ['avocado:3:piece']],
    ['avocado nickname', 'avo 2', ['avocado:2:piece']],
    ['watermelon', 'tikiti maji 1', ['watermelon:1:piece']],
    ['orange', 'machungwa 2', ['orange:2:heap']],
    ['lemon', 'limau 1', ['lemon:1:heap']],
    ['cabbage spelling', 'kabeji 2', ['cabbage:2:piece']],
    ['managu', 'managu 2', ['managu:2:bunch']],
    ['cooking bananas', 'ndizi mbichi 1', ['matoke:1:heap']],
    ['matoke', 'matoke 2', ['matoke:2:heap']],
    ['two chillies', 'pilipili hoho 2 pilipili kali 1', ['green-pepper:2:piece', 'chilli:1:heap']],

    // Several items
    ['comma separated', 'sukuma 2 bunch, nyanya 1kg', ['kale:2:bunch', 'tomato:1:kg']],
    ['space separated', 'sukuma 2 bunch nyanya 1kg', ['kale:2:bunch', 'tomato:1:kg']],
    ['three items suffix', 'sukuma 2 nyanya 1kg vitunguu 2kg', ['kale:2:bunch', 'tomato:1:kg', 'onion:2:kg']],
    ['three items prefix', '2 sukuma 1 nyanya 3 vitunguu', ['kale:2:bunch', 'tomato:1:kg', 'onion:3:kg']],
    ['na separator', 'sukuma 2 na nyanya 1kg', ['kale:2:bunch', 'tomato:1:kg']],
    ['and separator', 'sukuma 2 and tomatoes 1kg', ['kale:2:bunch', 'tomato:1:kg']],
    ['plus separator', 'sukuma 2 + dhania 1', ['kale:2:bunch', 'coriander:1:bunch']],
    ['numbered list', "1. sukuma 2\n2. nyanya 1kg\n3. vitunguu 1kg", ['kale:2:bunch', 'tomato:1:kg', 'onion:1:kg']],
    ['dashed list', "- sukuma 2\n- nyanya 1", ['kale:2:bunch', 'tomato:1:kg']],
    ['bullet list', "• sukuma 2\n• nyanya 1", ['kale:2:bunch', 'tomato:1:kg']],
    ['paren list', "1) sukuma 2\n2) nyanya 1", ['kale:2:bunch', 'tomato:1:kg']],
    ['duplicate lines merged', "sukuma 2\nsukuma 1", ['kale:3:bunch']],
    ['duplicates one line', 'sukuma 2 sukuma 1', ['kale:3:bunch']],
    ['emoji', '🥬 sukuma 2 🍅 nyanya 1kg', ['kale:2:bunch', 'tomato:1:kg']],

    // Chatter around the order
    ['greeting en', 'Habari! Please send tomatoes 2kg and onions 1kg. Thanks', ['tomato:2:kg', 'onion:1:kg']],
    ['greeting sw', 'Hi mama njeri, naomba sukuma 2 bunch na nyanya kilo 1. Asante!', ['kale:2:bunch', 'tomato:1:kg']],
    ['nipe tafadhali', 'nipe vitunguu kilo mbili tafadhali', ['onion:2:kg']],
    ['delivery time pm', "sukuma 2, nyanya 1kg, deliver 6pm", ['kale:2:bunch', 'tomato:1:kg'], ['time' => '6pm']],
    ['delivery time swahili', "sukuma 2\nnyanya 1kg\ndeliver saa kumi na mbili jioni", ['kale:2:bunch', 'tomato:1:kg'], ['time' => 'saa 12 jioni']],
    ['phone number line', "sukuma 2\n0700 000 001", ['kale:2:bunch'], ['phone' => '254700000001']],
    ['phone with dashes', 'sukuma 2 from 0700-000-003', ['kale:2:bunch'], ['phone' => '254700000003']],
    ['phone international', "+254 700 000 002\nsukuma 2", ['kale:2:bunch'], ['phone' => '254700000002']],
    ['whatsapp export prefix', '[12/03/2026, 07:41] Njeri: sukuma 2', ['kale:2:bunch']],
    ['long realistic message', 'Mambo! Naomba: nyanya kilo mbili, sukuma mikungu mitano, ndizi kumi, na parachichi nne. Deliver saa kumi jioni. Asante sana 🙏', ['tomato:2:kg', 'kale:5:bunch', 'banana:10:piece', 'avocado:4:piece'], ['time' => 'saa 10 jioni']],
    ['only chatter', 'hello', []],
    ['only thanks', 'asante sana', []],
    ['only time', 'deliver tomorrow 8am', []],
    ['empty', '', []],

    // Flagged for the owner: must not be guessed silently
    ['unit mismatch kg for bunch', 'sukuma 2 kg', ['kale:2:bunch!']],
    ['unit mismatch bunch for piece', 'ndizi 2 bunch', ['banana:2:piece!']],
    ['unit mismatch bunch for kg', 'nyanya 2 bunch', ['tomato:2:kg!']],
    ['fractional bunch', 'sukuma 2.5', ['kale:2.5:bunch!']],
    ['fractional piece', 'ndizi 1.5', ['banana:1.5:piece!']],
    ['odd kg step', 'nyanya 0.3 kg', ['tomato:0.3:kg!']],
    ['no quantity', 'nyanya', ['tomato:1:kg!']],
    ['no quantity bunch', 'sukuma', ['kale:1:bunch!']],
    ['absurd quantity', 'nyanya 500', ['tomato:500:kg!']],
    ['zero', 'sukuma 0', ['kale:0:bunch!']],
    ['ambiguous viazi', 'viazi 2kg', ['?:2!']],
    ['ambiguous pilipili', 'pilipili 2', ['?:2!']],
    ['not on the board', 'mchele 2kg', ['?:2!']],
    ['unknown packet', 'unga 1 packet', ['?:1!']],
    ['one unknown in three', 'sukuma 2, mchele 1kg, nyanya 1kg', ['kale:2:bunch', '?:1!', 'tomato:1:kg']],
    ['partial alias is only a suggestion', 'ulaya 2kg', ['potato:2:kg!']],
    ['packet is not a board unit', 'sukuma 2 packets', ['kale:2:bunch!']],
];
