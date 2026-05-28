<?php

/**
 * Test E2E: crea e pubblica una pagina trasparenza (pagina_trasparenza) via eZ PHP API e verifica Kafka.
 *
 * Nessun endpoint REST dedicato — il test usa eZContentClass::instantiate() per creare
 * il content object direttamente, bypassing il validatore REST.
 *
 * Nota: il campo si chiama 'titolo' nel CMS ma nel payload Kafka viene rinominato in 'title'
 * dal FieldMap — la verifica usa titleField='title'.
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_trasparenza.php
 *
 * SKIP se il content type pagina_trasparenza non esiste nell'installazione.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Pagine Trasparenza (pagina_trasparenza) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Verifica che il content type esista ───────────────────────────────────────

$class = eZContentClass::fetchByIdentifier('pagina_trasparenza');
if (!$class) {
    echo "\033[33m[SKIP]\033[0m Content type pagina_trasparenza non trovato\n";
    $script->shutdown(0);
    exit(0);
}

// ── Trova nodo padre ──────────────────────────────────────────────────────────

$db = eZDB::instance();

// Cerca un nodo trasparenza
$nodeRows = $db->arrayQuery(
    "SELECT n.node_id FROM ezcontentobject_tree n " .
    "JOIN ezcontentobject o ON o.id = n.contentobject_id " .
    "WHERE LOWER(o.name) LIKE '%trasparenza%' " .
    "LIMIT 1"
);

// Fallback: nodo 2 (root content)
$parentNodeId = !empty($nodeRows) ? (int)$nodeRows[0]['node_id'] : 2;
echo "Nodo padre: $parentNodeId\n";

// ── Crea pagina_trasparenza via eZContentClass::instantiate() ─────────────────

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title        = 'Trasparenza Test E2E ' . $uniqueSuffix;

$user      = eZUser::fetchByName('admin');
$ownerId   = $user ? $user->attribute('contentobject_id') : 14;
$sectionId = eZSection::fetchByIdentifier('standard') ? eZSection::fetchByIdentifier('standard')->attribute('id') : 1;

$contentObject = $class->instantiate($ownerId, $sectionId, false, 'ita-IT');
if (!$contentObject) {
    echo "\033[33m[SKIP]\033[0m Impossibile istanziare pagina_trasparenza\n";
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

// Setta attributi — il campo CMS si chiama 'titolo', nel payload Kafka viene mappato in 'title'
$version    = $contentObject->version(1);
$attributes = $version->contentObjectAttributes('ita-IT');

foreach ($attributes as $attr) {
    if ($attr->contentClassAttributeIdentifier() === 'titolo') {
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
echo "Pubblicata pagina trasparenza id=$objectId — \"$title\"\n\n";

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione pagina trasparenza');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────
// Il campo 'titolo' del CMS viene rinominato in 'title' dal FieldMap nel payload Kafka

e2e_verify_kafka_message($message, $title, 'title');

// ── Salva artifact ────────────────────────────────────────────────────────────

save_kafka_artifact('pagina_trasparenza', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

echo "\nCleanup: cancello pagina trasparenza id=$objectId...\n";
eZContentObjectOperations::remove($objectId);
echo "Rimossa.\n";

// ── Risultati ─────────────────────────────────────────────────────────────────

e2e_results($script);
