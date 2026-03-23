<?php

class OCWebHookKafkaProducer
{
    /** @var RdKafka\Producer */
    private $producer;

    private $topic;

    private $flushTimeout;

    private $tenantId;

    private $productSlug;

    private $appName;

    private $appVersion;

    private $ceTypeMap;

    /**
     * @param string $brokers Comma-separated list of brokers (e.g. "broker1:9092,broker2:9092")
     * @param string $topic   Kafka topic name
     */
    public function __construct($brokers, $topic)
    {
        $ini = eZINI::instance('webhook.ini');
        $this->topic = $topic;
        $this->flushTimeout = (int)$ini->variable('KafkaSettings', 'FlushTimeoutMs');
        $this->tenantId = $ini->variable('KafkaSettings', 'TenantId');
        $this->productSlug = $ini->variable('KafkaSettings', 'ProductSlug');
        $this->appName = $ini->variable('KafkaSettings', 'AppName');
        $this->appVersion = $ini->variable('KafkaSettings', 'AppVersion');
        $this->ceTypeMap = $ini->hasGroup('KafkaCeTypeMap')
            ? $ini->group('KafkaCeTypeMap')
            : [];

        $conf = new RdKafka\Conf();
        $conf->set('metadata.broker.list', $brokers);
        // acks=all: MSK non darà ack finché min.insync.replicas non hanno scritto il messaggio
        $conf->set('acks', 'all');
        $conf->set('retries', '3');
        $conf->set('retry.backoff.ms', '200');

        $this->producer = new RdKafka\Producer($conf);
    }

    /**
     * Genera un UUID v4 (RFC 4122) usando random_bytes().
     *
     * @return string es. "550e8400-e29b-4d3c-a456-426614174000"
     */
    private static function generateUuid()
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // version 4
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // variant RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Costruisce gli header CloudEvents per il messaggio Kafka.
     *
     * @param string $triggerIdentifier
     * @return array
     */
    private function buildHeaders($triggerIdentifier)
    {
        $ceTypeSuffix = isset($this->ceTypeMap[$triggerIdentifier])
            ? $this->ceTypeMap[$triggerIdentifier]
            : $triggerIdentifier;
        $ceType = 'it.opencity.' . $this->productSlug . '.' . $ceTypeSuffix;
        $ceSource = 'urn:opencity:' . $this->productSlug . ':' . $this->tenantId;

        return [
            'ce_specversion' => '1.0',
            'ce_id'          => self::generateUuid(),
            'ce_type'        => $ceType,
            'ce_source'      => $ceSource,
            'ce_time'        => date('c'),
            'content-type'   => 'application/json',
            'oc_app_name'    => $this->appName,
            'oc_app_version' => $this->appVersion,
        ];
    }

    /**
     * Produce un messaggio su Kafka e attende l'ack del broker.
     *
     * @param string $triggerIdentifier usato per gli header CloudEvents
     * @param array  $payload
     * @return bool  true se l'ack è arrivato entro FlushTimeoutMs, false altrimenti
     */
    public function produce($triggerIdentifier, $payload)
    {
        if (empty($this->tenantId)) {
            eZDebug::writeError(
                'KafkaSettings.TenantId non configurato: evento Kafka non emesso per trigger ' . $triggerIdentifier . '. ' .
                'Configurare EZINI_webhook__KafkaSettings__TenantId per abilitare la produzione.',
                __CLASS__
            );
            return false;
        }

        try {
            $topic = $this->producer->newTopic($this->topic);
            $topic->producev(
                RD_KAFKA_PARTITION_UA,
                0,
                json_encode($payload),
                $this->tenantId ?: null,
                $this->buildHeaders($triggerIdentifier)
            );

            $result = $this->producer->flush($this->flushTimeout);

            if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                eZDebug::writeError(
                    'Kafka flush timeout or error (code ' . $result . ') for trigger ' . $triggerIdentifier,
                    __CLASS__
                );
                return false;
            }

            return true;
        } catch (Exception $e) {
            eZDebug::writeError('Kafka produce failed: ' . $e->getMessage(), __CLASS__);
            return false;
        }
    }
}
