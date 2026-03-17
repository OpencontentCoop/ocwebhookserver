<?php

/**
 * Tests for the outbox pattern in OCWebHookEmitter.
 *
 * Verifies:
 *  1. Kafka path: when produce() succeeds, all jobs are removed (not sent via HTTP)
 *  2. Fallback path: when produce() fails, jobs are kept and pushed to HTTP queue
 *  3. Kafka disabled: jobs are pushed to HTTP queue directly (no produce attempt)
 *
 * No database or full eZ Publish bootstrap needed — uses spy/stub doubles.
 *
 * Usage:
 *   php tests/EmitterOutboxTest.php
 */

require_once __DIR__ . '/stubs.php';

// ─────────────────────────────────────────────────────────────────────────────
// Spy doubles — minimal implementations that record what was called
// ─────────────────────────────────────────────────────────────────────────────

class SpyJob
{
    /** @var bool */
    public $stored  = false;
    /** @var bool */
    public $removed = false;
    /** @var string */
    private $endpoint;

    public function __construct(string $endpoint = 'https://example.com/hook')
    {
        $this->endpoint = $endpoint;
    }

    public function store(): void  { $this->stored  = true; }
    public function remove(): void { $this->removed = true; }
    public function getSerializedEndpoint(): string { return $this->endpoint; }
    public static function encodePayload($payload): string { return json_encode($payload); }
}

class SpyQueue
{
    /** @var bool */
    public $pushJobsCalled  = false;
    /** @var bool */
    public $executeCalled   = false;
    /** @var array */
    public $receivedJobs    = [];

    public function pushJobs(array $jobs): self
    {
        $this->pushJobsCalled = true;
        $this->receivedJobs   = $jobs;
        return $this;
    }

    public function execute(): void { $this->executeCalled = true; }
}

// ─────────────────────────────────────────────────────────────────────────────
// Controllable producer stub (replaces OCWebHookKafkaProducer)
// ─────────────────────────────────────────────────────────────────────────────

class FakeKafkaProducer
{
    /** @var bool */
    public static $enabled         = true;
    /** @var bool */
    public static $produceResult   = true;
    /** @var array */
    public static $calls           = [];

    public static function isEnabled(): bool { return self::$enabled; }

    public static function instance(): self { return new self(); }

    public function produce(string $trigger, $payload): bool
    {
        self::$calls[] = ['trigger' => $trigger, 'payload' => $payload];
        return self::$produceResult;
    }

