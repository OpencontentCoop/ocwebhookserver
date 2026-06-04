<?php

/**
 * Test unitari per OCWebHookKafkaSetupService.
 *
 * Copre i tre scenari del setup idempotente:
 *   1. Prima esecuzione: crea workflow + webhook kafka://
 *   2. Riesecuzione idempotente: salta tutto (già configurato, stesso URL)
 *   3. Cambio configurazione: aggiorna l'URL del webhook esistente
 *
 * Non richiede DB, eZ Publish bootstrap, né broker Kafka — usa uno SpyDB.
 *
 * Uso:
 *   php tests/SetupKafkaWorkflowTest.php
 */

// ─────────────────────────────────────────────────────────────────────────────
// SpyDB: stub eZDB che registra query e restituisce dati configurabili
// ─────────────────────────────────────────────────────────────────────────────

class SpyDB
{
    /** @var string[] Query SQL eseguite */
    public $queries = [];

    /**
     * Mappa: stringa contenuta nella query → array di righe restituito.
     * La prima chiave che matcha viene usata.
     * @var array<string,array>
     */
    public $results = [];

    public function query($sql)
    {
        $this->queries[] = $sql;
    }

    public function arrayQuery($sql)
    {
        $this->queries[] = $sql;
        foreach ($this->results as $pattern => $rows) {
            if (strpos($sql, $pattern) !== false) {
                return $rows;
            }
        }
        return [];
    }

    public function escapeString($s)
    {
        return addslashes($s);
    }

    /** Restituisce true se almeno una query contiene $needle */
    public function hasQuery($needle)
    {
        foreach ($this->queries as $q) {
            if (strpos($q, $needle) !== false) {
                return true;
            }
        }
        return false;
    }

