<?php

/**
 * Tests for OCWebHookEmitter — outbox pattern behaviour.
 *
 * Verifies that the emitter:
 *  1. Creates one job per webhook, stores each to DB (PENDING), then passes all
 *     to OCWebHookQueue (the queue decides when / how to execute).
 *  2. Accepts kafka:// endpoints (bypasses filter_var URL validation).
 *  3. Rejects invalid (non-kafka, non-http) endpoints and writes a debug error.
 *  4. Does nothing when no webhooks are configured.
 *  5. Does nothing when the trigger is unknown.
 *
 * No database or full eZ Publish bootstrap needed — uses spy/stub doubles.
 *
 * Usage:
 *   php tests/EmitterOutboxTest.php
 */

require_once __DIR__ . '/stubs.php';

// ─────────────────────────────────────────────────────────────────────────────
// Spy doubles
// ─────────────────────────────────────────────────────────────────────────────

class SpyQueue
{
    public $pushJobsCalled = false;
    public $executeCalled  = false;
    public $receivedJobs   = [];

    public function pushJobs(array $jobs): self
    {
        $this->pushJobsCalled = true;
        $this->receivedJobs   = array_merge($this->receivedJobs, $jobs);
        return $this;
    }

    public function execute(): void { $this->executeCalled = true; }
}

// ─────────────────────────────────────────────────────────────────────────────
// eZ static class stubs used by OCWebHookEmitter
// ─────────────────────────────────────────────────────────────────────────────

interface OCWebHookTriggerInterface
{
    public function getIdentifier(): string;
    public function useFilter(): bool;
    public function isValidPayload($payload, $filters): bool;
}

class TestTrigger implements OCWebHookTriggerInterface
{
    public function getIdentifier(): string          { return 'post_publish'; }
    public function useFilter(): bool                { return false; }
    public function isValidPayload($p, $f): bool     { return true; }
}

/**
 * Stub webhook: the endpoint URL is configurable so we can test http://, kafka://, invalid.
 */
class TestWebHook
{
    /** @var int */
    private $id;
    /** @var string */
    private $name;
    /** @var string */
    private $url;

    public function __construct($id, $name = '', $url = 'https://example.com/hook')
    {
        $this->id   = $id;
        $this->name = $name ?: 'webhook-' . $id;
        $this->url  = $url;
    }

    public function attribute($attr)
    {
        if ($attr === 'id')   return $this->id;
        if ($attr === 'name') return $this->name;
        if ($attr === 'url')  return $this->url;
        return null;
    }

    public function getTriggers(): array { return []; }
}

/**
 * Minimal job spy: records store/remove calls, returns the webhook URL as endpoint.
 *
 * The emitter constructs jobs with ['webhook_id' => $id, ...] then calls
 * $job->getSerializedEndpoint(). We satisfy that by looking up the URL in
 * TestWebHookRegistry::$registry.
 */
class OCWebHookJob
{
    /** @var array  Records store/remove events for assertion */
    private static $log = [];
    /** @var int */
    private static $nextId = 1;
    /** @var int */
    private $id;
    /** @var array */
    private $data;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->id   = null;
    }

    public static function encodePayload($payload): string { return json_encode($payload); }

    public function getSerializedEndpoint(): string
    {
        $webhookId = $this->data['webhook_id'] ?? 0;
        return TestWebHookRegistry::endpointFor($webhookId);
    }

    public function store(): void
    {
        $this->id    = self::$nextId++;
        self::$log[] = ['event' => 'store', 'job' => $this];
    }

    public function remove(): void
    {
        self::$log[] = ['event' => 'remove', 'id' => $this->id];
    }

    public function attribute($attr)
    {
        if ($attr === 'id') return $this->id;
        return $this->data[$attr] ?? null;
    }

    // ── log inspection helpers ────────────────────────────────────────────────

    public static function storedCount(): int
    {
        return count(array_filter(self::$log, function($e) { return $e['event'] === 'store'; }));
    }

    public static function removedCount(): int
    {
        return count(array_filter(self::$log, function($e) { return $e['event'] === 'remove'; }));
    }

    public static function resetLog(): void
    {
        self::$log    = [];
        self::$nextId = 1;
    }
}

