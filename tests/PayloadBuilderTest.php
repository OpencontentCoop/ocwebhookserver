<?php
// tests/PayloadBuilderTest.php
// Unit test per OCWebHookPayloadBuilder (no eZ Publish bootstrap, no Kafka).

require_once __DIR__ . '/../classes/ocwebhookpayloadbuilder.php';

// ── stub minimi ──────────────────────────────────────────────────────────────

class eZContentObjectVersion {
    public function translationList($language = false, $asObject = true) { return $asObject ? [] : ['ita-IT']; }
    public function attribute($k) { return $k === 'creator_id' ? 10 : null; }
}

class eZContentObject {
    private $id;
    private $data;
    public function __construct($id, array $data = []) {
        $this->id   = $id;
        $this->data = array_merge([
            'id'               => $id,
            'remote_id'        => 'remote-' . $id,
            'class_identifier' => 'article',
            'current_version'  => 3,
            'name'             => 'Test Object ' . $id,
            'owner_id'         => 1,
        ], $data);
    }
    public function attribute($k) { return $this->data[$k] ?? null; }
    public function currentVersion() { return new eZContentObjectVersion(); }
    public function mainNode() { return null; }
    public function name() { return $this->data['name']; }
}

$PASSED = 0; $FAILED = 0;
function ok($n)   { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $n\n"; }
function fail($n,$r='') { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $n" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a,$b,$t) { $a === $b ? ok($t) : fail($t, sprintf('expected %s, got %s', var_export($b,true), var_export($a,true))); }

// ── TEST buildMinimal ────────────────────────────────────────────────────────

$obj = new eZContentObject(42);
$min = OCWebHookPayloadBuilder::buildMinimal($obj);

assert_eq($min['metadata']['id'],              42,        'buildMinimal: id');
assert_eq($min['metadata']['remoteId'],        'remote-42','buildMinimal: remoteId');
assert_eq($min['metadata']['classIdentifier'], 'article', 'buildMinimal: classIdentifier');
assert_eq($min['metadata']['currentVersion'],  3,         'buildMinimal: currentVersion');
assert_eq($min['metadata']['isPublic'],        false,     'buildMinimal: isPublic always false (oggetto in eliminazione)');
assert_eq($min['data'],                        [],        'buildMinimal: data is empty');
assert_eq($min['metadata']['languages'],       ['ita-IT'],'buildMinimal: languages from currentVersion');

// ── NOTE sulla logica isPublic in build() ────────────────────────────────────
// build() calcola isPublic come:
//   !is_invisible && checkAccess('read', anon)
//
// Il flag is_invisible vale 1 sia per nodi nascosti direttamente (is_hidden=1)
// che per loro figli. Questa è la fix per il bug "isPublic=true dopo HIDE":
// checkAccess da solo è policy-based e non considera la visibilità del nodo.
//
// Testato E2E nel container via test_piano_c_v3.php (Piano C smoke test):
//   HIDE → isPublic=false ✓
//   SHOW → isPublic=true  ✓

// ── risultati ────────────────────────────────────────────────────────────────

echo "\n" . str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) echo ", \033[31m{$FAILED} failed\033[0m";
echo "\n";
exit($FAILED > 0 ? 1 : 0);
