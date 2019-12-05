<?php

class DummyTrigger implements OCWebHookTriggerInterface
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

    public function useFilter()
    {
        return false;
    }

    public function isValidPayload($payload, $filters)
    {
        return true;
    }

}
