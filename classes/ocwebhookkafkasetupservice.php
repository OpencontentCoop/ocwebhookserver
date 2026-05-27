<?php

/**
 * Service che incapsula la logica idempotente di configurazione workflow/webhook Kafka.
 *
 * Estratto da bin/php/setup_kafka_workflow.php per consentire i test unitari.
 * Non dipende da eZScript, exit() o echo — restituisce un array di log.
 *
 * Utilizzo:
 *   $service = new OCWebHookKafkaSetupService(eZDB::instance());
 *   $result  = $service->run(['redpanda:9092'], 'cms');
 *   foreach ($result['log'] as $line) { echo $line . "\n"; }
 *   if (!$result['ok']) { exit(1); }
 *
 * Utilizzo dall'installer (php_callable):
 *   type: php_callable
 *   identifier: 'OCWebHookKafkaSetupService::setupFromIni'
 */
class OCWebHookKafkaSetupService
{
    /** @var object eZDB instance */
    private $db;

    /** @param object $db eZDB-compatible instance */
    public function __construct($db)
    {
        $this->db = $db;
    }

    /**
     * Esegue il setup idempotente:
     *   1. Crea il workflow eZ post_publish → WorkflowWebHookType (se non esiste)
     *   2. Registra il webhook kafka:// nell'outbox (se non esiste) o aggiorna l'URL
     *
     * @param  array  $brokers Broker Kafka (es. ['redpanda:9092'])
     * @param  string $topic   Kafka topic
     * @return array           ['ok' => bool, 'log' => string[]]
     */
    public function run(array $brokers, $topic)
    {
        $log = [];

        // ── Step 1: workflow eZ Publish ───────────────────────────────────────

        if ($this->workflowExists()) {
            $log[] = '[ok] Workflow post_publish → WorkflowWebHookType già configurato';
        } else {
            if (!$this->createWorkflow($log)) {
                return ['ok' => false, 'log' => $log];
            }
        }

        // ── Step 2: webhook kafka:// ──────────────────────────────────────────

        if (empty($brokers) || empty($topic)) {
            $log[] = '[skip] KafkaSettings.Brokers o Topic non configurati — webhook kafka:// non creato';
            return ['ok' => true, 'log' => $log];
        }

        $this->setupWebhook($brokers, $topic, $log);

        return ['ok' => true, 'log' => $log];
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    /**
     * Verifica se esiste già un workflow event_workflowwebhook collegato a post_publish.
     */
    private function workflowExists()
    {
        $check = $this->db->arrayQuery(
            "SELECT COUNT(*) AS c FROM ezworkflow_event " .
            "WHERE workflow_type_string = 'event_workflowwebhook' " .
            "AND workflow_id IN (" .
            "  SELECT workflow_id FROM eztrigger " .
            "  WHERE module_name = 'content' AND function_name = 'publish' AND connect_type = 'a'" .
            ")"
        );
        return (int)($check[0]['c'] ?? 0) > 0;
    }

    /**
     * Crea ezworkflow + ezworkflow_event + eztrigger.
     * Aggiunge messaggi a $log e restituisce false in caso di errore.
     */
    private function createWorkflow(array &$log)
    {
        $now  = time();
        $name = 'OCWebHookServer - post_publish';

        $this->db->query(
            "INSERT INTO ezworkflow " .
            "  (creator_id, modifier_id, created, modified, name, is_enabled, event_count) " .
            "VALUES (14, 14, $now, $now, '$name', 1, 1)"
        );

        $wfRes = $this->db->arrayQuery(
            "SELECT id FROM ezworkflow WHERE name = '$name' ORDER BY id DESC LIMIT 1"
        );
        $workflowId = (int)($wfRes[0]['id'] ?? 0);

        if ($workflowId === 0) {
            $log[] = '[error] INSERT INTO ezworkflow fallito';
            return false;
        }

        $this->db->query(
            "INSERT INTO ezworkflow_event " .
            "  (workflow_id, version, placement, workflow_type_string) " .
            "VALUES ($workflowId, 0, 1, 'event_workflowwebhook')"
        );

        $this->db->query(
            "INSERT INTO eztrigger " .
            "  (module_name, function_name, connect_type, name, workflow_id) " .
            "VALUES ('content', 'publish', 'a', 'post_publish', $workflowId)"
        );

        $trigRes = $this->db->arrayQuery(
            "SELECT id FROM eztrigger " .
            "WHERE workflow_id = $workflowId AND name = 'post_publish' LIMIT 1"
        );
        $triggerId = (int)($trigRes[0]['id'] ?? 0);

        if ($triggerId === 0) {
            $log[] = '[error] INSERT INTO eztrigger fallito';
            return false;
        }

        $log[] = "[ok] Workflow configurato: ezworkflow.id=$workflowId, eztrigger.id=$triggerId";
        return true;
    }

    /**
     * Cerca webhook kafka:// esistente per il trigger post_publish_ocopendata.
     * Se trovato: controlla se l'URL è aggiornato o lo aggiorna.
     * Se non trovato: inserisce ocwebhook + ocwebhook_trigger_link.
     */
    private function setupWebhook(array $brokers, $topic, array &$log)
    {
        $kafkaUrl          = 'kafka://' . implode(',', $brokers) . '/' . $topic;
        $webhookName       = 'kafka-' . $topic;
        $triggerIdentifier = 'post_publish_ocopendata';

        $existingWebhook = $this->db->arrayQuery(
            "SELECT w.id, w.url FROM ocwebhook w " .
            "JOIN ocwebhook_trigger_link tl ON tl.webhook_id = w.id " .
            "WHERE tl.trigger_identifier = '" . $this->db->escapeString($triggerIdentifier) . "' " .
            "AND w.url LIKE 'kafka://%' LIMIT 1"
        );

        if (!empty($existingWebhook)) {
            $webhookId  = (int)$existingWebhook[0]['id'];
            $currentUrl = $existingWebhook[0]['url'];
            if ($currentUrl === $kafkaUrl) {
                $log[] = "[ok] Webhook kafka:// già presente e aggiornato (id=$webhookId, url=$kafkaUrl)";
            } else {
                $this->db->query(
                    "UPDATE ocwebhook SET url = '" . $this->db->escapeString($kafkaUrl) . "', " .
                    "name = '" . $this->db->escapeString($webhookName) . "' " .
                    "WHERE id = $webhookId"
                );
                $log[] = "[ok] Webhook kafka:// aggiornato (id=$webhookId): $currentUrl → $kafkaUrl";
            }
            return;
        }

        $now = time();
        $this->db->query(
            "INSERT INTO ocwebhook (name, url, enabled, retry_enabled, method, content_type, created_at) " .
            "VALUES (" .
            "'" . $this->db->escapeString($webhookName) . "', " .
            "'" . $this->db->escapeString($kafkaUrl) . "', " .
            "1, 1, 'post', 'application/json', $now)"
        );

        $whRow = $this->db->arrayQuery(
            "SELECT id FROM ocwebhook WHERE url = '" . $this->db->escapeString($kafkaUrl) . "' LIMIT 1"
        );
        $webhookId = (int)($whRow[0]['id'] ?? 0);

        if ($webhookId === 0) {
            $log[] = '[error] INSERT INTO ocwebhook fallito';
            return;
        }

        $this->db->query(
            "INSERT INTO ocwebhook_trigger_link (webhook_id, trigger_identifier) " .
            "VALUES ($webhookId, '" . $this->db->escapeString($triggerIdentifier) . "')"
        );

        $log[] = "[ok] Webhook kafka:// registrato: ocwebhook.id=$webhookId, url=$kafkaUrl";
    }

    /**
     * Verifica le precondizioni operative del Piano C.
     * - eZSolr installato → CRITICO se OCSearchEngine è SearchEngine
     * - DelayedIndexing=disabled → CRITICO (eventi sarebbero deferiti al cron)
     * - SearchEngine = OCSearchEngine → WARNING (se non lo è, il workflow handler emetterà in fallback)
     *
     * @param array      $log      log array passato per riferimento
     * @param object|null $siteIni  istanza INI per site.ini (null = usa eZINI::instance)
     * @return bool  true se OK (solo warning), false se ci sono errori critici
     */
    public function checkPreconditions(array &$log, $siteIni = null)
    {
        $ok = true;

        // 1. eZSolr deve esistere
        if (!class_exists('eZSolr')) {
            $log[] = '[fail] eZSolr non trovato — Piano C richiede eZ Find/eZSolr installato.';
            $ok = false;
        } else {
            $log[] = '[ok] eZSolr caricabile';
        }

        // 2. DelayedIndexing deve essere disabled
        if ($siteIni === null) {
            $siteIni = eZINI::instance('site.ini');
        }
        $delayed = $siteIni->variable('SearchSettings', 'DelayedIndexing');
        if ($delayed !== 'disabled') {
            $log[] = "[fail] [SearchSettings] DelayedIndexing='$delayed' — Piano C richiede 'disabled'. " .
                     "Con DelayedIndexing attivo, gli eventi Kafka sarebbero deferiti al cron ezfindexsubtree.";
            $ok = false;
        } else {
            $log[] = '[ok] DelayedIndexing=disabled';
        }

        // 3. SearchEngine attivo
        $searchEngine = $siteIni->variable('SearchSettings', 'SearchEngine');
        if ($searchEngine === 'OCSearchEngine') {
            $log[] = '[ok] SearchEngine=OCSearchEngine';
        } else {
            $log[] = "[warn] SearchEngine='$searchEngine' (atteso 'OCSearchEngine'). " .
                     "Il workflow handler emetterà in fallback — verifica site.ini merge order.";
            // Non blocca: il fallback funziona, ma è un sintomo di configurazione errata.
        }

        return $ok;
    }

    // ── Installer entry-point ─────────────────────────────────────────────────

    /**
     * Entry-point per l'installer ocinstall (step di tipo php_callable).
     *
     * Legge la configurazione da webhook.ini; non fa nulla se
     * KafkaSettings.Enabled != 'enabled'. Lancia RuntimeException se Kafka
     * è abilitato ma Brokers o Topic sono vuoti (misconfiguration).
     *
     * @param object|null $stepInstaller  Istanza PhpCallable (passata da ocinstall)
     */
    public static function setupFromIni($stepInstaller = null)
    {
        $ini     = eZINI::instance('webhook.ini');
        $enabled = $ini->variable('KafkaSettings', 'Enabled');

        if ($enabled !== 'enabled') {
            return;
        }

        $brokers = $ini->variable('KafkaSettings', 'Brokers');
        $brokers = is_array($brokers) ? array_filter(array_values($brokers)) : [];

        $topic = (string)$ini->variable('KafkaSettings', 'Topic');

        if (empty($brokers)) {
            throw new RuntimeException(
                'KafkaSettings.Enabled=enabled ma KafkaSettings.Brokers è vuoto — misconfiguration'
            );
        }
        if ($topic === '') {
            throw new RuntimeException(
                'KafkaSettings.Enabled=enabled ma KafkaSettings.Topic è vuoto — misconfiguration'
            );
        }

        $service = new self(eZDB::instance());

        // Verifica precondizioni Piano C prima del setup
        $precondLog = [];
        if (!$service->checkPreconditions($precondLog)) {
            $logger = null;
            if ($stepInstaller !== null && method_exists($stepInstaller, 'getLogger')) {
                $logger = $stepInstaller->getLogger();
            }
            foreach ($precondLog as $line) {
                if ($logger !== null) {
                    $logger->warning($line);
                } else {
                    eZCLI::instance()->output($line);
                }
            }
            throw new RuntimeException(
                "Setup abort: precondizioni Piano C non soddisfatte. Vedi log per dettagli."
            );
        }

        $result  = $service->run($brokers, $topic);

        $logger = null;
        if ($stepInstaller !== null && method_exists($stepInstaller, 'getLogger')) {
            $logger = $stepInstaller->getLogger();
        }
        foreach (array_merge($precondLog, $result['log']) as $line) {
            if ($logger !== null) {
                $logger->info($line);
            } else {
                eZCLI::instance()->output($line);
            }
        }

        if (!$result['ok']) {
            throw new RuntimeException('OCWebHookKafkaSetupService::setupFromIni failed');
        }
    }
}
