# ocwebhookserver — guida per Claude

## Scopo dell'estensione

`ocwebhookserver` è l'estensione eZ Publish che gestisce la consegna di webhook HTTP al verificarsi di eventi sul CMS (es. pubblicazione di contenuti). Ogni istanza OpenCity (~500 tenant) ha i propri webhook configurati nel backend.

## Architettura webhook HTTP (originale)

```
Pubblicazione contenuto
        │
        ▼
WorkflowWebHookType::execute()   ← workflow eZ Publish
        │
        ▼
OCWebHookEmitter::emit()
        │
        ├─ crea OCWebHookJob (PENDING) nel DB    ← outbox transazionale
        │
        └─ OCWebHookQueue::pushJobs()->execute()  ← delivery HTTP sincrona
                                                    oppure cron fallback
```

Il cron eZ Publish (`webhook_unfrequently` / `webhook_frequently`) riprende i job `PENDING` rimasti non consegnati.

---

## Integrazione Kafka (feature/kafka-producer)

### Problema da risolvere

Con 500 tenant, il polling DB per ogni worker HTTP non scala bene per eventi ad alta frequenza. La soluzione è far produrre gli eventi **direttamente su Kafka** al momento della pubblicazione, senza introdurre nuovi processi o tabelle.

### Design della soluzione

#### Pattern: Transactional Outbox

```
Pubblicazione contenuto
        │
        ▼
OCWebHookEmitter::emit()
        │
        ├─ [1] Scrive OCWebHookJob PENDING in DB  ← outbox, garanzia durabilità
        │
        ├─ [2] Se Kafka abilitato e job > 0:
        │       OCWebHookKafkaProducer::produce()
        │       │
        │       ├─ ack ricevuto → cancella i job → return  ← percorso Kafka (fast path)
        │       │
        │       └─ timeout/errore → i job rimangono PENDING  ← fallback automatico
        │
        └─ [3] Se Kafka disabilitato o produce fallito:
                OCWebHookQueue::pushJobs()->execute()         ← percorso HTTP (fallback)
```

**Nessuna nuova tabella DB.** La tabella `ocwebhook_job` esistente serve come outbox transazionale: il job è scritto **prima** di produrre su Kafka e cancellato **solo** su ack confermato.

#### Perché `acks=all` è sufficiente su MSK

AWS MSK è configurato con `min.insync.replicas=2` su cluster a 4 broker. Con `acks=all` il producer aspetta che almeno 2 broker abbiano scritto il messaggio prima di dare ack. Questo è sufficiente come garanzia di durabilità lato Kafka — non serve un consumer di conferma.

#### Timeout e comportamento leggero

- **`FlushTimeoutMs=2000`** (2 secondi): MSK in condizioni normali risponde in poche decine di ms. Il timeout è volutamente basso per non appesantire il processo di pubblicazione. Se MSK non risponde entro 2s, il job rimane PENDING e viene ripreso dal cron.
- **Fallback via cron eZ Publish**: i job PENDING non cancellati vengono processati dal cron `webhook_unfrequently` esistente. `CronRetryAfterSeconds=300` evita race condition con il path sincrono (evita che il cron riprenda job che Kafka sta ancora completando).
- **Nessun nuovo cron**: si riutilizzano i cronjob eZ Publish esistenti. Non serve un processo separato.

#### Ordering dei messaggi

Il `$triggerIdentifier` (es. `post_publish`) viene usato come **message key** Kafka. Questo garantisce che tutti gli eventi dello stesso tipo vadano sulla stessa partizione, preservando l'ordinamento per tipo di evento.

#### Abilitazione per ambiente

Kafka è **disabilitato per default** (`Enabled=disabled` in `webhook.ini`). Si abilita per ambiente tramite variabili d'ambiente Docker seguendo la convenzione del progetto:

