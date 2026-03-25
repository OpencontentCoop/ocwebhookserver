<?php

/**
 * Test E2E: crea una persona del personale amministrativo (PublicPerson) e verifica Kafka.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_persone.php
 *
 * Campi compilati: given_name, family_name, has_contact_point (contatto URI),
 *   has_role (ruolo URI), abstract, competenze, deleghe, bio, notes
 * Campi richiesti (schema PublicPerson): given_name, family_name, has_contact_point, has_role
 *
 * SKIP se non disponibili: punti-di-contatto (has_contact_point), ruoli (has_role)
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Persone (PublicPerson / Personale Amministrativo) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Fetch URI necessari ───────────────────────────────────────────────────────

echo "Cerco un punto di contatto disponibile (has_contact_point)...\n";
$contattoUri = fetch_first_uri('/api/openapi/classificazioni/punti-di-contatto', $authHeader, $APP_HOST);

echo "Cerco un ruolo disponibile (has_role)...\n";
$ruoloUri = fetch_first_uri('/api/openapi/amministrazione/ruoli', $authHeader, $APP_HOST);

$missing = [];
if ($contattoUri === null) $missing[] = 'punto-di-contatto';
if ($ruoloUri === null)    $missing[] = 'ruolo';

if (!empty($missing)) {
    echo "\033[33m[SKIP]\033[0m Risorse non disponibili: " . implode(', ', $missing) . "\n";
    $script->shutdown(0);
    exit(0);
}

echo "Contatto URI: $contattoUri\n";
echo "Ruolo URI:    $ruoloUri\n\n";

// ── Genera payload ────────────────────────────────────────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);

$nomi     = ['Marco', 'Laura', 'Giovanni', 'Alessandra', 'Roberto', 'Federica', 'Paolo'];
$cognomi  = ['Rossi', 'Bianchi', 'Verdi', 'Ferraro', 'Mancini', 'Romano', 'Conti'];
$givenName  = $nomi[array_rand($nomi)] . ' Test';
$familyName = $cognomi[array_rand($cognomi)] . ' E2E';
$title = "$givenName $familyName";

$payload = json_encode([
    'given_name'        => $givenName,
    'family_name'       => $familyName,
    'has_contact_point' => [['uri' => $contattoUri]],
    'has_role'          => [['uri' => $ruoloUri]],
    'abstract'          => 'Funzionario test creato dal sistema E2E — ' . $uniqueSuffix,
    'competenze'        => 'Competenze: ' . rand_words(6),
    'deleghe'           => 'Deleghe: ' . rand_words(4),
    'bio'               => rand_html_body(2),
    'notes'             => 'Nota automatica test E2E — ' . $uniqueSuffix,
]);

// ── POST ──────────────────────────────────────────────────────────────────────

$apiPath = '/api/openapi/amministrazione/personale-amministrativo';
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
    'REST API crea persona (HTTP 200/201)',
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

save_kafka_artifact('persone', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

if ($resourceId !== null) {
    echo "\nCleanup: cancello persona id=$resourceId...\n";
    $delResp = http_request('DELETE', $apiPath . '/' . $resourceId, [
        'Host'          => $APP_HOST,
        'Authorization' => $authHeader,
    ], null, $APP_HOST);
    echo "DELETE → HTTP {$delResp['code']}\n";
}

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
