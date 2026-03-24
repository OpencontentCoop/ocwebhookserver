<?php

interface OCWebHookTriggerQueueAwareInterface
{
    /**
     * @return int One of OCWebHookQueue::HANDLER_* constants
     */
    public function getQueueHandler();
}