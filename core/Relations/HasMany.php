<?php

declare(strict_types=1);

namespace Widdx\Relations;

use Widdx\Model;

/** One parent → many children (User → posts, via posts.user_id). */
class HasMany extends Relation
{
    public function __construct(
        Model $parent,
        string $related,
        protected string $foreignKey,
        protected string $localKey,
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): array
    {
        $value = $this->parent->{$this->localKey};
        if ($value === null || $value === '') {
            return [];
        }
        $related = $this->related;
        return $related::query()->where($this->foreignKey, $value)->get();
    }

    public function eagerLoad(array $models, string $name): void
    {
        $keys = [];
        foreach ($models as $model) {
            $k = static::keyOf($model->{$this->localKey});
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
        $grouped = [];
        if ($keys !== []) {
            $related = $this->related;
            $rows = $related::query()->where($this->foreignKey, 'IN', array_keys($keys))->get();
            foreach ($rows as $row) {
                $grouped[static::keyOf($row->{$this->foreignKey})][] = $row;
            }
        }
        foreach ($models as $model) {
            $model->setRelation($name, $grouped[static::keyOf($model->{$this->localKey})] ?? []);
        }
    }
}
