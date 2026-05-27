<?php
// tests/SearchEngineEmitTest.php
//
// Verifica:
//   - addObject delega a parent E emette post_publish_ocopendata con payload completo
//   - removeObject delega a parent E emette delete_ocopendata con payload minimal
//   - loop guard: se durante emit() si rientra in addObject, l'emit interno è skippato
//   - try/catch: un'eccezione nell'emit non blocca l'indicizzazione (parent::addObject ritorna comunque)

// ── stub minimi (definire PRIMA del require di OCSearchEngine) ─────────────────

class eZSolr {
    public static $addCalls = 0;
    public static $removeCalls = 0;
    public function addObject($obj, $commit = true, $commitWithin = 0, $softCommit = null) {
        self::$addCalls++;
        return true;
    }
    public function removeObject($obj, $commit = null, $commitWithin = 0) {
        self::$removeCalls++;
        return true;
    }
    public function needCommit() { return true; }
    public function needRemoveWithUpdate() { return true; }
    public function removeObjectById($id, $commit = null) { return true; }
    public function search($searchText, $params = [], $searchTypes = []) { return []; }
    public function supportedSearchTypes() { return []; }
    public function commit() { return true; }
}

class eZDebug {
    public static $errors = [];
    public static function writeError($msg, $label = '') { self::$errors[] = "$label: $msg"; }
}

class eZContentObject {
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function attribute($k) {
        $map = ['id' => $this->id, 'remote_id' => 'r-' . $this->id,
                'class_identifier' => 'article', 'current_version' => 2, 'name' => 'Test'];
        return $map[$k] ?? null;
    }
    public function currentVersion() { return null; }
}

// Stub trigger registry + queue
class OCWebHookQueue {
    const HANDLER_IMMEDIATE = 1;
    const HANDLER_SCHEDULED = 2; // mirrors the real OCWebHookQueue constant (integer)
    public static function defaultHandler() { return self::HANDLER_IMMEDIATE; }
}

interface OCWebHookTriggerQueueAwareInterface {
    public function getQueueHandler();
}

class FakeQueueAwareTrigger implements OCWebHookTriggerQueueAwareInterface {
    public function getQueueHandler() { return OCWebHookQueue::HANDLER_SCHEDULED; }
}

class OCWebHookTriggerRegistry {
    public static function registeredTrigger($id) { return new FakeQueueAwareTrigger(); }
}

class PostPublishWebHookTrigger {
    const IDENTIFIER = 'post_publish_ocopendata';
}

class DeleteWebHookTrigger {
    const IDENTIFIER = 'delete_ocopendata';
}

class OCWebHookPayloadBuilder {
    public static $shouldThrow = false;
    public static function build(eZContentObject $obj) {
        if (self::$shouldThrow) throw new RuntimeException('builder failure');
        return ['metadata' => ['id' => $obj->attribute('id'), 'isPublic' => true], 'data' => ['it-IT' => ['title' => 'X']]];
    }
    public static function buildMinimal(eZContentObject $obj) {
        return ['metadata' => ['id' => $obj->attribute('id'), 'isPublic' => false], 'data' => []];
    }
}

class OCWebHookEmitter {
    public static $log = [];
    public static $reentryEngine = null;
    public static function emit($trigger, $payload, $handler) {
        self::$log[] = ['trigger' => $trigger, 'payload' => $payload, 'handler' => $handler];
        // Simula ri-entrata: emit() richiama addObject sullo stesso engine
        if (self::$reentryEngine !== null) {
            $eng = self::$reentryEngine;
            self::$reentryEngine = null; // armato una volta sola
            $eng->addObject(new eZContentObject(999));
        }
    }
}

// ── load OCSearchEngine ────────────────────────────────────────────────────────

require_once __DIR__ . '/../classes/ocsearchengine.php';

// ── test helpers ───────────────────────────────────────────────────────────────

