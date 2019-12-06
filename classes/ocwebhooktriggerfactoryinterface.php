<?php

interface OCWebHookTriggerFactoryInterface
{
    /**
     * @return OCWebHookTriggerInterface[]
     */
    public function getTriggers();
}