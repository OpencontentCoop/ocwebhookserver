# ocwebhookserver — guida per Claude

## ⛔ Regola assoluta sul branching git

**Non pushare MAI direttamente su `master`.** Sempre creare un branch da `master`:
1. `git checkout master && git pull`
2. `git checkout -b feature/nome` oppure `fix/nome`
3. Committare sul branch
4. **Aspettare conferma esplicita** prima di fare `push`

Nessuna eccezione.

---

## Scopo dell'estensione

`ocwebhookserver` è l'estensione eZ Publish che gestisce la consegna di eventi (webhook HTTP e Kafka) al verificarsi di eventi sul CMS (es. pubblicazione di contenuti). Ogni istanza OpenCity (~500 tenant) ha i propri webhook configurati nel backend.

## Architettura — Transactional Outbox Pattern

Tutti gli endpoint (HTTP e Kafka) usano lo stesso pattern di outbox transazionale:

```
Pubblicazione/cancellazione contenuto
        │
        ▼
WorkflowWebHookType::execute()   ← workflow eZ Publish (post_publish)
        │
        ▼
OCWebHookEmitter::emit($triggerIdentifier, $payload, $queueHandler)
        │
        ├─ per ogni webhook abilitato per il trigger:
        │   ├─ Crea OCWebHookJob con url = endpoint (http:// o kafka://)
        │   ├─ Scrive job PENDING nel DB              ← outbox transazionale
        │   └─ Aggiungi job alla lista
        │
        └─ OCWebHookQueue::pushJobs($jobs)->execute()
                │
                ├─ endpoint http://  → HTTP POST sincrono
                └─ endpoint kafka:// → OCWebHookKafkaProducer::produce()
                                        acks=all, flush timeout 2s
```

**Nessun percorso Kafka separato.** Ogni webhook è un record nella tabella `ocwebhook` con un campo `url`. Il prefisso `kafka://` distingue i webhook Kafka da quelli HTTP. Il pusher (`OCWebHookPusher`) interpreta il prefisso e usa il producer corretto.

Il cron eZ Publish (`webhook_unfrequently` / `webhook_frequently`) riprende i job `PENDING` rimasti non consegnati — sia HTTP che Kafka.

---

## OCSearchEngine — eventi di visibilità (Piano C)

`OCSearchEngine` estende `eZSolr` e intercetta `addObject`/`removeObject` per emettere eventi Kafka ogni volta che cambia la visibilità di un contenuto (hide/show, cambio stato, cambio sezione, move, restore), non solo alla pubblicazione.

### Attivazione (opt-in per tenant)

```yaml
EZINI_site__SearchSettings__SearchEngine: OCSearchEngine
EZINI_site__SearchSettings__ExtensionDirectories__0: ocwebhookserver
```

**Entrambe le env var sono necessarie.** `eZSearch::getEngine()` non usa l'autoload eZ ma cerca il file a `extension/{ext}/search/plugins/{name}/{name}.php` — il file `search/plugins/ocsearchengine/ocsearchengine.php` è l'entry point che carica la classe vera da `classes/ocsearchengine.php`.

### Gating anti-doppia-emissione

Quando `OCSearchEngine` è attivo:
- `WorkflowWebHookType` (post_publish) → **silente** — OCSearchEngine ha già emesso
- `DeleteWorkflowWebHookType` (pre_delete) → **silente solo su hard delete** (oggetto `STATUS_ARCHIVED`). Per **soft delete (trash)** il workflow emette perché `eZSolr::removeObject` non viene chiamato da eZ durante il cestinamento.

### Comportamenti da conoscere

**`isPublic`**: calcolato come `!is_invisible && checkAccess(anon)`.
- `is_invisible=1` vale sia per nodi nascosti direttamente (`is_hidden=1`) che per figli di nodi nascosti — copre entrambi i casi.
- `checkAccess` da solo non è sufficiente: è policy-based e non riflette il flag `is_hidden`.

**Move nodo**: genera 3–4 eventi `post_publish_ocopendata` per la stessa operazione (by design eZ: `removeNodeAssignment` + `addNodeAssignment` + `moveNode` chiamano ciascuno `registerSearchObject`). Non è un bug — il consumer deve gestire eventi idempotenti.

