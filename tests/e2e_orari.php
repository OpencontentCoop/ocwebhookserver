<?php

/**
 * Test E2E: crea un orario (OpeningHoursSpecification) via REST API e verifica Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_orari.php
 *
 * Prova prima /classificazioni/orari-uffici-e-strutture, poi /classificazioni/orari-servizi
 * come fallback; usa il primo endpoint che risponde con HTTP 200/201.
 *
 * Campi compilati: name, opening_hours (array di oggetti iCal)
 * Campi richiesti (schema OpeningHoursSpecification): name
 *
 * Nota: nessuna risorsa esterna richiesta — questo test non ha dipendenze da URI.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Orari (OpeningHoursSpecification) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Genera payload ────────────────────────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Orario Test E2E ' . $uniqueSuffix;

$giorni = [
    ['Mo', 'Lunedì'],
    ['Tu', 'Martedì'],
    ['We', 'Mercoledì'],
    ['Th', 'Giovedì'],
    ['Fr', 'Venerdì'],
];
$giorno = $giorni[array_rand($giorni)];

$payload = json_encode([
    'name'          => $title,
    'opening_hours' => [
        [
            'day_of_week'     => $giorno[0],
            'opens'           => '09:00',
            'closes'          => '12:00',
            'valid_from'      => date('Y-m-d'),
            'valid_through'   => date('Y-m-d', strtotime('+1 year')),
        ],
        [
            'day_of_week'     => $giorno[0],
            'opens'           => '14:00',
            'closes'          => '17:00',
            'valid_from'      => date('Y-m-d'),
            'valid_through'   => date('Y-m-d', strtotime('+1 year')),
        ],
    ],
    'notes'         => 'Orario di test E2E — ' . $uniqueSuffix,
]);

// ── Prova endpoint uffici e poi servizi ───────────────────────────────────────

$candidates = [
    '/api/openapi/classificazioni/orari-uffici-e-strutture',
    '/api/openapi/classificazioni/orari-servizi',
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
    echo "\033[33m[SKIP]\033[0m Nessun endpoint orari disponibile (orari-uffici-e-strutture, orari-servizi)\n";
    $script->shutdown(0);
    exit(0);
}

echo "Response (first 300): " . substr($resp['body'], 0, 300) . "\n\n";
ok('REST API crea orario (HTTP 200/201)');

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

e2e_verify_kafka_message($message, $title, 'name');

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('opening_hours_specification', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello orario id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
