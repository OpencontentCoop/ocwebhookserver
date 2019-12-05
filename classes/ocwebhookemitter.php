<?php

class OCWebHookEmitter
{
    /**
     * @param string $triggerName
     * @param string|array|JsonSerializable $payload
     * @param string $queueHandlerIdentifier
     */
    public static function emit($triggerName, $payload, $queueHandlerIdentifier)
    {
        $trigger = OCWebhookTriggerRegistry::registeredTrigger($triggerName);
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
                    'payload' => json_encode($payload),
                ]);
                $job->store();
                $jobs[] = $job;
            }

            OCWebHookQueue::instance($queueHandlerIdentifier)
                ->pushJobs($jobs)
                ->execute();

        }else{
            eZDebug::writeError("Trigger $triggerName not found", __METHOD__);
        }
    }
}