**Trash (soft delete)**: eZ non chiama `eZSolr::removeObject` durante il cestinamento. Il trigger `pre_delete → DeleteWorkflowWebHookType` deve essere configurato manualmente per tenant per ricevere eventi `delete_ocopendata` su trash. Il workflow NON viene configurato da `setup_kafka_workflow.php`.

**Hard delete**: `OCSearchEngine::removeObject` intercetta automaticamente (workflow gated).

**Solr down**: se `parent::addObject` lancia, Kafka emette comunque e l'eccezione viene rilanciata al chiamante eZ. Kafka è indipendente da Solr.

### Verifica precondizioni

`setup_kafka_workflow.php` valida automaticamente le precondizioni di Piano C e logga:
```
[ok] eZSolr caricabile
[ok] DelayedIndexing=disabled
[ok] SearchEngine=OCSearchEngine   ← warn se non configurato, non blocca
```

---

## Tipi di evento (trigger)

| Trigger | Identifier | Quando | Note |
|---------|-----------|--------|------|
| `PostPublishWebHookTrigger` | `post_publish_ocopendata` | Post-publish workflow | Sia creazione che aggiornamento contenuto |
| `DeleteWebHookTrigger` | `delete_ocopendata` | Pre-delete workflow | Cancellazione contenuto |

**Attenzione — creazione vs aggiornamento**: il trigger `post_publish_ocopendata` si attiva sia per la prima pubblicazione (creazione, `currentVersion=1`) che per le successive (aggiornamento, `currentVersion>1`). Il payload include `entity.meta.version` che permette al consumer di distinguere i due casi. Se servono eventi distinti nel Kafka topic, occorre:
1. Aggiungere due nuovi trigger (`create_ocopendata`, `update_ocopendata`)
2. Modificare `PostPublishWebHookTrigger` o creare un `WorkflowWebHookType` dedicato che biforca in base a `currentVersion`

---

## Formato evento Kafka (CloudEvents)

I messaggi Kafka seguono il formato CloudEvents 1.0 con header:

| Header | Esempio | Note |
|--------|---------|------|
| `ce_specversion` | `1.0` | Stringa, non intero |
| `ce_id` | UUID v4 | Generato per ogni messaggio |
| `ce_type` | `it.opencity.cms.article.created` | `productSlug.{type_id}.{created\|updated\|deleted}` |
| `ce_source` | `urn:opencity:cms:opencity` | `productSlug` + `tenantId` |
| `ce_time` | `2026-03-23T17:00:00Z` | UTC, ISO 8601 |
| `content-type` | `application/json` | |
| `oc_app_name` | `OpenCity CMS` | Configurabile |
| `oc_app_version` | `1.2.3` | Letto da `Composer\InstalledVersions` se non configurato |
| `oc_operation` | `created` | Operazione: `created` / `updated` / `deleted` |
| `oc_is_custom` | `true` | Presente solo se `IsOpenCityFork=true` in `openpa.ini [CreditsSettings]`. Segnala istanze con struttura dati diversa da OpenCity standard (es. BIM). |

### Payload (corpo del messaggio)

```json
{
  "entity": {
    "meta": {
      "id":           "bugliano:228",
      "siteaccess":   "frontend",
      "object_id":    "228",
      "remote_id":    "abc123...",
      "type_id":      "article",
      "version":      3,
      "languages":    ["ita-IT"],
      "name":         "Titolo del contenuto",
      "site_url":     "https://www.comune.example.it",
      "published_at": "2026-01-15T10:00:00Z",
      "updated_at":   "2026-03-23T17:00:00Z"
    },
    "data": {
      "ita-IT": {
        "titolo":    "Titolo del contenuto",
        "abstract":  "...",
        "body":      "<p>...</p>"
      }
    }
  }
}
```

**Message key**: `entity.meta.id` (es. `bugliano:228`) — `{TenantId}:{objectId}`, garantisce ordinamento per oggetto all'interno del tenant.

