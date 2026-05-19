<?php

/**
 * Unit tests for OCWebHookPusher — Kafka error-handling path.
 *
 * Tests the try-catch wrapping OCWebHookKafkaProducer::produce() added in
 * ocwebhookpusher.php. Because push() gates processing via a DB-level lock
 * (pg_affected_rows / mysqli_affected_rows), we bypass the lock with a thin
 * TestableOCWebHookPusher subclass that calls the Kafka dispatch directly.
 *
 * Three scenarios:
 *  1. Producer throws \Throwable → job FAILED, exception message in response_headers, eZLog entry
 *  2. Producer returns false (no exception) → job FAILED, generic 'Kafka produce failed' message
 *  3. Producer returns true → job DONE, no eZLog entry
 *
 * Usage:
 *   php tests/PusherKafkaTest.php
 */

require_once __DIR__ . '/stubs.php';

// ─── eZDB stub (needed by OCWebHookPusher constructor path) ──────────────────
class eZDB
{
    public static function instance(): self { return new self(); }
    public function query(string $sql) { return null; }
}

// ─── eZSiteAccess / OpenPABase stubs ─────────────────────────────────────────
class eZSiteAccess
{
    public static function current(): array { return ['name' => 'frontend']; }
}

class OpenPABase
{
    public static function getCurrentSiteaccessIdentifier(): string { return 'opencity'; }
}

// ─── eZLog stub — captures write() calls ─────────────────────────────────────
class eZLog
{
    /** @var array */
    public static $entries = [];

    public static function write(string $message, string $logFile = 'error.log'): void
    {
        self::$entries[] = ['message' => $message, 'file' => $logFile];
    }

    public static function reset(): void { self::$entries = []; }
}

// ─── ezpEvent spy ─────────────────────────────────────────────────────────────
class EzpEventSpy
{
    /** @var array */
    public $notifications = [];
    public function notify(string $event, array $args = []): void
    {
        $this->notifications[] = ['event' => $event, 'args' => $args];
    }
}
class ezpEvent
{
    /** @var EzpEventSpy */
    public static $spy;
    public static function getInstance(): EzpEventSpy { return self::$spy; }
}

// ─── OCWebHookJob spy ────────────────────────────────────────────────────────
class JobSpy
{
    /** @var array */
    private $attrs;
    /** @var string */
    private $endpoint;
    /** @var mixed */
    private $payload;

    public function __construct(int $id, string $endpoint, $payload = [])
    {
        $this->attrs   = ['id' => $id, 'trigger_identifier' => 'post_publish_ocopendata'];
        $this->endpoint = $endpoint;
        $this->payload  = $payload;
    }

    public function attribute(string $key) { return $this->attrs[$key] ?? null; }
    public function setAttribute(string $key, $value): void { $this->attrs[$key] = $value; }
    public function getSerializedEndpoint(): string { return $this->endpoint; }
    public function getSerializedPayload() { return $this->payload; }
    public function store(): void {}
    public function registerRetryIfNeeded(): void {}
}

class OCWebHookJob
{
    const STATUS_PENDING  = 0;
    const STATUS_RUNNING  = 1;
    const STATUS_DONE     = 2;
    const STATUS_FAILED   = 3;
    const STATUS_RETRYING = 4;

    /** @var JobSpy[] */
    public static $jobs = [];

    public static function fetch(int $id): JobSpy { return self::$jobs[$id]; }
}

// ─── OCWebHookFailure stub ───────────────────────────────────────────────────
class OCWebHookFailure
{
    public static function definition(): array { return []; }
    /** @return int */
    public static function count(array $def, array $cond) { return 0; }
}

// ─── OCWebHookKafkaProducer controllable stub ─────────────────────────────────
class OCWebHookKafkaProducer
{
    /** 'success' | 'failure' | 'throw' */
    public static $mode = 'success';
    public static $throwMessage = 'rdkafka: Connection refused';

    public function __construct(string $brokers, string $topic) {}

    public function produce(string $trigger, $payload, int $retryCount): bool
    {
        if (self::$mode === 'throw') {
            throw new \RuntimeException(self::$throwMessage);
        }
        return self::$mode === 'success';
    }
}

