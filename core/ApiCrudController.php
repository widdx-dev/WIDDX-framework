<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Zero-code JSON API controller.
 *
 *   class PostApiController extends ApiCrudController
 *   {
 *       protected string $model = Post::class;
 *       protected array $rules = ['title' => 'required|string'];
 *   }
 *
 * Pair with: apiResource('posts', PostApiController::class);
 */
abstract class ApiCrudController
{
    /** Model class (required) */
    protected string $model = '';

    /** Validation rules (falls back to model $rules) */
    protected array $rules = [];

    /** Items per page (0 = return all) */
    protected int $perPage = 0;

    protected function model(): string
    {
        if ($this->model !== '') {
            return $this->model;
        }
        // PostApiController → App\Models\Post (zero-code inference).
        $short = (new \ReflectionClass(static::class))->getShortName();
        $base = (string) preg_replace('/Controller$/i', '', $short);
        $base = (string) preg_replace('/Api$/i', '', $base);
        $base = $base !== '' ? $base : $short;
        $guess = 'App\\Models\\' . $base;
        if (class_exists($guess)) {
            return $this->model = $guess;
        }
        throw new Exceptions\ModelException(
            'Cannot infer the model for ' . static::class . '. ' .
            'Add protected string $model = YourModel::class; — or create ' . $guess . '.'
        );
    }

    protected function rules(): array
    {
        if (!empty($this->rules)) {
            return $this->rules;
        }
        $model = $this->model();
        return $model::getRules();
    }

    public function index(): Response
    {
        if ($deny = $this->deny('viewAny')) {
            return $deny;
        }
        $model = $this->model();
        if ($this->perPage > 0) {
            return Response::json(paginate($model::query(), $this->perPage));
        }
        $items = array_map(fn($item) => $item->toArray(), $model::all());
        return Response::json($items);
    }

    public function store(): Response
    {
        if ($deny = $this->deny('create')) {
            return $deny;
        }
        $model = $this->model();
        $rules = $this->rules();
        $data = !empty($rules)
            ? request()->validate($rules)
            : request()->except(['_method', 'csrf_token']);
        return Response::json($model::create($data)->toArray(), 201);
    }

    public function show(mixed $id): Response
    {
        $model = $this->model();
        $item = $model::findOrFail($id);
        if ($deny = $this->deny('view', $item)) {
            return $deny;
        }
        return Response::json($item->toArray());
    }

    public function update(mixed $id): Response
    {
        $model = $this->model();
        $item = $model::findOrFail($id);
        if ($deny = $this->deny('update', $item)) {
            return $deny;
        }
        $rules = $this->rules();
        $data = !empty($rules)
            ? request()->validate($rules)
            : request()->except(['_method', 'csrf_token']);
        $item->fill($data);
        $item->save();
        return Response::json($item->toArray());
    }

    public function destroy(mixed $id): Response
    {
        $model = $this->model();
        $item = $model::findOrFail($id);
        if ($deny = $this->deny('delete', $item)) {
            return $deny;
        }
        $item->delete();
        return Response::json(['message' => 'Deleted.']);
    }

    /**
     * Policy auto-guard with a JSON answer: null = allowed/un guarded,
     * otherwise a ready 403 Response. Zero wiring code required.
     */
    protected function deny(string $ability, mixed $argument = null): ?Response
    {
        $target = $argument ?? $this->model();
        if (!Gate::has($ability, $target)) {
            return null;
        }
        return Gate::allows($ability, $target)
            ? null
            : Response::json(['error' => 'Forbidden'], 403);
    }
}
