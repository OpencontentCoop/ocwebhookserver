<?php

/**
 * Integration tests for OCWebHookKafkaProducer.
 *
 * Requires:
 *  - PHP extension rdkafka
 *  - Redpanda (or Kafka) reachable at KAFKA_BROKER env var (default: redpanda:9092)
 *  - KAFKA_TOPIC env var (default: cms-test)
 *
 * Usage (standalone):
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

$BROKER   = getenv('KAFKA_BROKER') ?: 'redpanda:9092';
$TOPIC    = getenv('KAFKA_TOPIC')  ?: 'cms-test';
$FLUSH_MS = 2000;
$PASSED   = 0;
$FAILED   = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_true(bool $v, string $t, string $r = ''): void { $v ? ok($t) : fail($t, $r); }
function assert_false(bool $v, string $t, string $r = ''): void { (!$v) ? ok($t) : fail($t, $r); }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) {
        ok($t);
    } else {
        fail($t, sprintf("expected %s, got %s. %s", var_export($b, true), var_export($a, true), $r));
    }
}

/**
 * Returns the current high-water-mark offset for partition 0.
 */
function get_end_offset(string $broker, string $topic): int
{
    $conf = new RdKafka\Conf();
    $conf->set('metadata.broker.list', $broker);
    $rk  = new RdKafka\Consumer($conf);
    $low = $high = 0;
    $rk->queryWatermarkOffsets($topic, 0, $low, $high, 2000);
    return $high;
}

/**
 * Consume one message from $topic starting at $startOffset.
 * Returns the RdKafka\Message object (with ->payload and ->headers) or null.
 */
function consume_message(string $broker, string $topic, int $startOffset, int $timeoutMs = 5000): ?RdKafka\Message
{
    $conf = new RdKafka\Conf();
    $conf->set('metadata.broker.list', $broker);

    $consumer = new RdKafka\Consumer($conf);
    $topicConf = new RdKafka\TopicConf();
    $topicObj  = $consumer->newTopic($topic, $topicConf);

    $topicObj->consumeStart(0, $startOffset);

    $deadline = microtime(true) + ($timeoutMs / 1000);
    $result   = null;
    while (microtime(true) < $deadline) {
        $message = $topicObj->consume(0, 500);
        if ($message === null) {
            continue;
        }
        if ($message->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            $result = $message;
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

/**
 * Build the INI test configuration.
 */
function set_ini(string $broker, string $topic, int $flushMs, array $extra = []): void
{
    eZINI::setTestData('webhook.ini', array_merge([
        'KafkaSettings' => [
            'FlushTimeoutMs' => (string)$flushMs,
            'TenantId'       => 'test-tenant-uuid-1234',
            'ProductSlug'    => 'website',
            'AppName'        => 'website-comuni',
            'AppVersion'     => '1.5.0',
        ],
        'KafkaCeTypeMap' => [
            'post_publish' => 'content.published',
            'delete'       => 'content.deleted',
        ],
    ], $extra));
    eZDebug::reset();
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1: produce() returns true and message arrives on Kafka (integration)
// ─────────────────────────────────────────────────────────────────────────────

set_ini($BROKER, $TOPIC, $FLUSH_MS);

$payload = [
    'entity' => [
        'meta' => [
            'id'         => 'comune_it:42',
            'siteaccess' => 'comune_it',
            'object_id'  => '42',
            'type_id'    => 'article',
        ],
        'data' => ['it-IT' => ['title' => 'Test notizia']],
    ],
];

$startOffset = get_end_offset($BROKER, $TOPIC);
$producer    = new OCWebHookKafkaProducer($BROKER, $TOPIC);
$sent        = $producer->produce('post_publish', $payload);

assert_true($sent, 'produce() returns true when Redpanda is reachable');

$message = consume_message($BROKER, $TOPIC, $startOffset, 5000);
assert_true($message !== null, 'Message arrived on Kafka topic after produce()');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: Payload matches what was sent
// ─────────────────────────────────────────────────────────────────────────────

if ($message !== null) {
    $decoded = json_decode($message->payload, true);
    assert_true(
        isset($decoded['entity']['meta']['id']) && $decoded['entity']['meta']['id'] === 'comune_it:42',
        'Payload entity.meta.id matches'
    );
    assert_true(
        isset($decoded['entity']['data']['it-IT']['title']),
        'Payload entity.data structure preserved'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: CloudEvents headers are set on the produced message
// ─────────────────────────────────────────────────────────────────────────────

if ($message !== null) {
    $headers = (array)($message->headers ?? []);

    assert_true(
        isset($headers['ce_specversion']) && $headers['ce_specversion'] === '1.0',
        'Header ce_specversion = "1.0"'
    );
    assert_true(
        isset($headers['ce_type']) && $headers['ce_type'] === 'it.opencity.website.content.published',
        'Header ce_type resolved via KafkaCeTypeMap'
    );
    assert_true(
        isset($headers['ce_source']) && $headers['ce_source'] === 'urn:opencity:website:test-tenant-uuid-1234',
        'Header ce_source follows urn:opencity:<slug>:<tenant>'
    );
    assert_true(
        isset($headers['ce_id']) && strlen($headers['ce_id']) === 36,
        'Header ce_id is a UUID (length 36)'
    );
    assert_true(
        isset($headers['ce_time']) && strtotime($headers['ce_time']) !== false,
        'Header ce_time is a valid date/time'
    );
    assert_true(
        isset($headers['content-type']) && $headers['content-type'] === 'application/json',
        'Header content-type = "application/json"'
    );
    assert_true(
        isset($headers['oc_app_name']) && $headers['oc_app_name'] === 'website-comuni',
        'Header oc_app_name set'
    );
    assert_true(
        isset($headers['oc_app_version']) && $headers['oc_app_version'] === '1.5.0',
        'Header oc_app_version set'
    );

    // Verify ce_id is a valid UUID v4 format
    $uuid = $headers['ce_id'] ?? '';
    assert_true(
        (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $uuid),
        'Header ce_id is a valid UUID v4'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 4: ce_type fallback when trigger not in KafkaCeTypeMap
// ─────────────────────────────────────────────────────────────────────────────

$startOffset2 = get_end_offset($BROKER, $TOPIC);
$producer2    = new OCWebHookKafkaProducer($BROKER, $TOPIC);
$sent2        = $producer2->produce('custom_trigger_xyz', ['entity' => ['meta' => [], 'data' => []]]);
assert_true($sent2, 'produce() with unmapped trigger returns true');

$message2 = consume_message($BROKER, $TOPIC, $startOffset2, 5000);
if ($message2 !== null) {
    $headers2 = (array)($message2->headers ?? []);
    assert_true(
        isset($headers2['ce_type']) && $headers2['ce_type'] === 'it.opencity.website.custom_trigger_xyz',
        'ce_type uses raw trigger identifier as fallback'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 5: produce() returns false when broker is unreachable
// ─────────────────────────────────────────────────────────────────────────────

set_ini('127.0.0.1:19999', $TOPIC, 500);  // short flush timeout to speed up test
eZDebug::reset();

$start       = microtime(true);
$producerBad = new OCWebHookKafkaProducer('127.0.0.1:19999', $TOPIC);
$resultDown  = $producerBad->produce('post_publish', ['entity' => ['meta' => [], 'data' => []]]);
$elapsed     = microtime(true) - $start;

assert_false($resultDown, 'produce() returns false when broker is unreachable');
assert_true(
    $elapsed < 3.0,
    sprintf('Flush timeout respected: %.2fs < 3s (FlushTimeoutMs=500)', $elapsed)
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
