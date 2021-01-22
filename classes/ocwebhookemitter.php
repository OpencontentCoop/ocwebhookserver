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
        if ($trigger instanceof OCWebHookTriggerInterface) {
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
                    'webhook_id' => $webHook->attribute('id'),
                    'trigger_identifier' => $trigger->getIdentifier(),
                    'payload' => OCWebHookJob::encodePayload($payload),
                ]);
                if (filter_var($job->getSerializedEndpoint(), FILTER_VALIDATE_URL)) {
                    $job->store();
                    $jobs[] = $job;
                }else{
                    eZDebug::writeError("Invalid endpoint url: " . $job->getSerializedEndpoint(), __METHOD__);
                }
            }

            OCWebHookQueue::instance($queueHandlerIdentifier)
                ->pushJobs($jobs)
                ->execute();

        } else {
            eZDebug::writeError("Trigger $triggerIdentifier not found", __METHOD__);
        }
    }
}