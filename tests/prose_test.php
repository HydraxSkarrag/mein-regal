<?php
declare(strict_types=1);

use App\Core\Text;

Assert::group('Text::prose');

Assert::same('empty stays empty', Text::prose(''), '');
Assert::same('null stays empty', Text::prose(null), '');
Assert::same('a single line becomes a paragraph', Text::prose('Hallo'), '<p>Hallo</p>');
Assert::same(
    'a blank line splits paragraphs',
    Text::prose("Eins\n\nZwei"),
    '<p>Eins</p><p>Zwei</p>'
);
// nl2br keeps the newline after the tag, which is correct HTML and keeps
// the source readable.
Assert::same(
    'a single newline becomes a line break',
    Text::prose("Eins\nZwei"),
    "<p>Eins<br>\nZwei</p>"
);

// Escaping happens before any tag is added, so nothing typed can become markup.
Assert::same(
    'markup is escaped, not executed',
    Text::prose('<script>alert(1)</script>'),
    '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'
);
Assert::same(
    'an onclick attribute is just text',
    Text::prose('<a href="#" onclick="x">hi</a>'),
    '<p>&lt;a href=&quot;#&quot; onclick=&quot;x&quot;&gt;hi&lt;/a&gt;</p>'
);

Assert::same(
    'a bare address becomes a link',
    Text::prose('Mehr auf https://www.buecherhausen.de/'),
    '<p>Mehr auf <a href="https://www.buecherhausen.de/" rel="noopener">https://www.buecherhausen.de/</a></p>'
);
Assert::same(
    'a full stop after a link is not part of it',
    Text::prose('Siehe https://example.org/seite.'),
    '<p>Siehe <a href="https://example.org/seite" rel="noopener">https://example.org/seite</a>.</p>'
);

// A javascript: URL is not an http(s) one, so it is never turned into a link.
Assert::same(
    'javascript: is left as plain text',
    Text::prose('javascript:alert(1)'),
    '<p>javascript:alert(1)</p>'
);
Assert::true(
    'and produces no anchor at all',
    !str_contains(Text::prose('javascript:alert(1)'), '<a ')
);
