<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Zero-code CRUD controller — now truly ZERO code.
 *
 *   class PostController extends CrudController {}   // that's literally it
 *
 * Everything is inferred:
 *   - $model  ← App\Models\Post (from the controller name)
 *   - views   ← views/posts/* if present, otherwise the framework's
 *               built-in templates (core/views/crud/*) render list/form/show
 *               automatically from the database schema + model rules
 *   - rules   ← model $rules
 *   - routes  ← redirect base inferred from the table name
 *
 * Or skip the controller class entirely:
 *   resource('posts');   // finds App\Controllers\PostController, then App\Models\Post
 */
abstract class CrudController
{
    use Concerns\HandlesCrudInput;
    use Concerns\GatesCrudActions;

    /** Model class (optional — inferred from the controller name) */
    protected string $model = '';

    /** View folder (defaults to model table name) */
    protected string $view = '';

    /** Redirect base after store/update/destroy (defaults to /view-folder) */
    protected string $route = '';

    /** Validation rules (falls back to model rules) */
    protected array $rules = [];

    /** Items per page (0 = no pagination) */
    protected int $perPage = 0;

    protected function model(): string
    {
        if ($this->model !== '') {
            return $this->model;
        }
        // UserController → App\Models\User, PostApiController → App\Models\Post.
        $short = (new \ReflectionClass(static::class))->getShortName();
        $base = (string) preg_replace('/Controller$/i', '', $short);
        $base = (string) preg_replace('/Api$/i', '', $base);
        $base = $base !== '' ? $base : $short;
        $guess = 'App\\Models\\' . $base;
        if (class_exists($guess)) {
            return $this->model = $guess;
        }
        // Also try the singular of the inferred table convention.
        throw new Exceptions\ModelException(
            'Cannot infer the model for ' . static::class . '. ' .
            'Add protected string $model = YourModel::class; — or create ' . $guess . '.'
        );
    }

    protected function viewFolder(): string
    {
        if ($this->view !== '') {
            return $this->view;
        }
        $model = $this->model();
        return (new $model())->getTable();
    }

    protected function routeBase(): string
    {
        return $this->route !== '' ? $this->route : '/' . $this->viewFolder();
    }

    protected function rules(): array
    {
        if (!empty($this->rules)) {
            return $this->rules;
        }
        $model = $this->model();
        return $model::getRules();
    }

    /**
     * Render a project view when it exists, otherwise fall back to the
     * framework's built-in CRUD template. This is what lets a project ship
     * no view files at all.
     */
    protected function render(string $template, array $data = []): string
    {
        if (View::exists($template)) {
            return view($template, $data);
        }
        $parts = explode('.', $template);
        $fallback = end($parts); // 'posts.create' → 'create'
        return View::core('crud.' . $fallback, $data);
    }

