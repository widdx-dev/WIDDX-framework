<?php

declare(strict_types=1);

namespace Widdx\Relations;

use Widdx\Model;

/**
 * Base of the tiny ORM relations system.
 *
 * A Relation knows two things:
 *   getResults()            — lazy fetch for ONE parent
 *   eagerLoad($models,$name) — ONE bulk query for MANY parents (kills N+1)
 *
 * User-facing API lives on Model: hasMany/hasOne/belongsTo/belongsToMany.
 */
abstract class Relation
{
    public function __construct(
        protected Model $parent,
        protected string $related,
    ) {
    }

    abstract public function getResults(): mixed;

    /** @param Model[] $models */
    abstract public function eagerLoad(array $models, string $name): void;

    /** Fluent alias — $post->comments()->get() */
    public function get(): mixed
    {
        return $this->getResults();
    }

    /** The parent model this relation hangs off. */
    public function getParent(): Model
    {
        return $this->parent;
    }

    /** User → user, UserProfile → user_profile (short class name → snake_case). */
    public static function snake(string $class): string
    {
        // A relation naming a class that was never created reached the user as a
        // bare ReflectionException — and as a generic 500 whenever APP_DEBUG was
        // off. Say which file the framework looked for instead.
        if (!class_exists($class)) {
            $short = substr(strrchr('\\' . $class, '\\'), 1);
            $path = str_starts_with($class, 'App\\Models\\')
                ? 'app/Models/' . $short . '.php'
                : '(an autoloaded file for ' . $class . ')';
            throw new \Widdx\Exceptions\WiddxException(
                "A relation points at [{$class}], which does not exist. "
                . "Expected {$path} — create it (php widdx make:model {$short}) or fix the class name."
            );
        }
        $short = (new \ReflectionClass($class))->getShortName();
        return strtolower((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $short));
    }

    /** Normalized grouping key for cross-type comparison (int 1 === '1'). */
    protected static function keyOf(mixed $value): string
    {
        return $value === null ? '' : (string) $value;
    }

    protected function relatedInstance(): Model
    {
        $class = $this->related;
        return new $class();
    }
}
