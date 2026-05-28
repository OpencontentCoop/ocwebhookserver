<?php

/**
 * Test E2E: crea e pubblica un itinerario (Itinerary) via eZ PHP API e verifica Kafka.
 *
 * Nessun endpoint REST dedicato — il test usa eZContentClass::instantiate() per creare
 * il content object direttamente, bypassing il validatore REST.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_itinerari.php
 *
 * SKIP se il content type itinerary non esiste nell'installazione.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Itinerari (Itinerary) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Verifica che il content type esista ───────────────────────────────────────

$class = eZContentClass::fetchByIdentifier('itinerary');
if (!$class) {
    echo "\033[33m[SKIP]\033[0m Content type itinerary non trovato\n";
    $script->shutdown(0);
    exit(0);
}

// ── Trova nodo padre ──────────────────────────────────────────────────────────

$db = eZDB::instance();

// Cerca un nodo appropriato (turismo, luoghi, o simile)
$nodeRows = $db->arrayQuery(
    "SELECT n.node_id FROM ezcontentobject_tree n " .
    "JOIN ezcontentobject o ON o.id = n.contentobject_id " .
    "WHERE LOWER(o.name) LIKE '%turismo%' OR LOWER(o.name) LIKE '%luoghi%' " .
    "LIMIT 1"
);

// Fallback: nodo 2 (root content)
$parentNodeId = !empty($nodeRows) ? (int)$nodeRows[0]['node_id'] : 2;
echo "Nodo padre: $parentNodeId\n";

// ── Crea itinerary via eZContentClass::instantiate() ─────────────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title        = 'Itinerario Test E2E ' . $uniqueSuffix;

$user      = eZUser::fetchByName('admin');
$ownerId   = $user ? $user->attribute('contentobject_id') : 14;
$sectionId = eZSection::fetchByIdentifier('standard') ? eZSection::fetchByIdentifier('standard')->attribute('id') : 1;

$contentObject = $class->instantiate($ownerId, $sectionId, false, 'ita-IT');
if (!$contentObject) {
    echo "\033[33m[SKIP]\033[0m Impossibile istanziare itinerary\n";
    $script->shutdown(0);
    exit(0);
}

// Assegna al nodo padre
$nodeAssignment = eZNodeAssignment::create([
    'contentobject_id'      => $contentObject->attribute('id'),
    'contentobject_version' => 1,
    'parent_node'           => $parentNodeId,
    'is_main'               => 1,
    'sort_field'            => eZContentObjectTreeNode::SORT_FIELD_PUBLISHED,
    'sort_order'            => eZContentObjectTreeNode::SORT_ORDER_DESC,
]);
$nodeAssignment->store();

// Setta attributi
$version    = $contentObject->version(1);
$attributes = $version->contentObjectAttributes('ita-IT');

foreach ($attributes as $attr) {
    if ($attr->contentClassAttributeIdentifier() === 'title') {
        $attr->fromString($title);
        $attr->store();
    }
}

// Pubblica
eZOperationHandler::execute(
    'content', 'publish',
    ['object_id' => $contentObject->attribute('id'), 'version' => 1]
);

$objectId = $contentObject->attribute('id');
echo "Pubblicato itinerario id=$objectId — \"$title\"\n\n";

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione itinerario');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────

e2e_verify_kafka_message($message, $title, 'title');

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('itinerary', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

echo "\nCleanup: cancello itinerario id=$objectId...\n";
eZContentObjectOperations::remove($objectId);
echo "Rimosso.\n";

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
