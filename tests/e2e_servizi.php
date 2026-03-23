<?php

/**
 * Test E2E: crea un servizio (PublicService) via REST API e verifica il messaggio Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_servizi.php
 *
 * Campi compilati: tutti i 17 campi richiesti + campi opzionali dove possibile
 * Campi richiesti (schema PublicService): type, name, identifier, has_service_status,
 *   abstract, audience, has_spatial_coverage, process, has_language, how_to, has_input,
 *   produces_output, is_physically_available_at, terms_of_service, has_online_contact_point,
 *   holds_role_in_time, topics
 *
 * SKIP se non disponibili: argomenti (topics), luoghi (is_physically_available_at,
 *   has_spatial_coverage), punti-di-contatto (has_online_contact_point),
 *   documenti (produces_output), ruoli (holds_role_in_time)
 *
 * Nota: PublicService è il content type più complesso (17 required). Molti campi richiedono
 * URI a risorse esistenti. Il test fa SKIP se anche solo una risorsa manca.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Servizi (PublicService) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Fetch URI necessari ───────────────────────────────────────────────────────

echo "Cerco argomento (topics)...\n";
$topicUri = fetch_first_uri('/api/openapi/argomenti', $authHeader, $APP_HOST);

echo "Cerco luogo (is_physically_available_at, has_spatial_coverage)...\n";
$luogoUri = fetch_first_uri('/api/openapi/vivere-il-comune/luoghi', $authHeader, $APP_HOST);

echo "Cerco punto di contatto (has_online_contact_point)...\n";
$contattoUri = fetch_first_uri('/api/openapi/classificazioni/punti-di-contatto', $authHeader, $APP_HOST);

echo "Cerco documento (produces_output)...\n";
$documentoUri = fetch_first_uri('/api/openapi/amministrazione/documenti-e-dati/documenti-funzionamento-interno', $authHeader, $APP_HOST);

echo "Cerco ruolo (holds_role_in_time)...\n";
$ruoloUri = fetch_first_uri('/api/openapi/amministrazione/ruoli', $authHeader, $APP_HOST);

$missing = [];
if ($topicUri === null)    $missing[] = 'argomento';
if ($luogoUri === null)    $missing[] = 'luogo';
if ($contattoUri === null) $missing[] = 'punto-di-contatto';
if ($documentoUri === null) $missing[] = 'documento';
if ($ruoloUri === null)    $missing[] = 'ruolo';

if (!empty($missing)) {
    echo "\033[33m[SKIP]\033[0m Risorse non disponibili: " . implode(', ', $missing) . "\n";
    $script->shutdown(0);
    exit(0);
}

echo "\nURIs disponibili:\n";
echo "  topic:     $topicUri\n";
echo "  luogo:     $luogoUri\n";
echo "  contatto:  $contattoUri\n";
echo "  documento: $documentoUri\n";
echo "  ruolo:     $ruoloUri\n\n";

// ── Genera payload ────────────────────────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Servizio Test E2E ' . $uniqueSuffix;

$tipiServizio = [
    'Tributi, finanze e contravvenzioni',
    'Anagrafe e stato civile',
    'Lavori pubblici e manutenzione',
    'Cultura e tempo libero',
    'Istruzione e formazione',
    'Salute, benessere e assistenza',
];
$statiServizio = ['Servizio attivo', 'Servizio in fase di aggiornamento', 'Servizio non attivo'];
$processi = [
    'Procedimento ad iniziativa privata',
    'Procedimento ad iniziativa pubblica'
];
$lingue = ['Italiano', 'Inglese'];

$payload = json_encode([
    'name'                   => $title,
    'identifier'             => 'srv-e2e-' . $uniqueSuffix,
    'type'                   => [$tipiServizio[array_rand($tipiServizio)]],
    'has_service_status'     => [$statiServizio[array_rand($statiServizio)]],
    'abstract'               => 'Servizio di test: ' . rand_words(8),
    'description'            => rand_html_body(3),
    'audience'               => 'Cittadini residenti nel territorio comunale',
    'has_spatial_coverage'   => [['uri' => $luogoUri]],
    'process'                => [$processi[array_rand($processi)]],
    'has_language'           => [$lingue[array_rand($lingue)]],
    'how_to'                 => rand_html_body(2),
    'has_input'              => 'Documento di identità valido, codice fiscale. ' . rand_words(5),
    'produces_output'        => [['uri' => $documentoUri]],
    'is_physically_available_at' => [['uri' => $luogoUri]],
    'terms_of_service'       => [],
    'has_online_contact_point' => [['uri' => $contattoUri]],
    'holds_role_in_time'     => [['uri' => $ruoloUri]],
    'topics'                 => [['uri' => $topicUri]],
    'has_cost_description'   => 'Il servizio è gratuito.',
    'output_notes'           => 'Note output: ' . rand_words(4),
]);

// ── POST ──────────────────────────────────────────────────────────────────────

$apiPath = '/api/openapi/servizi';
echo "POST $apiPath — \"$title\"\n";
$resp = http_request('POST', $apiPath, [
    'Host'          => $APP_HOST,
    'Content-Type'  => 'application/json',
    'Accept'        => 'application/json',
    'Authorization' => $authHeader,
], $payload, $APP_HOST);

echo "HTTP {$resp['code']}\n";
echo "Response (first 500): " . substr($resp['body'], 0, 500) . "\n\n";

assert_true(
    in_array($resp['code'], [200, 201], true),
    'REST API crea servizio (HTTP 200/201)',
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

save_kafka_artifact('servizi', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello servizio id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
