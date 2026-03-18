<?php

/**
 * Tests for the outbox pattern in OCWebHookEmitter.
 *
 * Verifies:
 *  1. Kafka path: when produce() succeeds, only the kafka-push-* job is removed;
 *     other HTTP jobs (es. search engine) stay PENDING for the cron.
 *  2. Fallback path: when produce() fails, ALL jobs stay PENDING and are pushed to HTTP queue.
 *  3. Kafka disabled: jobs are pushed to HTTP queue directly (no produce attempt).
 *  4. No webhooks configured: nothing stored, nothing sent.
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
    /** @var string */
    public static $prefix          = 'kafka-push-';

    public static function isEnabled(): bool { return self::$enabled; }

    public static function fallbackWebhookPrefix(): string { return self::$prefix; }

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
        self::$prefix        = 'kafka-push-';
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
    /** @var string */
    private $name;

    public function __construct($id, $name = '')
    {
        $this->id   = $id;
        $this->name = $name ?: 'webhook-' . $id;
    }

    public function attribute($attr)
    {
        if ($attr === 'id')   return $this->id;
        if ($attr === 'name') return $this->name;
        return null;
    }

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
    /** @var int */
    private static $nextId = 1;
    /** @var int */
    private $id;

    public function __construct(array $data)
    {
        $this->data = $data;
        $this->id   = null;
    }

    public static function encodePayload($p): string { return json_encode($p); }

    public function getSerializedEndpoint(): string
    {
        return 'https://example.com/hook/' . $this->data['webhook_id'];
    }

    public function store(): void
    {
        $this->id = self::$nextId++;
        self::$spies[] = ['type' => 'store', 'job' => $this];
    }

    public function remove(): void
    {
        self::$spies[] = ['type' => 'remove', 'job' => $this, 'id' => $this->id];
    }

    public function attribute($attr)
    {
        if ($attr === 'id') return $this->id;
        return $this->data[$attr] ?? null;
    }

    public static function getCalls(): array { return self::$spies; }

    public static function resetCalls(): void
    {
        self::$spies  = [];
        self::$nextId = 1;
    }

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

// Patch the emitter on-the-fly: replace OCWebHookKafkaProducer with FakeKafkaProducer
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
    // Webhook 1: kafka-push-* (fallback Redpanda Connect)
    // Webhook 2: HTTP generico (es. motore di ricerca)
    OCWebHookTriggerRegistry::$trigger = new TestTrigger();
    OCWebHook::$hooks                  = [
        new TestWebHook(1, 'kafka-push-42'),
        new TestWebHook(2, 'search-engine'),
    ];
    OCWebHookQueue::$spy               = new SpyQueue();
    OCWebHookJob::resetCalls();
    FakeKafkaProducer::reset();
    eZDebug::reset();
}

// ─────────────────────────────────────────────────────────────────────────────
// TEST 1: Kafka path — produce succeeds → solo il job kafka-push-* rimosso,
//         il job HTTP resta PENDING per il cron
// ─────────────────────────────────────────────────────────────────────────────

setup();
FakeKafkaProducer::$enabled       = true;
FakeKafkaProducer::$produceResult = true;

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'default');

assert_eq(OCWebHookJob::storedCount(),  2, 'Kafka path: 2 jobs scritti in DB (outbox)');
assert_eq(OCWebHookJob::removedCount(), 1, 'Kafka path: solo il job kafka-push-* rimosso');
assert_true(!OCWebHookQueue::$spy->pushJobsCalled, 'Kafka path: HTTP queue NOT invocata');
assert_true(!OCWebHookQueue::$spy->executeCalled,  'Kafka path: HTTP execute NOT invocato');

$calls = FakeKafkaProducer::$calls;
assert_eq(count($calls), 1, 'Kafka path: produce() chiamato esattamente una volta');
assert_eq($calls[0]['trigger'] ?? null, 'post_publish', 'Kafka path: trigger key corretto');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 2: Fallback path — produce fails → TUTTI i job restano PENDING,
//         incluso kafka-push-*, e vengono passati alla coda HTTP
// ─────────────────────────────────────────────────────────────────────────────

setup();
FakeKafkaProducer::$enabled       = true;
FakeKafkaProducer::$produceResult = false;   // Kafka down

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'default');

assert_eq(OCWebHookJob::storedCount(),  2, 'Fallback path: 2 jobs scritti in DB');
assert_eq(OCWebHookJob::removedCount(), 0, 'Fallback path: nessun job rimosso (restano PENDING)');
assert_true(OCWebHookQueue::$spy->pushJobsCalled, 'Fallback path: HTTP queue.pushJobs() chiamato');
assert_true(OCWebHookQueue::$spy->executeCalled,  'Fallback path: HTTP queue.execute() chiamato');
assert_eq(count(OCWebHookQueue::$spy->receivedJobs), 2, 'Fallback path: entrambi i job passati alla coda');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 3: Kafka disabled → HTTP path diretto, produce() mai chiamato
// ─────────────────────────────────────────────────────────────────────────────

setup();
FakeKafkaProducer::$enabled = false;

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'default');

assert_eq(OCWebHookJob::storedCount(),         2, 'Kafka disabled: jobs scritti in DB');
assert_eq(count(FakeKafkaProducer::$calls),    0, 'Kafka disabled: produce() mai chiamato');
assert_true(OCWebHookQueue::$spy->pushJobsCalled, 'Kafka disabled: HTTP queue.pushJobs() chiamato');
assert_eq(count(OCWebHookQueue::$spy->receivedJobs), 2, 'Kafka disabled: entrambi i job alla coda HTTP');

// ─────────────────────────────────────────────────────────────────────────────
// TEST 4: Nessun webhook configurato → niente creato, niente inviato
// ─────────────────────────────────────────────────────────────────────────────

setup();
OCWebHook::$hooks = [];

OCWebHookEmitter::emit('post_publish', ['data' => 'test'], 'default');

assert_eq(OCWebHookJob::storedCount(),      0, 'No webhooks: nessun job creato');
assert_eq(count(FakeKafkaProducer::$calls), 0, 'No webhooks: produce() non chiamato');

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