**Formato `ce_type`**: `it.opencity.{productSlug}.{type_id}.{created|updated|deleted}`
- `type_id` = `entity.meta.type_id` dal payload (es. `article`, `event`, `document`)
- operazione: `created` (version=1), `updated` (version>1), `deleted` (trigger delete)
- `ceTypeMap` in `webhook.ini` può mappare `type_id` → nome canonico (es. `article → news`)
- fallback: se non c'è `type_id`, usa `ceTypeMap[triggerIdentifier]` o il trigger stesso

**Mapping campi (canonical field names)**: configurabile per content type in `[KafkaCeTypeMap]` e `[KafkaFieldMap_<content_type>]` in `webhook.ini`.

**Normalizzazione relation items**: i campi camelCase vengono convertiti in snake_case:
- `remoteId` → `remote_id`
- `classIdentifier` → `class_identifier`
- `mainNodeId` → `main_node_id`

---

## Setup automatico per tenant (setup_kafka_workflow.php)

Con ~500 tenant, la configurazione manuale non è praticabile. Lo script `bin/php/setup_kafka_workflow.php` è idempotente e viene eseguito dall'installer per ogni tenant.

### Cosa fa

1. **Controlla** `KafkaSettings.Enabled` — se non `enabled`, esce senza fare nulla
2. **Verifica precondizioni Piano C** (`checkPreconditions`): eZSolr presente, `DelayedIndexing=disabled`, `SearchEngine=OCSearchEngine` (warn se mancante, non blocca)
3. **Crea** (se non esiste) workflow eZ Publish `post_publish → WorkflowWebHookType`
4. **Crea** (se non esiste) o **aggiorna** (se broker/topic cambiati) il record `ocwebhook` con `url = kafka://...` e il link `ocwebhook_trigger_link`

### Idempotenza e gestione cambio config

| Scenario | Comportamento |
|----------|--------------|
| Prima esecuzione | Crea workflow + webhook |
| Riesecuzione (nessuna modifica) | Salta tutto, log `[ok] già configurato` |
| Cambio broker o topic | Aggiorna `url` nel record `ocwebhook` esistente |
| Kafka non abilitato | Esce con `[skip]`, nessuna modifica |

La logica è estratta in `classes/ocwebhookkafkasetupservice.php` per consentire test unitari.

### Uso

```bash
php extension/ocwebhookserver/bin/php/setup_kafka_workflow.php --allow-root-user -sbackend
```

### Test

```bash
php extension/ocwebhookserver/tests/SetupKafkaWorkflowTest.php
```

---

## Configurazione INI (via env var Docker)

Le impostazioni si configurano **esclusivamente tramite variabili d'ambiente** seguendo la convenzione del repo CMS (`EZINI_file__Sezione__Chiave`). Gli array usano il suffisso `__N` (doppio underscore):

```yaml
# Produzione (MSK)
EZINI_webhook__KafkaSettings__Enabled: 'enabled'
EZINI_webhook__KafkaSettings__Brokers__0: 'broker1.msk.amazonaws.com:9092'
EZINI_webhook__KafkaSettings__Brokers__1: 'broker2.msk.amazonaws.com:9092'
EZINI_webhook__KafkaSettings__Topic: 'cms'
EZINI_webhook__KafkaSettings__FlushTimeoutMs: '2000'
EZINI_webhook__KafkaSettings__TenantId: 'nome-comune'
EZINI_webhook__KafkaSettings__ProductSlug: 'cms'

# Sviluppo locale (Redpanda via docker-compose.events.yml)
EZINI_webhook__KafkaSettings__Enabled: 'enabled'
EZINI_webhook__KafkaSettings__Brokers__0: 'redpanda:9092'
EZINI_webhook__KafkaSettings__Topic: 'cms'
EZINI_webhook__KafkaSettings__FlushTimeoutMs: '2000'
EZINI_webhook__KafkaSettings__TenantId: 'opencity'
```

**Attenzione**: `$ini->variable('KafkaSettings', 'Brokers')` restituisce l'array iniettato correttamente. `$ini->group('KafkaSettings')` NON restituisce i valori iniettati via env var — usare sempre `variable()`.

**Non creare mai file `webhook.ini.append.php` statici** per configurazioni che variano per ambiente.

---

## Ambiente di sviluppo locale (Redpanda)

