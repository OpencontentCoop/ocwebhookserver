<?php

class OCWebHookFailure extends eZPersistentObject
{
    const CALC_BASE = 2;

    const CALC_GAP = 8;

    const MAX_RETRY_COUNT = 9;

    public static function definition()
    {
        return [
            'fields' => [
                'id' => [
                    'name' => 'ID',
                    'datatype' => 'integer',
                    'default' => null,
                    'required' => true
                ],
                'job_id' => [
                    'name' => 'job_id',
                    'datatype' => 'integer',
                    'default' => null,
                    'required' => true
                ],
                'executed_at' => [
                    'name' => 'executed_at',
                    'datatype' => 'integer',
                    'default' => null,
                    'required' => false
                ],
                'response_headers' => [
                    'name' => 'response_headers',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => true
                ],
                'response_status' => [
                    'name' => 'response_status',
                    'datatype' => 'integer',
                    'default' => null,
                    'required' => true
                ],
                'hostname' => [
                    'name' => 'hostname',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => false
                ],
                'pid' => [
                    'name' => 'pid',
                    'datatype' => 'string',
                    'default' => null,
                    'required' => false
                ],
                'scheduled' => [
                    'name' => 'scheduled',
                    'datatype' => 'integer',
                    'default' => 0,
                    'required' => true
                ],
            ],
            'keys' => ['id'],
            'increment_key' => 'id',
            'class_name' => 'OCWebHookFailure',
            'name' => 'ocwebhook_failure',
            'function_attributes' => [
                'job' => 'getJob',
            ]
        ];
    }

    public static function register(OCWebHookJob $job)
    {
        $db = eZDB::instance();
        $db->begin();

        $jobId = (int)$job->attribute('id');
        $db->query("UPDATE ocwebhook_failure SET scheduled = 0 WHERE job_id = $jobId");

        $failureJob = new OCWebHookFailure([
            'job_id' => $job->attribute('id'),
            'executed_at' => $job->attribute('executed_at'),
            'response_headers' => $job->attribute('response_headers'),
            'response_status' => $job->attribute('response_status'),
            'hostname' => $job->attribute('hostname'),
            'pid' => $job->attribute('pid'),
        ]);
        $failureJob->store();

        $job->setAttribute('execution_status', OCWebHookJob::STATUS_RETRYING);
        $job->store();

        $db->commit();
    }

    public static function cleanup($jobId)
    {
        $db = eZDB::instance();
        $db->begin();
        $jobId = (int)$jobId;
        $db->query("DELETE FROM ocwebhook_failure WHERE job_id = $jobId");
        $db->commit();
    }

    public static function fetchListByJob(OCWebHookJob $job)
    {
        return self::fetchObjectList(self::definition(), null, ['job_id' => (int)$job->attribute('id')], ['id' => 'asc']);
    }

    public static function getNextRetryByJob(OCWebHookJob $job)
    {
        $failures = self::fetchForSchedule($job->attribute('id'));
        foreach ($failures as $index => $failure) {
            if ($failure['job_id'] == $job->attribute('id') && $failure['retry_count'] < self::MAX_RETRY_COUNT){
                return self::calculateNextRetry($failure['retry_count'], $failure['last_executed_at']);
            }
        }

        return false;
    }

    public static function fetchForSchedule($jobId = null)
    {
        $db = eZDB::instance();
        $byJobId = '';
        if ($jobId){
            $byJobId = "AND ocwhf.job_id = " . (int)$jobId;
        }
        $query = "SELECT count(ocwhf.job_id) as retry_count, ocwhf.job_id, max(ocwhf.scheduled) as scheduled, max(ocwhf.executed_at) as last_executed_at, max(ocwhj.created_at) as created_at 
                        FROM ocwebhook_failure as ocwhf 
                        JOIN ocwebhook_job as ocwhj ON ocwhj.id = ocwhf.job_id 
                        WHERE ocwhf.scheduled = 0 $byJobId
                        GROUP BY ocwhf.job_id 
                        ORDER BY last_executed_at asc;";
        return $db->arrayQuery($query);
    }

    public static function calculateNextRetry($retryCount, $lastExecutedAt)
    {
        $time = pow(self::CALC_BASE, ($retryCount + self::CALC_GAP));

        return $time + $lastExecutedAt;
    }

    public static function scheduleRetries()
    {
        $now = time();

        $db = eZDB::instance();

        $failures = self::fetchForSchedule();
        foreach ($failures as $index => $failure) {

            $jobId = (int)$failure['job_id'];
            $pendingStatus = OCWebHookJob::STATUS_PENDING;
            $retryingStatus = OCWebHookJob::STATUS_RETRYING;
            $failedStatus = OCWebHookJob::STATUS_FAILED;

            if ($failure['retry_count'] > self::MAX_RETRY_COUNT) {
                eZDebug::writeDebug("Maximum number of attempts reached for job #$jobId", __METHOD__);
                $db->begin();
                self::cleanup($failure['job_id']);
                $db->query("UPDATE ocwebhook_job SET execution_status = $failedStatus WHERE id = $jobId AND execution_status = $retryingStatus");
                $db->commit();
            }
            $nextRetry = self::calculateNextRetry($failure['retry_count'], $failure['last_executed_at']);
            if ($now >= $nextRetry) {
                eZDebug::writeDebug("Do schedule a new retry for job #$jobId", __METHOD__);
                $db->begin();
                $db->query("UPDATE ocwebhook_job SET execution_status = $pendingStatus WHERE id = $jobId AND execution_status = $retryingStatus");
                $db->query("UPDATE ocwebhook_failure SET scheduled = 1 WHERE job_id = $jobId AND scheduled = 0");
                $db->commit();
            }
        }
    }

    public function getJob()
    {
        return OCWebHookJob::fetch($this->attribute('job_id'));
    }
}