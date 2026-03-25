<?php

/**
 * Test E2E: crea una notizia (Article) via REST API e verifica il messaggio Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_notizie.php
 *
 * Prerequisiti: app avviata con installer terminato, Redpanda raggiungibile,
 *   trigger PostPublishWebHookTrigger attivo.
 *
 * Campi compilati: title, abstract, body, published, expiry_date, content_type, topics, author
 * Campi richiesti (schema Article): title, content_type, abstract, published, topics, body, author
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Notizie (Article) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Fetch URI necessari ───────────────────────────────────────────────────────

echo "Cerco un argomento disponibile...\n";
$topicUri = fetch_first_uri('/api/openapi/argomenti', $authHeader, $APP_HOST);

echo "Cerco un ufficio disponibile...\n";
$ufficioUri = fetch_first_uri('/api/openapi/amministrazione/uffici', $authHeader, $APP_HOST);

if ($topicUri === null || $ufficioUri === null) {
    echo "\033[33m[SKIP]\033[0m Nessun argomento o ufficio disponibile\n";
    $script->shutdown(0);
    exit(0);
}

echo "Topic URI:   $topicUri\n";
echo "Ufficio URI: $ufficioUri\n\n";

// ── Genera payload con dati casuali ──────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Test E2E Notizia ' . $uniqueSuffix;

$payload = json_encode([
    'title'        => $title,
    'abstract'     => 'Abstract automatico: ' . rand_words(10) . ' — ' . $uniqueSuffix,
    'body'         => rand_html_body(3),
    'published'    => date('Y-m-d'),
    'expiry_date'  => rand_future_date(60),
    'content_type' => ['Comunicato stampa'],
    'topics'       => [['uri' => $topicUri]],
    'author'       => [['uri' => $ufficioUri]],
]);

// ── POST ──────────────────────────────────────────────────────────────────────

echo "POST /api/openapi/novita/notizie — \"$title\"\n";
$resp = http_request('POST', '/api/openapi/novita/notizie', [
    'Host'          => $APP_HOST,
    'Content-Type'  => 'application/json',
    'Accept'        => 'application/json',
    'Authorization' => $authHeader,
], $payload, $APP_HOST);

echo "HTTP {$resp['code']}\n";
echo "Response (first 300): " . substr($resp['body'], 0, 300) . "\n\n";

assert_true(
    in_array($resp['code'], [200, 201], true),
    'REST API crea notizia (HTTP 200/201)',
    "HTTP {$resp['code']}"
);

if (!in_array($resp['code'], [200, 201], true)) {
    e2e_results($script);
}

$responseData = json_decode($resp['body'], true);
$articleId    = $responseData['metadata']['id'] ?? $responseData['id'] ?? null;
if ($articleId !== null) {
    ok('Risposta REST contiene id');
    echo "ID: $articleId\n\n";
}

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────

e2e_verify_kafka_message($message, $title);

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('notizie', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($articleId !== null) {
    echo "\nCleanup: cancello notizia id=$articleId...\n";
    $delResp = http_request('DELETE', '/api/openapi/novita/notizie/' . $articleId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
