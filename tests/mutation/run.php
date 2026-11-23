<?php

/**
 * @file
 * Breaks the module one way at a time and checks a test notices.
 *
 * Run from the project root, inside DDEV:
 *
 * @code
 * ddev exec php web/modules/custom/canvas_view_template/tests/mutation/run.php
 * ddev exec php web/modules/custom/canvas_view_template/tests/mutation/run.php --only=rowset-ignores-limit
 * @endcode
 *
 * Every change is listed in mutants.php. First the runner runs each test file
 * unchanged, and stops if one fails. Then it applies a change, runs the tests
 * named for it, and puts the file back. A change that no test fails on
 * "survived", and the script exits with 1. Files are restored even if the run
 * is interrupted, but check `git status` afterwards.
 */

declare(strict_types=1);

$module = \dirname(__DIR__, 2);
$root = \dirname($module, 4);
/**
 * The changes to make, keyed by id.
 *
 * @var array<string, array{description: string, file: string, find: string, replace: string, count?: int, tests: list<string>}> $mutants
 */
$mutants = require __DIR__ . '/mutants.php';

$only = NULL;
foreach (\array_slice($argv, 1) as $argument) {
  if (\str_starts_with($argument, '--only=')) {
    $only = \substr($argument, 7);
  }
}
if ($only !== NULL) {
  if (!isset($mutants[$only])) {
    \fwrite(\STDERR, "Unknown change \"$only\".\n");
    exit(2);
  }
  $mutants = [$only => $mutants[$only]];
}

/**
 * The original content of every file currently changed, by path.
 *
 * @var array<string, string> $originals
 */
$originals = [];
$restore = static function () use (&$originals): void {
  foreach ($originals as $path => $content) {
    \file_put_contents($path, $content);
  }
  $originals = [];
};
\register_shutdown_function($restore);
if (\function_exists('pcntl_signal')) {
  \pcntl_async_signals(TRUE);
  foreach ([\SIGINT, \SIGTERM] as $signal) {
    \pcntl_signal($signal, static function () use ($restore): void {
      $restore();
      exit(130);
    });
  }
}

/**
 * Runs one test file.
 *
 * @return string
 *   "passed", "failed" or "crashed" (no result line at all).
 */
function run_tests(string $root, string $module, string $test): string {
  $command = \sprintf(
    'cd %s && vendor/bin/phpunit -c web/core/phpunit.xml.dist %s 2>&1',
    \escapeshellarg($root),
    \escapeshellarg($module . '/' . $test),
  );
  $output = (string) \shell_exec($command);
  if (\str_contains($output, 'FAILURES!') || \str_contains($output, 'ERRORS!')) {
    return 'failed';
  }
  return \str_contains($output, 'Tests:') || \str_contains($output, 'OK (') ? 'passed' : 'crashed';
}

$tests = [];
foreach ($mutants as $mutant) {
  foreach ($mutant['tests'] as $test) {
    $tests[$test] = TRUE;
  }
}
echo 'Checking that ' . \count($tests) . " test files pass unchanged...\n";
foreach (\array_keys($tests) as $test) {
  if (($result = run_tests($root, $module, $test)) !== 'passed') {
    \fwrite(\STDERR, "$test does not pass before any change ($result). Fix it first.\n");
    exit(2);
  }
}

$survived = [];
$inconclusive = [];
foreach ($mutants as $id => $mutant) {
  $path = $module . '/' . $mutant['file'];
  $original = (string) \file_get_contents($path);
  $expected = $mutant['count'] ?? 1;
  $found = \substr_count($original, $mutant['find']);
  if ($found !== $expected) {
    \fwrite(\STDERR, "$id: found the text $found times in {$mutant['file']}, expected $expected. Update mutants.php.\n");
    exit(2);
  }
  $originals[$path] = $original;
  \file_put_contents($path, \str_replace($mutant['find'], $mutant['replace'], $original));

  $outcome = 'survived';
  foreach ($mutant['tests'] as $test) {
    $result = run_tests($root, $module, $test);
    if ($result === 'failed') {
      $outcome = 'caught';
      break;
    }
    if ($result === 'crashed') {
      $outcome = 'inconclusive';
    }
  }
  $restore();

  \printf("%-34s %s\n", $id, $outcome);
  if ($outcome === 'survived') {
    $survived[] = $id;
  }
  elseif ($outcome === 'inconclusive') {
    $inconclusive[] = $id;
  }
}

$total = \count($mutants);
echo \sprintf("\n%d of %d changes caught.\n", $total - \count($survived) - \count($inconclusive), $total);
if ($survived !== []) {
  echo 'Survived (a test is missing): ' . \implode(', ', $survived) . "\n";
}
if ($inconclusive !== []) {
  echo 'Inconclusive (the tests crashed without a result): ' . \implode(', ', $inconclusive) . "\n";
}
exit($survived === [] && $inconclusive === [] ? 0 : 1);