class TestWebHookRegistry
{
    /** @var array<int,string> webhook_id → endpoint URL */
    public static $registry = [];

    public static function endpointFor(int $id): string
    {
        return self::$registry[$id] ?? ('https://example.com/hook/' . $id);
    }
}

class OCWebHookTriggerRegistry
{
    /** @var OCWebHookTriggerInterface|null */
    public static $trigger = null;

    public static function registeredTrigger(string $id)
    {
        return self::$trigger;
    }
}

class OCWebHook
{
    /** @var array */
    public static $hooks = [];

    public static function fetchEnabledListByTrigger(string $id): array
    {
        return self::$hooks;
    }
}

class OCWebHookQueue
{
    const HANDLER_IMMEDIATE = 1;
    const HANDLER_SCHEDULED = 2;

    /** @var SpyQueue */
    public static $spy;

    public static function instance($handler): SpyQueue
    {
        return self::$spy;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Load the class under test
// ─────────────────────────────────────────────────────────────────────────────

require_once __DIR__ . '/../classes/ocwebhookemitter.php';

// ─────────────────────────────────────────────────────────────────────────────
// Test helpers
// ─────────────────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void   { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t, string $r = ''): void
{
    if ($a === $b) {
        ok($t);
    } else {
        fail($t, sprintf("expected %s, got %s. %s", var_export($b, true), var_export($a, true), $r));
    }
}
function assert_true(bool $v, string $t, string $r = ''): void  { $v ? ok($t) : fail($t, $r); }
function assert_false(bool $v, string $t, string $r = ''): void { (!$v) ? ok($t) : fail($t, $r); }

function setup(): void
{
    OCWebHookTriggerRegistry::$trigger  = new TestTrigger();
    OCWebHookQueue::$spy                = new SpyQueue();
    TestWebHookRegistry::$registry      = [];
    OCWebHookJob::resetLog();
    eZDebug::reset();
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1: Two HTTP webhooks → 2 jobs stored, both passed to queue
// ─────────────────────────────────────────────────────────────────────────────

setup();
OCWebHook::$hooks = [
    new TestWebHook(1, 'search-engine',  'https://search.example.com/hook'),
    new TestWebHook(2, 'analytics-push', 'https://analytics.example.com/hook'),
];
TestWebHookRegistry::$registry = [
    1 => 'https://search.example.com/hook',
    2 => 'https://analytics.example.com/hook',
];

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'immediate');

assert_eq(OCWebHookJob::storedCount(),            2, 'HTTP: 2 jobs written to DB (outbox)');
assert_eq(OCWebHookJob::removedCount(),           0, 'HTTP: no jobs removed (all stay PENDING)');
assert_true(OCWebHookQueue::$spy->pushJobsCalled,    'HTTP: queue.pushJobs() called');
assert_true(OCWebHookQueue::$spy->executeCalled,     'HTTP: queue.execute() called');
assert_eq(count(OCWebHookQueue::$spy->receivedJobs), 2, 'HTTP: both jobs passed to queue');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: Kafka endpoint (kafka://) is accepted and job is stored
// ─────────────────────────────────────────────────────────────────────────────

setup();
OCWebHook::$hooks = [
    new TestWebHook(1, 'kafka-cms', 'kafka://redpanda:9092/cms'),
    new TestWebHook(2, 'search',    'https://search.example.com/hook'),
];
TestWebHookRegistry::$registry = [
    1 => 'kafka://redpanda:9092/cms',
    2 => 'https://search.example.com/hook',
];

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'immediate');

assert_eq(OCWebHookJob::storedCount(),                2, 'Kafka: kafka:// endpoint accepted, job stored');
assert_true(OCWebHookQueue::$spy->pushJobsCalled,        'Kafka: queue.pushJobs() called with kafka job');
assert_eq(count(OCWebHookQueue::$spy->receivedJobs),  2, 'Kafka: both jobs (kafka + http) passed to queue');
assert_true(empty(eZDebug::$errors),                     'Kafka: no eZDebug errors for valid kafka:// URL');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: Invalid URL → skipped with eZDebug error, valid job still stored
// ─────────────────────────────────────────────────────────────────────────────

setup();
OCWebHook::$hooks = [
    new TestWebHook(1, 'bad-webhook',   'not-a-valid-url'),
    new TestWebHook(2, 'good-webhook',  'https://good.example.com/hook'),
];
TestWebHookRegistry::$registry = [
    1 => 'not-a-valid-url',
    2 => 'https://good.example.com/hook',
];

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'immediate');

assert_eq(OCWebHookJob::storedCount(),            1, 'Invalid URL: only the valid webhook job stored');
assert_true(!empty(eZDebug::$errors),                'Invalid URL: eZDebug::writeError() called');
assert_true(OCWebHookQueue::$spy->pushJobsCalled,    'Invalid URL: queue.pushJobs() still called with valid job');
assert_eq(count(OCWebHookQueue::$spy->receivedJobs), 1, 'Invalid URL: 1 job passed to queue');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 4: No webhooks configured → no jobs, queue still called with empty list
// ─────────────────────────────────────────────────────────────────────────────

setup();
OCWebHook::$hooks = [];

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'immediate');

