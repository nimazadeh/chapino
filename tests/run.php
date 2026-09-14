<?php

declare(strict_types=1);

/**
 * Test runner. No Composer, no framework, no configuration file.
 *
 *   php tests/run.php                 run everything
 *   php tests/run.php --filter=Router run only tests whose class or method matches
 *
 * Exit code 0 means every test passed; anything else is a failure. The runner never
 * hides a failure or a skipped test (see the QA rule).
 *
 * Development note: when no native PHP is available, run it through
 * `node tools/dev/php.mjs test` (see tools/dev/README.md).
 */

define('APP_ROOT', dirname(__DIR__));

require __DIR__ . '/../app/Core/Autoloader.php';
App\Core\Autoloader::register(dirname(__DIR__) . '/app', 'App\\');
App\Core\Autoloader::register(__DIR__, 'Tests\\');
App\Core\Clock::init();

$filter = '';
foreach ($argv as $argument) {
    if (str_starts_with($argument, '--filter=')) {
        $filter = substr($argument, 9);
    }
}

/** @return list<string> */
function discover_test_files(string $directory): array
{
    $files = [];
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . '/' . $entry;
        if (is_dir($path)) {
            $files = array_merge($files, discover_test_files($path));
        } elseif (str_ends_with($entry, 'Test.php')) {
            $files[] = $path;
        }
    }

    return $files;
}

$testFiles = discover_test_files(__DIR__);
sort($testFiles);

$passed = 0;
$failed = 0;
$assertions = 0;
$failures = [];
$started = microtime(true);

foreach ($testFiles as $file) {
    $before = get_declared_classes();
    require_once $file;
    $newClasses = array_diff(get_declared_classes(), $before);
    $testClasses = array_values(array_filter(
        $newClasses,
        static fn (string $class): bool => is_subclass_of($class, Tests\TestCase::class),
    ));

    if ($testClasses === []) {
        fwrite(STDERR, 'WARNING: no TestCase found in ' . basename($file) . "\n");
    }

    foreach ($testClasses as $class) {
        $reflection = new ReflectionClass($class);
        if ($reflection->isAbstract()) {
            continue;
        }
        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if (!str_starts_with($method->getName(), 'test') || $method->isStatic()) {
                continue;
            }
            $label = $reflection->getShortName() . '::' . $method->getName();
            if ($filter !== '' && stripos($label, $filter) === false) {
                continue;
            }

            /** @var Tests\TestCase $instance */
            $instance = $reflection->newInstance();
            try {
                $instance->runTest($method->getName());
                $assertions += $instance->assertionsCount();
                $passed++;
                echo "  PASS  {$label}\n";
            } catch (Tests\AssertionFailed $e) {
                $assertions += $instance->assertionsCount();
                $failed++;
                $failures[$label] = $e->getMessage();
                echo "  FAIL  {$label}\n";
            } catch (\Throwable $e) {
                $assertions += $instance->assertionsCount();
                $failed++;
                $failures[$label] = $e::class . ': ' . $e->getMessage()
                    . "\n        at " . $e->getFile() . ':' . $e->getLine();
                echo "  ERROR {$label}\n";
            }
        }
    }
}

$duration = (int) round((microtime(true) - $started) * 1000);

echo "\n" . str_repeat('-', 60) . "\n";
foreach ($failures as $label => $message) {
    echo "FAILED: {$label}\n  {$message}\n\n";
}
echo "Tests: {$passed} passed, {$failed} failed, {$assertions} assertions, {$duration} ms\n";

exit($failed === 0 ? 0 : 1);
