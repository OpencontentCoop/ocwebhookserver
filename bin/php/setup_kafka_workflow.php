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
 * Aggiorna l'URL kafka:// se broker/topic sono cambiati nella configurazione.
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
$options = $script->getOptions();
$script->initialize();

// ── Verifica che Kafka sia abilitato ─────────────────────────────────────────

$ini = eZINI::instance('webhook.ini');
if ($ini->variable('KafkaSettings', 'Enabled') !== 'enabled') {
    echo "[skip] KafkaSettings.Enabled non è 'enabled' — nessuna azione\n";
    $script->shutdown(0);
    exit(0);
}

// ── Leggi configurazione Kafka ────────────────────────────────────────────────

$brokers = $ini->variable('KafkaSettings', 'Brokers');
$brokers = is_array($brokers) ? $brokers : [];
$topic   = $ini->variable('KafkaSettings', 'Topic');

// ── Esegui setup tramite service ─────────────────────────────────────────────

require_once dirname(__FILE__) . '/../../classes/ocwebhookkafkasetupservice.php';

$service = new OCWebHookKafkaSetupService(eZDB::instance());
$result  = $service->run($brokers, $topic);

foreach ($result['log'] as $line) {
    echo $line . "\n";
}

$exitCode = $result['ok'] ? 0 : 1;
$script->shutdown($exitCode);
exit($exitCode);
