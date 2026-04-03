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

    /** @var string|null Errore riportato dal delivery report callback, null se nessun errore */
    private $deliveryError;

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
        $appVersion = $ini->variable('KafkaSettings', 'AppVersion');
        if (empty($appVersion) && class_exists('Composer\InstalledVersions')) {
            $appVersion = \Composer\InstalledVersions::getPrettyVersion('opencontent/ocwebhookserver-ls') ?: '';
        }
        $this->appVersion = $appVersion;
        $this->ceTypeMap = $ini->hasGroup('KafkaCeTypeMap')
            ? $ini->group('KafkaCeTypeMap')
            : [];

        $conf = new RdKafka\Conf();
        $conf->set('metadata.broker.list', $brokers);
        // acks=all: MSK non darà ack finché min.insync.replicas non hanno scritto il messaggio
        $conf->set('acks', 'all');
        $conf->set('retries', '3');
        $conf->set('retry.backoff.ms', '200');

        // Delivery report callback: senza questo, gli errori di consegna vengono
        // silenziosamente scartati e flush() ritorna RD_KAFKA_RESP_ERR_NO_ERROR
        // anche quando i messaggi non sono stati consegnati al broker.
        $conf->setDrMsgCb(function ($kafka, $message) {
            if ($message->err !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                $errMsg = rd_kafka_err2str($message->err) .
                    ' (topic: ' . $message->topic_name . ', partition: ' . $message->partition . ')';
                eZDebug::writeError('Kafka delivery error: ' . $errMsg, __CLASS__);
                $this->deliveryError = $errMsg;
            }
        });

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
     * Formato ce_type: it.opencity.{productSlug}.{entityType}.{operation_past_tense}
     * es. it.opencity.cms.article.created / it.opencity.cms.event.updated
     *
     * entityType:
     *   1. entity.meta.type_id del payload (es. "article", "event")
     *   2. ceTypeMap[triggerIdentifier] come fallback
     *   3. triggerIdentifier come ultimo fallback
     *
     * Operazione (past tense):
     *   - trigger contiene "delete" → "deleted"
     *   - entity.meta.version == 1  → "created"
     *   - entity.meta.version > 1   → "updated"
     *   - nessuna versione nel payload → "updated"
     *
     * @param string     $triggerIdentifier
     * @param array|null $payload           Payload formattato (entity.meta.type_id e version usati)
     * @param int        $retryCount        Numero di tentativi precedenti falliti (0 = prima consegna)
     * @return array
     */
    private function buildHeaders($triggerIdentifier, $payload = null, $retryCount = 0)
    {
        // Determina entity type: type_id dal payload > ceTypeMap[trigger] > trigger
        $typeId = is_array($payload) && isset($payload['entity']['meta']['type_id'])
            ? $payload['entity']['meta']['type_id']
            : null;
        if ($typeId !== null) {
            $entityType = isset($this->ceTypeMap[$typeId]) ? $this->ceTypeMap[$typeId] : $typeId;
        } else {
            $entityType = isset($this->ceTypeMap[$triggerIdentifier])
                ? $this->ceTypeMap[$triggerIdentifier]
                : $triggerIdentifier;
        }

        // Determina l'operazione al passato
        if (strpos($triggerIdentifier, 'delete') !== false) {
            $operation = 'deleted';
        } elseif (is_array($payload) && isset($payload['entity']['meta']['version'])) {
            $operation = ((int)$payload['entity']['meta']['version'] === 1) ? 'created' : 'updated';
        } else {
            $operation = 'updated';
        }

        $ceType   = 'it.opencity.' . $this->productSlug . '.' . $entityType . '.' . $operation;
        $ceSource = 'urn:opencity:' . $this->productSlug . ':' . $this->tenantId;

        return [
            'ce_specversion'  => '1.0',
            'ce_id'           => self::generateUuid(),
            'ce_type'         => $ceType,
            'ce_source'       => $ceSource,
            'ce_time'         => gmdate('Y-m-d\TH:i:s\Z'),
            'content-type'    => 'application/json',
            'oc_app_name'     => $this->appName,
            'oc_app_version'  => $this->appVersion,
            'oc_operation'    => $operation,
            'oc_retry_count'  => (string)(int)$retryCount,
        ];
    }

    /**
     * Produce un messaggio su Kafka e attende l'ack del broker.
     *
     * @param string $triggerIdentifier usato per gli header CloudEvents
     * @param array  $payload
     * @param int    $retryCount        Numero di tentativi precedenti falliti (0 = prima consegna diretta)
     * @return bool  true se l'ack è arrivato entro FlushTimeoutMs, false altrimenti
     */
    public function produce($triggerIdentifier, $payload, $retryCount = 0)
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
            $this->deliveryError = null;

            $topic = $this->producer->newTopic($this->topic);
            // La partition key è sempre il TenantId: tutti i messaggi dello stesso
            // tenant finiscono sulla stessa partizione, garantendo ordinamento temporale.
            $messageKey = $this->tenantId;

            $topic->producev(
                RD_KAFKA_PARTITION_UA,
                0,
                json_encode($payload),
                $messageKey,
                $this->buildHeaders($triggerIdentifier, $payload, $retryCount)
            );

            $result = $this->producer->flush($this->flushTimeout);

            if ($result !== RD_KAFKA_RESP_ERR_NO_ERROR) {
                eZDebug::writeError(
                    'Kafka flush timeout or error (code ' . $result . ') for trigger ' . $triggerIdentifier,
                    __CLASS__
                );
                return false;
            }

            if ($this->deliveryError !== null) {
                return false;
            }

            return true;
        } catch (Exception $e) {
            eZDebug::writeError('Kafka produce failed: ' . $e->getMessage(), __CLASS__);
            return false;
        }
    }
}
