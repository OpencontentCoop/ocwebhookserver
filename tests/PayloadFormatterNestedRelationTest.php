<?php
// tests/PayloadFormatterNestedRelationTest.php
// Unit test per OCWebHookKafkaPayloadFormatter::normalizeRelationItem() — ricorsione
// su campi annidati (has_role[].for_entity). No eZ Publish bootstrap, no broker.
//
// Verifica il contratto standard per gli item annidati, identico a quello già
// garantito per gli item di primo livello: id = "instanceId:objectId", mainNodeId
// droppato, content_url (già impostato da enrichRelationContentUrls) preservato.

require_once __DIR__ . '/../classes/ocwebhookpayloadbuilder.php';
require_once __DIR__ . '/../classes/ocwebhookkafkapayloadformatter.php';

$PASSED = 0;
$FAILED = 0;
function ok(string $name): void { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) { ok($t); } else { fail($t, sprintf('expected %s, got %s. %s', var_export($b, true), var_export($a, true), $r)); }
}
function assert_false(bool $v, string $t, string $r = ''): void { (!$v) ? ok($t) : fail($t, $r); }

// ─────────────────────────────────────────────────────────────────────────────
// TEST: has_role[] con for_entity[] annidato — caso reale Turci Stefano / Bugliano
// ─────────────────────────────────────────────────────────────────────────────

$payload = [
    'metadata' => ['id' => '659', 'classIdentifier' => 'public_person', 'languages' => ['ita-IT']],
    'data' => [
        'ita-IT' => [
            'has_role' => ['content' => [
                [
                    'id' => 2263, 'remoteId' => '1f69dffb0a3e4f97a0298396d0c2ed9f',
                    'classIdentifier' => 'time_indexed_role', 'mainNodeId' => 2097,
                    'name' => 'Ruolo "Assessore" di Ufficio Ambiente',
                    'content_url' => 'https://www.comune.example.it/incarichi/assessore-ambiente',
                    'role' => ['Assessore'],
                    'for_entity' => [
                        [
                            'id' => 856, 'remoteId' => '2c1aad31d1f20d6189ee5d1881a125e4',
                            'classIdentifier' => 'organization', 'mainNodeId' => 772,
                            'name' => 'Ufficio Ambiente',
                            'content_url' => 'https://www.comune.example.it/uffici/ambiente',
                            'class' => 'organization', 'languages' => ['ita-IT'], 'link' => 'read/856',
                        ],
                    ],
                    'start_date' => '2025-07-09T14:47:17+02:00',
                    'end_date' => null,
                ],
            ], 'type' => 'openparole'],
        ],
    ],
];

$formatter = new OCWebHookKafkaPayloadFormatter('frontend', 'bugliano');
$result    = $formatter->format($payload);
$roles     = $result['entity']['data']['ita-IT']['has_role'];

assert_eq(count($roles), 1, 'has_role: un solo ruolo nel risultato');
$role = $roles[0];

// ── item di primo livello (ruolo): standardizzazione già garantita, verifica di non-regressione ──
assert_eq($role['type_id'],   'time_indexed_role', 'ruolo: type_id = classIdentifier');
assert_eq($role['id'],        'bugliano:2263',     'ruolo: id = instanceId:objectId');
assert_eq($role['object_id'], '2263',              'ruolo: object_id');
assert_eq($role['remote_id'], '1f69dffb0a3e4f97a0298396d0c2ed9f', 'ruolo: remote_id');
assert_eq($role['title'],     'Ruolo "Assessore" di Ufficio Ambiente', 'ruolo: title da name');
assert_eq($role['content_url'], 'https://www.comune.example.it/incarichi/assessore-ambiente', 'ruolo: content_url pass-through');
assert_false(isset($role['mainNodeId']), 'ruolo: mainNodeId droppato');

// ── for_entity ANNIDATO: stesso standard applicato dalla ricorsione ──
assert_eq(count($role['for_entity']), 1, 'for_entity: un solo item');
$entity = $role['for_entity'][0];

