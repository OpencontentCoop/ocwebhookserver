<?php

interface OCWebHookCustomPayloadSerializerInterface extends OCWebHookTriggerInterface
{
    public function serializeCustomPayload($originalPayload, OCWebHook $webHook);

    public function getPlaceholders();

    public function getHelpText();
}