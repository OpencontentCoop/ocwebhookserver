<?php

/**
 * Test E2E: crea un luogo (Place) via REST API e verifica il messaggio Kafka.
 *
 * Campi richiesti: name, type, abstract, image (URI), accessibility, has_address, help (URI)
 * SKIP se non disponibili: immagini (/media/images) o punti di contatto (/classificazioni/punti-di-contatto)
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Luoghi (Place) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Fetch URI necessari ───────────────────────────────────────────────────────

echo "Cerco un'immagine disponibile...\n";
$imageUri = fetch_first_uri('/api/openapi/media/images', $authHeader, $APP_HOST);

echo "Cerco un punto di contatto disponibile...\n";
$contattoUri = fetch_first_uri('/api/openapi/classificazioni/punti-di-contatto', $authHeader, $APP_HOST);

if ($imageUri === null || $contattoUri === null) {
    echo "\033[33m[SKIP]\033[0m Immagini o punti di contatto non disponibili nell'istanza\n";
    $script->shutdown(0);
    exit(0);
}

echo "Image URI:    $imageUri\n";
echo "Contatto URI: $contattoUri\n\n";

// ── Genera payload ────────────────────────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Luogo Test E2E ' . $uniqueSuffix;

$tipi = ['Struttura pubblica', 'Sede municipale', 'Biblioteca', 'Museo', 'Parco', 'Parcheggio'];

$payload = json_encode([
    'name'          => $title,
    'type'          => [$tipi[array_rand($tipi)]],
    'abstract'      => '<p>Luogo di test automatico: ' . rand_words(8) . ' — ' . $uniqueSuffix . '</p>',
    'accessibility' => '<p>Accessibile alle persone con disabilità motoria.</p>',
    'has_address'   => [
        'latitude'  => (float)(45.0 + rand(0, 999) / 1000),
        'longitude' => (float)(9.0  + rand(0, 999) / 1000),
        'address'   => 'Via Test E2E ' . rand(1, 200) . ', Comune di Esempio',
    ],
    'image'         => [['uri' => $imageUri]],
    'help'          => [['uri' => $contattoUri]],
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

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione luogo');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────

e2e_verify_kafka_message($message, $title, 'name');

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('place', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello luogo id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

e2e_results($script);
