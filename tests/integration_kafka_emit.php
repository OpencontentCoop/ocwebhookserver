<?php

/**
 * Test di integrazione end-to-end: eZ Publish → OCWebHookEmitter → Kafka
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/integration_kafka_emit.php
 *
 * Prerequisiti:
 *   - eZ Publish bootstrap completo (va eseguito con `php` dall'interno del container)
 *   - Redpanda/Kafka raggiungibile (variabile d'ambiente KAFKA_BROKER, default: redpanda:9092)
 *   - Kafka abilitato in webhook.ini (EZINI_webhook__KafkaSettings__Enabled=enabled)
 *
 * Il test:
 *   1. Crea un webhook con URL kafka:// nel DB
 *   2. Registra un trigger post_publish_ocopendata
 *   3. Chiama OCWebHookEmitter::emit() con un payload ocopendata realistico
 *   4. Consuma il messaggio da Kafka
 *   5. Verifica payload { entity: { meta, data } } e header CloudEvents
 *   6. Rimuove il webhook creato (cleanup)
 */

define('OCWEBHOOKSERVER_INTEGRATION_TEST', true);

// ── Bootstrap eZ Publish ──────────────────────────────────────────────────────

$ezRoot = '/var/www/html';
chdir($ezRoot);

require_once $ezRoot . '/autoload.php';

$script = eZScript::instance([
    'description' => 'OCWebHookServer Kafka integration test',
    'use-session'  => false,
    'use-modules'  => true,
    'use-extensions' => true,
]);
$script->startup();
$script->initialize();

// ── Helpers ───────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_true(bool $v, string $t, string $r = ''): void  { $v ? ok($t) : fail($t, $r); }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) {
        ok($t);
    } else {
        fail($t, sprintf("expected %s, got %s. %s", var_export($b, true), var_export($a, true), $r));
    }
}

// ── Kafka helpers ─────────────────────────────────────────────────────────────

$BROKER = getenv('KAFKA_BROKER') ?: 'redpanda:9092';
$TOPIC  = getenv('KAFKA_TOPIC')  ?: 'cms';

function get_end_offset(string $broker, string $topic): int
{
    $conf = new RdKafka\Conf();
    $conf->set('metadata.broker.list', $broker);
    $rk  = new RdKafka\Consumer($conf);
    $low = $high = 0;
    $rk->queryWatermarkOffsets($topic, 0, $low, $high, 2000);
    return $high;
}

function consume_message(string $broker, string $topic, int $startOffset, int $timeoutMs = 8000): ?RdKafka\Message
{
    $conf = new RdKafka\Conf();
    $conf->set('metadata.broker.list', $broker);
    $consumer  = new RdKafka\Consumer($conf);
    $topicConf = new RdKafka\TopicConf();
    $topicObj  = $consumer->newTopic($topic, $topicConf);
    $topicObj->consumeStart(0, $startOffset);

    $deadline = microtime(true) + ($timeoutMs / 1000);
    $result   = null;
    while (microtime(true) < $deadline) {
        $msg = $topicObj->consume(0, 500);
        if ($msg === null) continue;
        if ($msg->err === RD_KAFKA_RESP_ERR_NO_ERROR) { $result = $msg; break; }
        if ($msg->err !== RD_KAFKA_RESP_ERR__PARTITION_EOF &&
            $msg->err !== RD_KAFKA_RESP_ERR__TIMED_OUT) break;
    }
    $topicObj->consumeStop(0);
    return $result;
}

// ── Crea il topic se non esiste ───────────────────────────────────────────────

echo "Creating topic $TOPIC if needed...\n";
shell_exec("/usr/bin/rpk topic create $TOPIC --brokers $BROKER 2>/dev/null");

// ── Setup: crea webhook con URL kafka:// ──────────────────────────────────────

$db = eZDB::instance();

// Trigger identifier configurato in webhook.ini per la mappa ce_type
$triggerIdentifier = 'post_publish_ocopendata';

// Verifica che il trigger sia registrato (oppure usa il fallback)
$trigger = OCWebHookTriggerRegistry::registeredTrigger($triggerIdentifier);
if (!$trigger instanceof OCWebHookTriggerInterface) {
    echo "[WARN] Trigger '$triggerIdentifier' non registrato — provo post_publish\n";
    $triggerIdentifier = 'post_publish';
    $trigger = OCWebHookTriggerRegistry::registeredTrigger($triggerIdentifier);
}

if (!$trigger instanceof OCWebHookTriggerInterface) {
    echo "\033[31m[SKIP]\033[0m Nessun trigger registrato. Aggiungere un trigger in webhook.ini [TriggersSettings]\n";
    $script->shutdown();
    exit(0);
}

echo "Using trigger: $triggerIdentifier\n";

// Crea un webhook temporaneo per il test
$kafkaUrl = "kafka://$BROKER/$TOPIC";
$webhook = new OCWebHook();
$webhook->setAttribute('name', 'integration-test-kafka');
$webhook->setAttribute('url', $kafkaUrl);
$webhook->setAttribute('enabled', 1);
$webhook->setAttribute('secret', '');
$webhook->setAttribute('method', 'POST');
$webhook->setAttribute('headers', json_encode([]));
$webhook->setAttribute('retry_enabled', 0);
$webhook->setAttribute('max_retries', 0);
$webhook->setAttribute('retry_delay', 0);
$webhook->store();
$webhookId = $webhook->attribute('id');

