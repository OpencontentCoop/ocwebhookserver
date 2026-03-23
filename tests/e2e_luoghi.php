<?php

/**
 * Test E2E: crea un luogo (Place) via REST API e verifica il messaggio Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_luoghi.php
 *
 * Campi compilati: name, type (enum), abstract, accessibility, has_address (inline),
 *   image (URI), help (contatto URI), description, alternative_name, more_information
 * Campi richiesti (schema Place): name, type, abstract, image, accessibility, has_address, help
 *
 * SKIP se non disponibili: immagini (image), punti-di-contatto (help)
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Luoghi (Place) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Fetch URI necessari ───────────────────────────────────────────────────────

echo "Cerco un'immagine disponibile (image)...\n";
$imageUri = fetch_first_uri('/api/openapi/media/immagini', $authHeader, $APP_HOST);

echo "Cerco un punto di contatto disponibile (help)...\n";
$contattoUri = fetch_first_uri('/api/openapi/classificazioni/punti-di-contatto', $authHeader, $APP_HOST);

if ($imageUri === null || $contattoUri === null) {
    echo "\033[33m[SKIP]\033[0m Immagini o punti di contatto non disponibili nell'istanza\n";
    echo "  image: " . ($imageUri ?? 'null') . "\n";
    echo "  contatto: " . ($contattoUri ?? 'null') . "\n";
    $script->shutdown(0);
    exit(0);
}

echo "Image URI:    $imageUri\n";
echo "Contatto URI: $contattoUri\n\n";

// ── Genera payload ────────────────────────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Luogo Test E2E ' . $uniqueSuffix;

$tipiLuogo = [
    'Piazza', 'Parco', 'Museo', 'Biblioteca', 'Teatro',
    'Centro culturale', 'Sede municipale', 'Ufficio', 'Scuola',
];

$payload = json_encode([
    'name'             => $title,
    'alternative_name' => 'Luogo Alt. ' . $uniqueSuffix,
    'type'             => [$tipiLuogo[array_rand($tipiLuogo)]],
    'abstract'         => 'Luogo di test: ' . rand_words(8),
    'description'      => rand_html_body(2),
    'accessibility'    => 'Accessibile — ' . rand_words(5),
    'has_address'      => [
        'address'   => 'Via Test ' . rand(1, 99) . ', ' . rand(10000, 99999) . ' Comune Test',
        'latitude'  => round(43.0 + (rand(0, 100) / 1000), 6),
        'longitude' => round(10.0 + (rand(0, 100) / 1000), 6),
    ],
    'image'            => [['uri' => $imageUri]],
    'help'             => [['uri' => $contattoUri]],
    'more_information' => rand_html_body(1),
]);

// ── POST ──────────────────────────────────────────────────────────────────────

$apiPath = '/api/openapi/vivere-il-comune/luoghi';
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
    'REST API crea luogo (HTTP 200/201)',
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

save_kafka_artifact('luoghi', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello luogo id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
