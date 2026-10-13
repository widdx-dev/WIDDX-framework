<?php

declare(strict_types=1);

namespace Widdx\Relations;

use Widdx\Model;

/** One parent → at most one child (User → profile). */
class HasOne extends Relation
{
    public function __construct(
        Model $parent,
        string $related,
        protected string $foreignKey,
        protected string $localKey,
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): mixed
    {
        $value = $this->parent->{$this->localKey};
        if ($value === null || $value === '') {
            return null;
        }
        $related = $this->related;
        return $related::query()->where($this->foreignKey, $value)->first();
    }

    public function eagerLoad(array $models, string $name): void
    {
        $grouped = [];
        $keys = [];
        foreach ($models as $model) {
            $k = static::keyOf($model->{$this->localKey});
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
        if ($keys !== []) {
            $related = $this->related;
            foreach ($related::query()->where($this->foreignKey, 'IN', array_keys($keys))->get() as $row) {
                $fk = static::keyOf($row->{$this->foreignKey});
                $grouped[$fk] ??= $row; // first wins
            }
        }
        foreach ($models as $model) {
            $model->setRelation($name, $grouped[static::keyOf($model->{$this->localKey})] ?? null);
        }
    }
}