```yaml
# docker-compose.yml (produzione MSK)
EZINI_webhook__KafkaSettings__Enabled: 'enabled'
EZINI_webhook__KafkaSettings__Brokers_0: 'broker1.msk.amazonaws.com:9092'
EZINI_webhook__KafkaSettings__Brokers_1: 'broker2.msk.amazonaws.com:9092'
EZINI_webhook__KafkaSettings__Topic: 'cms'
EZINI_webhook__KafkaSettings__FlushTimeoutMs: '2000'

# docker-compose.override.yml (sviluppo locale con Redpanda)
EZINI_webhook__KafkaSettings__Enabled: 'enabled'
EZINI_webhook__KafkaSettings__Brokers_0: 'redpanda:9092'
EZINI_webhook__KafkaSettings__Topic: 'cms'
EZINI_webhook__KafkaSettings__FlushTimeoutMs: '2000'
```

**Non creare mai file `webhook.ini.append.php` statici** per impostazioni che variano per ambiente.

#### Requisito infrastrutturale

La libreria C `php-rdkafka` deve essere installata nell'immagine PHP. Il Dockerfile del CMS aggiunge l'estensione durante la build:

```dockerfile
RUN apk add --no-cache --virtual .build-deps autoconf g++ make librdkafka-dev \
    && pecl install rdkafka \
    && docker-php-ext-enable rdkafka \
    && apk del .build-deps \
    && apk add --no-cache librdkafka
```

Se `rdkafka` non è caricato, `OCWebHookKafkaProducer::isEnabled()` restituisce `false` e il sistema degrada silenziosamente sul path HTTP — zero breaking changes.

---

## File rilevanti

| File | Ruolo |
|------|-------|
| `classes/ocwebhookemitter.php` | Entry point; scrive l'outbox, chiama Kafka, fallback HTTP |
| `classes/ocwebhookkafkaproducer.php` | Wrapper singleton php-rdkafka; produce e flush |
| `eventtypes/event/workflowwebhook/workflowwebhooktype.php` | Workflow eZ che chiama `OCWebHookEmitter::emit()` on post_publish |
| `settings/webhook.ini` | Default INI (Kafka disabilitato); override via env var Docker |
| `classes/ocwebhookqueue.php` | Queue HTTP per il path di fallback |

---

## Ambiente di sviluppo locale

Il `docker-compose.override.yml` del repo CMS (`opencontent/cms`) avvia:
- **Redpanda** (Kafka-compatible single node) su `redpanda:9092`
- **Redpanda Console** su `https://redpanda-opencity.localtest.me` (UI per ispezionare messaggi)

Per testare la produzione di messaggi senza pubblicare contenuto:

```bash
# Produce direttamente con rdkafka (verifica che l'estensione funzioni)
OUT=$(docker exec cms-app-1 /usr/local/bin/php -r '
$conf = new RdKafka\Conf();
$conf->set("bootstrap.servers", "redpanda:9092");
$conf->set("acks", "all");
$p = new RdKafka\Producer($conf);
$t = $p->newTopic("cms");
$t->produce(RD_KAFKA_PARTITION_UA, 0, json_encode(["test"=>true,"ts"=>date("c")]), "post_publish");
echo $p->flush(2000) === RD_KAFKA_RESP_ERR_NO_ERROR ? "SUCCESS" : "FAIL";
' 2>&1); echo $OUT

# Consuma messaggi con rpk
OUT=$(docker exec cms-redpanda-1 /usr/bin/rpk topic consume cms \
  --brokers redpanda:9092 --offset start --num 5 2>&1); echo "$OUT"
```

> **Nota CLI PHP**: in questo ambiente `docker exec cms-app-1 php ...` restituisce exit=1 a causa di come il tool cattura l'output. Usare la forma `OUT=$(docker exec cms-app-1 php -r '...' 2>&1); echo "$OUT"` che funziona correttamente.

---

## Come aggiungere un nuovo trigger Kafka

Il producer invia **tutti** i trigger sullo stesso topic `cms`, usando il `$triggerIdentifier` come message key. I consumer filtrano per tipo di evento leggendo il campo `trigger` nel payload (o la key del messaggio Kafka).

Per aggiungere un nuovo trigger:
1. Creare una classe che implementa `OCWebHookTriggerInterface` in `classes/triggers/`
2. Registrarla in `settings/webhook.ini` sotto `[TriggersSettings]`
3. Chiamare `OCWebHookEmitter::emit($newTrigger::IDENTIFIER, $payload, $queueHandler)` nel punto corretto del codice

Kafka riceverà automaticamente i messaggi del nuovo trigger senza modifiche al producer.
