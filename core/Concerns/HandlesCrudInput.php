<?php

declare(strict_types=1);

namespace Widdx\Concerns;

use Widdx\Exceptions\ModelException;

/**
 * Shared input logic for the zero-code CRUD controllers.
 *
 * With model $rules: validate exactly as before.
 * Without rules: NEVER hand raw request input to create()/fill() — the
 * allow-list becomes $fillable ∩ real table columns, and an empty
 * $fillable fails loudly with guidance (deny by default).
 */
trait HandlesCrudInput
{
    /**
     * @return array<string,mixed> safe mass-assignment payload
     */
    protected function inputData(bool $updating = false): array
    {
        $rules = $this->rules();
        if ($rules !== []) {
            $skipPassword = $updating && (request()->input('password') === null || request()->input('password') === '');
            if ($skipPassword) {
                unset($rules['password']);
            }
            $data = $rules !== [] ? request()->validate($rules) : [];
            if ($skipPassword) {
                unset($data['password']);
            }
            return $data;
        }

        /** @var \Widdx\Model $model */
        $model = $this->model();
        $instance = new $model();
        $fillable = $instance->getFillable();
        if ($fillable === []) {
            throw new ModelException(
                $model . ' has no $rules and an empty $fillable, so mass assignment is blocked '
                . '(deny by default). Define protected array $fillable = [columns] or $rules on '
                . "the model — or opt in fully with \$fillable = ['*']."
            );
        }

        $columns = $instance->columns();
        $keys = $fillable === ['*'] ? $columns : array_values(array_intersect($fillable, $columns));
        $keys = array_values(array_filter($keys, fn(string $key): bool => $instance->isSensitiveFieldAllowed($key)));

        $all = request()->all();
        $data = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $all)) {
                $data[$key] = $all[$key];
            }
        }
        // Uploaded files participate even without rules (same as validate()).
        foreach (request()->uploads() as $key => $file) {
            if (in_array($key, $keys, true) && !array_key_exists($key, $data)) {
                $data[$key] = $file;
            }
        }
        return $data;
    }
}
