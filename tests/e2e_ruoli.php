<?php

/**
 * Test E2E: crea e pubblica un ruolo (time_indexed_role) via eZ PHP API e verifica Kafka.
 *
 * L'endpoint REST POST /api/openapi/media/ruoli richiede i campi person e for_entity
 * che rendono il setup complesso. Si usa eZContentClass::instantiate() per creare
 * il content object direttamente, bypassing il validatore REST.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_ruoli.php
 *
 * SKIP se il content type time_indexed_role non esiste nell'installazione.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Ruoli (time_indexed_role) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Verifica che il content type esista ───────────────────────────────────────

$class = eZContentClass::fetchByIdentifier('time_indexed_role');
if (!$class) {
    echo "\033[33m[SKIP]\033[0m Content type time_indexed_role non trovato\n";
    $script->shutdown(0);
    exit(0);
}

// ── Trova nodo padre appropriato ─────────────────────────────────────────────

$db = eZDB::instance();

// Cerca il nodo padre dove vivono i ruoli esistenti
$nodeRows = $db->arrayQuery(
    "SELECT DISTINCT n.parent_node_id FROM ezcontentobject_tree n " .
    "JOIN ezcontentobject o ON o.id = n.contentobject_id " .
    "JOIN ezcontentclass c ON c.id = o.contentclass_id " .
    "WHERE c.identifier = 'time_indexed_role' LIMIT 1"
);

if (!empty($nodeRows)) {
    $parentNodeId = (int)$nodeRows[0]['parent_node_id'];
} else {
    // Cerca nodo ruoli per nome
    $namedRows = $db->arrayQuery(
        "SELECT n.node_id FROM ezcontentobject_tree n " .
        "JOIN ezcontentobject o ON o.id = n.contentobject_id " .
        "WHERE LOWER(o.name) LIKE '%ruoli%' OR LOWER(o.name) LIKE '%incarichi%' " .
        "LIMIT 1"
    );
    // Fallback: nodo 2 (root content)
    $parentNodeId = !empty($namedRows) ? (int)$namedRows[0]['node_id'] : 2;
}

echo "Nodo padre: $parentNodeId\n";

// ── Crea time_indexed_role via eZContentClass::instantiate() ──────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$label        = 'Ruolo Test E2E ' . $uniqueSuffix;

$user      = eZUser::fetchByName('admin');
$ownerId   = $user ? $user->attribute('contentobject_id') : 14;
$sectionId = eZSection::fetchByIdentifier('standard') ? eZSection::fetchByIdentifier('standard')->attribute('id') : 1;

$contentObject = $class->instantiate($ownerId, $sectionId, false, 'ita-IT');
if (!$contentObject) {
    echo "\033[33m[SKIP]\033[0m Impossibile istanziare time_indexed_role\n";
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
    if ($attr->contentClassAttributeIdentifier() === 'label') {
        $attr->fromString($label);
        $attr->store();
    }
}

// Pubblica
eZOperationHandler::execute(
    'content', 'publish',
    ['object_id' => $contentObject->attribute('id'), 'version' => 1]
);

$objectId = $contentObject->attribute('id');
echo "Pubblicato ruolo id=$objectId — \"$label\"\n\n";

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione ruolo');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────

e2e_verify_kafka_message($message, $label, 'label');

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('time_indexed_role', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

echo "\nCleanup: cancello ruolo id=$objectId...\n";
eZContentObjectOperations::remove($objectId);
echo "Rimosso.\n";

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