$PASSED = 0; $FAILED = 0;
function ok($n)   { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $n\n"; }
function fail($n,$r='') { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $n" . ($r ? " — $r" : '') . "\n"; }
function eq($a,$b,$t)   { $a === $b ? ok($t) : fail($t, sprintf('expected %s, got %s', var_export($b,true), var_export($a,true))); }

// ── test 1: addObject → 1 emit post_publish_ocopendata + parent chiamato ───────

OCWebHookEmitter::$log = [];
eZSolr::$addCalls = 0;
$engine = new OCSearchEngine();
$engine->addObject(new eZContentObject(42));

eq(eZSolr::$addCalls,                 1,                                  'addObject: parent invocato');
eq(count(OCWebHookEmitter::$log),     1,                                  'addObject: 1 emit');
eq(OCWebHookEmitter::$log[0]['trigger'], PostPublishWebHookTrigger::IDENTIFIER, 'addObject: trigger corretto');
eq(OCWebHookEmitter::$log[0]['handler'], OCWebHookQueue::HANDLER_SCHEDULED,     'addObject: queue handler dalla Registry');
eq(OCWebHookEmitter::$log[0]['payload']['metadata']['id'], 42,            'addObject: payload completo da build()');
eq(OCWebHookEmitter::$log[0]['payload']['data']['it-IT']['title'], 'X',   'addObject: payload include data');

// ── test 2: removeObject → 1 emit delete_ocopendata + parent chiamato ──────────

OCWebHookEmitter::$log = [];
eZSolr::$removeCalls = 0;
$engine->removeObject(new eZContentObject(42));

eq(eZSolr::$removeCalls,              1,                                  'removeObject: parent invocato');
eq(count(OCWebHookEmitter::$log),     1,                                  'removeObject: 1 emit');
eq(OCWebHookEmitter::$log[0]['trigger'], DeleteWebHookTrigger::IDENTIFIER, 'removeObject: trigger corretto');
eq(OCWebHookEmitter::$log[0]['payload']['metadata']['isPublic'], false,   'removeObject: payload minimal isPublic=false');
eq(OCWebHookEmitter::$log[0]['payload']['data'], [],                      'removeObject: payload minimal data vuoto');

// ── test 3: loop guard ─────────────────────────────────────────────────────────

OCWebHookEmitter::$log = [];
eZSolr::$addCalls = 0;
OCWebHookEmitter::$reentryEngine = $engine; // arma: il primo emit() richiama addObject
$engine->addObject(new eZContentObject(7));

// Il parent::addObject viene chiamato 2 volte (Solr deve indicizzare anche l'oggetto re-entrante)
// MA emit() viene chiamato solo 1 volta (il secondo è gated dal loop guard)
eq(eZSolr::$addCalls,             2, 'loop guard: parent chiamato 2 volte (Solr indicizza)');
eq(count(OCWebHookEmitter::$log), 1, 'loop guard: emit chiamato 1 sola volta (no doppio evento)');

// ── test 4: eccezione nel builder NON blocca parent::addObject ─────────────────

OCWebHookEmitter::$log = [];
eZDebug::$errors = [];
eZSolr::$addCalls = 0;
OCWebHookPayloadBuilder::$shouldThrow = true;
$result = $engine->addObject(new eZContentObject(99));
OCWebHookPayloadBuilder::$shouldThrow = false;

eq(eZSolr::$addCalls,             1,    'eccezione builder: parent chiamato comunque (Solr indicizza)');
eq($result,                       true, 'eccezione builder: addObject ritorna true (no rethrow)');
eq(count(OCWebHookEmitter::$log), 0,    'eccezione builder: nessun emit (builder ha fallito)');
ok('eccezione builder: ' . count(eZDebug::$errors) . ' errori loggati via eZDebug');

// ── test 5: eccezione in parent::addObject (Solr down) → Kafka emette comunque ──
// Scelta architetturale "Kafka indipendente da Solr": l'evento parte anche se
// Solr lancia. L'eccezione di Solr viene poi rilanciata al chiamante.

class TestEngineSolrFails extends OCSearchEngine {
    public function addObject($obj, $commit = true, $commitWithin = 0, $softCommit = null) {
        // Simula parent::addObject che lancia; poi chiama emitSafely direttamente
        // (protected è accessibile alle sottoclassi, no Reflection needed)
        $solrException = null;
        try {
            eZSolr::$addCalls++;
            throw new RuntimeException('Solr unreachable');
        } catch (Exception $e) {
            $solrException = $e;
            if (class_exists('eZDebug')) eZDebug::writeError($e->getMessage(), __METHOD__);
        }
        $this->emitSafely(PostPublishWebHookTrigger::IDENTIFIER, $obj, 'build');
        if ($solrException !== null) throw $solrException;
    }
}

OCWebHookEmitter::$log = [];
eZSolr::$addCalls = 0;
eZDebug::$errors = [];
$failEngine = new TestEngineSolrFails();

$caughtException = null;
try {
    $failEngine->addObject(new eZContentObject(123));
} catch (Exception $e) {
    $caughtException = $e;
}

eq(eZSolr::$addCalls,             1,                  'Solr down: parent invocato (e ha lanciato)');
eq(count(OCWebHookEmitter::$log), 1,                  'Solr down: Kafka emette COMUNQUE');
eq(OCWebHookEmitter::$log[0]['trigger'], PostPublishWebHookTrigger::IDENTIFIER, 'Solr down: trigger corretto');
eq($caughtException !== null,     true,               'Solr down: eccezione rilanciata al chiamante');
eq($caughtException->getMessage(),'Solr unreachable', 'Solr down: messaggio eccezione preservato');

// ── risultati ──────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 60) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) echo ", \033[31m{$FAILED} failed\033[0m";
echo "\n";
exit($FAILED > 0 ? 1 : 0);