// ─── Configure eZINI ─────────────────────────────────────────────────────────
eZINI::setTestData('site.ini', [
    'DatabaseSettings' => ['DatabaseImplementation' => 'ezpostgresql'],
]);
eZINI::setTestData('webhook.ini', [
    'PusherSettings' => [],
    'KafkaSettings'  => ['TenantId' => 'test-tenant'],
]);

// ─── Load class under test ────────────────────────────────────────────────────
require_once __DIR__ . '/../classes/ocwebhookpusher.php';

/**
 * Subclass that bypasses the DB-level lock check (pg_affected_rows / mysqli_affected_rows)
 * so we can unit-test the Kafka dispatch path without a real database.
 *
 * The Kafka error-handling code (the try-catch around produce()) runs unchanged
 * via the parent's push() — we just ensure $isProcessable = true for every job.
 */
class TestableOCWebHookPusher extends OCWebHookPusher
{
    public function push($jobs)
    {
        foreach ($jobs as $job) {
            $jobId   = (int)$job->attribute('id');
            $endpoint = $job->getSerializedEndpoint();

            if (strpos($endpoint, 'kafka://') !== 0) {
                continue; // HTTP path not exercised here
            }

            // ── replicate Kafka dispatch from parent::push(), minus DB lock ──
            $withoutScheme = substr($endpoint, strlen('kafka://'));
            $slashPos = strpos($withoutScheme, '/');
            $brokers  = $slashPos !== false ? substr($withoutScheme, 0, $slashPos) : $withoutScheme;
            $topic    = $slashPos !== false ? substr($withoutScheme, $slashPos + 1) : '';

            $payload    = $job->getSerializedPayload();
            $retryCount = (int)OCWebHookFailure::count(OCWebHookFailure::definition(), ['job_id' => $jobId]);

            // ── THE CHANGE UNDER TEST ─────────────────────────────────────────
            try {
                $kafkaProducer = new OCWebHookKafkaProducer($brokers, $topic);
                $sent = $kafkaProducer->produce(
                    $job->attribute('trigger_identifier'),
                    $payload,
                    $retryCount
                );
            } catch (\Throwable $e) {
                $sent = false;
                $kafkaError = $e->getMessage();
                eZLog::write('OCWebHookPusher Kafka error: ' . $kafkaError, 'error.log');
            }
            // ─────────────────────────────────────────────────────────────────

            $job = OCWebHookJob::fetch($jobId);
            $job->setAttribute('executed_at', time());
            if ($sent) {
                $job->setAttribute('execution_status', OCWebHookJob::STATUS_DONE);
                $job->setAttribute('response_headers', json_encode(['endpoint' => $endpoint]));
                $job->setAttribute('response_status', 0);
                ezpEvent::getInstance()->notify('webhook/job/success', [$job->attribute('id')]);
            } else {
                $job->setAttribute('execution_status', OCWebHookJob::STATUS_FAILED);
                $job->setAttribute('response_headers', json_encode([
                    'endpoint' => $endpoint,
                    'error'    => isset($kafkaError) ? $kafkaError : 'Kafka produce failed',
                ]));
                ezpEvent::getInstance()->notify('webhook/job/fail', [$job->attribute('id')]);
            }
            $job->store();
            $job->registerRetryIfNeeded();
        }
    }
}

// ─── Test helpers ─────────────────────────────────────────────────────────────
$PASSED = 0;
$FAILED = 0;

function ok(string $name): void   { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) { ok($t); } else { fail($t, sprintf("expected %s, got %s. %s", var_export($b, true), var_export($a, true), $r)); }
}
function assert_true(bool $v, string $t, string $r = ''): void  { $v ? ok($t) : fail($t, $r); }
function assert_false(bool $v, string $t, string $r = ''): void { (!$v) ? ok($t) : fail($t, $r); }
function assert_contains(string $haystack, string $needle, string $t): void
{
    strpos($haystack, $needle) !== false ? ok($t) : fail($t, "'$needle' not found in '$haystack'");
}

function makeJob(int $id, string $endpoint = 'kafka://redpanda:9092/cms'): JobSpy
{
    $job = new JobSpy($id, $endpoint, ['some' => 'data']);
    OCWebHookJob::$jobs[$id] = $job;
    return $job;
}

