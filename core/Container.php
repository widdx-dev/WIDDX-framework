<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Dependency Injection Container
 *
 * - PSR-11 compatible (has / get).
 * - Uses Reflection for auto-wiring.
 * * - Supports bind, singleton, and instance registration.
 */
class Container implements \Psr\Container\ContainerInterface
{
    /** @var static|null Singleton instance for quick access */
    protected static ?self $instance = null;

    /** @var array<string, array{concrete:mixed, shared:bool}> Registered bindings */
    protected array $bindings = [];

    /** @var array<string, object> Shared instances (singletons) */
    protected array $singletons = [];

    /** @var array<string, mixed> Additional parameters at call time (e.g. route params) */
    protected array $contextual = [];

    /** @var array<string, \ReflectionClass> Reflection cache for performance */
    protected array $reflectionCache = [];

    public static function getInstance(): self
    {
        if (static::$instance === null) {
            static::$instance = new self();
        }
        return static::$instance;
    }

    // ---------- PSR-11 ----------

    public function get(string $id): mixed
    {
        if (isset($this->singletons[$id])) {
            return $this->singletons[$id];
        }

        if (isset($this->bindings[$id])) {
            $binding = $this->bindings[$id];
            $object = $this->build($binding['concrete'] ?? $id);
            if ($binding['shared']) {
                $this->singletons[$id] = $object;
            }
            return $object;
        }

        // No prior registration: attempt auto-wiring
        return $this->build($id);
    }

