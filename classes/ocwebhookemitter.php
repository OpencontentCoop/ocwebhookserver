<?php

class OCWebHookEmitter
{
    /**
     * @param string $triggerIdentifier
     * @param string|array|Serializable $payload
     * @param string $queueHandlerIdentifier
     */
    public static function emit($triggerIdentifier, $payload, $queueHandlerIdentifier)
    {
        $trigger = OCWebHookTriggerRegistry::registeredTrigger($triggerIdentifier);
        if (!$trigger instanceof OCWebHookTriggerInterface) {
            eZDebug::writeError("Trigger $triggerIdentifier not found", __METHOD__);
            return;
        }

        $webHooks = OCWebHook::fetchEnabledListByTrigger($trigger->getIdentifier());
        foreach ($webHooks as $index => $webHook) {
            $filters = null;
            if ($trigger->useFilter()) {
                $currentTriggers = $webHook->getTriggers();
                foreach ($currentTriggers as $currentTrigger) {
                    if ($currentTrigger['identifier'] == $trigger->getIdentifier()) {
                        $filters = $currentTrigger['filters'];
                    }
                }
            }
            if (!$trigger->isValidPayload($payload, $filters)) {
                unset($webHooks[$index]);
            }
        }

        // ── OUTBOX ──────────────────────────────────────────────────────────
        // Tutti i job vengono scritti in DB come PENDING prima di tentare Kafka.
        // I webhook il cui nome inizia con FallbackWebhookPrefix sono il job di
        // fallback verso Redpanda Connect: vengono cancellati se Kafka ha successo.
        // Gli altri job HTTP (es. motore di ricerca) restano PENDING e vengono
        // eseguiti dal cron indipendentemente dall'esito di Kafka.
        $kafkaFallbackJob = null;
        $httpJobs = [];
        $kafkaPrefix = OCWebHookKafkaProducer::isEnabled()
            ? OCWebHookKafkaProducer::fallbackWebhookPrefix()
            : null;

        foreach ($webHooks as $webHook) {
            $job = new OCWebHookJob([
                'webhook_id'         => $webHook->attribute('id'),
                'trigger_identifier' => $trigger->getIdentifier(),
                'payload'            => OCWebHookJob::encodePayload($payload),
            ]);
            if (!filter_var($job->getSerializedEndpoint(), FILTER_VALIDATE_URL)) {
                eZDebug::writeError("Invalid endpoint url: " . $job->getSerializedEndpoint(), __METHOD__);
                continue;
            }
            $job->store();
            // Dopo store() il job ha l'ID assegnato dal DB
            if ($kafkaPrefix !== null && strpos($webHook->attribute('name'), $kafkaPrefix) === 0) {
                $kafkaFallbackJob = $job;
            } else {
                $httpJobs[] = $job;
            }
        }

        // ── KAFKA PATH ──────────────────────────────────────────────────────
        // Se Kafka conferma la ricezione (acks=all), viene cancellato solo il
        // job di fallback Redpanda Connect (kafka-push-*), non gli altri job HTTP.
        // Se Kafka fallisce o va in timeout, anche il job di fallback resta
        // PENDING e verrà eseguito dal cron insieme agli altri.
        if ($kafkaFallbackJob !== null) {
            $sent = OCWebHookKafkaProducer::instance()->produce($trigger->getIdentifier(), $payload);
            if ($sent) {
                $kafkaFallbackJob->remove();
                return;
            }
            // Kafka fallito: il job di fallback torna in coda con gli altri
            $httpJobs[] = $kafkaFallbackJob;
        }

        // ── HTTP PATH ───────────────────────────────────────────────────────
        // Eseguito quando Kafka è disabilitato o quando il produce è fallito.
        OCWebHookQueue::instance($queueHandlerIdentifier)
            ->pushJobs($httpJobs)
            ->execute();
    }
}
