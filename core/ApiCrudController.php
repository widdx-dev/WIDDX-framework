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
 *            apiResource('posts')->public();  // opt out of the fail-closed gate
 */
abstract class ApiCrudController
{
    use Concerns\HandlesCrudInput;
    use Concerns\GatesCrudActions;

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
        return Response::json($model::create($this->inputData())->toArray(), 201);
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
        $item->fill($this->inputData(true));
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
     * Fail-closed auto-guard with a JSON answer: null = allowed, otherwise
     * 401 (guest) or 403. Allowed only when a policy allows, the route was
     * marked ->public(), or the controller sets $public = true.
     */
    protected function deny(string $ability, mixed $argument = null): ?Response
    {
        $target = $argument ?? $this->model();
        if ($this->crudAuthorized($ability, $target)) {
            return null;
        }
        if ($this->crudIsGuest()) {
            return Response::json(['error' => 'Unauthenticated.'], 401);
        }
        return Response::json(['error' => 'Forbidden'], 403);
    }
}
