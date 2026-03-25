<?php

/**
 * Test E2E: crea un evento (Event) via REST API e verifica il messaggio Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_eventi.php
 *
 * Campi compilati: event_title, has_public_event_typology (enum), time_interval (iCal RRULE),
 *   event_abstract, topics (URI), description, target_audience (enum), short_event_title,
 *   about_target_audience, is_accessible_for_free, cost_notes, maximum_attendee_capacity
 * Campi richiesti (schema Event): event_title, has_public_event_typology, time_interval,
 *   event_abstract, topics, description, target_audience
 *
 * SKIP se non disponibili: argomenti (topics)
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Eventi (Event) ===\n\n";

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
$title = 'Evento Test E2E ' . $uniqueSuffix;

// Genera iCalendar RRULE per un evento di 2 ore oggi
$today    = date('Ymd');
$tomorrow = date('Ymd', strtotime('+1 day'));
$startDt  = $today . 'T' . sprintf('%02d', rand(9, 17)) . '0000';
$endDt    = $today . 'T' . sprintf('%02d', rand(18, 20)) . '0000';
$until    = $tomorrow . 'T235900';
$timeInterval = "FREQ=DAILY;UNTIL={$until};DTSTART={$startDt};DTEND={$endDt};INTERVAL=1";

$tipologie  = ['Evento culturale', 'Festival', 'Mostra', 'Convegno / Conferenza', 'Seminario',
               'Laboratorio', 'Presentazione libro', 'Corso', 'Giornata informativa'];
$destinatari = ['Adulti', 'Giovani', 'Famiglie', 'Bambini', 'Anziani'];

$payload = json_encode([
    'event_title'              => $title,
    'short_event_title'        => 'Ev. ' . $uniqueSuffix,
    'has_public_event_typology' => [$tipologie[array_rand($tipologie)]],
    'time_interval'            => $timeInterval,
    'event_abstract'           => 'Evento di test: ' . rand_words(8),
    'topics'                   => [['uri' => $topicUri]],
    'description'              => rand_html_body(3),
    'target_audience'          => [$destinatari[array_rand($destinatari)]],
    'about_target_audience'    => 'Rivolto a: ' . rand_words(4),
    'is_accessible_for_free'   => (bool)rand(0, 1),
    'cost_notes'               => 'Note costo: ' . rand_words(3),
    'maximum_attendee_capacity' => rand(10, 500),
]);

// ── POST ──────────────────────────────────────────────────────────────────────

$apiPath = '/api/openapi/vivere-il-comune/eventi';
echo "POST $apiPath — \"$title\"\n";
echo "time_interval: $timeInterval\n";
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
    'REST API crea evento (HTTP 200/201)',
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

// Event usa 'event_title' come titolo ma nel payload Kafka il campo name/title dipende
// dall'implementazione ocopendata. Verifica almeno i metadati comuni.
$decoded = json_decode($message->payload, true);
$meta    = $decoded['entity']['meta'] ?? [];
assert_true(
    isset($meta['object_id']) && ctype_digit((string)$meta['object_id']),
    'entity.meta.object_id è un ID numerico'
);
assert_true(
    isset($meta['siteaccess']) && $meta['siteaccess'] !== '',
    'entity.meta.siteaccess presente'
);
assert_true(isset($decoded['entity']['data']), 'entity.data presente');

// Verifica CloudEvents headers
$headers = (array)($message->headers ?? []);
assert_eq($headers['ce_specversion'] ?? null, '1.0', 'ce_specversion = "1.0"');
assert_true(
    isset($headers['ce_type']) && strpos($headers['ce_type'], 'it.opencity.') === 0,
    'ce_type inizia con "it.opencity."'
);
assert_true(
    isset($headers['ce_source']) && strpos($headers['ce_source'], 'urn:opencity:') === 0,
    'ce_source segue il formato "urn:opencity:..."'
);

echo "ce_type:   " . ($headers['ce_type']   ?? '(missing)') . "\n";
echo "ce_source: " . ($headers['ce_source'] ?? '(missing)') . "\n";

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('eventi', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello evento id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
