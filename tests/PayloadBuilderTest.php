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

// ── stub eZUser ──────────────────────────────────────────────────────────────

class eZUser {
    private $data;
    public function __construct(array $data) { $this->data = $data; }
    public function attribute($k) { return isset($this->data[$k]) ? $this->data[$k] : null; }
    public static function anonymousId() { return 10; }
    public static function fetch($id) { return new self(['contentobject_id' => (int)$id]); }
    public static function currentUser() {
        if (!empty($GLOBALS['eZUserGlobalInstance_'])) {
            return $GLOBALS['eZUserGlobalInstance_'];
        }
        return new self(['contentobject_id' => self::anonymousId()]);
    }
}

// ── stub eZContentObjectTreeNode ─────────────────────────────────────────────
// checkAccess() simula il comportamento reale: admin può sempre leggere,
// l'anonimo può leggere solo se $anonCanRead = true.
// Chiama eZUser::currentUser() come fa il vero eZ, così possiamo verificare
// che il fix scambi correttamente il contesto utente prima di chiamarlo.

class eZContentObjectTreeNode {
    private $data;
    public static $anonCanRead = false; // controllabile nei test

    public function __construct(array $data = []) { $this->data = $data; }
    public function attribute($k) { return isset($this->data[$k]) ? $this->data[$k] : null; }
    public function urlAlias() { return 'test/path'; }

    public function checkAccess($fn) {
        $user = eZUser::currentUser();
        $isAnon = (int)$user->attribute('contentobject_id') === eZUser::anonymousId();
        return $isAnon ? self::$anonCanRead : true; // admin può tutto
    }
}

// ── TEST checkIsPublic ────────────────────────────────────────────────────────
// Bug: il 5° parametro di eZContentObjectTreeNode::checkAccess() è $language,
// non $user. Il vecchio codice passava eZUser::anonymousId() come $language
// (ignorato) e il check girava come eZUser::currentUser() (admin).
// Con admin loggato, isPublic era sempre true anche per contenuti privacy.private.

$admin = new eZUser(['contentobject_id' => 1]);
$GLOBALS['eZUserGlobalInstance_'] = $admin;

// is_invisible = 1 → sempre false (nodo nascosto)
eZContentObjectTreeNode::$anonCanRead = true;
$hiddenNode = new eZContentObjectTreeNode(['is_invisible' => 1]);
$r = OCWebHookPayloadBuilder::checkIsPublic($hiddenNode);
assert_eq($r, false, 'checkIsPublic: false per nodo nascosto (is_invisible=1)');

// is_invisible = 0, admin loggato, anonimo NON può leggere (privacy.private)
// BUG prima del fix: checkAccess viene chiamato come admin → true → isPublic=true (SBAGLIATO)
// Fix: swap anon → checkAccess(anon) → false → isPublic=false (CORRETTO)
eZContentObjectTreeNode::$anonCanRead = false;
$privateNode = new eZContentObjectTreeNode(['is_invisible' => 0]);
$r = OCWebHookPayloadBuilder::checkIsPublic($privateNode);
assert_eq($r, false, 'checkIsPublic: false quando admin è loggato ma anonimo non può leggere (privacy.private)');

// is_invisible = 0, admin loggato, anonimo può leggere (contenuto pubblico)
eZContentObjectTreeNode::$anonCanRead = true;
$publicNode = new eZContentObjectTreeNode(['is_invisible' => 0]);
$r = OCWebHookPayloadBuilder::checkIsPublic($publicNode);
assert_eq($r, true, 'checkIsPublic: true quando il contenuto è pubblicamente leggibile');

// il global user deve essere ripristinato ad admin dopo la chiamata
$currentAfter = $GLOBALS['eZUserGlobalInstance_'];
assert_eq($currentAfter->attribute('contentobject_id'), 1, 'checkIsPublic: ripristina il current user originale dopo la chiamata');

unset($GLOBALS['eZUserGlobalInstance_']);

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
