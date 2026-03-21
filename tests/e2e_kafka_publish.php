<?php

/**
 * Test E2E: crea una notizia reale via REST API e verifica l'evento Kafka
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_kafka_publish.php
 *
 * Prerequisiti:
 *   - App completamente avviata con installer terminato
 *   - Redpanda/Kafka raggiungibile (KAFKA_BROKER, default: redpanda:9092)
 *   - Kafka abilitato (EZINI_webhook__KafkaSettings__Enabled=enabled)
 *   - Trigger PostPublishWebHookTrigger attivo (EZINI_webhook__TriggersSettings__TriggerList_0)
 *
 * Il test:
 *   1. Crea una notizia reale via POST /Novita/Notizie (HTTP Basic Auth)
 *   2. Aspetta il messaggio Kafka emesso dal trigger PostPublish
 *   3. Verifica entity.meta (object_id, id, siteaccess, type_id, ...)
 *   4. Verifica entity.data.it-IT.title == titolo inviato
 *   5. Verifica gli header CloudEvents (ce_specversion, ce_type, ce_source, ce_id, ce_time)
 *   6. Verifica la chiave di partizione = TenantId (se configurato)
 *   7. Cancella la notizia creata (cleanup)
 */

define('OCWEBHOOKSERVER_INTEGRATION_TEST', true);

// ── Bootstrap eZ Publish ──────────────────────────────────────────────────────

$ezRoot = '/var/www/html';
chdir($ezRoot);

require_once $ezRoot . '/autoload.php';

$script = eZScript::instance([
    'description'    => 'OCWebHookServer E2E Kafka test',
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
]);
$script->startup();
$script->initialize();

// ── Helpers ───────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

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

function assert_eq($actual, $expected, string $test, string $reason = ''): void
{
    if ($actual === $expected) {
        ok($test);
    } else {
        fail($test, sprintf('expected %s, got %s%s',
            var_export($expected, true),
            var_export($actual, true),
            $reason ? ". $reason" : ''
        ));
    }
}

// ── Config ────────────────────────────────────────────────────────────────────

$BROKER   = getenv('KAFKA_BROKER') ?: 'redpanda:9092';
$TOPIC    = getenv('KAFKA_TOPIC')  ?: 'cms';
$APP_HOST = getenv('APP_HOST')     ?: 'opencity.localtest.me';
$CMS_USER = getenv('CMS_USER')     ?: 'admin';
$CMS_PASS = getenv('CMS_PASS')     ?: 'changethispassword';

// ── Kafka helpers ─────────────────────────────────────────────────────────────

function get_end_offset(string $broker, string $topic): int
{
    $conf = new RdKafka\Conf();
    $conf->set('metadata.broker.list', $broker);
    $rk  = new RdKafka\Consumer($conf);
    $low = $high = 0;
    $rk->queryWatermarkOffsets($topic, 0, $low, $high, 3000);
    return $high;
}

function consume_message(string $broker, string $topic, int $startOffset, int $timeoutMs = 15000): ?RdKafka\Message
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
        if ($msg === null) {
            continue;
        }
        if ($msg->err === RD_KAFKA_RESP_ERR_NO_ERROR) {
            $result = $msg;
            break;
        }
        if ($msg->err !== RD_KAFKA_RESP_ERR__PARTITION_EOF &&
            $msg->err !== RD_KAFKA_RESP_ERR__TIMED_OUT) {
            break;
        }
    }
    $topicObj->consumeStop(0);
    return $result;
}

// ── HTTP helpers ──────────────────────────────────────────────────────────────

function http_request(string $method, string $path, array $headers, ?string $body, string $host): array
{
    $ch = curl_init();
    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = "$k: $v";
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'http://localhost' . $path,
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $raw  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'code'    => $code,
        'headers' => substr($raw, 0, $size),
        'body'    => substr($raw, $size),
    ];
}

// ── Crea il topic se non esiste ───────────────────────────────────────────────

echo "Creating topic $TOPIC if needed...\n";
shell_exec("/usr/bin/rpk topic create $TOPIC --brokers $BROKER 2>/dev/null");

