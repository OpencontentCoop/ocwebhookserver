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

        $jobs = [];
        foreach ($webHooks as $webHook) {
            $job = new OCWebHookJob([
                'webhook_id'         => $webHook->attribute('id'),
                'trigger_identifier' => $trigger->getIdentifier(),
                'payload'            => OCWebHookJob::encodePayload($payload),
            ]);
            if (filter_var($job->getSerializedEndpoint(), FILTER_VALIDATE_URL)) {
                $job->store();
                $jobs[] = $job;
            } else {
                eZDebug::writeError("Invalid endpoint url: " . $job->getSerializedEndpoint(), __METHOD__);
            }
        }

        // ── KAFKA PATH ──────────────────────────────────────────────────────
        // I job sono già scritti in DB come outbox (PENDING).
        // Se Kafka conferma la ricezione (acks=all), i job vengono cancellati
        // e non arriveranno mai al worker HTTP.
        // Se Kafka fallisce o va in timeout, i job rimangono PENDING e il
        // worker HTTP li esegue come fallback.
        if (OCWebHookKafkaProducer::isEnabled() && count($jobs) > 0) {
            $sent = OCWebHookKafkaProducer::instance()->produce($trigger->getIdentifier(), $payload);
            if ($sent) {
                foreach ($jobs as $job) {
                    $job->remove();
                }
                return;
            }
        }

        // ── HTTP PATH ───────────────────────────────────────────────────────
        // Eseguito quando Kafka è disabilitato o quando il produce è fallito.
        OCWebHookQueue::instance($queueHandlerIdentifier)
            ->pushJobs($jobs)
            ->execute();
    }
}
