<?php
/**
 * Minimal test runner.
 *
 * PHPUnit needs Composer, which docs/00 rules out, so this is a small
 * assertion runner instead.
 *
 *   php tests/run.php
 *   php tests/run.php --filter=auth
 *
 * Test files are tests/*_test.php. Each returns an array of
 * 'description' => callable. A test passes if it throws nothing.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    exit('Tests may only be run from the command line.');
}

require dirname(__DIR__) . '/bootstrap.php';

final class TestRunner
{
    private int $passed = 0;
    private array $failures = [];
    private string $currentGroup = '';

    public function run(?string $filter): int
    {
        $files = glob(__DIR__ . '/*_test.php') ?: [];
        sort($files);

        if ($files === []) {
            echo "  No test files found.\n";
            return 0;
        }

        echo "\n  SchoolHub test suite\n";
        echo "  ====================\n\n";

        foreach ($files as $file) {
            $group = basename($file, '_test.php');

            if ($filter !== null && !str_contains($group, $filter)) {
                continue;
            }

            $this->currentGroup = $group;
            echo "  {$group}\n";

            /** @var array<string,callable> $tests */
            $tests = require $file;

            foreach ($tests as $description => $test) {
                $this->runOne($description, $test);
            }

            echo "\n";
        }

        return $this->report();
    }

    private function runOne(string $description, callable $test): void
    {
        try {
            $test($this);
            $this->passed++;
            echo "    PASS  {$description}\n";
        } catch (Throwable $e) {
            $this->failures[] = [$this->currentGroup, $description, $e->getMessage()];
            echo "    FAIL  {$description}\n";
            echo "          {$e->getMessage()}\n";
        }
    }

    private function report(): int
    {
        $failed = count($this->failures);

        echo "  --------------------\n";
        echo "  {$this->passed} passed, {$failed} failed\n\n";

        if ($failed > 0) {
            echo "  Failures:\n";
            foreach ($this->failures as [$group, $description, $message]) {
                echo "    {$group}: {$description}\n      {$message}\n";
            }
            echo "\n";
            return 1;
        }

        return 0;
    }

    // -- Assertions --------------------------------------------------------

    public function assertTrue(mixed $value, string $message = 'Expected true'): void
    {
        if ($value !== true) {
            throw new RuntimeException($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    public function assertFalse(mixed $value, string $message = 'Expected false'): void
    {
        if ($value !== false) {
            throw new RuntimeException($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    public function assertEquals(mixed $expected, mixed $actual, string $message = 'Values differ'): void
    {
        if ($expected != $actual) {
            throw new RuntimeException(sprintf(
                '%s (expected %s, got %s)',
                $message,
                var_export($expected, true),
                var_export($actual, true)
            ));
        }
    }

    public function assertNull(mixed $value, string $message = 'Expected null'): void
    {
        if ($value !== null) {
            throw new RuntimeException($message . ' (got ' . var_export($value, true) . ')');
        }
    }

    public function assertNotNull(mixed $value, string $message = 'Expected a value, got null'): void
    {
        if ($value === null) {
            throw new RuntimeException($message);
        }
    }

    public function assertContains(string $needle, string $haystack, string $message = 'Substring not found'): void
    {
        if (!str_contains($haystack, $needle)) {
            throw new RuntimeException($message . " (looking for '{$needle}')");
        }
    }

    /** Assert that a callable throws, optionally with a message containing $expect. */
    public function assertThrows(callable $callback, ?string $expect = null, string $message = 'Expected an exception'): void
    {
        try {
            $callback();
        } catch (Throwable $e) {
            if ($expect !== null && !str_contains($e->getMessage(), $expect)) {
                throw new RuntimeException("{$message}: thrown message did not contain '{$expect}' (got '{$e->getMessage()}')");
            }
            return;
        }
        throw new RuntimeException($message . ' but nothing was thrown');
    }
}

$filter = null;
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--filter=')) {
        $filter = substr($argument, 9);
    }
}

exit((new TestRunner())->run($filter));
