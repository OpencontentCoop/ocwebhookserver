<?php

class OCWebHookEndpointProvider extends ezpRestApiProvider
{
    public function getRoutes()
    {
        $webhook = new PostPublishWebHookTrigger();
        return [
            'webhook-endpoint-ping' => new OcOpenDataVersionedRoute(
                new OcOpenDataRoute(
                    '/ping',
                    'OCWebHookEndpointController',
                    'ping',
                    array(),
                    'http-get',
                    null,
                    'Ping'
                ), 1
            ),
            'webhook-endpoint-import' => new OcOpenDataVersionedRoute(
                new OcOpenDataRoute(
                    '/endpoint/:ParentNodeId',
                    'OCWebHookEndpointController',
                    'import',
                    array(),
                    'http-post',
                    null,
                    'Import data from ' . $webhook->getName() . 'web hook format'
                ), 1
            ),
        ];
    }
}