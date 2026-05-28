<?php

/**
 * Test E2E: crea un dataset (Dataset) via REST API e verifica il messaggio Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_dataset.php
 *
 * Campi compilati: title, topics (URI), abstract, license (stringa),
 *   accrualperiodicity (stringa), theme (stringa)
 * Campi richiesti (schema Dataset): title, topics, abstract, license,
 *   accrualperiodicity, theme
 *
 * SKIP se non disponibili: argomenti (topics)
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Dataset ===\n\n";

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
$title = 'Dataset Test E2E ' . $uniqueSuffix;

$licenze = [
    'Creative Commons CC0 1.0 Universal - Public Domain Dedication (CC0 1.0)',
    'Creative Commons Attribution 4.0 International (CC BY 4.0)',
    'Licenza aperta',
];
$periodicita = [
    'Aggiornamento continuo',
    'Giornaliero',
    'Settimanale',
    'Mensile',
    'Annuale',
    'Non pianificato',
];
$temi = [
    'Agricoltura, pesca, silvicoltura e prodotti alimentari',
    'Economia e finanze',
    'Educazione, cultura e sport',
    'Energia',
    'Ambiente',
    'Governo e settore pubblico',
    'Salute',
    'Regioni e città',
    'Popolazione e società',
    'Scienza e tecnologia',
    'Trasporti',
];

// rights_holder: usa il primo ufficio disponibile
$rightsHolderUri = fetch_first_uri('/api/openapi/amministrazione/uffici', $authHeader, $APP_HOST);

$payload = json_encode([
    'title'              => $title,
    'topics'             => [['uri' => $topicUri]],
    'abstract'           => 'Dataset di test: ' . rand_words(8) . ' — ' . $uniqueSuffix,
    'license'            => [$licenze[array_rand($licenze)]],
    'accrualperiodicity' => [$periodicita[array_rand($periodicita)]],
    'theme'              => [$temi[array_rand($temi)]],
    'keyword'            => 'test e2e kafka ' . $uniqueSuffix,
    'format'             => ['CSV'],
    'modified'           => date('Y-m-d'),
    'language'           => ['ita'],
    'spatial'            => ['001001'], // ISTAT pro_com_t: Agliè (TO) — valore fisso di test
    'rights_holder'      => $rightsHolderUri ? [['uri' => $rightsHolderUri]] : [],
]);

// ── POST ──────────────────────────────────────────────────────────────────────

$apiPath = '/api/openapi/amministrazione/documenti-e-dati/dataset';
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
    'REST API crea dataset (HTTP 200/201)',
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

save_kafka_artifact('dataset', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello dataset id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
