<?php

/**
 * Test E2E: crea un servizio (PublicService) via eZ PHP API e verifica il messaggio Kafka.
 *
 * public_service ha 17 campi required con dipendenze circolari (produces_output richiede
 * oggetti di classe output, holds_role_in_time richiede role con person+entity configurati).
 * Si usa eZContentClass::instantiate() per bypassare il validatore REST, impostando solo
 * i campi scalari (name, abstract, type, identifier, ecc.).
 *
 * Eseguire dall'interno del container:
 *   docker compose exec -T app php /var/www/html/extension/ocwebhookserver/tests/e2e_servizi.php
 */

require_once __DIR__ . '/e2e_helpers.php';

global $script, $BROKER, $TOPIC, $APP_HOST, $authHeader, $PASSED, $FAILED;

echo "=== E2E Test: Servizi (PublicService) ===\n\n";

e2e_check_trigger($script);

$startOffset = get_end_offset($BROKER, $TOPIC);
echo "Kafka offset before publish: $startOffset\n\n";

// ── Trova nodo padre ──────────────────────────────────────────────────────────

$db = eZDB::instance();

$nodeRows = $db->arrayQuery(
    "SELECT n.node_id FROM ezcontentobject_tree n " .
    "JOIN ezcontentobject o ON o.id = n.contentobject_id " .
    "WHERE LOWER(o.name) LIKE '%servizi%' " .
    "LIMIT 1"
);
$parentNodeId = !empty($nodeRows) ? (int)$nodeRows[0]['node_id'] : 2;
echo "Nodo padre: $parentNodeId\n";

// ── Crea public_service via eZ PHP API ────────────────────────────────────────

$class = eZContentClass::fetchByIdentifier('public_service');
if (!$class) {
    echo "\033[33m[SKIP]\033[0m Content type public_service non trovato\n";
    $script->shutdown(0);
    exit(0);
}

$uniqueSuffix = date('Ymd-His') . '-' . substr(md5(uniqid()), 0, 6);
$title = 'Servizio Test E2E ' . $uniqueSuffix;

$user      = eZUser::fetchByName('admin');
$ownerId   = $user ? $user->attribute('contentobject_id') : 14;
$sectionId = 1;

$contentObject = $class->instantiate($ownerId, $sectionId, false, 'ita-IT');
if (!$contentObject) {
    echo "\033[33m[SKIP]\033[0m Impossibile istanziare public_service\n";
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

// Imposta i campi scalari
$scalars = [
    'name'       => $title,
    'identifier' => 'srv-e2e-' . $uniqueSuffix,
    'abstract'   => 'Servizio di test automatico E2E Kafka — ' . $uniqueSuffix,
    'audience'   => '<p>Cittadini residenti nel territorio comunale.</p>',
    'how_to'     => '<p>Rivolgersi allo sportello o presentare domanda online.</p>',
    'has_input'  => '<p>Documento di identità valido, codice fiscale.</p>',
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
echo "Pubblicato servizio id=$objectId — \"$title\"\n\n";

// ── Consume Kafka ─────────────────────────────────────────────────────────────

echo "Attendo messaggio Kafka (max 15s)...\n";
$message = consume_message($BROKER, $TOPIC, $startOffset, 15000);

assert_true($message !== null, 'Messaggio Kafka ricevuto dopo pubblicazione servizio');

if ($message === null) {
    e2e_results($script);
}

// ── Verifica payload ──────────────────────────────────────────────────────────

$payload = json_decode($message->payload, true);
assert_true($payload !== null, 'Payload JSON valido');

$data = [];
foreach ($payload['entity']['data'] as $lang => $d) { $data = $d; break; }

assert_true(isset($data['name']) && $data['name'] === $title,
    'entity.data.name = ' . $title);

save_kafka_artifact('public_service', $uniqueSuffix, $message);

// ── Cleanup ───────────────────────────────────────────────────────────────────

echo "\nCleanup: cancello servizio id=$objectId...\n";
eZContentObjectOperations::remove($objectId);
echo "Rimosso.\n";

e2e_results($script);
