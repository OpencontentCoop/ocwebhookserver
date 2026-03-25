# ocwebhookserver

Estensione eZ Publish legacy per la configurazione e l'invio di webhook HTTP e
messaggi Kafka al verificarsi di eventi sul CMS (pubblicazione, cancellazione, ecc.).

---

## Architettura

### Outbox pattern

Ogni evento genera uno o più **job** scritti in DB con stato `PENDING` prima che
venga tentato qualsiasi invio. Questo garantisce che nessun evento venga perso
anche in caso di crash del processo o timeout del broker.

```
evento CMS (es. pubblicazione)
    └── OCWebHookEmitter::emit()
             ├── tutti i job scritti in DB come PENDING
             └── esecuzione immediata o differita a seconda del trigger
```

### Modalità di invio

Ogni trigger dichiara la propria modalità tramite `getQueueHandler()`:

| Modalità      | Costante            | Comportamento                                                                                               |
|---------------|---------------------|-------------------------------------------------------------------------------------------------------------|
| **Immediata** | `HANDLER_IMMEDIATE` | Il job viene eseguito subito nel processo corrente. Se fallisce rimane `FAILED` in DB e il cron lo riprova. |
| **Differita** | `HANDLER_SCHEDULED` | Il job rimane `PENDING` in DB; il worker o il cron lo eseguono in background.                               |

Il trigger `post_publish` usa `HANDLER_IMMEDIATE` — l'evento Kafka viene inviato
in sincrono durante la pubblicazione del contenuto.

### Transport: HTTP e Kafka

Il transport è determinato dall'**URL configurato nel webhook**:

| URL                                       | Transport                               |
|-------------------------------------------|-----------------------------------------|
| `https://esempio.com/webhook`             | HTTP POST via Guzzle                    |
| `kafka://broker1:9092,broker2:9092/topic` | Produce su Kafka con header CloudEvents |

`OCWebHookPusher` rileva lo schema dell'URL e instrada il job al transport corretto.
La logica di retry è identica per entrambi: se l'invio fallisce il job torna
`PENDING` e viene ripreso dal cron.

---

## Invio su Kafka

I messaggi Kafka seguono il formato [CloudEvents 1.0](https://cloudevents.io/)
con binding Kafka: i metadati dell'evento stanno negli **header** del messaggio,
il **value** contiene solo i dati dell'entità.

### Header

| Header            | Valore                                                          |
|-------------------|-----------------------------------------------------------------|
| `ce_specversion`  | `1.0`                                                           |
| `ce_id`           | UUID v4 generato al produce                                     |
| `ce_type`         | `it.opencity.<product>.<domain>.<event>` (da `KafkaCeTypeMap`)  |
| `ce_source`       | `urn:opencity:<product>:<tenant-uuid>`                          |
| `ce_time`         | ISO 8601 UTC, momento del produce                               |
| `content-type`    | `application/json`                                              |
| `oc_app_name`     | nome variante prodotto (es. `website-comuni`)                   |
| `oc_app_version`  | versione applicativo                                            |
| `oc_retry_count`  | numero di tentativi precedenti (0 al primo invio)               |

### Payload

```json
{
  "entity": {
    "meta": {
      "id":           "<instanceId>:<object_id>",
      "tenant_id":    "<uuid-tenant>",
      "siteaccess":   "<siteaccess>",
      "object_id":    "42",
      "remote_id":    "abc123remote",
      "type_id":      "article",
      "version":      3,
      "languages":    ["it-IT", "eng-GB"],
      "name":         "Titolo notizia",
      "site_url":     "https://www.comune.example.it",
      "content_url":  "https://www.comune.example.it/notizie/titolo-notizia",
      "api_url":      "https://www.comune.example.it/api/openapi/novita/notizie/abc123remote#titolo-notizia",
      "created_by":   {"id": 14, "login": "admin", "name": "Administrator"},
      "modified_by":  {"id": 55, "login": "editor", "name": "Mario Rossi"},
      "published_at": "2026-03-15T09:00:00Z",
      "updated_at":   "2026-03-20T10:30:00Z"
    },
    "data": {
      "it-IT": {
        "title":    "Titolo notizia",
        "abstract": "Breve descrizione",
        "topics": [
          {
            "id": 101,
            "remote_id": "topic-xyz",
            "class_identifier": "tag",
            "main_node_id": "501",
            "name": "Innovazione",
            "content_url": "https://www.comune.example.it/argomenti/innovazione"
          }
        ]
      },
      "eng-GB": { "...": "..." }
    }
  }
}
```

#### Regole di formattazione del payload

- **Tutti i timestamp** in `entity.meta` e in `entity.data` (inclusi quelli annidati
  nei relation items) sono normalizzati a UTC (`YYYY-MM-DDTHH:MM:SSZ`).
- **Relation items**: i campi camelCase di ocopendata vengono rinominati in snake_case
  (`remoteId` → `remote_id`, `classIdentifier` → `class_identifier`,
  `mainNodeId` → `main_node_id`). I campi ridondanti `class`, `languages` e `link`
  vengono eliminati. Il campo `name` viene risolto alla lingua del blocco
  `entity.data.<lang>` corrente se è una mappa multilingua.
- **`content_url` negli elementi relazionati**: se l'elemento ha un `mainNodeId`,
  viene iniettato `content_url` con l'URL pubblico del nodo eZ.
- **Nomi canonici degli attributi**: per i content type configurati in
  `OCWebHookKafkaFieldMap`, gli attributi vengono rinominati ai nomi canonici
  di dominio (es. `titolo` → `title`, `testo` → `description`) per uniformità
  tra istanze con modelli dati diversi.

### Configurazione

```ini
[KafkaSettings]
Enabled=enabled
Brokers[]=broker1:9092
Topic=cms
TenantId=<uuid-dal-tenant-manager>
ProductSlug=website
AppName=website-comuni
AppVersion=1.5.1
FlushTimeoutMs=2000
RunningJobTimeoutSeconds=300

[KafkaCeTypeMap]
post_publish_ocopendata=content.published
delete_ocopendata=content.deleted
```

L'URL del webhook Kafka va configurato come:
```
kafka://broker1:9092,broker2:9092/cms
```

---

## Installazione

```bash
composer require opencontent/ocwebhookserver-ls
```

- Abilitare l'estensione in `site.ini`
- Rigenerare gli autoload e svuotare la cache
- Creare i webhook in `/webhook/list`
- Avviare il worker: `php extension/ocwebhookserver/bin/php/worker.php`

---

## Creare un trigger

1. Implementare `OCWebHookTriggerInterface` + `OCWebHookTriggerQueueAwareInterface`
2. Registrarlo in `webhook.ini [TriggersSettings] TriggerList[]`
3. Emettere l'evento nel codice:

```php
OCWebHookEmitter::emit(
    MyTrigger::IDENTIFIER,
    $payload,
    $trigger->getQueueHandler()   // HANDLER_IMMEDIATE o HANDLER_SCHEDULED
);
```

`getQueueHandler()` ha default `HANDLER_SCHEDULED`. Sovrascriverlo nel trigger
se l'invio deve essere sincrono (es. eventi Kafka su pubblicazione).

Vedi `eventtypes/event/workflowwebhook/workflowwebhooktype.php` come esempio.

---

## Cron jobs

| Part       | Script                   | Funzione                                                                              |
|------------|--------------------------|---------------------------------------------------------------------------------------|
| `frequent` | `reset_running_jobs.php` | Riporta in `PENDING` i job bloccati in `RUNNING` da più di `RunningJobTimeoutSeconds` |
