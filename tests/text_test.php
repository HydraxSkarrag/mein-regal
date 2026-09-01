<?php
declare(strict_types=1);

use App\Core\Text;

Assert::group('Text::splitAuthors');

$one = Text::splitAuthors('LePera, Nicole');
Assert::same('single "Last, First"', $one['names'], ['Nicole LePera']);
Assert::same('single is unambiguous', $one['ambiguous'], false);

Assert::same('single pen name', Text::splitAuthors('Rose Snow')['names'], ['Rose Snow']);

$two = Text::splitAuthors('Flessner, Bernd, Schilling, Peter');
Assert::same('two "Last, First" pairs', $two['names'], ['Bernd Flessner', 'Peter Schilling']);

$full = Text::splitAuthors('Kobi Yamada, Mae Besom');
Assert::same('list of full names', $full['names'], ['Kobi Yamada', 'Mae Besom']);

$milne = Text::splitAuthors('Milne, Alan Alexander, Milne, A. A. (Alan Alexander)');
Assert::same('pairs with a parenthetical', $milne['names'], ['Alan Alexander Milne', 'A. A. (Alan Alexander) Milne']);

$odd = Text::splitAuthors('Snow, Rose, Miller');
Assert::same('odd short parts stay unsplit', $odd['names'], ['Snow, Rose, Miller']);
Assert::same('odd short parts are flagged', $odd['ambiguous'], true);

Assert::same('empty field', Text::splitAuthors('')['names'], []);

Assert::group('Text::authorMatchKey');

// The whole point: these two spellings are one person and must collapse.
Assert::same(
    'both spellings of Dorothea Flechsig agree',
    Text::authorMatchKey('Flechsig, Dorothea'),
    Text::authorMatchKey('Dorothea Flechsig')
);
Assert::same(
    'accents do not split a person',
    Text::authorMatchKey('Gablé, Rebecca'),
    Text::authorMatchKey('Rebecca Gable')
);
Assert::same(
    'different people stay apart',
    Text::authorMatchKey('Rose Snow') === Text::authorMatchKey('Rose Miller'),
    false
);

Assert::group('Text::slug');

Assert::same('umlauts transliterate', Text::slug('Rückkehr zur Erde'), 'rueckkehr-zur-erde');
Assert::same('punctuation collapses', Text::slug('Heile. Dich. Selbst.'), 'heile-dich-selbst');
Assert::same('empty input still yields a slug', Text::slug(''), 'ohne-titel');
Assert::same('no trailing hyphen after truncation', str_ends_with(Text::slug(str_repeat('wort ', 40)), '-'), false);

Assert::group('Text::isPlaceholderName');

Assert::same('"Unbekannt" is not a person', Text::isPlaceholderName('Unbekannt'), true);
Assert::same('"Diverse " with stray space', Text::isPlaceholderName('Diverse '), true);
Assert::same('a real name is not a placeholder', Text::isPlaceholderName('Rose Snow'), false);

Assert::group('Text::sortName');

Assert::same('full name inverts', Text::sortName('Bernd Flessner'), 'Flessner, Bernd');
Assert::same('already inverted stays', Text::sortName('Flechsig, Dorothea'), 'Flechsig, Dorothea');
Assert::same('mononym unchanged', Text::sortName('Homer'), 'Homer');

Assert::group('Text::splitAuthors mixed formats');

// Real rows from the export that mix a full name with inverted pairs.
Assert::same(
    'full name then an inverted pair',
    Text::splitAuthors('Florian Huber, Kunz, Uli')['names'],
    ['Florian Huber', 'Uli Kunz']
);
Assert::same(
    'multi-word given name then a pair',
    Text::splitAuthors('Heinrich von Veldeke, Brandt-Schwarze, Ulrike')['names'],
    ['Heinrich von Veldeke', 'Ulrike Brandt-Schwarze']
);

