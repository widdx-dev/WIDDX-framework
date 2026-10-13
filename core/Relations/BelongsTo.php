<?php

declare(strict_types=1);

namespace Widdx\Relations;

use Widdx\Model;

/** Many children → one parent (Post → author, via posts.author_id). */
class BelongsTo extends Relation
{
    public function __construct(
        Model $parent,
        string $related,
        protected string $foreignKey,
        protected string $ownerKey = 'id',
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): mixed
    {
        $value = $this->parent->{$this->foreignKey};
        if ($value === null || $value === '') {
            return null;
        }
        $related = $this->related;
        return $related::query()->where($this->ownerKey, $value)->first();
    }

    public function eagerLoad(array $models, string $name): void
    {
        $values = [];
        foreach ($models as $model) {
            $k = static::keyOf($model->{$this->foreignKey});
            if ($k !== '') {
                $values[$k] = true;
            }
        }
        $indexed = [];
        if ($values !== []) {
            $related = $this->related;
            foreach ($related::query()->where($this->ownerKey, 'IN', array_keys($values))->get() as $row) {
                $indexed[static::keyOf($row->{$this->ownerKey})] = $row;
            }
        }
        foreach ($models as $model) {
            $model->setRelation($name, $indexed[static::keyOf($model->{$this->foreignKey})] ?? null);
        }
    }
}
