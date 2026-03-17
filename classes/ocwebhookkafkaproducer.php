<?php

class OCWebHookKafkaProducer
{
    private static $instance = null;

    /** @var RdKafka\Producer */
    private $producer;

    private $topic;

    private $flushTimeout;

    public static function isEnabled()
    {
        if (!extension_loaded('rdkafka')) {
            return false;
        }
        $ini = eZINI::instance('webhook.ini');
        return $ini->variable('KafkaSettings', 'Enabled') === 'enabled';
    }

    /**
     * @return OCWebHookKafkaProducer
     */
    public static function instance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $ini = eZINI::instance('webhook.ini');
        $brokers = implode(',', (array)$ini->variableArray('KafkaSettings', 'Brokers'));
        $this->topic = $ini->variable('KafkaSettings', 'Topic');
        $this->flushTimeout = (int)$ini->variable('KafkaSettings', 'FlushTimeoutMs');

        $conf = new RdKafka\Conf();
        $conf->set('metadata.broker.list', $brokers);
        // acks=all: MSK non darà ack finché min.insync.replicas non hanno scritto il messaggio
        $conf->set('acks', 'all');
        $conf->set('retries', '3');
        $conf->set('retry.backoff.ms', '200');

        $this->producer = new RdKafka\Producer($conf);
    }

    /**
     * Produce un messaggio su Kafka e attende l'ack del broker.
     *
     * @param string $triggerIdentifier usato come message key (garantisce ordering per tipo evento)
     * @param array  $payload
     * @return bool  true se l'ack è arrivato entro FlushTimeoutMs, false altrimenti
     */
    public function produce($triggerIdentifier, $payload)
    {
        try {
            $topic = $this->producer->newTopic($this->topic);
            $topic->produce(
                RD_KAFKA_PARTITION_UA,
                0,
                json_encode($payload),
                $triggerIdentifier
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
