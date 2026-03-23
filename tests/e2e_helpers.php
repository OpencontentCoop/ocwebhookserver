<?php

/**
 * Shared helpers per i test E2E Kafka.
 *
 * Da includere all'inizio di ogni script di test:
 *   require_once __DIR__ . '/e2e_helpers.php';
 *
 * Questo file:
 *  - Fa il bootstrap di eZ Publish
 *  - Definisce le variabili di configurazione globali ($script, $BROKER, ...)
 *  - Definisce le funzioni di assert, Kafka, HTTP e utility
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

// ── Configurazione globale ────────────────────────────────────────────────────

$BROKER   = getenv('KAFKA_BROKER') ?: 'redpanda:9092';
$TOPIC    = getenv('KAFKA_TOPIC')  ?: 'cms';
$APP_HOST = getenv('APP_HOST')     ?: 'opencity.localtest.me';
$CMS_USER = getenv('CMS_USER')     ?: 'admin';
$CMS_PASS = getenv('CMS_PASS')     ?: 'changethispassword';
$authHeader = 'Basic ' . base64_encode("$CMS_USER:$CMS_PASS");

// ── Counter globali ───────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

// ── Assert helpers ────────────────────────────────────────────────────────────

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

// ── HTTP helper ───────────────────────────────────────────────────────────────

function http_request(string $method, string $path, array $headers, ?string $body, string $host): array
{
    $ch = curl_init();
    $curlHeaders = [];
    foreach ($headers as $k => $v) {
        $curlHeaders[] = "$k: $v";
    }
    curl_setopt_array($ch, [
        CURLOPT_URL            => 'http://127.0.0.1' . $path,
        CURLOPT_IPRESOLVE      => CURL_IPRESOLVE_V4,
        CURLOPT_RESOLVE        => ["$host:80:127.0.0.1"],
        CURLOPT_HTTPHEADER     => $curlHeaders,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POSTREDIR      => 3,
        CURLOPT_MAXREDIRS      => 3,
    ]);
    $raw    = curl_exec($ch);
    $code   = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $size   = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $errno  = curl_errno($ch);
    $errmsg = curl_error($ch);
    curl_close($ch);
    if ($errno !== 0) {
        echo "[curl error $errno] $errmsg\n";
    }
    return [
        'code'    => $code,
        'headers' => substr($raw, 0, $size),
        'body'    => substr($raw, $size),
    ];
}

// ── Utility: fetch primo URI disponibile da un endpoint lista ─────────────────

/**
 * Chiama GET $endpoint e restituisce il campo `uri` del primo elemento.
 * Restituisce null se l'endpoint non risponde o non ha items.
 */
function fetch_first_uri(string $endpoint, string $authHeader, string $appHost): ?string
{
    $resp = http_request('GET', $endpoint, [
        'Host'          => $appHost,
        'Accept'        => 'application/json',
        'Authorization' => $authHeader,
    ], null, $appHost);
    $data = json_decode($resp['body'], true);
    return $data['items'][0]['uri'] ?? null;
}

// ── Utility: salva messaggio Kafka come artifact ───────────────────────────────

/**
 * Salva il payload Kafka come file JSON in /tmp/kafka-artifacts/.
 * La CI raccoglie questa directory come artifact GitLab CI (30 giorni).
 */
