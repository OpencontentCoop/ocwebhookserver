<?php

interface OCWebHookTriggerInterface
{
    /**
     * @return string
     */
    public function getIdentifier();

    /**
     * @return string
     */
    public function getName();

    /**
     * @return string
     */
    public function getDescription();

    /**
     * @return bool
     */
    public function canBeEnabled();

    /**
     * @return string|bool JSON Schema as string or false
     */
    public function useFilter();

    /**
     * @param mixed $payload
     * @param mixed $filters
     * @return bool
     */
    public function isValidPayload($payload, $filters);
}
