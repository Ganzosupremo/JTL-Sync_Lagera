<?php

declare(strict_types=1);

// Runs every tests/*_test.php file in its own PHP process and summarizes the results.
// Usage: php tests/run.php [filter]

$filter = $argv[1] ?? '';
$files = glob(__DIR__ . '/*_test.php') ?: [];
sort($files);

$passed = 0;
$skipped = 0;
$failed = [];

foreach ($files as $file) {
    $name = basename($file, '.php');

    if ($filter !== '' && !str_contains($name, $filter)) {
        continue;
    }

    $output = [];
    exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
    $text = trim(implode(PHP_EOL, $output));

    if ($exitCode === 0) {
        $passed++;
        echo 'PASS ' . $name . PHP_EOL;
    } elseif ($exitCode === 77) {
        $skipped++;
        echo 'SKIP ' . $name . ($text !== '' ? ': ' . $text : '') . PHP_EOL;
    } else {
        $failed[] = $name;
        echo 'FAIL ' . $name . PHP_EOL . $text . PHP_EOL;
    }
}

echo PHP_EOL . sprintf('%d passed, %d skipped, %d failed', $passed, $skipped, count($failed)) . PHP_EOL;

exit($failed === [] ? 0 : 1);
