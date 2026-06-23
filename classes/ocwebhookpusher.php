<?php

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;
use GuzzleHttp\Psr7\Response;

class OCWebHookPusher
{
    // Riusa il producer rdkafka per tutta la vita del processo — evita la
    // creazione di ~16 thread per ogni messaggio in script batch come emit_all_published.
    private static $kafkaProducers = [];

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
                $endpoint = $job->getSerializedEndpoint();

                if (strpos($endpoint, 'kafka://') === 0) {
                    // kafka://broker1:9092,broker2:9092/topic
                    $withoutScheme = substr($endpoint, strlen('kafka://'));
                    $slashPos = strpos($withoutScheme, '/');
                    $brokers = $slashPos !== false ? substr($withoutScheme, 0, $slashPos) : $withoutScheme;
                    $topic = $slashPos !== false ? substr($withoutScheme, $slashPos + 1) : '';

                    $payload = $job->getSerializedPayload();
                    if (is_array($payload) && isset($payload['metadata'])) {
                        $siteaccess = eZSiteAccess::current();
                        $siteaccessName = isset($siteaccess['name']) ? $siteaccess['name'] : 'default';
                        $kafkaIni = eZINI::instance('webhook.ini');
                        $tenantId  = $kafkaIni->variable('KafkaSettings', 'TenantId') ?: null;
                        // instanceId per entity.meta.id: usa EZ_INSTANCE (es. "opencity"),
                        // non il TenantId UUID — i due concetti sono separati.
                        $instanceId = OpenPABase::getCurrentSiteaccessIdentifier();
                        $formatter = new OCWebHookKafkaPayloadFormatter($siteaccessName, $instanceId, $tenantId, [$this, 'resolveImageUrl'], [$this, 'resolveDocumentFiles']);
                        $payload = $formatter->format($payload);
                    }

                    $retryCount = (int)OCWebHookFailure::count(OCWebHookFailure::definition(), ['job_id' => $jobId]);
                    $cacheKey = $brokers . '|' . $topic;
                    if (!isset(self::$kafkaProducers[$cacheKey])) {
                        self::$kafkaProducers[$cacheKey] = new OCWebHookKafkaProducer($brokers, $topic);
                    }
                    $kafkaProducer = self::$kafkaProducers[$cacheKey];
                    $sent = $kafkaProducer->produce(
                        $job->attribute('trigger_identifier'),
                        $payload,
                        $retryCount
                    );

                    $job = OCWebHookJob::fetch($jobId);
                    $job->setAttribute('executed_at', time());
                    if ($sent) {
                        $job->setAttribute('execution_status', OCWebHookJob::STATUS_DONE);
                        $job->setAttribute('response_headers', json_encode([
                            'endpoint' => $endpoint,
                        ]));
                        $job->setAttribute('response_status', 0);
                        ezpEvent::getInstance()->notify('webhook/job/success', [$job->attribute('id')]);
                    } else {
                        $job->setAttribute('execution_status', OCWebHookJob::STATUS_FAILED);
                        $job->setAttribute('response_headers', json_encode([
                            'endpoint' => $endpoint,
                            'error' => 'Kafka produce failed',
                        ]));
                        ezpEvent::getInstance()->notify('webhook/job/fail', [$job->attribute('id')]);
                    }
                    $job->store();
                    $job->registerRetryIfNeeded();
                    continue;
                }

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
                    $endpoint,
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
                    if ($reason instanceof RequestException && $reason->hasResponse()) {
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

    /**
     * Resolve the attached files of a document content object for inclusion in Kafka payloads.
     * Used as the $documentFilesResolver callable passed to OCWebHookKafkaPayloadFormatter.
     *
     * @param int|string $objectId  eZContentObject id of the document
     * @return array|null           Array of file items [{filename, url, displayName, ...}], or null
     */
    public function resolveDocumentFiles($objectId)
    {
        static $environment = null;
        if ($environment === null) {
            $environment = new DefaultEnvironmentSettings();
        }
        try {
            $repo = new \Opencontent\Opendata\Api\ContentRepository();
            $repo->setEnvironment($environment);
            $raw = (array)$repo->read((int)$objectId);
        } catch (\Exception $e) {
            return null;
        }
        foreach ($raw['data'] ?? [] as $lang => $attrs) {
            foreach ($attrs as $attrValue) {
                if (is_array($attrValue)
                    && isset($attrValue[0])
                    && is_array($attrValue[0])
                    && isset($attrValue[0]['filename'])
                ) {
                    return array_map(function ($fileItem) {
                        if (isset($fileItem['url'])) {
                            $fileItem['url'] = OCWebHookPayloadBuilder::forceHttps($fileItem['url']);
                        }
                        return $fileItem;
                    }, $attrValue);
                }
            }
            break; // First language is enough
        }
        return null;
    }

    /**
     * Resolve the URL of an image content object for inclusion in Kafka payloads.
     * Used as the $imageUrlResolver callable passed to OCWebHookKafkaPayloadFormatter.
     *
     * @param int|string  $objectId  eZContentObject id
     * @param string|null $siteUrl   Base site URL (e.g. "https://www.comune.example.it")
     * @return string|null           Absolute image URL, or null if not resolvable
     */
    public function resolveImageUrl($objectId, $siteUrl)
    {
        $object = eZContentObject::fetch((int)$objectId);
        if (!$object instanceof eZContentObject) {
            return null;
        }
        foreach ($object->attribute('data_map') as $attribute) {
            if ($attribute->attribute('data_type_string') !== 'ezimage') {
                continue;
            }
            if (!$attribute->attribute('has_content')) {
                continue;
            }
            $aliasHandler = $attribute->attribute('content');
            if (!$aliasHandler instanceof eZImageAliasHandler) {
                continue;
            }
            $original = $aliasHandler->imageAlias('original');
            if (isset($original['url']) && $original['url']) {
                $url = $original['url'];
                if (strpos($url, 'http') !== 0 && $siteUrl !== null) {
                    $url = rtrim($siteUrl, '/') . '/' . ltrim($url, '/');
                }
                return OCWebHookPayloadBuilder::forceHttps($url);
            }
        }
        return null;
    }

    private function calculateSignature($payload, $secret)
    {
        $payloadJson = json_encode($payload);

        return hash_hmac('sha256', $payloadJson, $secret);
    }
}
