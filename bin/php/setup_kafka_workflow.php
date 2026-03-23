<?php

/**
 * Configura automaticamente il workflow eZ Publish per post_publish → Kafka
 * e registra il webhook kafka:// nell'outbox di ocwebhookserver.
 *
 * Se KafkaSettings.Enabled=enabled in webhook.ini e non sono ancora presenti
 * nel DB, crea:
 *   ezworkflow           — workflow con evento WorkflowWebHookType
 *   ezworkflow_event     — evento di tipo event_workflowwebhook
 *   eztrigger            — collega il workflow al trigger post_publish di eZ
 *   ocwebhook            — webhook con url kafka://<brokers>/<topic>
 *   ocwebhook_trigger_link — collega il webhook al trigger post_publish_ocopendata
 *
 * Idempotente: le parti già presenti vengono saltate.
 *
 * Uso:
 *   php extension/ocwebhookserver/bin/php/setup_kafka_workflow.php \
 *       --allow-root-user -sbackend
 */

require 'autoload.php';

set_time_limit(0);

$script = eZScript::instance([
    'description'    => 'OCWebHookServer — setup Kafka post_publish workflow',
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
]);
$script->startup();
$script->initialize();

// ── Verifica che Kafka sia abilitato ─────────────────────────────────────────

$ini = eZINI::instance('webhook.ini');
if ($ini->variable('KafkaSettings', 'Enabled') !== 'enabled') {
    echo "[skip] KafkaSettings.Enabled non è 'enabled' — nessuna azione\n";
    $script->shutdown(0);
    exit(0);
}

// ── Verifica idempotente ──────────────────────────────────────────────────────

$db = eZDB::instance();

$check = $db->arrayQuery(
    "SELECT COUNT(*) AS c FROM ezworkflow_event " .
    "WHERE workflow_type_string = 'event_workflowwebhook' " .
    "AND workflow_id IN (" .
    "  SELECT workflow_id FROM eztrigger " .
    "  WHERE module_name = 'content' AND function_name = 'publish' AND connect_type = 'a'" .
    ")"
);

$workflowExists = (int)($check[0]['c'] ?? 0) > 0;

if ($workflowExists) {
    echo "[ok] Workflow post_publish → WorkflowWebHookType già configurato\n";
}

// ── Crea il workflow (se non esiste) ─────────────────────────────────────────
//
// eZDB::arrayQuery() accetta solo SELECT: non usiamo INSERT...RETURNING né
// lastSerialID(). Dopo l'INSERT recuperiamo l'ID con SELECT WHERE name.

$now  = time();
$name = 'OCWebHookServer - post_publish';

if ($workflowExists) {
    // salta la creazione del workflow, vai direttamente al webhook
    goto setup_webhook;
}

$db->query(
    "INSERT INTO ezworkflow " .
    "  (creator_id, modifier_id, created, modified, name, is_enabled, event_count) " .
    "VALUES (14, 14, $now, $now, '$name', 1, 1)"
);

$wfRes = $db->arrayQuery(
    "SELECT id FROM ezworkflow WHERE name = '$name' ORDER BY id DESC LIMIT 1"
);
$workflowId = (int)($wfRes[0]['id'] ?? 0);

if ($workflowId === 0) {
    echo "[error] INSERT INTO ezworkflow fallito — vedere i log di eZ Publish\n";
    $script->shutdown(1);
    exit(1);
}

// ── Crea l'evento workflow (WorkflowWebHookType) ──────────────────────────────

$db->query(
    "INSERT INTO ezworkflow_event " .
    "  (workflow_id, version, placement, workflow_type_string) " .
    "VALUES ($workflowId, 0, 1, 'event_workflowwebhook')"
);

// ── Connette il workflow al trigger post_publish di eZ Publish ────────────────

$db->query(
    "INSERT INTO eztrigger " .
    "  (module_name, function_name, connect_type, name, workflow_id) " .
    "VALUES ('content', 'publish', 'a', 'post_publish', $workflowId)"
);

$trigRes = $db->arrayQuery(
    "SELECT id FROM eztrigger " .
    "WHERE workflow_id = $workflowId AND name = 'post_publish' LIMIT 1"
);
$triggerId = (int)($trigRes[0]['id'] ?? 0);

if ($triggerId === 0) {
    echo "[error] INSERT INTO eztrigger fallito\n";
    $script->shutdown(1);
    exit(1);
}

echo "[ok] Workflow configurato: ezworkflow.id=$workflowId, eztrigger.id=$triggerId\n";

// ── Registra il webhook kafka:// nell'outbox ──────────────────────────────────
setup_webhook:

$brokers = $ini->variable('KafkaSettings', 'Brokers');
$brokers = is_array($brokers) ? $brokers : [];
$topic   = $ini->variable('KafkaSettings', 'Topic');

if (empty($brokers) || empty($topic)) {
    echo "[skip] KafkaSettings.Brokers o Topic non configurati — webhook kafka:// non creato\n";
    $script->shutdown(0);
    exit(0);
}

$kafkaUrl        = 'kafka://' . implode(',', $brokers) . '/' . $topic;
$webhookName     = 'kafka-' . $topic;
$triggerIdentifier = 'post_publish_ocopendata';

$existingWebhook = $db->arrayQuery(
    "SELECT id FROM ocwebhook WHERE url = '" . $db->escapeString($kafkaUrl) . "' LIMIT 1"
);

if (!empty($existingWebhook)) {
    echo "[ok] Webhook kafka:// già presente (id={$existingWebhook[0]['id']}, url=$kafkaUrl)\n";
    $script->shutdown(0);
    exit(0);
}

$db->query(
    "INSERT INTO ocwebhook (name, url, enabled, retry_enabled, method, content_type, created_at) " .
    "VALUES (" .
    "'" . $db->escapeString($webhookName) . "', " .
    "'" . $db->escapeString($kafkaUrl) . "', " .
    "1, 1, 'post', 'application/json', $now)"
);

$whRow = $db->arrayQuery(
    "SELECT id FROM ocwebhook WHERE url = '" . $db->escapeString($kafkaUrl) . "' LIMIT 1"
);
$webhookId = (int)($whRow[0]['id'] ?? 0);

if ($webhookId === 0) {
    echo "[error] INSERT INTO ocwebhook fallito\n";
    $script->shutdown(1);
    exit(1);
}

$db->query(
    "INSERT INTO ocwebhook_trigger_link (webhook_id, trigger_identifier) " .
    "VALUES ($webhookId, '" . $db->escapeString($triggerIdentifier) . "')"
);

echo "[ok] Webhook kafka:// registrato: ocwebhook.id=$webhookId, url=$kafkaUrl\n";

$script->shutdown(0);
