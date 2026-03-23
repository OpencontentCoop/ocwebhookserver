<?php

/**
 * Test E2E: crea un documento (Document) via REST API e verifica il messaggio Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_documenti.php
 *
 * Campi compilati: name, document_type (enum), topics (URI), abstract, has_organization (URI),
 *   license (enum), format (enum), start_time, publication_start_time, full_description,
 *   expiration_time, author, keyword
 * Campi richiesti (schema Document): name, document_type, topics, abstract, has_organization,
 *   license, format, start_time, publication_start_time
 *
 * SKIP se non disponibili: argomenti (topics), uffici (has_organization)
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Documenti (Document) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Fetch URI necessari ───────────────────────────────────────────────────────

echo "Cerco un argomento disponibile (topics)...\n";
$topicUri = fetch_first_uri('/api/openapi/argomenti', $authHeader, $APP_HOST);

echo "Cerco un ufficio disponibile (has_organization)...\n";
$ufficioUri = fetch_first_uri('/api/openapi/amministrazione/uffici', $authHeader, $APP_HOST);

if ($topicUri === null || $ufficioUri === null) {
    echo "\033[33m[SKIP]\033[0m Argomento o ufficio non disponibili nell'istanza\n";
    echo "  topic: " . ($topicUri ?? 'null') . "\n";
    echo "  ufficio: " . ($ufficioUri ?? 'null') . "\n";
    $script->shutdown(0);
    exit(0);
}

echo "Topic URI:   $topicUri\n";
echo "Ufficio URI: $ufficioUri\n\n";

// ── Genera payload ────────────────────────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Documento Test E2E ' . $uniqueSuffix;

$tipiDoc = [
    'Documenti funzionamento interno', 'Atti normativi', 'Modulistica',
    'Documenti (tecnici) di supporto', 'Accordo', 'Circolare', 'Regolamento'
];
$licenze = [
    'Creative Commons CC0 1.0 Universal - Public Domain Dedication (CC0 1.0)',
    'Creative Commons Attribution 4.0 International (CC BY 4.0)',
    'Licenza aperta',
    'Dominio pubblico',
];
$formati = ['PDF', 'DOC', 'ODT', 'XML', 'CSV'];

$payload = json_encode([
    'name'                    => $title,
    'document_type'           => [$tipiDoc[array_rand($tipiDoc)]],
    'topics'                  => [['uri' => $topicUri]],
    'abstract'                => 'Documento di test: ' . rand_words(8),
    'full_description'        => rand_html_body(3),
    'has_organization'        => [['uri' => $ufficioUri]],
    'license'                 => [$licenze[array_rand($licenze)]],
    'format'                  => [$formati[array_rand($formati)]],
    'start_time'              => rand_past_date(30),
    'publication_start_time'  => date('Y-m-d'),
    'expiration_time'         => rand_future_date(365),
    'author'                  => 'Autore Test E2E',
    'keyword'                 => 'test e2e kafka ' . $uniqueSuffix,
]);

// ── POST ──────────────────────────────────────────────────────────────────────

$apiPath = '/api/openapi/amministrazione/documenti-e-dati/documenti-funzionamento-interno';
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
    'REST API crea documento (HTTP 200/201)',
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

e2e_verify_kafka_message($message, $title);

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('documenti', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello documento id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