    public static function reset(): void
    {
        self::$enabled       = true;
        self::$produceResult = true;
        self::$calls         = [];
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Minimal implementations of eZ static dependencies used by OCWebHookEmitter
// ─────────────────────────────────────────────────────────────────────────────

interface OCWebHookTriggerInterface
{
    public function getIdentifier(): string;
    public function useFilter(): bool;
    public function isValidPayload($payload, $filters): bool;
}

class TestTrigger implements OCWebHookTriggerInterface
{
    public function getIdentifier(): string   { return 'post_publish'; }
    public function useFilter(): bool          { return false; }
    public function isValidPayload($p, $f): bool { return true; }
}

class TestWebHook
{
    /** @var int */
    private $id;
    public function __construct($id) { $this->id = $id; }
    public function attribute($attr) { return $attr === 'id' ? $this->id : null; }
    public function getTriggers() { return []; }
}

// Static class stubs used inside OCWebHookEmitter
class OCWebHookTriggerRegistry
{
    /** @var OCWebHookTriggerInterface|null */
    public static $trigger = null;

    public static function registeredTrigger(string $id)
    {
        return self::$trigger;
    }
}

class OCWebHookJob
{
    /** @var array */
    private static $spies = [];
    /** @var array */
    private $data;

    public function __construct(array $data)      { $this->data = $data; }
    public static function encodePayload($p): string { return json_encode($p); }

    public function getSerializedEndpoint(): string
    {
        // Resolve endpoint from the linked TestWebHook via webhook_id
        return 'https://example.com/hook/' . $this->data['webhook_id'];
    }

    public function store(): void  { self::$spies[] = ['type' => 'store',  'job' => $this]; }
    public function remove(): void { self::$spies[] = ['type' => 'remove', 'job' => $this]; }

    public static function getCalls(): array { return self::$spies; }
    public static function resetCalls(): void { self::$spies = []; }

    public static function storedCount()
    {
        return count(array_filter(self::$spies, function($s) { return $s['type'] === 'store'; }));
    }

    public static function removedCount()
    {
        return count(array_filter(self::$spies, function($s) { return $s['type'] === 'remove'; }));
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
    /** @var SpyQueue */
    public static $spy;

    public static function instance(string $handler)
    {
        return self::$spy;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// Load the class under test AFTER stubs are defined
// ─────────────────────────────────────────────────────────────────────────────

// Temporarily alias FakeKafkaProducer as OCWebHookKafkaProducer
// We patch the emitter file on-the-fly using a modified copy that uses
// FakeKafkaProducer instead of OCWebHookKafkaProducer.
// This avoids modifying the production source code.

$emitterSource = file_get_contents(__DIR__ . '/../classes/ocwebhookemitter.php');
$patchedSource = str_replace('OCWebHookKafkaProducer', 'FakeKafkaProducer', $emitterSource);
eval('?>' . $patchedSource);

// ─────────────────────────────────────────────────────────────────────────────
// Test helpers
// ─────────────────────────────────────────────────────────────────────────────

$PASSED = 0;
$FAILED = 0;

function ok(string $name): void    { global $PASSED; $PASSED++; echo "\033[32m[PASS]\033[0m $name\n"; }
function fail(string $name, string $r = ''): void { global $FAILED; $FAILED++; echo "\033[31m[FAIL]\033[0m $name" . ($r ? " — $r" : '') . "\n"; }
function assert_eq($a, $b, string $t, string $r = ''): void { $a === $b ? ok($t) : fail($t, "expected $b, got $a. $r"); }
function assert_true(bool $v, string $t, string $r = ''): void { $v ? ok($t) : fail($t, $r); }

function setup(): void
{
    OCWebHookTriggerRegistry::$trigger = new TestTrigger();
    OCWebHook::$hooks                  = [new TestWebHook(1), new TestWebHook(2)];
    OCWebHookQueue::$spy               = new SpyQueue();
    OCWebHookJob::resetCalls();
    FakeKafkaProducer::reset();
    eZDebug::reset();
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1: Kafka path — produce succeeds → jobs removed, HTTP queue NOT called
// ─────────────────────────────────────────────────────────────────────────────

setup();
FakeKafkaProducer::$enabled       = true;
FakeKafkaProducer::$produceResult = true;

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'default');

assert_eq(OCWebHookJob::storedCount(),   2, 'Kafka path: 2 jobs written to DB (outbox)');
assert_eq(OCWebHookJob::removedCount(),  2, 'Kafka path: both jobs removed after Kafka ack');
assert_true(!OCWebHookQueue::$spy->pushJobsCalled, 'Kafka path: HTTP queue NOT invoked');
assert_true(!OCWebHookQueue::$spy->executeCalled,  'Kafka path: HTTP execute NOT invoked');

$calls = FakeKafkaProducer::$calls;
assert_eq(count($calls), 1, 'Kafka path: produce() called exactly once');
assert_eq($calls[0]['trigger'] ?? null, 'post_publish', 'Kafka path: trigger key is post_publish');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: Fallback path — produce fails → jobs PENDING, HTTP queue called
// ─────────────────────────────────────────────────────────────────────────────

setup();
FakeKafkaProducer::$enabled       = true;
FakeKafkaProducer::$produceResult = false;   // Kafka down

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'default');

assert_eq(OCWebHookJob::storedCount(),  2, 'Fallback path: 2 jobs written to DB (outbox)');
assert_eq(OCWebHookJob::removedCount(), 0, 'Fallback path: NO jobs removed (stay PENDING)');
assert_true(OCWebHookQueue::$spy->pushJobsCalled, 'Fallback path: HTTP queue.pushJobs() called');
assert_true(OCWebHookQueue::$spy->executeCalled,  'Fallback path: HTTP queue.execute() called');
assert_eq(count(OCWebHookQueue::$spy->receivedJobs), 2, 'Fallback path: both jobs passed to HTTP queue');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: Kafka disabled → HTTP path directly, produce() never called
// ─────────────────────────────────────────────────────────────────────────────

setup();
FakeKafkaProducer::$enabled = false;

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'default');

assert_eq(OCWebHookJob::storedCount(),  2, 'Kafka disabled: jobs written to DB');
assert_eq(count(FakeKafkaProducer::$calls), 0, 'Kafka disabled: produce() never called');
assert_true(OCWebHookQueue::$spy->pushJobsCalled, 'Kafka disabled: HTTP queue.pushJobs() called');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 4: No webhooks configured → nothing stored, nothing sent
// ─────────────────────────────────────────────────────────────────────────────

setup();
OCWebHook::$hooks = [];  // no webhooks registered for this trigger

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'default');

assert_eq(OCWebHookJob::storedCount(), 0,  'No webhooks: no jobs created');
assert_eq(count(FakeKafkaProducer::$calls), 0, 'No webhooks: produce() not called (count($jobs)=0)');
// The HTTP queue is called with an empty job list — this is the current behaviour.
// The queue implementation is expected to handle an empty array gracefully.
assert_true(OCWebHookQueue::$spy->pushJobsCalled, 'No webhooks: HTTP queue called with empty jobs array');

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
