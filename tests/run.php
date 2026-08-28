<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

foreach (glob(__DIR__ . '/*_test.php') ?: [] as $file) {
    require $file;
}

exit(Assert::summary());
