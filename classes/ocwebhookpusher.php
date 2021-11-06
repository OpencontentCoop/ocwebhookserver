<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Response;

class OCWebHookPusher
{
    private $requestTimeout = 60;

    private $verifySsl = true;

    private $signatureHeaderName = 'Signature';

    public function __construct()
    {
        $webhookINI = eZINI::instance('webhook.ini');
        $pusherSettings = $webhookINI->group('PusherSettings');
        if (isset($pusherSettings['RequestTimeout'])) {
            $this->requestTimeout = (int)$pusherSettings['RequestTimeout'];
        }
        if (isset($pusherSettings['VerifySsl'])) {
            $this->verifySsl = $pusherSettings['VerifySsl'] == 'enabled';
        }
        if (isset($pusherSettings['SignatureHeaderName'])) {
            $this->signatureHeaderName = $pusherSettings['SignatureHeaderName'];
        }
    }

    /**
     * @param OCWebHookJob[] $jobs
     * @throws Exception
     */
    public function push($jobs)
    {
        $db = eZDB::instance();
        $databaseImplementation = eZINI::instance()->variable('DatabaseSettings', 'DatabaseImplementation');

        $promises = [];
        foreach ($jobs as $job) {

            $jobId = (int)$job->attribute('id');
            $pendingStatus = OCWebHookJob::STATUS_PENDING;
            $runningStatus = OCWebHookJob::STATUS_RUNNING;
            $hostname = gethostname();
            $pid = getmypid();

            // simple lock system: update execution_status in running only if yet pending
            $query = "UPDATE ocwebhook_job 
                      SET execution_status = $runningStatus,
                          hostname = '$hostname',
                          pid = '$pid'
                      WHERE id = $jobId 
                        AND execution_status = $pendingStatus";
            $result = $db->query($query);
            if ($databaseImplementation == 'ezpostgresql') {
                $isProcessable = pg_affected_rows($result);
            } elseif ($databaseImplementation == 'ezmysqli') {
                $isProcessable = mysqli_affected_rows($result);
            } else {
                throw new Exception("Database implementation $databaseImplementation is not supported");
            }

            if ($isProcessable) {

                $client = new Client();

                $webHook = $job->getWebhook();
                $requestBody = $job->getSerializedPayload();

                $headers = (array)json_decode($webHook->attribute('headers'), true);
                $headers['X-WebHook-Id'] = $webHook->attribute('id');
                $headers['X-WebHook-Name'] = $webHook->attribute('name');
                $headers['X-WebHook-Trigger'] = $job->attribute('trigger_identifier');
                if (!empty($webHook->attribute('secret'))) {
                    $headers[$this->signatureHeaderName] = $this->calculateSignature($requestBody, $webHook->attribute('secret'));
                }

                $promises[$job->attribute('id')] = $client->requestAsync(
                    strtoupper($webHook->attribute('method')),
                    $job->getSerializedEndpoint(),
                    [
                        'timeout' => $this->requestTimeout,
                        'verify' => $this->verifySsl,
                        'headers' => $headers,
                        'json' => $requestBody,
                    ]
                );
            }
        }

        if (count($promises) > 0) {
            $results = Promise\settle($promises)->wait();

            foreach ($results as $id => $result) {
                $job = OCWebHookJob::fetch($id);
                $job->setAttribute('executed_at', time());
                if ($result['state'] == Promise\PromiseInterface::FULFILLED) {
                    /** @var Response $response */
                    $response = $result['value'];
                    $job->setAttribute('execution_status', OCWebHookJob::STATUS_DONE);
                    $job->setAttribute('response_headers', json_encode([
                        'endpoint' => $job->getSerializedEndpoint(),
                        'headers' => $response->getHeaders(),
                        'body' => (string)$response->getBody()
                    ]));
                    $job->setAttribute('response_status', $response->getStatusCode());
                    ezpEvent::getInstance()->notify('webhook/job/success', [$job->attribute('id')]);
                } else {
                    /** @var RequestException $reason */
                    $reason = $result['reason'];
                    $job->setAttribute('execution_status', OCWebHookJob::STATUS_FAILED);
                    if ($reason->hasResponse()) {
                        $job->setAttribute('response_headers', json_encode([
                            'endpoint' => $job->getSerializedEndpoint(),
                            'headers' => $reason->getResponse()->getHeaders(),
                            'body' => (string)$reason->getResponse()->getBody()
                        ]));
                        $job->setAttribute('response_status', $reason->getResponse()->getStatusCode());
                    } else {
                        $job->setAttribute('response_headers', json_encode([
                            'endpoint' => $job->getSerializedEndpoint(),
                            'error' => $reason->getMessage(),
                        ]));
                    }
                    ezpEvent::getInstance()->notify('webhook/job/fail', [$job->attribute('id')]);
                }

                $job->store();
                $job->registerRetryIfNeeded();
            }
        }
    }

    private function calculateSignature($payload, $secret)
    {
        $payloadJson = json_encode($payload);

        return hash_hmac('sha256', $payloadJson, $secret);
    }
}
