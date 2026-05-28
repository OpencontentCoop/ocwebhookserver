<?php

/**
 * Test E2E: crea un orario (OpeningHoursSpecification) via REST API e verifica Kafka.
 *
 * Campi richiesti: name, valid_from, stagionalita, più almeno un giorno di apertura.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Orari (OpeningHoursSpecification) ===\n\n";

e2e_check_trigger($script);

// Verifica che l'endpoint esista
$checkResp = http_request('GET', '/api/openapi/classificazioni/orari-uffici-e-strutture', [
    'Host'          => $APP_HOST,
    'Accept'        => 'application/json',
    'Authorization' => $authHeader,
], null, $APP_HOST);

if ($checkResp['code'] === 404) {
    echo "\033[33m[SKIP]\033[0m Nessun endpoint orari disponibile (orari-uffici-e-strutture, orari-servizi)\n";
    $script->shutdown(0);
    exit(0);
}

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Orari Test E2E ' . $uniqueSuffix;

$payload = json_encode([
    'name'       => $title,
    'valid_from' => date('Y-m-d'),
    'stagionalita' => 'Orario continuato',
    'monday'     => [['opens' => '09:00', 'closes' => '13:00']],
    'tuesday'    => [['opens' => '09:00', 'closes' => '13:00']],
    'wednesday'  => [['opens' => '09:00', 'closes' => '13:00']],
    'thursday'   => [['opens' => '09:00', 'closes' => '13:00']],
    'friday'     => [['opens' => '09:00', 'closes' => '12:00']],
]);

$apiPath = '/api/openapi/classificazioni/orari-uffici-e-strutture';
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
    'REST API crea orario (HTTP 200/201)',
    "HTTP {$resp['code']}"
);

if (!in_array($resp['code'], [200, 201], true)) {
    e2e_results($script);
}

$responseData = json_decode($resp['body'], true);
$resourceId   = $responseData['metadata']['id'] ?? $responseData['id'] ?? null;
if ($resourceId !== null) {
    ok('Risposta REST contiene id');
}

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione orario');

if ($message === null) {
    e2e_results($script);
}

e2e_verify_kafka_message($message, $title, 'name');
save_kafka_artifact('opening_hours_specification', $uniqueSuffix, $message);

if ($resourceId !== null) {
    echo "\nCleanup: cancello orario id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host' => $APP_HOST, 'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

e2e_results($script);
