# Piano B — Eventi di visibilità via index plugin Solr

> **Stato:** non implementato. Conservato come fallback ripristinabile.
> **Piano A in vigore:** [`2026-05-18-index-plugin-visibility-events.md`](./2026-05-18-index-plugin-visibility-events.md) — emissione ibrida via workflow su operation handler + `ezpEvent('openpa/object/flushed')`.

## Quando rivalutare il Piano B

Riprendere in considerazione questo piano se:
- Emerge un caso d'uso "must-have" tra i gap del Piano A (hide subtree → figli, `post_removetranslation`, trash/restore, `post_move` cross-section) e mantenere hook puntuali distinti diventa insostenibile (NB: la review del 2026-05-19 ha chiuso tutti questi gap nel Piano A — questa condizione è quindi superata, ma resta valida se in futuro emergono nuovi path di visibilità non coperti).
- L'obiettivo "dismettere Solr" viene posticipato o abbandonato e quindi la dipendenza dall'index engine non è più un problema.
- Il consumer Kafka inizia a richiedere garanzie semantiche di "fire-on-reindex" (ogni re-index Solr ⇒ un evento Kafka).

Se nessuna di queste condizioni si verifica, restiamo sul Piano A.

## Idea centrale

Registrare un `ezpIndexPlugin` custom (`OCWebHookIndexPlugin`) tramite `site.ini`/`ezfind.ini`. `eZSolr::addObject()` invoca `modify(eZContentObject $object, array &$doc)` per ogni plugin registrato durante l'indicizzazione di un oggetto. Il plugin, dopo aver ricevuto l'oggetto, **non modifica** il documento Solr ma usa la callback come unico entry point per emettere un evento Kafka.

```
site.ini (o ezfind.ini)
  └── [SearchSettings]
      IndexPlugin[]=OCWebHookIndexPlugin   ← registrazione via INI

OCWebHookIndexPlugin::modify($obj, &$doc)
  └── OCWebHookPayloadBuilder::build($obj)
       └── OCWebHookEmitter::emit('post_publish_ocopendata', $payload, HANDLER_SCHEDULED)
```

## Cosa il Piano B copre automaticamente

Tutto ciò che provoca un re-index Solr passa da `eZSolr::addObject()` → `modify()` → emit, senza bisogno di hook puntuali. Quindi:

| Path | Coperto dal Piano B |
|---|---|
| Pubblicazione nuova versione | ✅ (re-index immediato) |
| Hide/show nodo singolo | ✅ (re-index in `eZSearch::updateNodeVisibility`) |
| Hide subtree → figli | ✅ (via cron `ezfindexsubtree`, ma a ogni `addObject` di un figlio) |
| Cambio stato (UI e cron) | ✅ (re-index in `eZSearch::updateObjectState`) |
| Cambio sezione (UI e cron) | ✅ (re-index in `eZSearch::updateObjectsSection`) |
| Cambio disponibilità traduzioni | ✅ (re-index) |
| Trash / restore | ✅ (re-index al restore; al trash il documento viene rimosso da Solr via `removeObject` → vedi nota su delete più sotto) |
| Move | ✅ (re-index) |

## Limiti noti del Piano B

1. **Dipendenza da Solr attivo.** Se Solr è disattivato per quel tenant, `eZSolr::addObject` non viene chiamato e nessun evento viene emesso. È il motivo per cui questo piano è in standby.
2. **Delete non coperti da `modify()`.** `modify()` viene invocato solo da `addObject`, NON da `removeObject` (la cancellazione passa altrove). Va mantenuto separato `DeleteWebHookTrigger` per i delete — come oggi.
3. **Possibili eventi "spuri".** Solr re-indicizza anche per cambi che non sono strettamente di visibilità (es. update di un attributo testuale). Lato consumer servirebbe lo stesso diff su `metadata.isPublic` che facciamo già nel Piano A.
4. **Latenza pari a quella di indicizzazione.** Hide/show non emette finché Solr non riceve il documento aggiornato — di solito sub-second, ma non garantito.

## Pezzi tecnici necessari (bozza)

### Registrazione del plugin

```ini
; settings/ezfind.ini.append.php nell'estensione ocwebhookserver
[IndexOptions]
IndexPlugins[]=OCWebHookIndexPlugin
```

(Il nome esatto della chiave INI va verificato sul codice eZ Solr/ezfind in uso — `IndexPlugins[]` è la chiave storica.)

### Classe del plugin

```php
<?php

class OCWebHookIndexPlugin implements ezpIndexPlugin
{
    /**
     * Invocato da eZSolr::addObject() PRIMA del flush verso Solr.
     * @param eZContentObject $object  Oggetto in re-indicizzazione
     * @param array           $doc     Documento Solr (NON mutato dal plugin)
     */
    public function modify(eZContentObject $object, &$doc)
    {
        try {
            $payload = OCWebHookPayloadBuilder::build($object);
            $triggerInstance = OCWebHookTriggerRegistry::registeredTrigger(
                PostPublishWebHookTrigger::IDENTIFIER
            );
            $queueHandler = $triggerInstance instanceof OCWebHookTriggerQueueAwareInterface
                ? $triggerInstance->getQueueHandler()
                : OCWebHookQueue::defaultHandler();
            OCWebHookEmitter::emit(
                PostPublishWebHookTrigger::IDENTIFIER,
                $payload,
                $queueHandler
            );
        } catch (Exception $e) {
            eZLog::write(__METHOD__ . ': ' . $e->getMessage(), 'webhook.log');
        }
        // NON modificare $doc — questo plugin è solo un emitter side-channel.
    }
}
```

L'interfaccia esatta (`ezpIndexPlugin` o `ezfIndexPlugin` o nome equivalente) va recuperata dal codice ezfind installato. Cercare con:
```bash
grep -rn "interface.*IndexPlugin\|abstract class.*IndexPlugin" html/extension/ html/kernel/ 2>/dev/null
```

### Loop guard

Se il payload builder o il listener facessero `$object->store()` o qualunque operazione che ri-triggerasse un `addObject`, si entrerebbe in loop infinito. **Il plugin deve essere solo in lettura.**

### Doppia emissione con il Piano A

Se Piano A e Piano B fossero attivi contemporaneamente, **ogni operazione UI emetterebbe due volte** (una dal workflow handler, una dal re-index Solr). I due piani sono quindi mutualmente esclusivi — la migrazione dall'uno all'altro richiede di disattivare l'altro nello stesso commit.

## Coesistenza con il Piano A (transizione)

Se in futuro si decide di passare al Piano B:
1. Aggiungere `OCWebHookIndexPlugin` e registrarlo via INI.
2. Disattivare i trigger `post_hide`/`post_updateobjectstate`/`post_updatesection`/`post_removetranslation`/`post_move`/`post_addlocation` dal workflow (eliminare le righe corrispondenti da `eztrigger`).
3. Rimuovere il listener `OCWebHookObjectFlushListener` dalla configurazione `[Event] Listeners[]`.
4. Rimuovere il `ezpEvent::notify('openpa/object/flushed', ...)` da `OpenPA*Tools::flushObject()`.
5. Conservare `OCWebHookPayloadBuilder` (è utile in entrambi i piani).
6. Conservare il workflow `post_publish` + `DeleteWebHookTrigger` (il plugin non copre publish da CLI quando Solr è disattivato e non copre i delete).

Test di regressione obbligatori al cutover: smoke test sulla tabella di copertura sopra, contando il numero di eventi Kafka per ciascuna operazione (deve essere 1, non 0 e non 2).
