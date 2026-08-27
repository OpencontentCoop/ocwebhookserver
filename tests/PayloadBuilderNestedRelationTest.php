<?php
// tests/PayloadBuilderNestedRelationTest.php
// Unit test per OCWebHookPayloadBuilder::enrichRelationContentUrls() — ricorsione su
// campi annidati (es. "for_entity" dentro un item di has_role). No eZ Publish bootstrap.
//
// Contesto: has_role (public_person) espone ora una lista di ruoli, ognuno con un
// campo for_entity annidato (lista di organization). enrichRelationContentUrls deve
// aggiungere content_url sia al ruolo di primo livello sia a for_entity annidato,
// senza toccare campi che non sono liste di relation item (es. "role": ["Assessore"]).

require_once __DIR__ . '/../classes/ocwebhookpayloadbuilder.php';

// ── stub minimo ────────────────────────────────────────────────────────────────

class eZContentObjectTreeNode
{
    /** @var array<int,string> nodeId => urlAlias, iniettato dal test */
    public static $urlAliasByNodeId = [];
    /** @var int[] nodeId richiesti, per verificare il caching */
    public static $fetchCalls = [];

    private $nodeId;

    private function __construct($nodeId) { $this->nodeId = $nodeId; }

    public static function fetch($nodeId)
    {
        self::$fetchCalls[] = $nodeId;
        if (!isset(self::$urlAliasByNodeId[$nodeId])) {
            return null;
        }
        return new self($nodeId);
    }

    public function urlAlias()
    {
        return self::$urlAliasByNodeId[$this->nodeId];
    }
}

$PASSED = 0;
$FAILED = 0;
function ok(string $name): void { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) { ok($t); } else { fail($t, sprintf('expected %s, got %s. %s', var_export($b, true), var_export($a, true), $r)); }
}
function assert_true(bool $v, string $t, string $r = ''): void  { $v ? ok($t) : fail($t, $r); }
function assert_false(bool $v, string $t, string $r = ''): void { (!$v) ? ok($t) : fail($t, $r); }

$baseUrl = 'https://www.comune.example.it';

eZContentObjectTreeNode::$urlAliasByNodeId = [
    2097 => 'amministrazione/incarichi/ruolo-assessore-ufficio-ambiente',
    772  => 'amministrazione/uffici/ufficio-ambiente',
];

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1: content_url aggiunto sia al ruolo (primo livello) sia a for_entity annidato
// ─────────────────────────────────────────────────────────────────────────────

$payload = [
    'metadata' => ['id' => '659', 'baseUrl' => $baseUrl],
    'data' => [
        'ita-IT' => [
            'has_role' => ['content' => [
                [
                    'id' => 2263, 'remoteId' => '1f69dffb0a3e4f97a0298396d0c2ed9f',
                    'classIdentifier' => 'time_indexed_role', 'mainNodeId' => 2097,
                    'name' => 'Ruolo "Assessore" di Ufficio Ambiente',
                    'role' => ['Assessore'],
                    'for_entity' => [
                        [
                            'id' => 856, 'remoteId' => '2c1aad31d1f20d6189ee5d1881a125e4',
                            'classIdentifier' => 'organization', 'mainNodeId' => 772,
                            'name' => 'Ufficio Ambiente',
                        ],
                    ],
                    'start_date' => '2025-07-09T14:47:17+02:00',
                    'end_date' => null,
                ],
            ], 'type' => 'openparole'],
        ],
    ],
];

OCWebHookPayloadBuilder::enrichRelationContentUrls($payload, $baseUrl);

$role = $payload['data']['ita-IT']['has_role']['content'][0];
assert_eq(
    $role['content_url'],
    $baseUrl . '/amministrazione/incarichi/ruolo-assessore-ufficio-ambiente',
    'content_url aggiunto al ruolo di primo livello (mainNodeId 2097)'
);

$entity = $role['for_entity'][0];
assert_eq(
    $entity['content_url'],
    $baseUrl . '/amministrazione/uffici/ufficio-ambiente',
    'content_url aggiunto a for_entity ANNIDATO dentro il ruolo (mainNodeId 772) — ricorsione funziona'
);

