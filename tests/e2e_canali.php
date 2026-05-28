<?php

/**
 * Test E2E: crea un canale digitale (DigitalChannel) via REST API e verifica il messaggio Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_canali.php
 *
 * Campi compilati: object (titolo canale), has_channel_type (stringa)
 * Campi richiesti (schema DigitalChannel): object, has_channel_type
 *
 * Nota: nel payload Kafka il campo 'object' viene rinominato in 'subject' —
 *   la verifica usa titleField='subject'.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Canali Digitali (DigitalChannel) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Genera payload ────────────────────────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Canale Test E2E ' . $uniqueSuffix;

$tipiCanale = [
    'Web',
    'Email',
    'Telefono',
    'Sportello fisico',
    'App mobile',
    'Social network',
    'Chat',
    'PEC',
];

$payload = json_encode([
    'object'           => $title,
    'has_channel_type' => [$tipiCanale[array_rand($tipiCanale)]],
    'abstract'         => 'Canale di test automatico — ' . $uniqueSuffix,
    'channel_url'      => 'https://www.comune.example.it/sportello-' . $uniqueSuffix,
]);

// ── POST ──────────────────────────────────────────────────────────────────────

$apiPath = '/api/openapi/classificazioni/canali-digitali';
echo "POST $apiPath — \"$title\"\n";
$resp = http_request('POST', $apiPath, [
    'Host'          => $APP_HOST,
    'Content-Type'  => 'application/json',
    'Accept'        => 'application/json',
    'Authorization' => $authHeader,
], $payload, $APP_HOST);

echo "HTTP {$resp['code']}\n";
echo "Response (first 300): " . substr($resp['body'], 0, 300) . "\n\n";

assert_true(
    in_array($resp['code'], [200, 201], true),
    'REST API crea canale digitale (HTTP 200/201)',
    "HTTP {$resp['code']}"
);

if (!in_array($resp['code'], [200, 201], true)) {
    e2e_results($script);
}

$responseData = json_decode($resp['body'], true);
$resourceId   = $responseData['metadata']['id'] ?? $responseData['id'] ?? null;
if ($resourceId !== null) {
    ok('Risposta REST contiene id');
    echo "ID: $resourceId\n\n";
}

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);
assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────
// Il campo 'object' viene rinominato in 'subject' nel payload Kafka

e2e_verify_kafka_message($message, $title, 'subject');

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('channel', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello canale id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
