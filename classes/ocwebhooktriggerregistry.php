<?php

class OCWebhookTriggerRegistry
{
    private static $loaded = false;

    private static $triggers = [];

    private static function loadAndRegisterTriggers()
    {
        if (self::$loaded === false) {
            self::$triggers = [];

            $webhookINI = eZINI::instance('webhook.ini');
            $triggerList = $webhookINI->variable('TriggersSettings', 'TriggerList');

            foreach ($triggerList as $triggerClassName) {
                OCWebhookTriggerRegistry::registerTrigger(new $triggerClassName());
            }
        }
        self::$loaded = true;
    }

    private static function registerTrigger(OCWebHookTriggerInterface $trigger)
    {
        self::$triggers[$trigger->getIdentifier()] = $trigger;
    }

    public static function registeredTriggers()
    {
        self::loadAndRegisterTriggers();

        return self::$triggers;
    }

    public static function registeredTriggersAsArray()
    {
        self::loadAndRegisterTriggers();
        $triggers = [];
        foreach (self::$triggers as $trigger){
            $triggers[] = [
                'name' => $trigger->getName(),
                'identifier' => $trigger->getIdentifier(),
                'description' => $trigger->getDescription(),
                'can_enabled' => $trigger->canBeEnabled(),
            ];
        }

        return $triggers;
    }

    /**
     * @param $name
     * @return OCWebHookTriggerInterface|null
     */
    public static function registeredTrigger($name)
    {
        self::loadAndRegisterTriggers();

        return isset(self::$triggers[$name]) ? self::$triggers[$name] : null;
    }
}