// campo scalare/tag list non deve essere toccato dalla ricorsione
assert_eq($role['role'], ['Assessore'], '"role" (lista di tag stringa) non alterato dalla ricorsione');
assert_eq($role['start_date'], '2025-07-09T14:47:17+02:00', 'start_date non alterato');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: for_entity senza mainNodeId (entità non pubblicabile) → nessun content_url,
// nessun errore
// ─────────────────────────────────────────────────────────────────────────────

$payloadNoNode = [
    'metadata' => ['id' => '660', 'baseUrl' => $baseUrl],
    'data' => [
        'ita-IT' => [
            'has_role' => ['content' => [
                [
                    'id' => 2264, 'classIdentifier' => 'time_indexed_role', 'mainNodeId' => 2097,
                    'for_entity' => [
                        ['id' => 999, 'classIdentifier' => 'organization'], // niente mainNodeId
                    ],
                ],
            ], 'type' => 'openparole'],
        ],
    ],
];

OCWebHookPayloadBuilder::enrichRelationContentUrls($payloadNoNode, $baseUrl);
$entityNoNode = $payloadNoNode['data']['ita-IT']['has_role']['content'][0]['for_entity'][0];
assert_false(isset($entityNoNode['content_url']), 'for_entity senza mainNodeId: nessun content_url, nessun crash');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: guard non scende in campi che NON sono liste di relation item
// (es. "compensi" ezxmltext serializzato come stringa, "recurrences" di un evento)
// ─────────────────────────────────────────────────────────────────────────────

eZContentObjectTreeNode::$fetchCalls = [];

$payloadGuard = [
    'metadata' => ['id' => '661', 'baseUrl' => $baseUrl],
    'data' => [
        'ita-IT' => [
            'has_role' => ['content' => [
                [
                    'id' => 2265, 'classIdentifier' => 'time_indexed_role', 'mainNodeId' => 2097,
                    'compensi' => '<p>Nessun compenso</p>',
                    'recurrences' => [
                        ['start_at' => '2026-01-01T00:00:00Z', 'end_at' => '2026-01-01T12:00:00Z'],
                    ],
                ],
            ], 'type' => 'openparole'],
        ],
    ],
];

OCWebHookPayloadBuilder::enrichRelationContentUrls($payloadGuard, $baseUrl);
$item = $payloadGuard['data']['ita-IT']['has_role']['content'][0];
assert_eq($item['compensi'], '<p>Nessun compenso</p>', 'campo stringa (ezxmltext) non toccato dalla ricorsione');
assert_false(
    isset($item['recurrences'][0]['content_url']),
    '"recurrences" (lista senza mainNodeId nei suoi elementi) non trattata come relation item'
);
// eZContentObjectTreeNode::fetch va chiamato solo per il nodo del ruolo (2097), mai per altro
assert_eq(eZContentObjectTreeNode::$fetchCalls, [2097], 'fetch() chiamato solo per mainNodeId reali, nessuna chiamata spuria');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 4: cache dei nodeUrl condivisa correttamente anche tra item annidati
// (stesso ente referenziato da due ruoli diversi → un solo fetch)
// ─────────────────────────────────────────────────────────────────────────────

eZContentObjectTreeNode::$fetchCalls = [];

$payloadCache = [
    'metadata' => ['id' => '662', 'baseUrl' => $baseUrl],
    'data' => [
        'ita-IT' => [
            'has_role' => ['content' => [
                [
                    'id' => 1, 'classIdentifier' => 'time_indexed_role', 'mainNodeId' => 2097,
                    'for_entity' => [['id' => 856, 'classIdentifier' => 'organization', 'mainNodeId' => 772]],
                ],
                [
                    'id' => 2, 'classIdentifier' => 'time_indexed_role', 'mainNodeId' => 2097,
                    'for_entity' => [['id' => 856, 'classIdentifier' => 'organization', 'mainNodeId' => 772]],
                ],
            ], 'type' => 'openparole'],
        ],
    ],
];

OCWebHookPayloadBuilder::enrichRelationContentUrls($payloadCache, $baseUrl);
$fetchesForNode772 = array_filter(eZContentObjectTreeNode::$fetchCalls, function ($id) { return $id === 772; });
assert_eq(count($fetchesForNode772), 1, 'nodeUrlCache condivisa tra ricorsioni: 772 richiesto una sola volta pur comparendo 2 volte');

// ─────────────────────────────────────────────────────────────────────────────
// Risultato finale
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

exit($FAILED > 0 ? 1 : 0);