// A compound surname must not be mistaken for a complete name.
Assert::same(
    'compound surname stays one person',
    Text::splitAuthors('van Gogh, Vincent')['names'],
    ['Vincent van Gogh']
);

$anthology = Text::splitAuthors(
    'Benkau, Jennifer, Falk, Alana, Rose Snow, Stein, Julia K., Tack, Stella'
);
Assert::same(
    'anthology list with a pen name in the middle',
    $anthology['names'],
    ['Jennifer Benkau', 'Alana Falk', 'Rose Snow', 'Julia K. Stein', 'Stella Tack']
);
Assert::same('anthology resolves cleanly', $anthology['ambiguous'], false);

// A dangling surname is still refused rather than guessed at.
$dangling = Text::splitAuthors('Snow, Rose, Miller');
Assert::same('dangling surname is flagged', $dangling['ambiguous'], true);

Assert::group('Text::splitAuthors compound surnames');

// A surname containing a space breaks the left-to-right scan. With an even
// number of parts, strict pairing is the only consistent reading.
$compound = Text::splitAuthors('Bürgi Wirth, Babette, Kolb, Stefanie');
Assert::same('compound surname pairs correctly', $compound['names'], ['Babette Bürgi Wirth', 'Stefanie Kolb']);
Assert::same('but is still flagged for review', $compound['ambiguous'], true);

Assert::same(
    'three compound surnames',
    Text::splitAuthors('Amann, Klaus A., Marín Barrera, Sara, Osorio Santiago, Elvira')['names'],
    ['Klaus A. Amann', 'Sara Marín Barrera', 'Elvira Osorio Santiago']
);
Assert::same(
    'nobiliary particle in a surname',
    Text::splitAuthors('von Bredow-Werndl, Jessica, Szillat, Antje')['names'],
    ['Jessica von Bredow-Werndl', 'Antje Szillat']
);

Assert::group('Text::sortName ignores a role marker');

// The Bookstats export puts the role in the author field. Eleven names came
// through that way, and every one of them filed under a bracket rather than
// under its own initial - invisible on an alphabetical list, which is where
// somebody would go looking for them.
Assert::same('illustrator marker dropped', Text::sortName('Eva Gebhardt (Ill.)'), 'Gebhardt, Eva');
Assert::same('long form too', Text::sortName('Katharina Staar (Illustratorin)'), 'Staar, Katharina');
Assert::same('author marker dropped', Text::sortName('Alexandra Haag (Aut.)'), 'Haag, Alexandra');
Assert::same('hyphenated given name survives', Text::sortName('Karl-Heinz Appelmann (Illustr.)'), 'Appelmann, Karl-Heinz');
Assert::same('a plain name is unchanged', Text::sortName('Bernd Flessner'), 'Flessner, Bernd');
Assert::same('an inverted name is left alone', Text::sortName('Flechsig, Dorothea'), 'Flechsig, Dorothea');
Assert::same('a mononym is left alone', Text::sortName('Cher'), 'Cher');

// The export uses both bracket shapes for the same idea. Knowing only round
// ones filed Bruno Horst Bull under "Bull, Bruno Horst [Hrsg.]".
Assert::same('square brackets too', Text::sortName('Bruno Horst [Hrsg.] Bull'), 'Bull, Bruno Horst');
Assert::same('and mid-name', Text::sortName('Hans-Jörg (Hrsg.) Uther'), 'Uther, Hans-Jörg');
Assert::same('an expanded initial is an aside as well', Text::sortName('A. A. (Alan Alexander) Milne'), 'Milne, A. A.');

// The sort name and the match key have to agree about what a name is, or the
// same person sorts in one place and merges in another.
Assert::same(
    'sort name and match key see the same person',
    Text::authorMatchKey('Eva Gebhardt (Ill.)'),
    Text::authorMatchKey('Eva Gebhardt')
);
Assert::same(
    'and so do their sort names',
    Text::sortName('Eva Gebhardt (Ill.)'),
    Text::sortName('Eva Gebhardt')
);
