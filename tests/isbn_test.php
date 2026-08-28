<?php
declare(strict_types=1);

use App\Core\Isbn;

Assert::group('Isbn');

Assert::same('valid ISBN-13', Isbn::isValid13('9783473408061'), true);
Assert::same('broken check digit', Isbn::isValid13('9783473408062'), false);
Assert::same('valid ISBN-10', Isbn::isValid10('3473408069'), true);
Assert::same('ISBN-10 ending in X', Isbn::isValid10('349270526X'), true);

Assert::same('13 to 10', Isbn::to10('9783473408061'), '3473408069');
Assert::same('13 to 10 yielding X', Isbn::to10('9783492705264'), '349270526X');
Assert::same('10 to 13 round trip', Isbn::to13('3473408069'), '9783473408061');
Assert::same('979 has no ISBN-10', Isbn::to10('9791234567896'), null);

Assert::same('normalise hyphens', Isbn::normalize('978-3-473-40806-1'), '9783473408061');
Assert::same('normalise an ISBN-10 to 13', Isbn::normalize('3473408069'), '9783473408061');
Assert::same('reject prose', Isbn::normalize('keine isbn'), null);
Assert::same('reject empty', Isbn::normalize(''), null);
Assert::same('reject null', Isbn::normalize(null), null);

// 4005556022946 appears in the export but is a boxed game's EAN, not an ISBN.
Assert::same('reject a non-book EAN', Isbn::normalize('4005556022946'), null);

Assert::same('German registration group', Isbn::languageArea('9783473408061'), 'german');
Assert::same('English group 1', Isbn::languageArea('9781451608137'), 'english');
Assert::same('English group 0', Isbn::languageArea('9780603562136'), 'english');

Assert::same('display formatting', Isbn::format('9783473408061'), '978-3-473-40806-1');
