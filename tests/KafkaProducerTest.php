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

// Realistic payload: all meta fields + multi-language data with mixed attribute types
$payload = [
    'entity' => [
        'meta' => [
            'id'           => 'test-tenant-uuid-1234:42',
            'siteaccess'   => 'comune_it',
            'object_id'    => '42',
            'remote_id'    => 'abc123remote456def',
            'type_id'      => 'article',
            'version'      => 1,
            'languages'    => ['ita-IT', 'eng-GB'],
            'name'         => 'Titolo della notizia di test',
            'site_url'     => 'https://www.comune.example.it',
            'published_at' => '2026-03-15T09:00:00Z',
            'updated_at'   => '2026-03-23T18:00:00Z',
        ],
        'data' => [
            'ita-IT' => [
                'title'       => 'Titolo della notizia di test',
                'abstract'    => 'Abstract della notizia di test per verifica payload completo',
                'body'        => '<p>Corpo del testo con <strong>HTML</strong>.</p>',
                'image'       => [],
                'files'       => [],
                'topics'      => [
                    ['id' => '101', 'remote_id' => 'topic-abc', 'class_identifier' => 'tag', 'main_node_id' => '501', 'name' => 'Mobilità'],
                    ['id' => '102', 'remote_id' => 'topic-def', 'class_identifier' => 'tag', 'main_node_id' => '502', 'name' => 'Ambiente'],
                ],
                'author'      => [
                    ['id' => '77', 'remote_id' => 'ufficio-xyz', 'class_identifier' => 'office', 'main_node_id' => '310', 'name' => 'Ufficio Stampa'],
                ],
                'reading_time' => 0,
                'video'        => null,
            ],
            'eng-GB' => [
                'title'   => 'Test news article title',
                'abstract' => 'Abstract of the test news article',
                'body'    => '<p>Body text.</p>',
                'image'   => [],
                'files'   => [],
                'topics'  => [
                    ['id' => '101', 'remote_id' => 'topic-abc', 'class_identifier' => 'tag', 'main_node_id' => '501', 'name' => 'Mobility'],
                ],
                'author'  => [],
            ],
        ],
    ],
];

$startOffset = get_end_offset($BROKER, $TOPIC);
$producer    = new OCWebHookKafkaProducer($BROKER, $TOPIC);
$sent        = $producer->produce('post_publish', $payload);

assert_true($sent, 'produce() returns true when Redpanda is reachable');