    /** Conta le query che contengono $needle */
    public function countQuery($needle)
    {
        $n = 0;
        foreach ($this->queries as $q) {
            if (strpos($q, $needle) !== false) {
                $n++;
            }
        }
        return $n;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Stubs globali richiesti da checkPreconditions() (chiamato da run())
// ─────────────────────────────────────────────────────────────────────────────

// eZSolr: presenza richiesta dal check 1 — stub minimo
class eZSolr {}

// eZINI: stub configurabile; default = valori Piano C corretti per test 1-4
class eZINI
{
    private static $vars = [
        'SearchSettings' => [
            'DelayedIndexing' => 'disabled',
            'SearchEngine'    => 'OCSearchEngine',
        ],
    ];

    public static function setVars(array $vars) { self::$vars = $vars; }

    public static function instance($file = 'site.ini') { return new self(); }

    public function variable($section, $key)
    {
        return self::$vars[$section][$key] ?? null;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Carica la classe da testare
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../classes/ocwebhookkafkasetupservice.php';

// ─────────────────────────────────────────────────────────────────────────────
// Helper di asserzione
// ─────────────────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void   { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_true(bool $v, string $t, string $r = ''): void  { $v ? ok($t) : fail($t, $r); }
function assert_false(bool $v, string $t, string $r = ''): void { (!$v) ? ok($t) : fail($t, $r); }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) {
        ok($t);
    } else {
        fail($t, sprintf("expected %s, got %s. %s", var_export($b, true), var_export($a, true), $r));
    }
}
function assert_contains(string $needle, string $haystack, string $t): void
{
    strpos($haystack, $needle) !== false ? ok($t) : fail($t, "\"$needle\" not found in \"$haystack\"");
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1: Prima esecuzione — workflow e webhook non esistono → crea tutto
// ─────────────────────────────────────────────────────────────────────────────

echo "\n── TEST 1: prima esecuzione ─────────────────────────────────────────────\n";

$db = new SpyDB();

// workflowExists() → non trovato (c=0)
$db->results['COUNT(*) AS c FROM ezworkflow_event'] = [['c' => 0]];

// SELECT id FROM ezworkflow dopo INSERT → restituisce id=42
$db->results['SELECT id FROM ezworkflow WHERE name'] = [['id' => 42]];

// SELECT id FROM eztrigger dopo INSERT → restituisce id=7
$db->results['SELECT id FROM eztrigger'] = [['id' => 7]];

// webhook esistente → nessuno (lista vuota)
$db->results['SELECT w.id, w.url FROM ocwebhook'] = [];

// SELECT id FROM ocwebhook dopo INSERT webhook
$db->results['SELECT id FROM ocwebhook WHERE url'] = [['id' => 5]];

$service = new OCWebHookKafkaSetupService($db);
$result  = $service->run(['redpanda:9092'], 'cms');

assert_true($result['ok'], 'Prima esecuzione: result[ok]=true');

assert_true($db->hasQuery("INSERT INTO ezworkflow"),
    'Prima esecuzione: INSERT INTO ezworkflow eseguito');

assert_true($db->hasQuery("INSERT INTO ezworkflow_event"),
    'Prima esecuzione: INSERT INTO ezworkflow_event eseguito');

assert_true($db->hasQuery("INSERT INTO eztrigger"),
    'Prima esecuzione: INSERT INTO eztrigger eseguito');

assert_true($db->hasQuery("INSERT INTO ocwebhook "),
    'Prima esecuzione: INSERT INTO ocwebhook eseguito');

assert_true($db->hasQuery("INSERT INTO ocwebhook_trigger_link"),
    'Prima esecuzione: INSERT INTO ocwebhook_trigger_link eseguito');

// Verifica che l'URL kafka:// sia corretto
assert_true($db->hasQuery("kafka://redpanda:9092/cms"),
    'Prima esecuzione: URL kafka:// corretto nel INSERT');

// Log
$log = implode("\n", $result['log']);
assert_contains('[ok] Workflow configurato: ezworkflow.id=42, eztrigger.id=7', $log,
    'Prima esecuzione: log contiene conferma workflow');
assert_contains('[ok] Webhook kafka:// registrato: ocwebhook.id=5', $log,
    'Prima esecuzione: log contiene conferma webhook');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: Riesecuzione idempotente — workflow e webhook già presenti, URL uguale
// ─────────────────────────────────────────────────────────────────────────────

echo "\n── TEST 2: idempotente (workflow e webhook già configurati) ─────────────\n";

$db = new SpyDB();

// workflowExists() → già presente (c=1)
$db->results['COUNT(*) AS c FROM ezworkflow_event'] = [['c' => 1]];

// webhook esistente con stesso URL
$db->results['SELECT w.id, w.url FROM ocwebhook'] = [[
    'id'  => 5,
    'url' => 'kafka://redpanda:9092/cms',
]];

$service = new OCWebHookKafkaSetupService($db);
$result  = $service->run(['redpanda:9092'], 'cms');

assert_true($result['ok'], 'Idempotente: result[ok]=true');

assert_false($db->hasQuery("INSERT INTO ezworkflow"),
    'Idempotente: nessun INSERT INTO ezworkflow (già esistente)');

assert_false($db->hasQuery("INSERT INTO ocwebhook "),
    'Idempotente: nessun INSERT INTO ocwebhook (già esistente)');

assert_false($db->hasQuery("UPDATE ocwebhook"),
    'Idempotente: nessun UPDATE ocwebhook (URL non cambiato)');

$log = implode("\n", $result['log']);
assert_contains('[ok] Workflow post_publish → WorkflowWebHookType già configurato', $log,
    'Idempotente: log segnala workflow già presente');
assert_contains('[ok] Webhook kafka:// già presente e aggiornato (id=5', $log,
    'Idempotente: log segnala webhook già aggiornato');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: Cambio config — workflow OK, webhook esiste ma con URL diverso → UPDATE
// ─────────────────────────────────────────────────────────────────────────────

echo "\n── TEST 3: cambio configurazione (broker/topic cambiati) ─────────────────\n";

$db = new SpyDB();

// workflowExists() → già presente
$db->results['COUNT(*) AS c FROM ezworkflow_event'] = [['c' => 1]];

// webhook esistente con URL VECCHIO
$db->results['SELECT w.id, w.url FROM ocwebhook'] = [[
    'id'  => 5,
    'url' => 'kafka://old-broker:9092/old-topic',
]];

$service = new OCWebHookKafkaSetupService($db);
$result  = $service->run(['new-broker:9092'], 'new-topic');

assert_true($result['ok'], 'Cambio config: result[ok]=true');

assert_false($db->hasQuery("INSERT INTO ezworkflow"),
    'Cambio config: nessun INSERT workflow (già esistente)');

assert_false($db->hasQuery("INSERT INTO ocwebhook "),
    'Cambio config: nessun INSERT webhook (già esistente)');

assert_true($db->hasQuery("UPDATE ocwebhook SET url"),
    'Cambio config: UPDATE ocwebhook eseguito');

assert_true($db->hasQuery("kafka://new-broker:9092/new-topic"),
    'Cambio config: nuovo URL kafka:// nel UPDATE');

$log = implode("\n", $result['log']);
assert_contains('[ok] Webhook kafka:// aggiornato (id=5)', $log,
    'Cambio config: log segnala aggiornamento webhook');
assert_contains('old-broker:9092', $log,
    'Cambio config: log mostra URL precedente');
assert_contains('new-broker:9092/new-topic', $log,
    'Cambio config: log mostra nuovo URL');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 4: Broker o topic non configurati → skip webhook, workflow creato
// ─────────────────────────────────────────────────────────────────────────────

echo "\n── TEST 4: brokers/topic non configurati → skip webhook ─────────────────\n";

$db = new SpyDB();
$db->results['COUNT(*) AS c FROM ezworkflow_event'] = [['c' => 0]];
$db->results['SELECT id FROM ezworkflow WHERE name'] = [['id' => 99]];
$db->results['SELECT id FROM eztrigger']             = [['id' => 12]];

$service = new OCWebHookKafkaSetupService($db);
$result  = $service->run([], '');   // brokers vuoti, topic vuoto

assert_true($result['ok'], 'No config: result[ok]=true');

assert_true($db->hasQuery("INSERT INTO ezworkflow"),
    'No config: workflow creato comunque');

assert_false($db->hasQuery("INSERT INTO ocwebhook"),
    'No config: nessun INSERT ocwebhook (skip)');

$log = implode("\n", $result['log']);
assert_contains('[skip] KafkaSettings.Brokers o Topic non configurati', $log,
    'No config: log indica skip webhook');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 5: checkPreconditions via run() — tutto OK (usa eZINI stub globale)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n── TEST 5: checkPreconditions — tutto OK ───────────────────────────────────\n";

// eZINI globale restituisce 'disabled' + 'OCSearchEngine' → precondizioni ok
$db5 = new SpyDB();
$db5->results['COUNT(*) AS c FROM ezworkflow_event'] = [['c' => 1]];
$db5->results['SELECT w.id, w.url FROM ocwebhook']   = [['id' => 5, 'url' => 'kafka://redpanda:9092/cms']];
$result5 = (new OCWebHookKafkaSetupService($db5))->run(['redpanda:9092'], 'cms');
$log5str = implode("\n", $result5['log']);

assert_true($result5['ok'],                           'checkPreconditions OK: run ritorna ok=true');
assert_contains('[ok] eZSolr caricabile',             $log5str, 'checkPreconditions OK: eZSolr ok');
assert_contains('[ok] DelayedIndexing=disabled',      $log5str, 'checkPreconditions OK: DelayedIndexing ok');
assert_contains('[ok] SearchEngine=OCSearchEngine',   $log5str, 'checkPreconditions OK: SearchEngine ok');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 6: checkPreconditions — DelayedIndexing=enabled → run() ritorna ok=false
// ─────────────────────────────────────────────────────────────────────────────

echo "\n── TEST 6: checkPreconditions — DelayedIndexing=enabled → fail ─────────────\n";

$fakeIniDelayed = new class {
    public function variable($s, $k) {
        $m = ['SearchSettings' => ['DelayedIndexing' => 'enabled', 'SearchEngine' => 'OCSearchEngine']];
        return $m[$s][$k] ?? null;
    }
};

$db6     = new SpyDB();
$result6 = (new OCWebHookKafkaSetupService($db6))->run(['redpanda:9092'], 'cms', $fakeIniDelayed);
$log6str = implode("\n", $result6['log']);

assert_false($result6['ok'], 'checkPreconditions DelayedIndexing=enabled: run ritorna ok=false');
assert_contains("[fail] [SearchSettings] DelayedIndexing='enabled'", $log6str,
    'checkPreconditions DelayedIndexing=enabled: log contiene [fail]');
assert_contains('[ok] eZSolr caricabile', $log6str,
    'checkPreconditions DelayedIndexing=enabled: eZSolr check eseguito prima');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 7: checkPreconditions — SearchEngine=ezsolr → warn, run() procede (ok=true)
// ─────────────────────────────────────────────────────────────────────────────

echo "\n── TEST 7: checkPreconditions — SearchEngine=ezsolr → warn ─────────────────\n";

$fakeIniWarn = new class {
    public function variable($s, $k) {
        $m = ['SearchSettings' => ['DelayedIndexing' => 'disabled', 'SearchEngine' => 'ezsolr']];
        return $m[$s][$k] ?? null;
    }
};

$db7 = new SpyDB();
$db7->results['COUNT(*) AS c FROM ezworkflow_event'] = [['c' => 1]];
$db7->results['SELECT w.id, w.url FROM ocwebhook']   = [['id' => 5, 'url' => 'kafka://redpanda:9092/cms']];
$result7 = (new OCWebHookKafkaSetupService($db7))->run(['redpanda:9092'], 'cms', $fakeIniWarn);
$log7str = implode("\n", $result7['log']);

assert_true($result7['ok'], 'checkPreconditions SearchEngine=ezsolr: ritorna true (warn non blocca)');
assert_contains("[warn] SearchEngine='ezsolr'", $log7str,
    'checkPreconditions SearchEngine=ezsolr: log contiene [warn]');
assert_contains('[ok] DelayedIndexing=disabled', $log7str,
    'checkPreconditions SearchEngine=ezsolr: DelayedIndexing ancora ok');

// ─────────────────────────────────────────────────────────────────────────────
// Risultati
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

exit($FAILED > 0 ? 1 : 0);