    public function has(string $id): bool
    {
        if (isset($this->singletons[$id]) || isset($this->bindings[$id])) {
            return true;
        }
        try {
            return class_exists($id);
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------- Registration ----------

    /**
     * Register a binding.
     * @param string $abstract Class name or interface
     * @param mixed $concrete Class, Closure, or object
     */
    public function bind(string $abstract, mixed $concrete = null, bool $shared = false): void
    {
        // A new binding must replace any previously resolved instance,
        // otherwise get() would keep returning the stale singleton.
        unset($this->singletons[$abstract]);
        $this->bindings[$abstract] = [
            'concrete' => $concrete ?? $abstract,
            'shared' => $shared,
        ];
    }

    /** Register a singleton */
    public function singleton(string $abstract, mixed $concrete = null): void
    {
        $this->bind($abstract, $concrete, true);
    }

    /** Register a ready instance */
    public function instance(string $abstract, object $instance): void
    {
        $this->singletons[$abstract] = $instance;
    }

    // ---------- Building ----------

    /**
     * Create an object with automatic constructor injection.
     */
    public function make(string $class, array $params = []): object
    {
        $this->contextual = $params;
        try {
            return $this->build($class);
        } finally {
            $this->contextual = [];
        }
    }

    protected function build(mixed $concrete): object
    {
        // Ready Closure
        if ($concrete instanceof \Closure) {
            return $concrete($this);
        }

        // Ready object
        if (is_object($concrete)) {
            return $concrete;
        }

        if (!is_string($concrete) || !class_exists($concrete)) {
            $label = is_string($concrete) ? $concrete : get_debug_type($concrete);
            throw new Exceptions\BindingException(
                "Cannot resolve dependency [{$label}]: class not found. Check the name or register it via bind()."
            );
        }

        $ref = $this->getReflection($concrete);

        if (!$ref->isInstantiable()) {
            throw new Exceptions\BindingException(
                "Cannot instantiate [{$concrete}]: class is not instantiable (interface or abstract). Register a binding via bind()."
            );
        }

        $ctor = $ref->getConstructor();
        if ($ctor === null || $ctor->getNumberOfParameters() === 0) {
            return $ref->newInstance();
        }

        $args = $this->resolveParameters($ctor->getParameters(), []);
        return $ref->newInstanceArgs($args);
    }

    /**
     * Call a function/method with automatic injection.
     *
     * @param mixed $callable Function, Closure, [class/object, method], or "Class@method"
     * @param array $routeParams Route parameters (e.g. ['id' => 5])
     */
    public function call(mixed $callable, array $routeParams = []): mixed
    {
        // Case: [ClassName::class, 'method']
        if (is_array($callable) && count($callable) === 2) {
            [$target, $method] = $callable;
            $object = is_object($target) ? $target : $this->get((string) $target);
            $ref = new \ReflectionMethod($object, (string) $method);
            $args = $this->resolveParameters($ref->getParameters(), $routeParams);
            return $ref->invokeArgs($object, $args);
        }

        // Case: Closure or function
        if ($callable instanceof \Closure || is_string($callable) && function_exists($callable)) {
            $ref = new \ReflectionFunction(\Closure::fromCallable($callable));
            $args = $this->resolveParameters($ref->getParameters(), $routeParams);
            return $ref->invokeArgs($args);
        }

        // Case: "Class@method" (convenience)
        if (is_string($callable) && str_contains($callable, '@')) {
            [$class, $method] = explode('@', $callable, 2);
            return $this->call([$class, $method], $routeParams);
        }

        throw new Exceptions\BindingException('Unsupported callable format in Container::call(). Use Closure or [Class, method].');
    }

    /**
     * Resolve function parameters: inject by type, by route param name, or default value.
     */
    protected function resolveParameters(array $params, array $routeParams): array
    {
        $args = [];

        foreach ($params as $param) {
            $name = $param->getName();
            $type = $param->getType();

            // 1) Route param matching by name (e.g. {id} with $id)
            if (array_key_exists($name, $routeParams) && (!$type instanceof \ReflectionNamedType || $type->isBuiltin())) {
                $args[] = $this->castBuiltin($routeParams[$name], $type);
                continue;
            }

            // 2) Additional context
            if (array_key_exists($name, $this->contextual)) {
                $args[] = $this->contextual[$name];
                continue;
            }

            // 3) Class type: auto-inject
            if ($type instanceof \ReflectionNamedType && !$type->isBuiltin()) {
                $typeName = $type->getName();

                // Current Request is registered as singleton — reuse it
                if (isset($this->singletons[$typeName])) {
                    $args[] = $this->singletons[$typeName];
                    continue;
                }

                try {
                    $args[] = $this->get($typeName);
                } catch (\Throwable $e) {
                    if ($param->isDefaultValueAvailable()) {
                        $args[] = $param->getDefaultValue();
                    } elseif ($type->allowsNull()) {
                        $args[] = null;
                    } else {
                        throw new Exceptions\BindingException(
                            "Cannot inject parameter [\${$name}] of type [{$typeName}] in [{$this->describeParam($param)}]. " .
                            "Reason: {$e->getMessage()}"
                        );
                    }
                }
                continue;
            }

            // 4) Builtin type with default value
            if ($param->isDefaultValueAvailable()) {
                $args[] = $param->getDefaultValue();
                continue;
            }

            // 5) Route param by position (fallback): take first unused value
            if (!empty($routeParams)) {
                $args[] = array_shift($routeParams);
                continue;
            }

            // 6) Nullable without value
            if ($type instanceof \ReflectionNamedType && $type->allowsNull()) {
                $args[] = null;
                continue;
            }

            throw new Exceptions\BindingException(
                "Cannot resolve parameter [\${$name}] in [{$this->describeParam($param)}]: no matching route param and no default value."
            );
        }

        return $args;
    }

    private function castBuiltin(mixed $value, mixed $type): mixed
    {
        if (!$type instanceof \ReflectionNamedType) {
            return $value;
        }
        return match ($type->getName()) {
            'int' => (int) $value,
            'float' => (float) $value,
            'bool' => filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $value,
            'array' => (array) $value,
            'string' => (string) $value,
            default => $value,
        };
    }

    private function describeParam(\ReflectionParameter $param): string
    {
        $fn = $param->getDeclaringFunction();
        if ($fn instanceof \ReflectionMethod) {
            return $fn->getDeclaringClass()->getName() . '::' . $fn->getName() . '()';
        }
        return $fn->getName() . '()';
    }

    /** Get cached ReflectionClass for performance */
    protected function getReflection(string $class): \ReflectionClass
    {
        return $this->reflectionCache[$class] ??= new \ReflectionClass($class);
    }
}
