<?php
declare(strict_types=1);

use App\Lookup\Contributors;

Assert::group('Contributors');

// A real Open Library record for "One" by Sarah Crossan.
Assert::same(
    'a comma-joined duplicate collapses to one person',
    Contributors::normalize(['Sarah Crossan,Sarah Crossan']),
    [['name' => 'Sarah Crossan', 'role' => 'author']]
);

Assert::same(
    'the two spellings of one name collapse',
    Contributors::normalize(['Flechsig, Dorothea', 'Dorothea Flechsig']),
    [['name' => 'Dorothea Flechsig', 'role' => 'author']]
);

Assert::same('placeholders are not people', Contributors::normalize(['Unbekannt', 'Diverse']), []);

Assert::same(
    'distinct people are kept, in order',
    array_column(Contributors::normalize(['Kobi Yamada', 'Mae Besom']), 'name'),
    ['Kobi Yamada', 'Mae Besom']
);

Assert::same('the role is carried through', Contributors::normalize(['Zapf'], 'illustrator')[0]['role'], 'illustrator');

Assert::same(
    'dedupe keeps the first role a person appeared with',
    Contributors::dedupe([
        ['name' => 'Ute Krause', 'role' => 'author'],
        ['name' => 'Krause, Ute', 'role' => 'illustrator'],
    ]),
    [['name' => 'Ute Krause', 'role' => 'author']]
);
