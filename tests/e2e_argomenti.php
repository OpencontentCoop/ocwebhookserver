<?php

/**
 * Test E2E: pubblica un argomento (Topic) esistente via eZ Publish PHP API e verifica Kafka.
 *
 * I topic non supportano POST/PUT via API pubblica REST — vengono gestiti dall'installer.
 * Il test usa le eZ Publish PHP API per creare una nuova versione e pubblicarla,
 * triggherando così il webhook post_publish.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_argomenti.php
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Argomenti (Topic) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Cerca un topic via DB ─────────────────────────────────────────────────────

$db = eZDB::instance();
$rows = $db->arrayQuery(
    "SELECT o.id, o.name FROM ezcontentobject o " .
    "JOIN ezcontentclass c ON c.id = o.contentclass_id " .
    "WHERE c.identifier = 'topic' LIMIT 1"
);

if (empty($rows)) {
    echo "\033[33m[SKIP]\033[0m Nessun argomento disponibile nel DB\n";
    $script->shutdown(0);
    exit(0);
}

$objectId = (int)$rows[0]['id'];
$topicName = $rows[0]['name'];
echo "Argomento trovato: id=$objectId name=\"$topicName\"\n\n";

// ── Pubblica una nuova versione via eZ PHP API ────────────────────────────────

$object = eZContentObject::fetch($objectId);
if (!$object) {
    echo "\033[33m[SKIP]\033[0m Impossibile caricare il topic\n";
    $script->shutdown(0);
    exit(0);
}

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);

// Crea nuova versione copiando quella corrente
$currentVersion = $object->currentVersion();
$newVersion = $object->createNewVersion();

// Copia gli attributi nella nuova versione
$attributes = $currentVersion->contentObjectAttributes();
$newAttributes = $newVersion->contentObjectAttributes();

foreach ($attributes as $i => $attr) {
    if (isset($newAttributes[$i])) {
        $newAttributes[$i]->fromString($attr->toString());
        $newAttributes[$i]->store();
    }
}
$newVersion->store();

// Pubblica
$operationResult = eZOperationHandler::execute(
    'content', 'publish',
    ['object_id' => $objectId, 'version' => $newVersion->attribute('version')]
);

echo "Pubblicato versione " . $newVersion->attribute('version') . " — $topicName\n\n";

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione topic');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────

e2e_verify_kafka_message($message, $topicName, 'name');

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('topic', $uniqueSuffix, $message);

e2e_results($script);