assert_eq($entity['type_id'],   'organization',  'for_entity annidato: type_id = classIdentifier (ricorsione applicata)');
assert_eq($entity['id'],        'bugliano:856',  'for_entity annidato: id = instanceId:objectId, STESSO instanceId del ruolo');
assert_eq($entity['object_id'], '856',           'for_entity annidato: object_id');
assert_eq($entity['remote_id'], '2c1aad31d1f20d6189ee5d1881a125e4', 'for_entity annidato: remote_id');
assert_eq($entity['title'],     'Ufficio Ambiente', 'for_entity annidato: title da name');
assert_eq($entity['content_url'], 'https://www.comune.example.it/uffici/ambiente', 'for_entity annidato: content_url pass-through');

assert_false(isset($entity['mainNodeId']),      'for_entity annidato: mainNodeId droppato — non deve uscire nel payload finale');
assert_false(isset($entity['classIdentifier']), 'for_entity annidato: classIdentifier droppato (rinominato type_id)');
assert_false(isset($entity['class']),           'for_entity annidato: "class" droppato');
assert_false(isset($entity['languages']),       'for_entity annidato: "languages" droppato');
assert_false(isset($entity['link']),            'for_entity annidato: "link" droppato');
assert_false(isset($entity['name']),            'for_entity annidato: "name" droppato (rinominato title)');

// ── campi non-relazionali del ruolo: non devono essere alterati dalla ricorsione ──
assert_eq($role['role'],       ['Assessore'],              '"role" (tag list stringa) non trattato come relation item');
// toUtcValue() (già esistente, invariato da questo lavoro) normalizza la stringa ISO 8601
// offset +02:00 in UTC — comportamento corretto, coerente con TEST 13 di PayloadFormatterTest.php.
assert_eq($role['start_date'], '2025-07-09T12:47:17Z', 'start_date: normalizzato a UTC come ogni altra data del payload');
assert_false(isset($role['start_time']), 'nessun campo start_time residuo nel payload finale (rinominato a monte in start_date)');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: has_role vuoto (persona senza incarichi) → lista vuota, nessun errore
// ─────────────────────────────────────────────────────────────────────────────

$payloadEmpty = [
    'metadata' => ['id' => '700', 'classIdentifier' => 'public_person', 'languages' => ['ita-IT']],
    'data' => ['ita-IT' => ['has_role' => ['content' => null, 'type' => 'openparole']]],
];
$resultEmpty = $formatter->format($payloadEmpty);
assert_eq($resultEmpty['entity']['data']['ita-IT']['has_role'], [], 'has_role vuoto (content null) normalizzato a []');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: for_entity con più enti — ognuno normalizzato indipendentemente
// ─────────────────────────────────────────────────────────────────────────────

$payloadMulti = [
    'metadata' => ['id' => '701', 'classIdentifier' => 'public_person', 'languages' => ['ita-IT']],
    'data' => ['ita-IT' => ['has_role' => ['content' => [
        [
            'id' => 1, 'classIdentifier' => 'time_indexed_role', 'mainNodeId' => 10,
            'for_entity' => [
                ['id' => 100, 'classIdentifier' => 'organization', 'mainNodeId' => 200, 'name' => 'Ente A'],
                ['id' => 101, 'classIdentifier' => 'organization', 'mainNodeId' => 201, 'name' => 'Ente B'],
            ],
        ],
    ], 'type' => 'openparole']]],
];
$resultMulti = $formatter->format($payloadMulti);
$entities = $resultMulti['entity']['data']['ita-IT']['has_role'][0]['for_entity'];
assert_eq(count($entities), 2, 'for_entity con più enti: entrambi presenti');
assert_eq($entities[0]['id'],    'bugliano:100', 'for_entity multi: primo ente id corretto');
assert_eq($entities[0]['title'], 'Ente A',       'for_entity multi: primo ente title corretto');
assert_eq($entities[1]['id'],    'bugliano:101', 'for_entity multi: secondo ente id corretto');
assert_eq($entities[1]['title'], 'Ente B',       'for_entity multi: secondo ente title corretto');

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
