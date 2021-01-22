<?php

class OCWebHookTriggerRegistry
{
    private static $loaded = false;

    /**
     * @var OCWebHookTriggerInterface[]
     */
    private static $triggers = [];

    private static function loadAndRegisterTriggers()
    {
        if (self::$loaded === false) {
            self::$triggers = [];

            $webhookINI = eZINI::instance('webhook.ini');

            if ($webhookINI->hasVariable('TriggersSettings', 'TriggerList')) {
                $triggerList = $webhookINI->variable('TriggersSettings', 'TriggerList');
                foreach ($triggerList as $triggerClassName) {
                    OCWebHookTriggerRegistry::registerTrigger(new $triggerClassName());
                }
            }

            if ($webhookINI->hasVariable('TriggersSettings', 'TriggerFactoryList')) {
                $triggerFactoryList = $webhookINI->variable('TriggersSettings', 'TriggerFactoryList');
                foreach ($triggerFactoryList as $triggerFactoryClass) {
                    /** @var OCWebHookTriggerFactoryInterface $triggerFactory */
                    $triggerFactory = new $triggerFactoryClass();
                    foreach ($triggerFactory->getTriggers() as $trigger) {
                        OCWebHookTriggerRegistry::registerTrigger($trigger);
                    }
                }
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
                'use_filter' => $trigger->useFilter(),
                'can_customize_payload' => $trigger instanceof OCWebHookCustomPayloadSerializerInterface,
                'available_payload_placeholders' => $trigger instanceof OCWebHookCustomPayloadSerializerInterface ? $trigger->getPlaceholders() : [],
                'help_text' => $trigger instanceof OCWebHookCustomPayloadSerializerInterface ? $trigger->getHelpText() : '',
            ];
        }

        return $triggers;
    }

    /**
     * @param $identifier
     * @return OCWebHookTriggerInterface|null
     */
    public static function registeredTrigger($identifier)
    {
        self::loadAndRegisterTriggers();

        return isset(self::$triggers[$identifier]) ? self::$triggers[$identifier] : null;
    }
}