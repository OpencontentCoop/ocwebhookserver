<?php

/**
 * Test E2E: crea un progetto pubblico (PublicProject) via eZ PHP API e verifica Kafka.
 *
 * holds_role_in_time è required e richiede time_indexed_role con person+entity configurati.
 * Si usa eZContentClass::instantiate() per bypassare il validatore REST.
 *
 * SKIP se il content type public_project non è installato.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Progetti (PublicProject) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Trova nodo padre ──────────────────────────────────────────────────────────

$db = eZDB::instance();
$nodeRows = $db->arrayQuery(
    "SELECT n.node_id FROM ezcontentobject_tree n " .
    "JOIN ezcontentobject o ON o.id = n.contentobject_id " .
    "WHERE LOWER(o.name) LIKE '%progett%' " .
    "LIMIT 1"
);
$parentNodeId = !empty($nodeRows) ? (int)$nodeRows[0]['node_id'] : 2;
echo "Nodo padre: $parentNodeId\n";

// ── Crea public_project via eZ PHP API ────────────────────────────────────────

$class = eZContentClass::fetchByIdentifier('public_project');
if (!$class) {
    echo "\033[33m[SKIP]\033[0m Content type public_project non trovato\n";
    $script->shutdown(0);
    exit(0);
}

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Progetto Test E2E ' . $uniqueSuffix;

$user      = eZUser::fetchByName('admin');
$ownerId   = $user ? $user->attribute('contentobject_id') : 14;
$sectionId = 1;

$contentObject = $class->instantiate($ownerId, $sectionId, false, 'ita-IT');
if (!$contentObject) {
    echo "\033[33m[SKIP]\033[0m Impossibile istanziare public_project\n";
    $script->shutdown(0);
    exit(0);
}

$nodeAssignment = eZNodeAssignment::create([
    'contentobject_id'      => $contentObject->attribute('id'),
    'contentobject_version' => 1,
    'parent_node'           => $parentNodeId,
    'is_main'               => 1,
    'sort_field'            => eZContentObjectTreeNode::SORT_FIELD_PUBLISHED,
    'sort_order'            => eZContentObjectTreeNode::SORT_ORDER_DESC,
]);
$nodeAssignment->store();

$version    = $contentObject->version(1);
$attributes = $version->contentObjectAttributes('ita-IT');

$scalars = [
    'title'        => $title,
    'identifier'   => 'prj-e2e-' . $uniqueSuffix,
    'abstract'     => 'Progetto di test automatico E2E Kafka.',
    'description'  => '<p>Descrizione del progetto di test ' . $uniqueSuffix . '</p>',
];

foreach ($attributes as $attr) {
    $id = $attr->contentClassAttributeIdentifier();
    if (isset($scalars[$id])) {
        $attr->fromString($scalars[$id]);
        $attr->store();
    }
}
$version->store();

$operationResult = eZOperationHandler::execute(
    'content', 'publish',
    ['object_id' => $contentObject->attribute('id'), 'version' => 1]
);

$objectId = $contentObject->attribute('id');
echo "Pubblicato progetto id=$objectId — \"$title\"\n\n";

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione progetto');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────

$payload = json_decode($message->payload, true);
assert_true($payload !== null, 'Payload JSON valido');

$data = [];
foreach ($payload['entity']['data'] as $lang => $d) { $data = $d; break; }

// Il FieldMap rinomina 'name' → usa il campo title o name a seconda dell'installazione
$nameField = isset($data['name']) ? 'name' : (isset($data['title']) ? 'title' : null);
assert_true($nameField !== null && $data[$nameField] === $title,
    "entity.data.$nameField = $title");

save_kafka_artifact('public_project', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

echo "\nCleanup: cancello progetto id=$objectId...\n";
eZContentObjectOperations::remove($objectId);
echo "Rimosso.\n";

e2e_results($script);
