# Piano B — Visibility events via Solr index plugin

> **Stato:** non implementato. Conservato come fallback ripristinabile.
> **Piano A in vigore:** [`2026-05-18-index-plugin-visibility-events.md`](./2026-05-18-index-plugin-visibility-events.md) — emissione ibrida via operation handler workflow + `ezpEvent('openpa/object/flushed')`.

## Quando rivalutare il Piano B

Riprendere in considerazione questo piano se:
- Emerge un caso d'uso "must-have" tra i gap del Piano A (hide subtree → figli, `post_removetranslation`, trash/restore, `post_move` cross-section) e mantenere hook puntuali distinti diventa insostenibile.
- L'obiettivo "dismettere Solr" viene posticipato/abbandonato e quindi la dipendenza dall'index engine non è più un problema.
- Il consumer Kafka inizia a richiedere garanzie "fire-on-reindex" semantiche (ogni re-index Solr ⇒ un evento Kafka).

Se nessuna di queste condizioni si verifica, restiamo sul Piano A.

## Idea centrale

Registrare un `ezpIndexPlugin` custom (`OCWebHookIndexPlugin`) tramite `site.ini`/`ezfind.ini`. `eZSolr::addObject()` invoca `modify(eZContentObject $object, array &$doc)` per ogni plugin registrato durante l'indicizzazione di un oggetto. Il plugin, dopo aver ricevuto l'oggetto, **non modifica** il documento Solr ma usa la callback come singolo entry point per emettere un evento Kafka.

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
| Publish nuova versione | ✅ (re-index immediato) |
| Hide/show nodo singolo | ✅ (re-index in `eZSearch::updateNodeVisibility`) |
| Hide subtree → figli | ✅ (via cron `ezfindexsubtree`, ma ad ogni `addObject` di un figlio) |
| State change UI e cron | ✅ (re-index in `eZSearch::updateObjectState`) |
| Section change UI e cron | ✅ (re-index in `eZSearch::updateObjectsSection`) |
| Translation availability change | ✅ (re-index) |
| Trash / restore | ✅ (re-index al restore; al trash il documento viene rimosso da Solr via `removeObject` → vedi nota delete sotto) |
| Move | ✅ (re-index) |

## Limiti noti del Piano B

1. **Dipendenza da Solr attivo.** Se Solr è disattivato per quel tenant, `eZSolr::addObject` non viene chiamato e nessun evento viene emesso. È il motivo per cui questo piano è in standby.
2. **Delete non coperti da `modify()`.** `modify()` viene invocato solo da `addObject`, NON da `removeObject` (la cancellazione passa altrove). Va mantenuto separato `DeleteWebHookTrigger` per i delete — come oggi.
3. **Possibili eventi "spuri".** Solr re-indicizza anche per cambi che non sono strettamente di visibilità (es. update di un attributo testuale). Lato consumer servirebbe lo stesso diff su `metadata.isPublic` che facciamo già nel Piano A.
4. **Latenza pari a quella di indicizzazione.** Hide/show non emette finché Solr non riceve il documento aggiornato — generalmente sub-second ma non garantito.

## Pezzi tecnici necessari (sketch)

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
     * Chiamato da eZSolr::addObject() PRIMA del flush verso Solr.
     * @param eZContentObject $object  Oggetto in re-indicizzazione
     * @param array           $doc     Documento Solr (NOT mutated)
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
        // NON modificare $doc — questo plugin è solo un side-channel emitter.
    }
}
```

L'interfaccia esatta (`ezpIndexPlugin` o `ezfIndexPlugin` o nome equivalente) va recuperata dal codice ezfind installato. Cercare con:
```bash
grep -rn "interface.*IndexPlugin\|abstract class.*IndexPlugin" html/extension/ html/kernel/ 2>/dev/null
```

### Loop guard

Se il payload-builder o il listener facessero `$object->store()` o qualunque operazione che ritrigger un `addObject`, si entrerebbe in loop infinito. **Il plugin deve essere read-only.**

### Doppio emit con il Piano A

Se Piano A e Piano B venissero attivati contemporaneamente, **ogni operazione UI emetterebbe due volte** (una dal workflow handler, una dal re-index Solr). Quindi i due piani sono mutualmente esclusivi — la migrazione tra l'uno e l'altro richiede di disattivare l'altro nello stesso commit.

## Coesistenza con il Piano A (transition path)

Se in futuro si decide di passare al Piano B:
1. Aggiungere `OCWebHookIndexPlugin` e registrarlo via INI.
2. Disattivare i trigger `post_hide`/`post_updateobjectstate`/`post_updatesection` dal workflow (delete dalle righe `eztrigger`).
3. Rimuovere il listener `OCWebHookObjectFlushListener` dalla configurazione `[Event] Listeners[]`.
4. Rimuovere il `ezpEvent::notify('openpa/object/flushed', ...)` da `OpenPA*Tools::flushObject()`.
5. Conservare `OCWebHookPayloadBuilder` (è utile in entrambi i piani).
6. Conservare `post_publish` workflow + `DeleteWebHookTrigger` (il plugin non copre publish-from-CLI quando Solr è disattivato e non copre delete).

Test di regressione obbligatori al cutover: smoke test della tabella coverage sopra, contando il numero di eventi Kafka per ogni operazione (deve essere 1, non 0 e non 2).
