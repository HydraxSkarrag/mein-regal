<?php
/**
 * The markup subset owners write their pages in.
 *
 * Two things are being checked, and the second matters more than the first:
 * that the syntax produces the structure it promises, and that no route
 * exists from typed text to executable markup. The escaping happens before
 * any tag is written, so the interesting cases are the ones where a tag
 * could sneak back in - through a link address, through a code span, through
 * an entity that decodes late.
 */
declare(strict_types=1);

use App\Core\Markup;

Assert::group('Markup structure');

Assert::same('empty stays empty', Markup::render(''), '');
Assert::same('null stays empty', Markup::render(null), '');

Assert::same('a heading starts at h2', Markup::render('## Über mich'), '<h2>Über mich</h2>');
Assert::same('three hashes give h3', Markup::render('### Kleiner'), '<h3>Kleiner</h3>');
Assert::same('a single hash is not a heading', Markup::render('# Eins'), '<p># Eins</p>');

Assert::same(
    'a dash list becomes ul',
    Markup::render("- Eins\n- Zwei"),
    '<ul><li>Eins</li><li>Zwei</li></ul>'
);
Assert::same(
    'a numbered list becomes ol',
    Markup::render("1. Eins\n2. Zwei"),
    '<ol><li>Eins</li><li>Zwei</li></ol>'
);
Assert::same(
    'switching list kind closes the first',
    Markup::render("- Eins\n1. Zwei"),
    '<ul><li>Eins</li></ul><ol><li>Zwei</li></ol>'
);
Assert::same(
    'an indented line continues the item above it',
    Markup::render("- Eins\n  und weiter"),
    '<ul><li>Eins und weiter</li></ul>'
);

Assert::same('a quote becomes blockquote', Markup::render('> Zitat'), '<blockquote><p>Zitat</p></blockquote>');
Assert::same('three dashes become a rule', Markup::render('---'), '<hr>');

Assert::same('bold', Markup::render('Ein **fettes** Wort'), '<p>Ein <strong>fettes</strong> Wort</p>');
Assert::same('italic with stars', Markup::render('Ein *schräges* Wort'), '<p>Ein <em>schräges</em> Wort</p>');
Assert::same('italic with underscores', Markup::render('Ein _schräges_ Wort'), '<p>Ein <em>schräges</em> Wort</p>');
Assert::same('code span', Markup::render('Ruf `bin/check.php` auf'), '<p>Ruf <code>bin/check.php</code> auf</p>');

// snake_case is common in prose about software and must survive intact.
Assert::same(
    'underscores inside a word are not emphasis',
    Markup::render('Die Spalte owner_id kommt aus der Datenbank'),
    '<p>Die Spalte owner_id kommt aus der Datenbank</p>'
);

Assert::same(
    'a named link',
    Markup::render('Mehr im [Blog](https://www.buecherhausen.de/)'),
    '<p>Mehr im <a href="https://www.buecherhausen.de/" rel="noopener">Blog</a></p>'
);
Assert::same(
    'a bare address links to itself',
    Markup::render('Siehe https://example.org/seite'),
    '<p>Siehe <a href="https://example.org/seite" rel="noopener">https://example.org/seite</a></p>'
);
Assert::same(
    'a mailto link',
    Markup::render('[Post](mailto:jemand@example.org)'),
    '<p><a href="mailto:jemand@example.org" rel="noopener">Post</a></p>'
);

Assert::group('Markup safety');

// The whole design rests on this: escaping runs before any tag is produced.
Assert::same(
    'a script tag is text',
    Markup::render('<script>alert(1)</script>'),
    '<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>'
);
Assert::same(
    'an attribute cannot be smuggled in',
    Markup::render('<a href="#" onclick="x">hi</a>'),
    '<p>&lt;a href=&quot;#&quot; onclick=&quot;x&quot;&gt;hi&lt;/a&gt;</p>'
);

// A link syntax with a scheme we do not allow stays as typed rather than
// silently becoming a link that runs code.
Assert::true(
    'javascript: in link syntax does not become a link',
    !str_contains(Markup::render('[klick](javascript:alert(1))'), '<a ')
);
Assert::true(
    'data: in link syntax does not become a link',
    !str_contains(Markup::render('[klick](data:text/html;base64,PHNjcmlwdD4=)'), '<a ')
);
Assert::true(
    'a bare javascript: address does not become a link',
    !str_contains(Markup::render('javascript:alert(1)'), '<a ')
);

// A quote inside a link address would end the href attribute if it reached
// the output undecoded.
Assert::true(
    'a quote in a link address cannot close the attribute',
    !str_contains(Markup::render('[x](https://example.org/"onmouseover="alert(1))'), 'onmouseover="alert')
);

// Code spans copy their contents through, so anything dangerous must already
// have been escaped by the time they are cut out.
Assert::same(
    'a tag inside a code span stays text',
    Markup::render('`<img onerror=alert(1)>`'),
    '<p><code>&lt;img onerror=alert(1)&gt;</code></p>'
);

// The placeholder used while links are parked must not be forgeable from the
// input; control characters are stripped before anything else happens.
Assert::true(
    'a NUL byte in the input does not survive',
    !str_contains(Markup::render("Eins \x000\x00 Zwei"), "\x00")
);
Assert::same(
    'text that looks like a placeholder is left alone',
    Markup::render('Eins 0 Zwei'),
    '<p>Eins 0 Zwei</p>'
);

// Every tag in the output is one this class wrote. If the rendered result
// ever contains a tag name that is not on this list, something got through.
$rendered = Markup::render(
    "## Titel\n\nText mit **fett**, *kursiv*, `code` und [Link](https://example.org).\n\n"
    . "- Punkt\n- Punkt\n\n> Zitat\n\n---\n\n1. Eins"
);
preg_match_all('~<\s*/?\s*([a-zA-Z0-9]+)~', $rendered, $found);
$allowed = ['h2', 'h3', 'h4', 'p', 'br', 'ul', 'ol', 'li', 'blockquote', 'hr', 'strong', 'em', 'code', 'a'];
Assert::same(
    'only the intended tags appear',
    array_values(array_diff(array_unique(array_map('strtolower', $found[1])), $allowed)),
    []
);