// Associa il trigger al webhook
$db->query("INSERT INTO ocwebhook_trigger (webhook_id, trigger_identifier, filters)
            VALUES ($webhookId, '$triggerIdentifier', '{}')");

echo "Created test webhook id=$webhookId url=$kafkaUrl\n\n";

// ── Payload ocopendata realistico ─────────────────────────────────────────────

$siteaccess = eZSiteAccess::current();
$siteaccessName = isset($siteaccess['name']) ? $siteaccess['name'] : 'opencity';

$publishedTs = mktime(9, 0, 0, 3, 15, 2026);
$modifiedTs  = time();

$payload = [
    'metadata' => [
        'id'               => '42',
        'currentVersion'   => '1',
        'remoteId'         => 'test-remote-id-' . uniqid(),
        'classIdentifier'  => 'article',
        'name'             => ['it-IT' => 'Notizia di test integrazione Kafka'],
        'languages'        => ['it-IT'],
        'mainNodeId'       => '88',
        'parentNodes'      => ['88'],
        'assignedNodes'    => ['88'],
        'published'        => (string)$publishedTs,
        'modified'         => (string)$modifiedTs,
        'baseUrl'          => 'https://opencity.localtest.me',
    ],
    'data' => [
        'it-IT' => [
            'title'    => ['content' => 'Notizia di test integrazione Kafka', 'type' => 'string'],
            'abstract' => ['content' => 'Abstract della notizia di test', 'type' => 'string'],
            'body'     => ['content' => '<p>Corpo del testo</p>', 'type' => 'string'],
        ],
    ],
    'extradata' => [],
];

// ── Emit ──────────────────────────────────────────────────────────────────────

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before emit: $startOffset\n";

OCWebHookEmitter::emit(
    $triggerIdentifier,
    $payload,
    $trigger->getQueueHandler()
);

echo "emit() called — waiting for Kafka message...\n\n";

// ── Consuma e verifica ────────────────────────────────────────────────────────

$message = consume_message($BROKER, $TOPIC, $startOffset, 10000);

assert_true($message !== null, 'Messaggio ricevuto su Kafka dopo emit()');

if ($message !== null) {
    // ── Payload ──
    $decoded = json_decode($message->payload, true);
    assert_true(is_array($decoded),                  'Payload è JSON valido');
    assert_true(isset($decoded['entity']),            'Payload ha chiave "entity"');
    assert_true(isset($decoded['entity']['meta']),    'entity.meta presente');
    assert_true(isset($decoded['entity']['data']),    'entity.data presente');

    $meta = $decoded['entity']['meta'] ?? [];
    assert_eq($meta['object_id'], '42',          'entity.meta.object_id = "42"');
    assert_eq($meta['type_id'],   'article',     'entity.meta.type_id = "article"');
    assert_true(
        strpos($meta['id'] ?? '', ':42') !== false,
        'entity.meta.id contiene "<siteaccess>:42"'
    );
    assert_true(
        isset($decoded['entity']['data']['it-IT']['title']),
        'entity.data.it-IT.title presente'
    );
    assert_eq(
        $decoded['entity']['data']['it-IT']['title'],
        'Notizia di test integrazione Kafka',
        'entity.data.it-IT.title è il testo corretto'
    );

    // ── CloudEvents headers ──
    $headers = (array)($message->headers ?? []);
    assert_true(
        isset($headers['ce_specversion']) && $headers['ce_specversion'] === '1.0',
        'Header ce_specversion = "1.0"'
    );
    assert_true(
        isset($headers['ce_type']) && strpos($headers['ce_type'], 'it.opencity.') === 0,
        'Header ce_type inizia con "it.opencity."'
    );
    assert_true(
        isset($headers['ce_source']) && strpos($headers['ce_source'], 'urn:opencity:') === 0,
        'Header ce_source segue il formato URN'
    );
    assert_true(
        isset($headers['ce_id']) && strlen($headers['ce_id']) === 36,
        'Header ce_id è un UUID'
    );
    assert_true(
        isset($headers['ce_time']) && strtotime($headers['ce_time']) !== false,
        'Header ce_time è una data ISO 8601 valida'
    );
    assert_eq($headers['content-type'] ?? '', 'application/json', 'Header content-type');

    echo "\nce_type:   " . ($headers['ce_type']   ?? '(missing)') . "\n";
    echo "ce_source: " . ($headers['ce_source'] ?? '(missing)') . "\n";
    echo "ce_time:   " . ($headers['ce_time']   ?? '(missing)') . "\n";
    echo "ce_id:     " . ($headers['ce_id']     ?? '(missing)') . "\n";
}

// ── Cleanup: rimuovi webhook e job di test ────────────────────────────────────

$db->query("DELETE FROM ocwebhook_trigger WHERE webhook_id = $webhookId");
$db->query("DELETE FROM ocwebhook_job WHERE webhook_id = $webhookId");
$db->query("DELETE FROM ocwebhook WHERE id = $webhookId");
echo "\nWebhook di test rimosso (id=$webhookId)\n";

// ── Risultati ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

$script->shutdown($FAILED > 0 ? 1 : 0);
