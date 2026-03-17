<?php

/**
 * Integration tests for OCWebHookKafkaProducer.
 *
 * Requires:
 *  - PHP extension rdkafka
 *  - Redpanda (or Kafka) reachable at KAFKA_BROKER env var (default: redpanda:9092)
 *  - KAFKA_TOPIC env var (default: cms-test)
 *
 * Usage (standalone, no PHPUnit):
 *   php tests/KafkaProducerTest.php
 *
 * Usage (via test runner):
 *   php tests/run_tests.php
 */

require_once __DIR__ . '/stubs.php';
require_once __DIR__ . '/../classes/ocwebhookkafkaproducer.php';

// ─────────────────────────────────────────────────────────────────────────────
// Helpers
// ─────────────────────────────────────────────────────────────────────────────

$BROKER     = getenv('KAFKA_BROKER') ?: 'redpanda:9092';
$TOPIC      = getenv('KAFKA_TOPIC')  ?: 'cms-test';
$FLUSH_MS   = 2000;
$PASSED     = 0;
$FAILED     = 0;

function ok(string $name): void
{
    global $PASSED;
    $PASSED++;
    echo "\033[32m[PASS]\033[0m $name\n";
}

function fail(string $name, string $reason = ''): void
{
    global $FAILED;
    $FAILED++;
    echo "\033[31m[FAIL]\033[0m $name" . ($reason ? " — $reason" : '') . "\n";
}

function assert_true(bool $value, string $test, string $reason = ''): void
{
    $value ? ok($test) : fail($test, $reason);
}

function assert_false(bool $value, string $test, string $reason = ''): void
{
    (!$value) ? ok($test) : fail($test, $reason);
}

/**
 * Returns the current high-water-mark offset for partition 0 of $topic.
 * Used to know where to start consuming after a produce.
 */
function get_end_offset(string $broker, string $topic): int
{
    $conf = new RdKafka\Conf();
    $conf->set('metadata.broker.list', $broker);
    $rk = new RdKafka\Consumer($conf);
    $topicObj = $rk->newTopic($topic);
    // Query watermark offsets for partition 0
    $low = $high = 0;
    $rk->queryWatermarkOffsets($topic, 0, $low, $high, 2000);
    return $high;
}

/**
 * Consume one message from $topic at partition 0 starting at $startOffset.
 * Returns the message value string or null on timeout.
 */
function consume_from_offset(string $broker, string $topic, int $startOffset, int $timeoutMs = 5000): ?string
{
    $conf = new RdKafka\Conf();
    $conf->set('metadata.broker.list', $broker);

    $consumer = new RdKafka\Consumer($conf);
    $topicConf = new RdKafka\TopicConf();
    $topicObj = $consumer->newTopic($topic, $topicConf);

    $topicObj->consumeStart(0, $startOffset);

    $deadline = microtime(true) + ($timeoutMs / 1000);
    $result = null;
    while (microtime(true) < $deadline) {
        $message = $topicObj->consume(0, 500);
        if ($message === null) {
            continue;
        }
        if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            $result = $message->payload;
            break;
        }
        if ($message->err !== RD_KAFKA_RESP_ERR__PARTITION_EOF &&
            $message->err !== RD_KAFKA_RESP_ERR__TIMED_OUT) {
            break;
        }
    }
    $topicObj->consumeStop(0);
    return $result;
}

// ─────────────────────────────────────────────────────────────────────────────
// Reset singleton between tests
// ─────────────────────────────────────────────────────────────────────────────

function reset_producer(): void
{
    $ref = new ReflectionProperty(OCWebHookKafkaProducer::class, 'instance');
    $ref->setAccessible(true);
    $ref->setValue(null, null);
    eZDebug::reset();
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1: isEnabled() returns false when rdkafka is missing (unit)
// ─────────────────────────────────────────────────────────────────────────────

// We can only test the "enabled" branch directly since rdkafka IS loaded here.
eZINI::setTestData('webhook.ini', ['KafkaSettings' => ['Enabled' => 'disabled']]);
reset_producer();
assert_false(
    OCWebHookKafkaProducer::isEnabled(),
    'isEnabled() returns false when Enabled=disabled'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: isEnabled() returns true when rdkafka is loaded and Enabled=enabled
// ─────────────────────────────────────────────────────────────────────────────

eZINI::setTestData('webhook.ini', ['KafkaSettings' => ['Enabled' => 'enabled']]);
assert_true(
    extension_loaded('rdkafka') && OCWebHookKafkaProducer::isEnabled(),
    'isEnabled() returns true when rdkafka loaded and Enabled=enabled'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: produce() succeeds and message arrives on Kafka topic (integration)
// ─────────────────────────────────────────────────────────────────────────────

global $BROKER, $TOPIC, $FLUSH_MS;

eZINI::setTestData('webhook.ini', [
    'KafkaSettings' => [
        'Enabled'        => 'enabled',
        'Brokers'        => [$BROKER],
        'Topic'          => $TOPIC,
        'FlushTimeoutMs' => (string)$FLUSH_MS,
    ]
]);
reset_producer();

$payload = [
    'test'    => true,
    'trigger' => 'post_publish',
    'ts'      => date('c'),
    'run_id'  => getmypid(),
];

// Get the end offset BEFORE producing, so we can consume exactly our message
$startOffset = get_end_offset($BROKER, $TOPIC);

$result = OCWebHookKafkaProducer::instance()->produce('post_publish', $payload);
assert_true($result, 'produce() returns true when Redpanda is reachable');

// Consume from the exact offset where our message was written
$received = consume_from_offset($BROKER, $TOPIC, $startOffset, 5000);
assert_true(
    $received !== null,
    'Message arrived on Kafka topic after produce()'
);

if ($received !== null) {
    $decoded = json_decode($received, true);
    assert_true(
        isset($decoded['run_id']) && $decoded['run_id'] === getmypid(),
        'Message payload matches what was sent (run_id check)'
    );
    assert_true(
        isset($decoded['trigger']) && $decoded['trigger'] === 'post_publish',
        'Message contains trigger identifier in payload'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 4: produce() returns false when broker is unreachable (within timeout)
// ─────────────────────────────────────────────────────────────────────────────

eZINI::setTestData('webhook.ini', [
    'KafkaSettings' => [
        'Enabled'        => 'enabled',
        'Brokers'        => ['127.0.0.1:19999'],  // nothing listening here
        'Topic'          => $TOPIC,
        'FlushTimeoutMs' => '2000',
    ]
]);
reset_producer();

$start = microtime(true);
$resultDown = OCWebHookKafkaProducer::instance()->produce('post_publish', ['test' => 'fallback']);
$elapsed = microtime(true) - $start;

assert_false($resultDown, 'produce() returns false when broker is unreachable');
assert_true(
    $elapsed < 4.0,
    sprintf('Timeout respected: elapsed %.2fs < 4s (FlushTimeoutMs=2000)', $elapsed)
);
assert_true(
    !empty(eZDebug::$errors),
    'eZDebug::writeError() called when Kafka flush fails'
);

// ─────────────────────────────────────────────────────────────────────────────
// Results
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

exit($FAILED > 0 ? 1 : 0);
