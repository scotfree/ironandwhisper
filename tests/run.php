<?php
/**
 * Test runner. No composer, no PHPUnit — a test is a function named test_*
 * in one of the files below.
 *
 *   php tests/run.php            # everything
 *   php tests/run.php rules      # only files matching "rules"
 */
declare(strict_types=1);

require_once __DIR__ . '/support/bootstrap.php';

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/test_*.php');
sort($files);

$tests = [];
foreach ($files as $file) {
    if ($filter !== '' && !str_contains(basename($file), $filter)) {
        continue;
    }
    $before = get_defined_functions()['user'];
    require_once $file;
    $after = get_defined_functions()['user'];
    foreach (array_diff($after, $before) as $function) {
        if (str_starts_with($function, 'test_')) {
            $tests[] = [basename($file), $function];
        }
    }
}

$failures = [];
foreach ($tests as [$file, $test]) {
    try {
        $test();
        echo '.';
    } catch (\Throwable $e) {
        echo 'F';
        $failures[] = [$file, $test, $e];
    }
}

echo "\n";
foreach ($failures as [$file, $test, $e]) {
    printf("\nFAIL %s :: %s\n%s\n", $file, $test, $e->getMessage());
    if (!($e instanceof AssertionFailed)) {
        printf("%s\n", $e->getTraceAsString());
    }
}

printf(
    "\n%d passed, %d failed\n",
    count($tests) - count($failures),
    count($failures),
);
exit($failures ? 1 : 0);