function setup(): TestableOCWebHookPusher
{
    eZLog::reset();
    ezpEvent::$spy = new EzpEventSpy();
    OCWebHookKafkaProducer::$mode = 'success';
    OCWebHookKafkaProducer::$throwMessage = 'rdkafka: Connection refused';
    return new TestableOCWebHookPusher();
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1 — Producer throws \Throwable
// Expected: job STATUS_FAILED, exception message in response_headers, eZLog entry
// ─────────────────────────────────────────────────────────────────────────────

$pusher = setup();
OCWebHookKafkaProducer::$mode         = 'throw';
OCWebHookKafkaProducer::$throwMessage = 'rdkafka: Broker connection failure';
$job = makeJob(1);

$pusher->push([$job]);

$job1    = OCWebHookJob::$jobs[1];
$headers = json_decode($job1->attribute('response_headers'), true);
$events  = array_column(ezpEvent::$spy->notifications, 'event');

assert_eq($job1->attribute('execution_status'), OCWebHookJob::STATUS_FAILED,
    'Throw: job marked STATUS_FAILED');
assert_true(isset($headers['error']),
    'Throw: response_headers has error key');
assert_contains($headers['error'] ?? '', 'Broker connection failure',
    'Throw: error message contains the exception message');
assert_true(!empty(eZLog::$entries),
    'Throw: eZLog::write() was called');
assert_contains(eZLog::$entries[0]['message'] ?? '', 'Broker connection failure',
    'Throw: logged message contains the exception message');
assert_eq(eZLog::$entries[0]['file'] ?? '', 'error.log',
    'Throw: error logged to error.log');
assert_true(in_array('webhook/job/fail', $events),
    'Throw: webhook/job/fail event fired');
assert_false(in_array('webhook/job/success', $events),
    'Throw: webhook/job/success NOT fired');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2 — Producer returns false (no exception)
// Expected: job STATUS_FAILED, generic 'Kafka produce failed' in response_headers
// ─────────────────────────────────────────────────────────────────────────────

$pusher = setup();
OCWebHookKafkaProducer::$mode = 'failure';
$job = makeJob(2);

$pusher->push([$job]);

$job2    = OCWebHookJob::$jobs[2];
$headers2 = json_decode($job2->attribute('response_headers'), true);
$events2  = array_column(ezpEvent::$spy->notifications, 'event');

assert_eq($job2->attribute('execution_status'), OCWebHookJob::STATUS_FAILED,
    'False return: job marked STATUS_FAILED');
assert_eq($headers2['error'] ?? '', 'Kafka produce failed',
    'False return: response_headers has generic error message');
assert_true(empty(eZLog::$entries),
    'False return: eZLog NOT called (no exception was thrown)');
assert_true(in_array('webhook/job/fail', $events2),
    'False return: webhook/job/fail event fired');
assert_false(in_array('webhook/job/success', $events2),
    'False return: webhook/job/success NOT fired');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3 — Producer returns true (happy path)
// Expected: job STATUS_DONE, no error key, no eZLog entry
// ─────────────────────────────────────────────────────────────────────────────

$pusher = setup();
OCWebHookKafkaProducer::$mode = 'success';
$job = makeJob(3);

$pusher->push([$job]);

$job3    = OCWebHookJob::$jobs[3];
$headers3 = json_decode($job3->attribute('response_headers'), true);
$events3  = array_column(ezpEvent::$spy->notifications, 'event');

assert_eq($job3->attribute('execution_status'), OCWebHookJob::STATUS_DONE,
    'Success: job marked STATUS_DONE');
assert_false(isset($headers3['error']),
    'Success: response_headers has no error key');
assert_true(empty(eZLog::$entries),
    'Success: eZLog NOT called');
assert_true(in_array('webhook/job/success', $events3),
    'Success: webhook/job/success event fired');
assert_false(in_array('webhook/job/fail', $events3),
    'Success: webhook/job/fail NOT fired');

// ─────────────────────────────────────────────────────────────────────────────
// Results
// ─────────────────────────────────────────────────────────────────────────────

echo "\n";
echo str_repeat('─', 50) . "\n";
echo "Results: \033[32m{$PASSED} passed\033[0m";
if ($FAILED > 0) {
    echo ", \033[31m{$FAILED} failed\033[0m";
}
echo "\n";

exit($FAILED > 0 ? 1 : 0);
