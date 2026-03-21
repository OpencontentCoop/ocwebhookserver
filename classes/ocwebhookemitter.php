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

        // INI-based direct Kafka produce.
        // When KafkaSettings.Enabled=enabled the emitter produces directly to the
        // broker/topic from webhook.ini, independently of any DB webhook record.
        // Best-effort: errors are logged but do not block content publication.
        self::emitToIniKafka($trigger->getIdentifier(), $payload);
    }

    private static function emitToIniKafka($triggerIdentifier, $payload)
    {
        $ini = eZINI::instance('webhook.ini');

        if ($ini->variable('KafkaSettings', 'Enabled') !== 'enabled') {
            return;
        }

        // Brokers can be set as array in INI (Brokers[]) or via env vars as indexed
        // keys (Brokers_0, Brokers_1, ...) — collect both.
        $group = $ini->group('KafkaSettings');
        $brokersList = isset($group['Brokers']) && is_array($group['Brokers']) ? $group['Brokers'] : [];
        for ($i = 0; isset($group["Brokers_$i"]); $i++) {
            $brokersList[] = $group["Brokers_$i"];
        }
        $topic = $ini->variable('KafkaSettings', 'Topic');

        if (empty($brokersList) || empty($topic)) {
            return;
        }

        $brokers = implode(',', $brokersList);

        $formattedPayload = $payload;
        if (is_array($payload) && isset($payload['metadata'])) {
            $siteaccess = eZSiteAccess::current();
            $siteaccessName = isset($siteaccess['name']) ? $siteaccess['name'] : 'default';
            $formatter = new OCWebHookKafkaPayloadFormatter($siteaccessName, getenv('EZ_INSTANCE') ?: null);
            $formattedPayload = $formatter->format($payload);
        }

        $producer = new OCWebHookKafkaProducer($brokers, $topic);
        $producer->produce($triggerIdentifier, $formattedPayload);
    }
}