// ── Verifica che il trigger sia attivo ────────────────────────────────────────

$trigger = OCWebHookTriggerRegistry::registeredTrigger('post_publish_ocopendata');
if (!$trigger instanceof OCWebHookTriggerInterface) {
    $trigger = OCWebHookTriggerRegistry::registeredTrigger('post_publish');
}

if (!$trigger instanceof OCWebHookTriggerInterface) {
    echo "\033[33m[SKIP]\033[0m Nessun trigger post_publish registrato — aggiungere EZINI_webhook__TriggersSettings__TriggerList_0=PostPublishWebHookTrigger\n";
    $script->shutdown(0);
    exit(0);
}

$triggerName = $trigger->getIdentifier();
echo "Active trigger: $triggerName\n";

// ── Step 1: leggi offset Kafka prima della pubblicazione ──────────────────────

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Step 2: crea una notizia via REST API ─────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = "Test E2E Kafka $uniqueSuffix";

$articleJson = json_encode([
    'title'        => $title,
    'abstract'     => 'Abstract notizia test E2E Kafka - ' . $uniqueSuffix,
    'body'         => '<p>Corpo della notizia generata automaticamente dal test E2E.</p>',
    'published'    => date('Y-m-d'),
    'content_type' => [['id' => 'comunicato-stampa']],
    'topics'       => [['id' => 'ambiente']],
    'author'       => [['id' => 'ufficio-comunicazione']],
]);

$authHeader = 'Basic ' . base64_encode("$CMS_USER:$CMS_PASS");

echo "POST /Novita/Notizie — titolo: \"$title\"\n";

$resp = http_request('POST', '/Novita/Notizie', [
    'Host'          => $APP_HOST,
    'Content-Type'  => 'application/json',
    'Authorization' => $authHeader,
], $articleJson, $APP_HOST);

assert_true(
    in_array($resp['code'], [200, 201], true),
    "REST API crea la notizia (HTTP 200/201)",
    "HTTP {$resp['code']} — body: " . substr($resp['body'], 0, 300)
);

if (!in_array($resp['code'], [200, 201], true)) {
    echo "\nRisposta API:\n" . $resp['body'] . "\n";
    $script->shutdown(1);
    exit(1);
}

$responseData = json_decode($resp['body'], true);

// Il formato di risposta di ocopendata usa metadata.id oppure direttamente id
$articleId = null;
if (isset($responseData['metadata']['id'])) {
    $articleId = (string)$responseData['metadata']['id'];
} elseif (isset($responseData['id'])) {
    $articleId = (string)$responseData['id'];
}

assert_true($articleId !== null, 'Risposta REST contiene id articolo', 'Campi disponibili: ' . implode(', ', array_keys($responseData ?? [])));
echo "Articolo creato con id: $articleId\n\n";

// ── Step 3: aspetta il messaggio Kafka ────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione REST');

if ($message === null) {
    echo "\033[31mNessun messaggio arrivato su Kafka. Verificare che Kafka sia abilitato e il trigger sia attivo.\033[0m\n";
    $script->shutdown(1);
    exit(1);
}

// ── Step 4: verifica payload ──────────────────────────────────────────────────

echo "\nVerifica payload:\n";

$decoded = json_decode($message->payload, true);
assert_true(is_array($decoded),                   'Payload è JSON valido');
assert_true(isset($decoded['entity']),             'Payload ha chiave "entity"');
assert_true(isset($decoded['entity']['meta']),     'entity.meta presente');
assert_true(isset($decoded['entity']['data']),     'entity.data presente');

$meta = $decoded['entity']['meta'] ?? [];
$data = $decoded['entity']['data'] ?? [];

// object_id — corrisponde all'id dell'articolo creato via REST
if ($articleId !== null) {
    assert_eq(
        $meta['object_id'] ?? null,
        $articleId,
        "entity.meta.object_id = \"$articleId\""
    );
}

// entity.meta.id = EZ_INSTANCE:object_id
$instanceId = getenv('EZ_INSTANCE') ?: null;
$expectedMetaId = ($instanceId ?: ($meta['siteaccess'] ?? '')) . ':' . ($meta['object_id'] ?? '');
assert_true(
    isset($meta['id']) && $meta['id'] === $expectedMetaId,
    "entity.meta.id = \"$expectedMetaId\"",
    "got: " . var_export($meta['id'] ?? null, true)
);