    /**
     * Form field descriptors for built-in views, derived from validation
     * rules when present, else from the actual table columns.
     *
     * @return array<int, array{name:string,label:string,type:string,required:bool,options:array}>
     */
    protected function fields(?Model $item = null): array
    {
        $model = $this->model();
        $instance = new $model();
        $rules = $this->rules();
        $names = array_keys($rules) ?: $instance->formColumns();
        $names = array_values(array_filter($names, fn($name): bool => $instance->isSensitiveFieldAllowed((string) $name)));
        $fields = [];
        foreach ($names as $name) {
            $rule = (string) ($rules[$name] ?? 'string');
            $parts = explode('|', $rule);
            $type = 'text';
            $options = [];
            $required = false;
            $decimal = false;
            foreach ($parts as $r) {
                if ($r === 'required') {
                    $required = true;
                    continue;
                }
                if (str_starts_with($r, 'in:')) {
                    $options = explode(',', substr($r, 3));
                    $type = 'select';
                } elseif (in_array($r, ['int', 'integer', 'float', 'double', 'numeric'], true)) {
                    $type = 'number';
                    $decimal = $decimal || in_array($r, ['float', 'double', 'numeric'], true);
                } elseif ($r === 'email') {
                    $type = 'email';
                } elseif ($r === 'url') {
                    $type = 'url';
                } elseif ($r === 'bool' || $r === 'boolean') {
                    $type = 'checkbox';
                } elseif ($r === 'file' || $r === 'image') {
                    $type = 'file';
                } elseif ($r === 'array') {
                    $type = 'textarea';
                } elseif ($r === 'text') {
                    $type = 'textarea';
                }
            }
            if (strtolower((string) $name) === 'password') {
                $type = 'password';
                if ($item !== null) {
                    $required = false;
                }
            }
            $value = strtolower((string) $name) === 'password' ? '' : $item?->{$name};
            if ($type === 'number' && $value !== null && !is_numeric($value)) {
                $type = 'text'; // varchar column holding numbers-as-text
            }
            $label = ucwords(str_replace(['_', '-'], ' ', $name));

            // Zero-code: any *_id column whose model exists (Author for
            // author_id) becomes a select fed by that table — no config.
            if (in_array($type, ['text', 'number'], true) && preg_match('/^(.*)_id$/', $name, $m)) {
                $guess = 'App\\Models\\' . str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $m[1])));
                if (class_exists($guess)) {
                    try {
                        $instance = new $guess();
                        $formCols = $instance->formColumns();
                        $labelCol = in_array('name', $formCols, true) ? 'name' : ($formCols[0] ?? null);
                        $opts = [];
                        foreach ($guess::query()->limit(200)->get() as $row) {
                            $opts[] = [
                                'value' => (string) ($row->{$instance->getPrimaryKey()} ?? ''),
                                'label' => (string) ($labelCol !== null ? ($row->{$labelCol} ?? $row->{$instance->getPrimaryKey()}) : ($row->{$instance->getPrimaryKey()} ?? '')),
                            ];
                        }
                        if ($opts !== []) {
                            $type = 'select';
                            $options = $opts;
                            $label = ucwords($m[1]); // author_id → "Author"
                        }
                    } catch (\Throwable) {
                        // related table missing → keep a plain text input
                    }
                }
            }

            $fields[] = [
                'name' => $name,
                'label' => $label,
                'type' => $type,
                'required' => $required,
                'options' => $options,
                'step' => $type === 'number' ? ($decimal ? 'any' : '1') : null,
                'value' => $value,
            ];
        }
        return $fields;
    }

    public function index(): string
    {
        $this->authorizeAction('viewAny');
        $model = $this->model();
        $data = $this->crudData();
        if ($this->perPage > 0) {
            $page = paginate($model::query(), $this->perPage);
            return $this->render($this->viewFolder() . '.index', ['items' => $page['items'], 'pagination' => $page] + $data);
        }
        return $this->render($this->viewFolder() . '.index', ['items' => $model::all()] + $data);
    }

    public function create(): string
    {
        $this->authorizeAction('create');
        return $this->render($this->viewFolder() . '.create', $this->crudData());
    }

    public function store(): Response
    {
        $this->authorizeAction('create');
        $model = $this->model();
        $data = $this->inputData();
        $data = $this->persistUploads($data);
        $model::create($data);
        return redirect($this->routeBase());
    }

    public function show(mixed $id): string
    {
        $model = $this->model();
        $item = $model::findOrFail($id);
        $this->authorizeAction('view', $item);
        return $this->render($this->viewFolder() . '.show', ['item' => $item] + $this->crudData($item));
    }

    public function edit(mixed $id): string
    {
        $model = $this->model();
        $item = $model::findOrFail($id);
        $this->authorizeAction('update', $item);
        return $this->render($this->viewFolder() . '.edit', ['item' => $item] + $this->crudData($item));
    }

    public function update(mixed $id): Response
    {
        $model = $this->model();
        $item = $model::findOrFail($id);
        $this->authorizeAction('update', $item);
        $data = $this->inputData(true);
        $data = $this->persistUploads($data);
        $item->fill($data);
        $item->save();
        return redirect($this->routeBase());
    }

    public function destroy(mixed $id): Response
    {
        $model = $this->model();
        $item = $model::findOrFail($id);
        $this->authorizeAction('delete', $item);
        $item->delete();
        return redirect($this->routeBase());
    }

    /**
     * Fail-closed auto-authorization — still with NO wiring code:
     *   - once App\Policies\{Model}Policy exists, its verdict rules;
     *   - with no policy, access is DENIED unless the route group was
     *     marked ->public() or this controller sets $public = true;
     *   - guests get a 302 to /login, logged-in users a 403.
     */
    protected function authorizeAction(string $ability, mixed $argument = null): void
    {
        $target = $argument ?? $this->model();
        if ($this->crudAuthorized($ability, $target)) {
            return;
        }
        if ($this->crudIsGuest()) {
            throw new Exceptions\AuthenticationException(
                'Sign in to manage ' . $ability . '.'
            );
        }
        throw new Exceptions\HttpException('This action is unauthorized.', 403);
    }

    /**
     * Zero-code uploads: any validated value that is an UploadedFile is
     * moved into storage/<resource-folder>/ and replaced by its relative
     * path BEFORE it reaches the database column.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    protected function persistUploads(array $data): array
    {
        foreach ($data as $key => $value) {
            if ($value instanceof UploadedFile) {
                $data[$key] = $value->store($this->viewFolder());
            } elseif (is_array($value)) {
                foreach ($value as $i => $one) {
                    if ($one instanceof UploadedFile) {
                        $data[$key][$i] = $one->store($this->viewFolder());
                    }
                }
                if (isset($data[$key]) && is_array($data[$key])) {
                    $data[$key] = json_encode($data[$key], JSON_UNESCAPED_SLASHES);
                }
            }
        }
        return $data;
    }

    /** Shared data every built-in template may need. */
    protected function crudData(?Model $item = null): array
    {
        $model = $this->model();
        $instance = new $model();
        $title = ucwords(str_replace('_', ' ', $instance->getTable()));
        return [
            'routeBase' => $this->routeBase(),
            'title' => $title,
            'columns' => array_values(array_filter($instance->formColumns(), fn(string $column): bool => strtolower($column) !== 'password' && $instance->isSensitiveFieldAllowed($column))),
            'fields' => $this->fields($item),
            'item' => $item,
        ];
    }
}
