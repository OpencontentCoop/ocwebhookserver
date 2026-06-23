<?php

/**
 * Unit tests for HTTPS normalization in OCWebHookKafkaPayloadFormatter.
 *
 * Covers three paths where http:// URLs possono arrivare nel payload Kafka
 * quando il CMS gira dietro un reverse proxy con SSL termination:
 *
 *   1. File ocmultibinary (pass-through): il campo `url` dei file item
 *      rilevati da isset($item['filename']) veniva restituito as-is.
 *
 *   2. imageUrlResolver: il resolver restituisce l'URL assoluto dell'immagine
 *      che poteva essere http:// se proveniente da eZSys o da eZImageAliasHandler.
 *
 *   3. docFilesResolver: il resolver restituisce un array di file item
 *      [{filename, url, ...}] — il campo url poteva essere http://.
 *
 * No eZ Publish bootstrap needed: il formatter è testabile con dati statici
 * e callable mock.
 *
 * Usage:
 *   php tests/PayloadFormatterForceHttpsTest.php
 */

require_once __DIR__ . '/../classes/ocwebhookpayloadbuilder.php';
require_once __DIR__ . '/../classes/ocwebhookkafkafieldmap.php';
require_once __DIR__ . '/../classes/ocwebhookkafkapayloadformatter.php';

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t): void
{
    if ($a === $b) {
        ok($t);
    } else {
        fail($t, sprintf("expected %s, got %s", var_export($b, true), var_export($a, true)));
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Payload base riutilizzato in tutti i test
// ─────────────────────────────────────────────────────────────────────────────

function baseMetadata(): array
{
    return [
        'id'              => '100',
        'currentVersion'  => '1',
        'remoteId'        => 'remoteabc',
        'classIdentifier' => 'document',
        'classRemoteId'   => 'classremote',
        'classNames'      => ['ita-IT' => 'Documento'],
        'name'            => ['ita-IT' => 'Documento di test'],
        'languages'       => ['ita-IT'],
        'mainNodeId'      => '200',
        'parentNodes'     => [],
        'assignedNodes'   => ['200'],
        'published'       => '1740000000',
        'modified'        => '1740000000',
        'baseUrl'         => 'https://www.comune.example.it',
        'contentUrl'      => 'https://www.comune.example.it/documenti/doc-test',
        'isPublic'        => true,
        'currentVersion'  => 1,
    ];
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1: file ocmultibinary — url http:// → https://
// ─────────────────────────────────────────────────────────────────────────────

$payloadOcmultibinary = [
    'metadata' => baseMetadata(),
    'data' => [
        'ita-IT' => [
            'allegati' => [
                ['filename' => 'report.pdf',  'url' => 'http://www.comune.example.it/var/storage/report.pdf',  'mimeType' => 'application/pdf',  'filesize' => 12345],
                ['filename' => 'prezent.pptx','url' => 'https://www.comune.example.it/var/storage/prezent.pptx','mimeType' => 'application/vnd.ms-powerpoint','filesize' => 67890],
                ['filename' => 'nourl.txt',                                                                       'mimeType' => 'text/plain',         'filesize' => 100],
            ],
        ],
    ],
    'extradata' => [],
];

$formatter = new OCWebHookKafkaPayloadFormatter('frontend', 'mysite', 'mysite');
$result    = $formatter->format($payloadOcmultibinary);
$allegati  = $result['entity']['data']['ita-IT']['allegati'] ?? [];

assert_eq(
    $allegati[0]['url'] ?? null,
    'https://www.comune.example.it/var/storage/report.pdf',
    'ocmultibinary: http:// → https://'
);
assert_eq(
    $allegati[1]['url'] ?? null,
    'https://www.comune.example.it/var/storage/prezent.pptx',
    'ocmultibinary: https:// già presente → invariato'
);
assert_eq(
    isset($allegati[2]['url']),
    false,
    'ocmultibinary: item senza url → nessun campo url aggiunto'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: imageUrlResolver — risolve url http:// → https://
// ─────────────────────────────────────────────────────────────────────────────

$payloadImmagine = [
    'metadata' => array_merge(baseMetadata(), ['classIdentifier' => 'article']),
    'data' => [
        'ita-IT' => [
            'immagine' => [
                [
                    'classIdentifier' => 'image',
                    'id'              => 55,
                    'remoteId'        => 'imgremote',
                    'mainNodeId'      => 300,
                    'name'            => 'Foto evento',
                ],
            ],
        ],
    ],
    'extradata' => [],
];

$imageResolverHttp = function ($objectId, $siteUrl) {
    return 'http://www.comune.example.it/var/storage/images/foto.jpg';
};
$imageResolverHttps = function ($objectId, $siteUrl) {
    return 'https://www.comune.example.it/var/storage/images/foto.jpg';
};

$formatterImg = new OCWebHookKafkaPayloadFormatter('frontend', 'mysite', 'mysite', $imageResolverHttp);
$resultImg    = $formatterImg->format($payloadImmagine);
$imgItem      = $resultImg['entity']['data']['ita-IT']['immagine'][0] ?? [];

assert_eq(
    $imgItem['url'] ?? null,
    'https://www.comune.example.it/var/storage/images/foto.jpg',
    'imageUrlResolver: http:// → https://'
);

$formatterImgS = new OCWebHookKafkaPayloadFormatter('frontend', 'mysite', 'mysite', $imageResolverHttps);
$resultImgS    = $formatterImgS->format($payloadImmagine);
$imgItemS      = $resultImgS['entity']['data']['ita-IT']['immagine'][0] ?? [];

assert_eq(
    $imgItemS['url'] ?? null,
    'https://www.comune.example.it/var/storage/images/foto.jpg',
    'imageUrlResolver: https:// già presente → invariato'
);

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: docFilesResolver — file allegati http:// → https://
// ─────────────────────────────────────────────────────────────────────────────

$payloadDocument = [
    'metadata' => baseMetadata(),
    'data' => [
        'ita-IT' => [
            'allegati' => [
                [
                    'classIdentifier' => 'document',
                    'id'              => 77,
                    'remoteId'        => 'docremote',
                    'mainNodeId'      => 400,
                    'name'            => 'Allegato documento',
                ],
            ],
        ],
    ],
    'extradata' => [],
];

$docFilesResolver = function ($objectId) {
    return [
        ['filename' => 'allegato.pdf', 'url' => 'http://www.comune.example.it/var/storage/allegato.pdf', 'mimeType' => 'application/pdf', 'filesize' => 5000],
        ['filename' => 'nota.pdf',     'url' => 'https://www.comune.example.it/var/storage/nota.pdf',    'mimeType' => 'application/pdf', 'filesize' => 3000],
    ];
};

$formatterDoc = new OCWebHookKafkaPayloadFormatter('frontend', 'mysite', 'mysite', null, $docFilesResolver);
$resultDoc    = $formatterDoc->format($payloadDocument);
$docItem      = $resultDoc['entity']['data']['ita-IT']['allegati'][0] ?? [];
$files        = $docItem['files'] ?? [];

assert_eq(
    $files[0]['url'] ?? null,
    'https://www.comune.example.it/var/storage/allegato.pdf',
    'docFilesResolver: file[0] http:// → https://'
);
assert_eq(
    $files[1]['url'] ?? null,
    'https://www.comune.example.it/var/storage/nota.pdf',
    'docFilesResolver: file[1] https:// già presente → invariato'
);

// ─────────────────────────────────────────────────────────────────────────────
// Results
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

exit($FAILED > 0 ? 1 : 0);
