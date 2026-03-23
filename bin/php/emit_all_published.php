<?php

/**
 * Emette eventi Kafka per tutti i contenuti pubblicati.
 *
 * Utile per inizializzare un topic Kafka dopo che Kafka è stato abilitato
 * su un sito già popolato, o per riprocessare tutti i contenuti.
 *
 * Uso:
 *   php extension/ocwebhookserver/bin/php/emit_all_published.php \
 *     --siteaccess=opencity
 *
 * Opzioni:
 *   --class-identifier=article,notizia  Filtra per class identifier (comma-separated)
 *   --limit=100                          Numero massimo di oggetti da processare
 *   --offset=0                           Offset di partenza
 *   --dry-run                            Mostra cosa verrebbe emesso senza emettere
 *   --verbose                            Output verboso
 */

require 'autoload.php';

use Opencontent\Opendata\Api\Values\Content;

set_time_limit(0);

$cli    = eZCLI::instance();
$script = eZScript::instance([
    'description'    => 'Emette eventi Kafka per i contenuti pubblicati',
    'use-session'    => false,
    'use-modules'    => true,
    'use-extensions' => true,
]);

$script->startup();

$options = $script->getOptions(
    '[class-identifier:][limit:][offset:][dry-run][verbose]',
    '',
    [
        'class-identifier' => 'Filtra per class identifier, separati da virgola (es. article,notizia)',
        'limit'            => 'Numero massimo di oggetti da processare (default: tutti)',
        'offset'           => 'Offset di partenza (default: 0)',
        'dry-run'          => 'Mostra cosa verrebbe emesso senza emettere eventi',
        'verbose'          => 'Output verboso',
    ]
);

$script->initialize();

$user = eZUser::fetchByName('admin');
eZUser::setCurrentlyLoggedInUser($user, $user->attribute('contentobject_id'));
eZDB::setErrorHandling(eZDB::ERROR_HANDLING_EXCEPTIONS);

// ── Parametri ──────────────────────────────────────────────────────────────

$classIdentifiers = $options['class-identifier']
    ? array_filter(array_map('trim', explode(',', $options['class-identifier'])))
    : [];
$limit   = $options['limit']   ? (int)$options['limit']  : 0;
$offset  = $options['offset']  ? (int)$options['offset'] : 0;
$dryRun  = isset($options['dry-run']) && $options['dry-run'];
$verbose = $options['verbose'];

// ── Registra il trigger ────────────────────────────────────────────────────

$triggerIdentifier = PostPublishWebHookTrigger::IDENTIFIER;
$triggerInstance   = OCWebHookTriggerRegistry::registeredTrigger($triggerIdentifier);
if (!$triggerInstance instanceof OCWebHookTriggerInterface) {
    $cli->error("Trigger '$triggerIdentifier' non registrato. Verificare webhook.ini [TriggersSettings].");
    $script->shutdown(1);
}
$queueHandler = $triggerInstance->getQueueHandler();

// ── Ambiente ocopendata (usato per buildare il payload) ────────────────────

$currentEnvironment = new DefaultEnvironmentSettings();
$parser             = new ezpRestHttpRequestParser();
$request            = $parser->createRequest();
$currentEnvironment->__set('request', $request);

// ── Query DB: tutti i content object pubblicati ───────────────────────────

$db = eZDB::instance();

$classFilter = '';
if (!empty($classIdentifiers)) {
    $placeholders = implode(', ', array_map(function ($id) use ($db) {
        return "'" . $db->escapeString($id) . "'";
    }, $classIdentifiers));
    $classFilter = "AND ezcontentclass.identifier IN ($placeholders)";
}

$limitClause  = $limit  ? "LIMIT $limit"    : '';
$offsetClause = $offset ? "OFFSET $offset"  : '';

$countQuery = "
    SELECT COUNT(DISTINCT ezcontentobject.id)
    FROM ezcontentobject
    JOIN ezcontentclass ON ezcontentclass.id = ezcontentobject.contentclass_id
    WHERE ezcontentobject.status = 1
    $classFilter
";
$countResult = $db->arrayQuery($countQuery);
$total = (int)($countResult[0]['count'] ?? 0);

$query = "
    SELECT DISTINCT ezcontentobject.id, ezcontentobject.published
    FROM ezcontentobject
    JOIN ezcontentclass ON ezcontentclass.id = ezcontentobject.contentclass_id
    WHERE ezcontentobject.status = 1
    $classFilter
    ORDER BY ezcontentobject.published ASC, ezcontentobject.id ASC
    $limitClause $offsetClause
";

$rows = $db->arrayQuery($query);
$count = count($rows);

if ($dryRun) {
    $cli->output("DRY RUN — nessun evento verrà emesso.");
}
$cli->output("Trovati $total contenuti pubblicati" . ($classIdentifiers ? " (filtro: " . implode(', ', $classIdentifiers) . ")" : '') . ".");
$cli->output("Processando $count oggetti (offset=$offset" . ($limit ? ", limit=$limit" : '') . ")...\n");

// ── Iterazione e emit ─────────────────────────────────────────────────────

$emitted = 0;
$skipped = 0;
$errors  = 0;

foreach ($rows as $i => $row) {
    $objectId = (int)$row['id'];

    $object = eZContentObject::fetch($objectId);
    if (!$object instanceof eZContentObject) {
        if ($verbose) {
            $cli->output("  SKIP [$objectId] — oggetto non trovato");
        }
        $skipped++;
        continue;
    }

    try {
        $content = Content::createFromEzContentObject($object);
        $payload = $currentEnvironment->filterContent($content);
        $payload['metadata']['baseUrl']        = eZSys::serverURL();
        $payload['metadata']['currentVersion'] = (int)$object->attribute('current_version');

        $classId = $object->attribute('class_identifier');
        $name    = $object->attribute('name');

        if ($dryRun) {
            $cli->output("  WOULD EMIT [$objectId] $classId: $name");
        } else {
            OCWebHookEmitter::emit($triggerIdentifier, $payload, $queueHandler);
            $emitted++;
            if ($verbose) {
                $cli->output("  EMITTED [$objectId] $classId: $name");
            } elseif (($i + 1) % 50 === 0) {
                $cli->output("  ... $emitted emessi finora (su $count)");
            }
        }
    } catch (Exception $e) {
        $errors++;
        $cli->error("  ERROR [$objectId]: " . $e->getMessage());
    }
}

// ── Riepilogo ─────────────────────────────────────────────────────────────

$cli->output('');
$cli->output(str_repeat('─', 50));
if ($dryRun) {
    $cli->output("DRY RUN completato: $count oggetti sarebbero stati processati.");
} else {
    $cli->output("Completato: $emitted emessi, $skipped saltati, $errors errori.");
}

$script->shutdown($errors > 0 ? 1 : 0);
