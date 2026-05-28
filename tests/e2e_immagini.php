<?php

/**
 * Test E2E: re-pubblica un'immagine (Image) esistente via eZ PHP API e verifica Kafka.
 *
 * Gli oggetti image richiedono upload binario per la creazione — il test usa la re-pubblicazione
 * di un'immagine esistente per triggherare il webhook post_publish.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_immagini.php
 *
 * SKIP se nessuna immagine disponibile nel DB.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Immagini (Image) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Cerca un'immagine via DB ──────────────────────────────────────────────────

$db = eZDB::instance();
$rows = $db->arrayQuery(
    "SELECT o.id, o.name FROM ezcontentobject o " .
    "JOIN ezcontentclass c ON c.id = o.contentclass_id " .
    "WHERE c.identifier = 'image' LIMIT 1"
);

if (empty($rows)) {
    echo "\033[33m[SKIP]\033[0m Nessuna immagine disponibile nel DB\n";
    $script->shutdown(0);
    exit(0);
}

$objectId  = (int)$rows[0]['id'];
$imageName = $rows[0]['name'];
echo "Immagine trovata: id=$objectId name=\"$imageName\"\n\n";

// ── Carica l'oggetto ──────────────────────────────────────────────────────────

$object = eZContentObject::fetch($objectId);
if (!$object) {
    echo "\033[33m[SKIP]\033[0m Impossibile caricare l'immagine id=$objectId\n";
    $script->shutdown(0);
    exit(0);
}

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);

// ── Crea nuova versione copiando quella corrente ───────────────────────────────

$currentVersion = $object->currentVersion();
$newVersion     = $object->createNewVersion();

$attributes    = $currentVersion->contentObjectAttributes();
$newAttributes = $newVersion->contentObjectAttributes();

foreach ($attributes as $i => $attr) {
    if (isset($newAttributes[$i])) {
        $newAttributes[$i]->fromString($attr->toString());
        $newAttributes[$i]->store();
    }
}
$newVersion->store();

// ── Pubblica ──────────────────────────────────────────────────────────────────

eZOperationHandler::execute(
    'content', 'publish',
    ['object_id' => $objectId, 'version' => $newVersion->attribute('version')]
);

echo "Pubblicata versione " . $newVersion->attribute('version') . " — \"$imageName\"\n\n";

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione immagine');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────

e2e_verify_kafka_message($message, $imageName, 'name');

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('image', $uniqueSuffix, $message);

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
