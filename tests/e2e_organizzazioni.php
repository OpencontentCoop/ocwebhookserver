<?php

/**
 * Test E2E: crea un ufficio (Organization) via REST API e verifica il messaggio Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_organizzazioni.php
 *
 * Campi compilati: legal_name, abstract, main_function, type (enum), has_spatial_coverage
 *   (luogo URI), has_online_contact_point (contatto URI), alt_name, more_information, identifier
 * Campi richiesti (schema Organization): legal_name, abstract, main_function, type,
 *   has_spatial_coverage, has_online_contact_point
 *
 * SKIP se non disponibili: luoghi (has_spatial_coverage), punti-di-contatto (has_online_contact_point)
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Organizzazioni (Organization/Ufficio) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Fetch URI necessari ───────────────────────────────────────────────────────

echo "Cerco un luogo disponibile (has_spatial_coverage)...\n";
$luogoUri = fetch_first_uri('/api/openapi/vivere-il-comune/luoghi', $authHeader, $APP_HOST);

echo "Cerco un punto di contatto disponibile (has_online_contact_point)...\n";
$contattoUri = fetch_first_uri('/api/openapi/classificazioni/punti-di-contatto', $authHeader, $APP_HOST);

if ($luogoUri === null || $contattoUri === null) {
    echo "\033[33m[SKIP]\033[0m Luogo o punto di contatto non disponibili nell'istanza\n";
    echo "  luogo: " . ($luogoUri ?? 'null') . "\n";
    echo "  contatto: " . ($contattoUri ?? 'null') . "\n";
    $script->shutdown(0);
    exit(0);
}

echo "Luogo URI:    $luogoUri\n";
echo "Contatto URI: $contattoUri\n\n";

// ── Genera payload ────────────────────────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Ufficio Test E2E ' . $uniqueSuffix;

$payload = json_encode([
    'legal_name'              => $title,
    'alt_name'                => 'Uff. Test ' . $uniqueSuffix,
    'abstract'                => 'Descrizione automatica: ' . rand_words(8),
    'main_function'           => 'Funzione principale: ' . rand_words(6),
    'type'                    => ['Ufficio'],
    'has_spatial_coverage'    => [['uri' => $luogoUri]],
    'has_online_contact_point' => [['uri' => $contattoUri]],
    'more_information'        => rand_html_body(2),
    'identifier'              => 'test-e2e-' . $uniqueSuffix,
]);

// ── POST ──────────────────────────────────────────────────────────────────────

$apiPath = '/api/openapi/amministrazione/uffici';
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
    'REST API crea ufficio (HTTP 200/201)',
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

save_kafka_artifact('organizzazioni', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello ufficio id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