// siteaccess non vuoto
assert_true(
    isset($meta['siteaccess']) && $meta['siteaccess'] !== '',
    'entity.meta.siteaccess presente e non vuoto',
    'got: ' . var_export($meta['siteaccess'] ?? null, true)
);

// type_id
assert_true(
    isset($meta['type_id']) && $meta['type_id'] !== '',
    'entity.meta.type_id presente',
    'got: ' . var_export($meta['type_id'] ?? null, true)
);

// data.it-IT.title corrisponde al titolo inviato
assert_true(
    isset($data['it-IT']['title']),
    'entity.data.it-IT.title presente'
);
assert_eq(
    $data['it-IT']['title'] ?? null,
    $title,
    'entity.data.it-IT.title = titolo inviato'
);

// ── Step 5: verifica header CloudEvents ───────────────────────────────────────

echo "\nVerifica header CloudEvents:\n";

$headers = (array)($message->headers ?? []);

assert_eq(
    $headers['ce_specversion'] ?? null,
    '1.0',
    'ce_specversion = "1.0"'
);

$ceId = $headers['ce_id'] ?? null;
assert_true(
    $ceId !== null && (bool)preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $ceId),
    'ce_id è un UUID v4',
    'got: ' . var_export($ceId, true)
);

assert_true(
    isset($headers['ce_type']) && strpos($headers['ce_type'], 'it.opencity.') === 0,
    'ce_type inizia con "it.opencity."',
    'got: ' . var_export($headers['ce_type'] ?? null, true)
);

assert_true(
    isset($headers['ce_source']) && strpos($headers['ce_source'], 'urn:opencity:') === 0,
    'ce_source segue il formato "urn:opencity:..."',
    'got: ' . var_export($headers['ce_source'] ?? null, true)
);

$ceTime = $headers['ce_time'] ?? null;
assert_true(
    $ceTime !== null && strtotime($ceTime) !== false,
    'ce_time è una data ISO 8601 valida',
    'got: ' . var_export($ceTime, true)
);

assert_eq(
    $headers['content-type'] ?? null,
    'application/json',
    'content-type = "application/json"'
);

echo "\nce_type:   " . ($headers['ce_type']   ?? '(missing)') . "\n";
echo "ce_source: " . ($headers['ce_source'] ?? '(missing)') . "\n";
echo "ce_time:   " . ($headers['ce_time']   ?? '(missing)') . "\n";
echo "ce_id:     " . ($headers['ce_id']     ?? '(missing)') . "\n";

// ── Step 6: verifica chiave di partizione ─────────────────────────────────────

echo "\nVerifica partition key:\n";

$tenantId = eZINI::instance('webhook.ini')->variable('KafkaSettings', 'TenantId');
if ($tenantId !== '' && $tenantId !== null) {
    assert_eq(
        $message->key,
        $tenantId,
        "Partition key = TenantId (\"$tenantId\")"
    );
} else {
    // TenantId non configurato: la chiave deve essere null/empty
    assert_true(
        $message->key === null || $message->key === '',
        'Partition key è null/empty quando TenantId non è configurato',
        'got: ' . var_export($message->key, true)
    );
    echo "[INFO] TenantId non configurato — partition key null/empty atteso\n";
}

// ── Step 7: cleanup — cancella l'articolo creato ──────────────────────────────

if ($articleId !== null) {
    echo "\nCleanup: cancello articolo id=$articleId...\n";
    $delResp = http_request('DELETE', '/Novita/Notizie/' . $articleId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    if (in_array($delResp['code'], [200, 204, 404], true)) {
        echo "Articolo rimosso (HTTP {$delResp['code']})\n";
    } else {
        echo "[WARN] DELETE /Novita/Notizie/$articleId → HTTP {$delResp['code']}\n";
    }
}

// ── Risultati ─────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

$script->shutdown($FAILED > 0 ? 1 : 0);
