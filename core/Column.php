<?php

declare(strict_types=1);

namespace Widdx;

/**
 * 2.6: one column definition, built fluently inside a Blueprint.
 * Kept driver-agnostic — Blueprint turns these into per-driver SQL.
 */
class Column
{
    public bool $nullable = false;
    public bool $isPrimary = false;
    public bool $autoIncrement = false;
    public bool $hasDefault = false;
    public mixed $default = null;
    public bool $defaultRaw = false;
    public bool $uniqueFlag = false;
    public bool $indexFlag = false;
    public ?int $length = null;
    public ?int $precision = null;
    public ?int $scale = null;
    public ?string $references = null;
    /** @var string[] */
    public array $enumValues = [];

    public function __construct(public string $name, public string $type)
    {
    }

    public function nullable(bool $b = true): static
    {
        $this->nullable = $b;
        return $this;
    }

    public function default(mixed $value): static
    {
        $this->hasDefault = true;
        $this->default = $value;
        return $this;
    }

    /** DEFAULT CURRENT_TIMESTAMP / now(), emitted verbatim. */
    public function useCurrent(): static
    {
        $this->hasDefault = true;
        $this->defaultRaw = true;
        $this->default = 'CURRENT_TIMESTAMP';
        return $this;
    }

    public function unique(bool $b = true): static
    {
        $this->uniqueFlag = $b;
        return $this;
    }

    public function index(bool $b = true): static
    {
        $this->indexFlag = $b;
        return $this;
    }

    public function primary(bool $b = true): static
    {
        $this->isPrimary = $b;
        return $this;
    }

    public function autoIncrement(bool $b = true): static
    {
        $this->autoIncrement = $b;
        $this->isPrimary = true;
        return $this;
    }

    /** Inline foreign key: ->constrained('authors') => REFERENCES authors(id). */
    public function constrained(string $table, string $column = 'id'): static
    {
        $this->references = $table . '(' . $column . ')';
        return $this;
    }
}
