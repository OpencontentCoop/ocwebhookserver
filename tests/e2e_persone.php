<?php

/**
 * Test E2E: crea e pubblica una persona pubblica (PublicPerson) via eZ PHP API e verifica Kafka.
 *
 * has_role è openparole (campo calcolato, non settabile). Si usa eZContentClass::instantiate()
 * per creare il content object direttamente, bypassing il validatore REST.
 *
 * SKIP se il content type public_person non esiste nell'installazione.
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Persone pubbliche (PublicPerson) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Trova nodo padre ──────────────────────────────────────────────────────────

$db = eZDB::instance();

// Cerca il nodo dell'area personale-amministrativo
$nodeRows = $db->arrayQuery(
    "SELECT n.node_id FROM ezcontentobject_tree n " .
    "JOIN ezcontentobject o ON o.id = n.contentobject_id " .
    "WHERE LOWER(o.name) LIKE '%personale%' OR LOWER(o.name) LIKE '%politici%' " .
    "LIMIT 1"
);

// Fallback: usa il nodo 2 (root content)
$parentNodeId = !empty($nodeRows) ? (int)$nodeRows[0]['node_id'] : 2;
echo "Nodo padre: $parentNodeId\n";

// ── Crea public_person via eZContentClass::instantiate() ──────────────────────

$class = eZContentClass::fetchByIdentifier('public_person');
if (!$class) {
    echo "\033[33m[SKIP]\033[0m Content type public_person non trovato\n";
    $script->shutdown(0);
    exit(0);
}

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$givenName    = 'Mario Test';
$familyName   = 'E2E ' . $uniqueSuffix;

// Crea oggetto
$user      = eZUser::fetchByName('admin');
$ownerId   = $user ? $user->attribute('contentobject_id') : 14;
$sectionId = eZSection::fetchByIdentifier('standard') ? eZSection::fetchByIdentifier('standard')->attribute('id') : 1;

$contentObject = $class->instantiate($ownerId, $sectionId, false, 'ita-IT');
if (!$contentObject) {
    echo "\033[33m[SKIP]\033[0m Impossibile istanziare public_person\n";
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
    $identifier = $attr->contentClassAttributeIdentifier();
    if ($identifier === 'given_name') {
        $attr->fromString($givenName);
        $attr->store();
    } elseif ($identifier === 'family_name') {
        $attr->fromString($familyName);
        $attr->store();
    }
}

// Pubblica
$operationResult = eZOperationHandler::execute(
    'content', 'publish',
    ['object_id' => $contentObject->attribute('id'), 'version' => 1]
);

$objectId = $contentObject->attribute('id');
echo "Pubblicata persona id=$objectId — \"$givenName $familyName\"\n\n";

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione persona');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────

$payload = json_decode($message->payload, true);
assert_true($payload !== null, 'Payload JSON valido');

$data = [];
foreach ($payload['entity']['data'] as $lang => $d) {
    $data = $d;
    break;
}

assert_true(isset($data['given_name']) && $data['given_name'] === $givenName,
    'entity.data.given_name = ' . $givenName);
assert_true(isset($data['family_name']) && $data['family_name'] === $familyName,
    'entity.data.family_name = ' . $familyName);

save_kafka_artifact('public_person', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

echo "\nCleanup: cancello persona id=$objectId...\n";
eZContentObjectOperations::remove($objectId);
echo "Rimossa.\n";

e2e_results($script);