$message = consume_message($BROKER, $TOPIC, $startOffset, 5000);
assert_true($message !== null, 'Message arrived on Kafka topic after produce()');
assert_eq(
    $message !== null ? $message->key : null,
    'test-tenant-uuid-1234',
    'Message partition key equals TenantId (ordering per tenant)'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: Payload round-trip — all meta fields and data structure preserved
// ─────────────────────────────────────────────────────────────────────────────

if ($message !== null) {
    $decoded = json_decode($message->payload, true);
    $meta    = $decoded['entity']['meta'] ?? [];
    $data    = $decoded['entity']['data'] ?? [];

    assert_eq($meta['id'],           'test-tenant-uuid-1234:42',              'Payload entity.meta.id round-trip');
    assert_eq($meta['remote_id'],    'abc123remote456def',                     'Payload entity.meta.remote_id round-trip');
    assert_eq($meta['type_id'],      'article',                                'Payload entity.meta.type_id round-trip');
    assert_eq($meta['version'],      1,                                        'Payload entity.meta.version round-trip');
    assert_eq($meta['languages'],    ['ita-IT', 'eng-GB'],                     'Payload entity.meta.languages round-trip');
    assert_eq($meta['name'],         'Titolo della notizia di test',           'Payload entity.meta.name round-trip');
    assert_eq($meta['site_url'],     'https://www.comune.example.it',          'Payload entity.meta.site_url round-trip');
    assert_eq($meta['published_at'], '2026-03-15T09:00:00Z',                  'Payload entity.meta.published_at round-trip');
    assert_eq($meta['updated_at'],   '2026-03-23T18:00:00Z',                  'Payload entity.meta.updated_at round-trip');

    assert_true(isset($data['ita-IT']) && isset($data['eng-GB']),              'Payload entity.data has both languages');
    assert_eq($data['ita-IT']['title'],   'Titolo della notizia di test',      'Payload ita-IT title round-trip');
    assert_eq($data['ita-IT']['image'],   [],                                   'Payload ita-IT empty image array preserved');
    assert_eq($data['ita-IT']['video'],   null,                                 'Payload ita-IT null video preserved');
    assert_eq(count($data['ita-IT']['topics']), 2,                             'Payload ita-IT topics (2 items) round-trip');
    assert_eq($data['ita-IT']['topics'][0]['class_identifier'], 'tag',         'Payload relation item class_identifier preserved');
    assert_eq($data['eng-GB']['title'],   'Test news article title',           'Payload eng-GB title round-trip');
    assert_eq($data['eng-GB']['author'],  [],                                   'Payload eng-GB empty relation list preserved');
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
        isset($headers['ce_type']) && $headers['ce_type'] === 'it.opencity.website.article.created',
        'Header ce_type = productSlug.type_id.created (version=1, type_id=article)'
    );
    assert_true(
        isset($headers['oc_operation']) && $headers['oc_operation'] === 'created',
        'Header oc_operation = created (version=1)'
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
    assert_eq(
        $headers['oc_retry_count'] ?? null,
        '0',
        'Header oc_retry_count = "0" for first delivery (no retries)'
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
        isset($headers2['ce_type']) && $headers2['ce_type'] === 'it.opencity.website.custom_trigger_xyz.updated',
        'ce_type uses raw trigger identifier as fallback (no type_id in payload) + .updated'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 5: delete trigger → ce_type=deleted, oc_operation=deleted
// ─────────────────────────────────────────────────────────────────────────────

set_ini($BROKER, $TOPIC, $FLUSH_MS);

// Realistic delete payload: all meta fields, version>1 (update scenario for the object being deleted)
$deletePayload = [
    'entity' => [
        'meta' => [
            'id'           => 'test-tenant-uuid-1234:42',
            'siteaccess'   => 'comune_it',
            'object_id'    => '42',
            'remote_id'    => 'abc123remote456def',
            'type_id'      => 'article',
            'version'      => 3,  // version is irrelevant for delete — must still be 'deleted'
            'languages'    => ['ita-IT'],
            'name'         => 'Notizia da cancellare',
            'site_url'     => 'https://www.comune.example.it',
            'published_at' => '2026-01-10T08:00:00Z',
            'updated_at'   => '2026-03-20T14:30:00Z',
        ],
        'data' => [
            'ita-IT' => [
                'title'    => 'Notizia da cancellare',
                'abstract' => 'Abstract della notizia in fase di cancellazione',
                'files'    => [],
                'topics'   => [
                    ['id' => '101', 'remote_id' => 'topic-abc', 'class_identifier' => 'tag', 'main_node_id' => '501'],
                ],
            ],
        ],
    ],
];

$startOffset3 = get_end_offset($BROKER, $TOPIC);
$producer3    = new OCWebHookKafkaProducer($BROKER, $TOPIC);
$sent3        = $producer3->produce('delete_ocopendata', $deletePayload);

assert_true($sent3, 'Delete: produce() returns true when Redpanda is reachable');

$message3 = consume_message($BROKER, $TOPIC, $startOffset3, 5000);
assert_true($message3 !== null, 'Delete: message arrived on Kafka topic');

if ($message3 !== null) {
    $headers3 = (array)($message3->headers ?? []);

    assert_true(
        isset($headers3['ce_type']) && $headers3['ce_type'] === 'it.opencity.website.article.deleted',
        'Delete: ce_type = it.opencity.<slug>.<type_id>.deleted'
    );
    assert_true(
        isset($headers3['oc_operation']) && $headers3['oc_operation'] === 'deleted',
        'Delete: oc_operation = deleted'
    );
    assert_true(
        isset($headers3['ce_specversion']) && $headers3['ce_specversion'] === '1.0',
        'Delete: ce_specversion = 1.0'
    );
    assert_true(
        isset($headers3['ce_id']) && strlen($headers3['ce_id']) === 36,
        'Delete: ce_id is a UUID'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 6: delete trigger with version=1 → still deleted (version ignored)
// ─────────────────────────────────────────────────────────────────────────────

// Full payload for an event (different content type) with version=1
$deletePayloadV1 = [
    'entity' => [
        'meta' => [
            'id'           => 'test-tenant-uuid-1234:99',
            'siteaccess'   => 'comune_it',
            'object_id'    => '99',
            'remote_id'    => 'event-remote-id-xyz',
            'type_id'      => 'event',
            'version'      => 1,  // version=1 would normally be 'created' — must be overridden by delete trigger
            'languages'    => ['ita-IT'],
            'name'         => 'Evento da cancellare',
            'site_url'     => 'https://www.comune.example.it',
            'published_at' => '2026-02-01T10:00:00Z',
            'updated_at'   => '2026-02-01T10:00:00Z',
        ],
        'data' => [
            'ita-IT' => [
                'title'    => 'Evento da cancellare',
                'abstract' => 'Evento creato e subito cancellato',
                'image'    => [],
                'files'    => [],
            ],
        ],
    ],
];

$startOffset4 = get_end_offset($BROKER, $TOPIC);
$producer4    = new OCWebHookKafkaProducer($BROKER, $TOPIC);
$sent4        = $producer4->produce('delete_ocopendata', $deletePayloadV1);

assert_true($sent4, 'Delete v1: produce() returns true');

$message4 = consume_message($BROKER, $TOPIC, $startOffset4, 5000);
if ($message4 !== null) {
    $headers4 = (array)($message4->headers ?? []);
    assert_true(
        isset($headers4['ce_type']) && $headers4['ce_type'] === 'it.opencity.website.event.deleted',
        'Delete v1: ce_type = event.deleted even when version=1 (delete takes priority)'
    );
    assert_true(
        isset($headers4['oc_operation']) && $headers4['oc_operation'] === 'deleted',
        'Delete v1: oc_operation = deleted even when version=1'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 7: oc_retry_count header reflects retry attempt number
// ─────────────────────────────────────────────────────────────────────────────

set_ini($BROKER, $TOPIC, $FLUSH_MS);

$startOffset5 = get_end_offset($BROKER, $TOPIC);
$producer5    = new OCWebHookKafkaProducer($BROKER, $TOPIC);
$payload5     = ['entity' => ['meta' => ['id' => 'test-tenant-uuid-1234:77', 'type_id' => 'service', 'version' => 2], 'data' => []]];
$sent5        = $producer5->produce('post_publish', $payload5, 3);

assert_true($sent5, 'Retry count: produce() with retryCount=3 returns true');

$message5 = consume_message($BROKER, $TOPIC, $startOffset5, 5000);
if ($message5 !== null) {
    $headers5 = (array)($message5->headers ?? []);
    assert_eq(
        $headers5['oc_retry_count'] ?? null,
        '3',
        'Header oc_retry_count = "3" when retryCount=3 passed to produce()'
    );
    assert_true(
        isset($headers5['ce_type']) && $headers5['ce_type'] === 'it.opencity.website.service.updated',
        'Retry count: ce_type correctly computed alongside retry count'
    );
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 8: produce() returns false when broker is unreachable
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
