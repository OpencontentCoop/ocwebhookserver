<?php
// classes/ocsearchengine.php
//
// Search engine wrapper che estende eZSolr aggiungendo emissione Kafka.
// Attivazione per-tenant via env var: EZINI_site__SearchSettings__SearchEngine=OCSearchEngine
//
// PRECONDIZIONI:
//   - eZSolr deve essere caricabile (eZ Find installato).
//   - [SearchSettings] DelayedIndexing=disabled (default eZ).

if (!class_exists('eZSolr')) {
    if (class_exists('eZDebug')) {
        eZDebug::writeError(
            'OCSearchEngine richiede eZSolr/eZFind. Rimuovere SearchEngine=OCSearchEngine da site.ini.',
            __FILE__
        );
    }
    return;
}

class OCSearchEngine extends eZSolr
{
    /**
     * Loop guard: previene una ri-entrata accidentale di emit() → addObject/removeObject.
     * Statico perché eZ può istanziare più volte il search engine nella stessa request.
     */
    private static $emitting = false;

    public function addObject($contentObject, $commit = true, $commitWithin = 0, $softCommit = null)
    {
        // Scelta architetturale: Kafka indipendente da Solr.
        // Se parent::addObject lancia (Solr down/lento), emettiamo Kafka comunque
        // e poi rilanciamo l'eccezione per preservare il comportamento verso eZ.
        $solrException = null;
        $result = false;
        try {
            $result = parent::addObject($contentObject, $commit, $commitWithin, $softCommit);
        } catch (Exception $e) {
            $solrException = $e;
            if (class_exists('eZDebug')) {
                eZDebug::writeError('Solr addObject failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        $this->emitSafely(
            PostPublishWebHookTrigger::IDENTIFIER,
            $contentObject,
            'build'
        );

        if ($solrException !== null) {
            throw $solrException;
        }
        return $result;
    }

    public function removeObject($contentObject, $commit = null, $commitWithin = 0)
    {
        // Stessa policy di addObject: Kafka indipendente da Solr.
        $solrException = null;
        $result = false;
        try {
            $result = parent::removeObject($contentObject, $commit, $commitWithin);
        } catch (Exception $e) {
            $solrException = $e;
            if (class_exists('eZDebug')) {
                eZDebug::writeError('Solr removeObject failed: ' . $e->getMessage(), __METHOD__);
            }
        }

        $this->emitSafely(
            DeleteWebHookTrigger::IDENTIFIER,
            $contentObject,
            'buildMinimal'
        );

        if ($solrException !== null) {
            throw $solrException;
        }
        return $result;
    }

    /**
     * Emette l'evento webhook senza propagare eccezioni al chiamante (Solr deve sempre indicizzare).
     * Loop guard incluso: la chiamata interna a emit() può finire in registerSearchObject
     * → addObject di nuovo; il flag statico previene la doppia emissione.
     *
     * @param string          $triggerIdentifier  es. PostPublishWebHookTrigger::IDENTIFIER
     * @param eZContentObject $contentObject
     * @param string          $builderMethod      'build' per addObject, 'buildMinimal' per removeObject
     */
    protected function emitSafely($triggerIdentifier, $contentObject, $builderMethod)
    {
        if (self::$emitting) {
            return;
        }
        self::$emitting = true;
        try {
            $payload = OCWebHookPayloadBuilder::$builderMethod($contentObject);

            $triggerInstance = OCWebHookTriggerRegistry::registeredTrigger($triggerIdentifier);
            $queueHandler = $triggerInstance instanceof OCWebHookTriggerQueueAwareInterface
                ? $triggerInstance->getQueueHandler()
                : OCWebHookQueue::defaultHandler();

            OCWebHookEmitter::emit($triggerIdentifier, $payload, $queueHandler);
        } catch (Exception $e) {
            if (class_exists('eZDebug')) {
                eZDebug::writeError($e->getMessage(), __METHOD__);
            }
        } finally {
            self::$emitting = false;
        }
    }
}
