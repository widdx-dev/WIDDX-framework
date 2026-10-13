<?php

declare(strict_types=1);

namespace Widdx\Relations;

use Widdx\Model;

/** Role ↔ Permission through a pivot table (role_permission, role_id, permission_id). */
class BelongsToMany extends Relation
{
    public function __construct(
        Model $parent,
        string $related,
        protected string $pivotTable,
        protected string $foreignPivotKey,
        protected string $relatedPivotKey,
        protected string $parentKey = 'id',
        protected string $relatedKey = 'id',
    ) {
        parent::__construct($parent, $related);
    }

    public function getResults(): array
    {
        $value = $this->parent->{$this->parentKey};
        if ($value === null || $value === '') {
            return [];
        }
        $ids = $this->pivotIdsFor([static::keyOf($value)])[static::keyOf($value)] ?? [];
        return $this->fetchRelated($ids);
    }

    public function eagerLoad(array $models, string $name): void
    {
        $keys = [];
        foreach ($models as $model) {
            $k = static::keyOf($model->{$this->parentKey});
            if ($k !== '') {
                $keys[$k] = true;
            }
        }
        $map = $keys !== [] ? $this->pivotIdsFor(array_keys($keys)) : [];

        $allIds = [];
        foreach ($map as $ids) {
            $allIds = array_merge($allIds, $ids);
        }
        $indexed = [];
        if ($allIds !== []) {
            foreach ($this->fetchRelated($allIds) as $row) {
                $indexed[static::keyOf($row->{$this->relatedKey})] = $row;
            }
        }

        foreach ($models as $model) {
            $ids = $map[static::keyOf($model->{$this->parentKey})] ?? [];
            $result = [];
            foreach ($ids as $id) {
                if (isset($indexed[$id])) {
                    $result[] = $indexed[$id];
                }
            }
            $model->setRelation($name, $result);
        }
    }

    /** @return array<string, string[]> parentKey → related ids */
    protected function pivotIdsFor(array $parentKeys): array
    {
        $map = [];
        $rows = db($this->pivotTable)
            ->where($this->foreignPivotKey, 'IN', $parentKeys)
            ->get();
        foreach ($rows as $row) {
            $row = (array) $row;
            $pk = static::keyOf($row[$this->foreignPivotKey] ?? null);
            $rk = static::keyOf($row[$this->relatedPivotKey] ?? null);
            if ($pk !== '' && $rk !== '') {
                $map[$pk][] = $rk;
            }
        }
        return $map;
    }

    protected function fetchRelated(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $related = $this->related;
        return $related::query()->where($this->relatedKey, 'IN', array_unique($ids))->get();
    }
}
