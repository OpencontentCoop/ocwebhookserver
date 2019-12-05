<?php

use GuzzleHttp\Client;
use GuzzleHttp\Promise;

class OCWebHookPusher
{
    private $requestTimeout = 3;

    private $verifySsl = true;

    private $signatureHeaderName = 'Signature';

    /**
     * @param OCWebHookJob[] $jobs
     */
    public function push($jobs)
    {
        $promises = [];
        foreach ($jobs as $job){
            $webHook = $job->getWebhook();

            $requestBody = [
                'webhook' => $webHook->attribute('name'),
                'trigger' => $job->attribute('trigger_identifier'),
                'payload' => json_decode($job->attribute('payload'), true),
            ];

            $client = new Client();

            $headers = (array)json_decode($webHook->attribute('headers'), true);
            if (!empty($webHook->attribute('secret'))){
                $headers[$this->signatureHeaderName] = $this->calculateSignature($requestBody, $webHook->attribute('secret'));
            }

            $promises[$job->attribute('id')] = $client->requestAsync(
                strtoupper($webHook->attribute('method')),
                $webHook->attribute('url'),
                [
                    'timeout' => $this->requestTimeout,
                    'verify' => $this->verifySsl,
                    'headers' => $headers,
                    'json' => $requestBody,
                ]
            );

            $job->setAttribute('execution_status', OCWebHookJob::STATUS_RUNNING);
            $job->store();
        }

        $results = Promise\settle($promises)->wait();

        foreach ($results as $id => $result){
            $job = OCWebHookJob::fetch($id);
            $job->setAttribute('executed_at', time());
            if ($result['state'] == Promise\PromiseInterface::FULFILLED) {
                /** @var \GuzzleHttp\Psr7\Response $response */
                $response = $result['value'];
                $job->setAttribute('execution_status', OCWebHookJob::STATUS_DONE);
                $job->setAttribute('response_headers', json_encode($response->getHeaders()));
                $job->setAttribute('response_status', $response->getStatusCode());
            }else{
                /** @var \GuzzleHttp\Exception\RequestException $reason */
                $reason = $result['reason'];
                $job->setAttribute('execution_status', OCWebHookJob::STATUS_FAILED);
                if ($reason->hasResponse()){
                    $job->setAttribute('response_headers', json_encode($reason->getResponse()->getHeaders()));
                    $job->setAttribute('response_status', $reason->getResponse()->getStatusCode());
                }else{
                    $job->setAttribute('response_headers', $reason->getMessage());
                }
            }


            $job->store();
        }
    }

    private function calculateSignature($payload, $secret)
    {
        $payloadJson = json_encode($payload);

        return hash_hmac('sha256', $payloadJson, $secret);
    }
}