Il repo CMS include `docker-compose.events.yml` per avviare Redpanda localmente:

```bash
# Avvia CMS + Redpanda
docker compose -f docker-compose.yml -f docker-compose.events.yml up -d

# Monitora messaggi Kafka
OUT=$(docker exec sito-comunale-dev-redpanda-1 /usr/bin/rpk topic consume cms \
  -X brokers=redpanda:9092 --offset start --num 10 2>&1); echo "$OUT"

# UI grafica
# https://redpanda-opencity.localtest.me
```

Dopo aver pubblicato un contenuto nel CMS, eseguire il setup:

```bash
OUT=$(docker exec sito-comunale-dev-app-1 /usr/local/bin/php \
  extension/ocwebhookserver/bin/php/setup_kafka_workflow.php \
  --allow-root-user -sbackend 2>&1); echo "$OUT"
```

> **Nota CLI PHP**: usare sempre la forma `OUT=$(docker exec ... 2>&1); echo "$OUT"` — la forma senza cattura dell'output può restituire exit=1 anche se il comando ha successo.

---

## File rilevanti

| File | Ruolo |
|------|-------|
| `classes/ocwebhookemitter.php` | Entry point; scrive l'outbox, chiama la queue |
| `classes/ocwebhookkafkaproducer.php` | Wrapper php-rdkafka; produce con CloudEvents header |
| `classes/ocwebhookkafkapayloadformatter.php` | Converte payload ocopendata → formato canonico entity |
| `classes/ocwebhookkafkafieldmap.php` | Mapping nomi campo per content type |
| `classes/ocwebhookpayloadbuilder.php` | Costruisce il payload ocopendata da `eZContentObject` (`build` completo + `buildMinimal` per delete) |
| `classes/ocsearchengine.php` | Piano C: extends `eZSolr`, intercetta `addObject`/`removeObject`, emette eventi di visibilità |
| `classes/ocwebhookkafkasetupservice.php` | Logica setup idempotente workflow+webhook + `checkPreconditions()` (testabile) |
| `classes/ocwebhookpusher.php` | Pusher: gestisce http:// e kafka:// |
| `eventtypes/event/workflowwebhook/workflowwebhooktype.php` | Workflow post_publish; gated quando OCSearchEngine è attivo |
| `eventtypes/event/deleteworkflowwebhook/deleteworkflowwebhooktype.php` | Workflow pre_delete; gated solo su hard delete (STATUS_ARCHIVED) |
| `search/plugins/ocsearchengine/ocsearchengine.php` | Entry point per `eZSearch::getEngine()` — necessario perché eZSearch usa file discovery, non autoload |
| `bin/php/setup_kafka_workflow.php` | Script CLI setup per tenant (usa OCWebHookKafkaSetupService) |
| `settings/webhook.ini` | Default INI (Kafka disabilitato) |
| `tests/SetupKafkaWorkflowTest.php` | Test unitari setup workflow (7 scenari incluse precondizioni) |
| `tests/SearchEngineEmitTest.php` | Test unitari OCSearchEngine (loop guard, Solr down, gating) |
| `tests/PayloadBuilderTest.php` | Test unitari OCWebHookPayloadBuilder |

---

## Come aggiungere un nuovo trigger

1. Creare classe in `classes/triggers/` che implementa `OCWebHookTriggerInterface`
2. Registrarla in `settings/webhook.ini` sotto `[TriggersSettings]`
3. Chiamare `OCWebHookEmitter::emit($identifier, $payload, $queueHandler)` nel punto corretto
4. Per Kafka: aggiungere il mapping `ce_type` in `[KafkaCeTypeMap]` se si vuole un nome evento diverso dall'identifier

Kafka riceverà automaticamente i messaggi del nuovo trigger senza modifiche al producer.

---

## Requisito infrastrutturale

La libreria C `php-rdkafka` deve essere installata nell'immagine PHP:

```dockerfile
RUN apk add --no-cache --virtual .build-deps autoconf g++ make librdkafka-dev \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && apk del .build-deps \
    && apk add --no-cache librdkafka
```

Se `rdkafka` non è installato, il sistema usa solo HTTP webhook — zero breaking changes.
