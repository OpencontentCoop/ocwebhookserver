<?php

class DummyTrigger implements OCWebHookTriggerInterface, OCWebHookTriggerQueueAwareInterface
{
    const IDENTIFIER = 'dummy_example';

    public function getIdentifier()
    {
        return self::IDENTIFIER;
    }

    public function getName()
    {
        return 'Dummy trigger';
    }

    public function getDescription()
    {
        return 'Dummy event never triggerd only for test';
    }

    public function canBeEnabled()
    {
        return true;
    }

    public function useFilter()
    {
        return false;
    }

    public function isValidPayload($payload, $filters)
    {
        return true;
    }

    public function getQueueHandler()
    {
        return OCWebHookQueue::HANDLER_SCHEDULED;
    }

}