assert_eq(OCWebHookJob::storedCount(), 0, 'No webhooks: no jobs created');
assert_eq(count(OCWebHookQueue::$spy->receivedJobs), 0, 'No webhooks: queue receives empty list');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 5: delete_ocopendata trigger → jobs stored and queued like any other trigger
// ─────────────────────────────────────────────────────────────────────────────

setup();
OCWebHook::$hooks = [
    new TestWebHook(1, 'kafka-cms',  'kafka://redpanda:9092/cms'),
    new TestWebHook(2, 'http-hook',  'https://notify.example.com/hook'),
];
TestWebHookRegistry::$registry = [
    1 => 'kafka://redpanda:9092/cms',
    2 => 'https://notify.example.com/hook',
];

OCWebHookEmitter::emit('delete_ocopendata', ['entity' => ['meta' => ['id' => 'opencity:42', 'type_id' => 'article'], 'data' => []]], 'immediate');

assert_eq(OCWebHookJob::storedCount(),               2, 'Delete: 2 jobs written to DB (outbox)');
assert_eq(OCWebHookJob::removedCount(),              0, 'Delete: no jobs removed (all stay PENDING)');
assert_true(OCWebHookQueue::$spy->pushJobsCalled,       'Delete: queue.pushJobs() called');
assert_true(OCWebHookQueue::$spy->executeCalled,        'Delete: queue.execute() called');
assert_eq(count(OCWebHookQueue::$spy->receivedJobs), 2, 'Delete: both jobs passed to queue');
assert_true(empty(eZDebug::$errors),                    'Delete: no eZDebug errors');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 6: Unknown trigger → eZDebug error, nothing else happens
// ─────────────────────────────────────────────────────────────────────────────

setup();
OCWebHookTriggerRegistry::$trigger = null;  // trigger not found
OCWebHook::$hooks = [new TestWebHook(1, 'some-hook', 'https://example.com/hook')];

OCWebHookEmitter::emit('unknown_trigger', ['data' => 'test'], 'immediate');

assert_eq(OCWebHookJob::storedCount(), 0,    'Unknown trigger: no jobs created');
assert_true(!empty(eZDebug::$errors),        'Unknown trigger: eZDebug::writeError() called');
assert_false(OCWebHookQueue::$spy->pushJobsCalled, 'Unknown trigger: queue not invoked');

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
