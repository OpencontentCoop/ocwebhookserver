<?php

interface OCWebHookCustomEndpointSerializerInterface
{
    public function serializeCustomEndpoint($originalEndpoint, $originalPayload, OCWebHook $webHook);
}
