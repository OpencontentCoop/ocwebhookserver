<?php

/**
 * Test runner — executes all test files and aggregates results.
 *
 * Usage:
 *   php tests/run_tests.php
 *
 * Environment:
 *   KAFKA_BROKER   Kafka/Redpanda broker (default: redpanda:9092)
 *   KAFKA_TOPIC    Topic name for integration tests (default: cms-test)
 *   SKIP_KAFKA     Set to '1' to skip KafkaProducerTest (no broker needed)
 */

$testFiles = [
    __DIR__ . '/PayloadFormatterTest.php',
    __DIR__ . '/EmitterOutboxTest.php',
    __DIR__ . '/KafkaProducerTest.php',
];

if (getenv('SKIP_KAFKA') === '1') {
    echo "[INFO] SKIP_KAFKA=1 — skipping KafkaProducerTest\n";
    $testFiles = array_filter($testFiles, function($f) { return strpos($f, 'KafkaProducerTest') === false; });
}

$allPassed  = true;
$totalPass  = 0;
$totalFail  = 0;

foreach ($testFiles as $file) {
    $name = basename($file);
    echo "\n" . str_repeat('═', 50) . "\n";
    echo "Running: $name\n";
    echo str_repeat('─', 50) . "\n";

    $output   = [];
    $exitCode = 0;
    exec('php ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);

    echo implode("\n", $output) . "\n";

    // Collect per-suite totals from "Results: N passed[, M failed]" line
    foreach ($output as $line) {
        if (preg_match('/(\d+) passed/', $line, $m)) {
            $totalPass += (int)$m[1];
        }
        if (preg_match('/(\d+) failed/', $line, $m)) {
            $totalFail += (int)$m[1];
        }
    }

    if ($exitCode !== 0) {
        $allPassed = false;
        echo "\033[31m[FAILED] $name exited with code $exitCode\033[0m\n";
    } else {
        echo "\033[32m[OK] $name\033[0m\n";
    }
}

$total = $totalPass + $totalFail;
$pct   = $total > 0 ? round($totalPass / $total * 100, 1) : 0.0;

echo "\n" . str_repeat('═', 50) . "\n";
echo $allPassed
    ? "\033[32m✓ All test suites passed\033[0m\n"
    : "\033[31m✗ One or more test suites FAILED\033[0m\n";

// Riga catturata dal regex coverage: in .gitlab-ci.yml
echo "Tests passed: {$pct}%\n";

exit($allPassed ? 0 : 1);
