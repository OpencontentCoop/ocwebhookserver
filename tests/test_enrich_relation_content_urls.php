<?php
/**
 * Test: enrichRelationContentUrls — formato raw array (DefaultEnvironmentSettings)
 *
 * DefaultEnvironmentSettings restituisce gli attributi come array grezzi numerici
 * (non {content:[...], type:"..."}). enrichRelationContentUrls deve aggiungere
 * content_url anche a questi, non solo al formato wrapped.
 *
 * Richiede: DB + eZ bootstrap (node 444 e 450 devono esistere)
 *
 * Run:
 *   docker compose exec -T app sh -c \
 *     'php /var/www/html/extension/ocwebhookserver/tests/test_enrich_relation_content_urls.php 2>&1'
 */

$ezRoot = '/var/www/html';
chdir($ezRoot);
require_once $ezRoot . '/autoload.php';

$script = eZScript::instance([
    'description'    => 'enrichRelationContentUrls raw array test',
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
]);
$script->startup();
$script->initialize();

$PASSED = 0;
$FAILED = 0;

function assert_true(bool $condition, string $label): void
{
    global $PASSED, $FAILED;
    if ($condition) { echo "\033[32m[PASS]\033[0m $label\n"; $PASSED++; }
    else            { echo "\033[31m[FAIL]\033[0m $label\n"; $FAILED++; }
}
function assert_false(bool $condition, string $label): void { assert_true(!$condition, $label); }
function assert_eq($a, $b, string $label): void
{
    assert_true($a === $b, $label);
}

$baseUrl = 'https://www.comune.example.it';

// Node 444 = articolo, node 450 = documento (esistono nel DB di test)
$payload = [
    'metadata' => ['id' => '229', 'baseUrl' => $baseUrl],
    'data' => [
        'ita-IT' => [
            // Formato B: array grezzo con mainNodeId (come DefaultEnvironmentSettings)
            'attachment' => [
                ['id' => 446, 'classIdentifier' => 'document', 'mainNodeId' => 450,
                 'name' => ['ita-IT' => 'Prova determinazione']],
            ],
            'image' => [
                ['id' => 59, 'classIdentifier' => 'image', 'mainNodeId' => 61,
                 'name' => ['ita-IT' => 'Immagine']],
            ],
            // Item senza mainNodeId — NON deve ricevere content_url
            'topics' => [
                ['id' => 1, 'classIdentifier' => 'topic'],
            ],
            // Formato A: wrapped {content:[...]} con tipo non escluso (document)
            'doc_wrapped' => ['content' => [
                ['id' => 446, 'classIdentifier' => 'document', 'mainNodeId' => 444],
            ], 'type' => 'ezobjectrelationlist'],
        ],
    ],
];

OCWebHookPayloadBuilder::enrichRelationContentUrls($payload, $baseUrl);

$data = $payload['data']['ita-IT'];

// Formato B: content_url aggiunto ai relation items con mainNodeId
$att = $data['attachment'][0];
assert_true(
    isset($att['content_url']) && strpos($att['content_url'], $baseUrl . '/') === 0,
    'enrichRelationContentUrls raw: content_url aggiunto ad attachment (document)'
);

$img = $data['image'][0];
assert_false(
    isset($img['content_url']),
    'enrichRelationContentUrls raw: content_url NON aggiunto ad image (tipo escluso)'
);

// Item senza mainNodeId: nessun content_url
assert_false(
    isset($data['topics'][0]['content_url']),
    'enrichRelationContentUrls raw: item senza mainNodeId non riceve content_url'
);

// Formato A (wrapped) con document: content_url aggiunto (tipo non escluso)
$wrapped = $data['doc_wrapped']['content'][0];
assert_true(
    isset($wrapped['content_url']) && strpos($wrapped['content_url'], $baseUrl . '/') === 0,
    'enrichRelationContentUrls wrapped: content_url aggiunto per document nel formato wrapped'
);

echo "\n";
echo "Risultato: $PASSED passati / " . ($PASSED + $FAILED) . " totali\n";
$script->shutdown($FAILED > 0 ? 1 : 0);