function save_kafka_artifact(string $contentType, string $suffix, RdKafka\Message $msg): void
{
    $dir = getenv('KAFKA_ARTIFACTS_DIR') ?: '/tmp/kafka-artifacts';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    $artifact = [
        'content_type'  => $contentType,
        'test_suffix'   => $suffix,
        'timestamp'     => date('c'),
        'kafka_headers' => (array)($msg->headers ?? []),
        'kafka_key'     => $msg->key,
        'kafka_offset'  => $msg->offset,
        'payload'       => json_decode($msg->payload, true),
    ];
    $filename = "$dir/{$contentType}_{$suffix}.json";
    file_put_contents(
        $filename,
        json_encode($artifact, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
    echo "Artifact salvato: $filename\n";
}

// ── Utility: verifica trigger ─────────────────────────────────────────────────

/**
 * Verifica che il trigger post_publish sia attivo.
 * SKIP (exit 0) se non trovato.
 */
function e2e_check_trigger($script): string
{
    $trigger = OCWebHookTriggerRegistry::registeredTrigger('post_publish_ocopendata');
    if (!$trigger instanceof OCWebHookTriggerInterface) {
        $trigger = OCWebHookTriggerRegistry::registeredTrigger('post_publish');
    }
    if (!$trigger instanceof OCWebHookTriggerInterface) {
        echo "\033[33m[SKIP]\033[0m Nessun trigger post_publish — aggiungere EZINI_webhook__TriggersSettings__TriggerList_0=PostPublishWebHookTrigger\n";
        $script->shutdown(0);
        exit(0);
    }
    $name = $trigger->getIdentifier();
    echo "Active trigger: $name\n";
    return $name;
}

// ── Utility: stampa risultati e shutdown ──────────────────────────────────────

function e2e_results($script): void
{
    global $PASSED, $FAILED;
    echo "\n" . str_repeat('─', 50) . "\n";
    echo "Results: \033[32m{$PASSED} passed\033[0m";
    if ($FAILED > 0) {
        echo ", \033[31m{$FAILED} failed\033[0m";
    }
    echo "\n";
    $script->shutdown($FAILED > 0 ? 1 : 0);
}

// ── Utility: verifica payload Kafka (parte comune) ────────────────────────────

/**
 * Verifica le parti comuni del payload Kafka: meta, data, CloudEvents headers, partition key.
 * Restituisce [$meta, $data, $primaryLang] per verifiche specifiche del content type.
 */
function e2e_verify_kafka_message(RdKafka\Message $message, string $expectedTitle): array
{
    echo "\nVerifica payload:\n";

    $decoded = json_decode($message->payload, true);
    assert_true(is_array($decoded),               'Payload è JSON valido');
    assert_true(isset($decoded['entity']),         'Payload ha chiave "entity"');
    assert_true(isset($decoded['entity']['meta']), 'entity.meta presente');
    assert_true(isset($decoded['entity']['data']), 'entity.data presente');

    $meta = $decoded['entity']['meta'] ?? [];
    $data = $decoded['entity']['data'] ?? [];

    assert_true(
        isset($meta['object_id']) && $meta['object_id'] !== '' && ctype_digit((string)$meta['object_id']),
        'entity.meta.object_id è un ID numerico non vuoto',
        'got: ' . var_export($meta['object_id'] ?? null, true)
    );

    $instanceId     = getenv('EZ_INSTANCE') ?: null;
    $expectedMetaId = ($instanceId ?: ($meta['siteaccess'] ?? '')) . ':' . ($meta['object_id'] ?? '');
    assert_true(
        isset($meta['id']) && $meta['id'] === $expectedMetaId,
        "entity.meta.id = \"$expectedMetaId\"",
        'got: ' . var_export($meta['id'] ?? null, true)
    );

    assert_true(
        isset($meta['siteaccess']) && $meta['siteaccess'] !== '',
        'entity.meta.siteaccess presente e non vuoto',
        'got: ' . var_export($meta['siteaccess'] ?? null, true)
    );

    assert_true(
        isset($meta['type_id']) && $meta['type_id'] !== '',
        'entity.meta.type_id presente',
        'got: ' . var_export($meta['type_id'] ?? null, true)
    );

    $primaryLang = $meta['languages'][0] ?? 'ita-IT';
    assert_true(isset($data[$primaryLang]['title']), "entity.data.$primaryLang.title presente");
    assert_eq(
        $data[$primaryLang]['title'] ?? null,
        $expectedTitle,
        "entity.data.$primaryLang.title = titolo inviato"
    );

    echo "\nVerifica header CloudEvents:\n";
    $headers = (array)($message->headers ?? []);

    assert_eq($headers['ce_specversion'] ?? null, '1.0', 'ce_specversion = "1.0"');

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

    assert_eq($headers['content-type'] ?? null, 'application/json', 'content-type = "application/json"');

    echo "\nce_type:   " . ($headers['ce_type']   ?? '(missing)') . "\n";
    echo "ce_source: " . ($headers['ce_source'] ?? '(missing)') . "\n";
    echo "ce_time:   " . ($headers['ce_time']   ?? '(missing)') . "\n";
    echo "ce_id:     " . ($headers['ce_id']     ?? '(missing)') . "\n";

    echo "\nVerifica partition key:\n";
    $tenantId = eZINI::instance('webhook.ini')->variable('KafkaSettings', 'TenantId');
    if ($tenantId !== '' && $tenantId !== null) {
        assert_eq($message->key, $tenantId, "Partition key = TenantId (\"$tenantId\")");
    } else {
        assert_true(
            $message->key === null || $message->key === '',
            'Partition key è null/empty quando TenantId non è configurato',
            'got: ' . var_export($message->key, true)
        );
    }

    return [$meta, $data, $primaryLang];
}

// ── Random data generators ────────────────────────────────────────────────────

/** Genera una stringa casuale di parole. */
function rand_words(int $count = 5): string
{
    $words = ['lorem', 'ipsum', 'dolor', 'sit', 'amet', 'consectetur', 'adipiscing',
              'elit', 'sed', 'eiusmod', 'tempor', 'incididunt', 'labore', 'dolore',
              'magna', 'aliqua', 'enim', 'minim', 'veniam', 'quis', 'nostrud'];
    $result = [];
    for ($i = 0; $i < $count; $i++) {
        $result[] = $words[array_rand($words)];
    }
    return implode(' ', $result);
}

/** Genera un paragrafo HTML casuale. */
function rand_html_body(int $sentences = 3): string
{
    $parts = [];
    for ($i = 0; $i < $sentences; $i++) {
        $parts[] = ucfirst(rand_words(rand(8, 15))) . '.';
    }
    return '<p>' . implode(' ', $parts) . '</p>';
}

/** Genera una data futura casuale (fino a +N giorni da oggi). */
function rand_future_date(int $maxDays = 365): string
{
    return date('Y-m-d', strtotime('+' . rand(1, $maxDays) . ' days'));
}

/** Genera una data passata casuale (fino a -N giorni). */
function rand_past_date(int $maxDays = 365): string
{
    return date('Y-m-d', strtotime('-' . rand(1, $maxDays) . ' days'));
}

/** Genera un numero di telefono italiano casuale. */
function rand_phone(): string
{
    return '+39 0' . rand(10, 99) . ' ' . rand(100000, 999999);
}

/** Genera un indirizzo email casuale. */
function rand_email(string $suffix): string
{
    return 'test.' . strtolower($suffix) . '.' . rand(100, 999) . '@example.com';
}

// ── Init topic ────────────────────────────────────────────────────────────────

shell_exec("/usr/bin/rpk topic create $TOPIC --brokers $BROKER 2>/dev/null");
