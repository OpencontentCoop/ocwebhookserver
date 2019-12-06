<?php

class OCWebHookQueue
{
    const HANDLER_IMMEDIATE = 1;

    const HANDLER_SCHEDULED = 2;

    private $handlerIdentifier;

    private $pusher;

    /**
     * @var OCWebHookJob[]
     */
    private $jobs = [];

    public static function instance($queueHandlerIdentifier)
    {
        return new OCWebHookQueue($queueHandlerIdentifier);
    }

    private function __construct($queueHandlerIdentifier)
    {
        $this->handlerIdentifier = $queueHandlerIdentifier;
        $this->pusher = new OCWebHookPusher();
    }

    /**
     * @param OCWebHookJob[] $jobs
     * @return $this
     */
    public function pushJobs($jobs)
    {
        $this->jobs = array_merge($this->jobs, $jobs);

        return $this;
    }

    public function execute()
    {
        if ($this->handlerIdentifier == self::HANDLER_IMMEDIATE){
            $this->pusher->push($this->jobs);
        }
    }

    public static function defaultHandler()
    {
        return self::HANDLER_SCHEDULED;
    }
}