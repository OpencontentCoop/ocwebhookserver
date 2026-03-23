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
            $endpoint = $job->getSerializedEndpoint();
            $isKafka = strpos($endpoint, 'kafka://') === 0;
            if (!$isKafka && !filter_var($endpoint, FILTER_VALIDATE_URL)) {
                eZDebug::writeError("Invalid endpoint url: " . $endpoint, __METHOD__);
                continue;
            }
            $job->store();
            $jobs[] = $job;
        }

        OCWebHookQueue::instance($queueHandlerIdentifier)->pushJobs($jobs)->execute();
    }
}
