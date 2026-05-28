<?php

/**
 * Test E2E: crea un progetto pubblico (PublicProject) via REST API e verifica Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_progetti.php
 *
 * Endpoint candidati tentati (in ordine):
 *   /api/openapi/amministrazione/progetti
 *   /api/openapi/amministrazione/documenti-e-dati/progetti
 *   /api/openapi/progetti
 *
 * SKIP se nessun endpoint risponde con 200/201.
 * Nota: il content type 'public_project' potrebbe non essere presente in tutte le istanze
 * OpenCity; se non trovato, il test si conclude con [SKIP].
 *
 * Campi compilati: title, topics (URI), abstract, start_date, end_date, budget
 * Campi opzionali aggiunti: description
 *
 * SKIP se non disponibili: argomenti (topics) o nessun endpoint progetto
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Progetti (PublicProject) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Fetch URI necessari ───────────────────────────────────────────────────────

echo "Cerco un argomento disponibile (topics)...\n";
$topicUri = fetch_first_uri('/api/openapi/argomenti', $authHeader, $APP_HOST);

if ($topicUri === null) {
    echo "\033[33m[SKIP]\033[0m Nessun argomento disponibile nell'istanza\n";
    $script->shutdown(0);
    exit(0);
}

echo "Topic URI: $topicUri\n\n";

// ── Genera payload ────────────────────────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Progetto Test E2E ' . $uniqueSuffix;

$payload = json_encode([
    'title'       => $title,
    'topics'      => [['uri' => $topicUri]],
    'abstract'    => 'Progetto di test: ' . rand_words(8) . ' — ' . $uniqueSuffix,
    'description' => rand_html_body(2),
    'start_date'  => rand_past_date(30),
    'end_date'    => rand_future_date(180),
    'budget'      => rand(10000, 500000),
]);

// ── Prova endpoint candidati ──────────────────────────────────────────────────

$candidates = [
    '/api/openapi/amministrazione/progetti',
    '/api/openapi/amministrazione/documenti-e-dati/progetti',
    '/api/openapi/progetti',
];

$apiPath    = null;
$resp       = null;
$resourceId = null;

foreach ($candidates as $candidate) {
    echo "POST $candidate — \"$title\"\n";
    $r = http_request('POST', $candidate, [
        'Host'          => $APP_HOST,
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
        'Authorization' => $authHeader,
    ], $payload, $APP_HOST);

    echo "HTTP {$r['code']}\n";
    if (in_array($r['code'], [200, 201], true)) {
        $apiPath = $candidate;
        $resp    = $r;
        break;
    }
    echo "Response (first 200): " . substr($r['body'], 0, 200) . "\n";
    echo "Endpoint non disponibile, provo il successivo...\n\n";
}

if ($apiPath === null) {
    echo "\033[33m[SKIP]\033[0m Nessun endpoint 'public_project' disponibile.\n";
    echo "Endpoint tentati: " . implode(', ', $candidates) . "\n";
    echo "Nota: il content type 'public_project' potrebbe non essere installato in questa istanza.\n";
    $script->shutdown(0);
    exit(0);
}

echo "Response (first 300): " . substr($resp['body'], 0, 300) . "\n\n";
ok('REST API crea progetto (HTTP 200/201)');

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

e2e_verify_kafka_message($message, $title);

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('public_project', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello progetto id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
