<?php

/**
 * Minimal stubs for eZ Publish classes, needed to load ocwebhookserver
 * classes outside of a full eZ Publish bootstrap.
 *
 * Used only in tests — never in production.
 */

// ── eZINI stub ───────────────────────────────────────────────────────────────

class eZINI
{
    /** @var array */
    private static $data = [];
    /** @var string */
    private $file;

    private function __construct(string $file)
    {
        $this->file = $file;
    }

    /** Inject test configuration before instantiating the producer. */
    public static function setTestData(string $file, array $data): void
    {
        self::$data[$file] = $data;
    }

    public static function instance(string $file = 'site.ini'): self
    {
        return new self($file);
    }

    public function variable(string $section, string $key): ?string
    {
        return self::$data[$this->file][$section][$key] ?? null;
    }

    /** Returns an array for multi-value INI keys. */
    public function variableArray(string $section, string $key): array
    {
        $val = self::$data[$this->file][$section][$key] ?? [];
        return (array)$val;
    }
}

// ── eZDebug stub ─────────────────────────────────────────────────────────────

class eZDebug
{
    /** @var array */
    public static $errors = [];

    public static function writeError(string $message, string $label = ''): void
    {
        self::$errors[] = ['message' => $message, 'label' => $label];
    }

    public static function reset(): void
    {
        self::$errors = [];
    }
}
