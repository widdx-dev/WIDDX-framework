<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Concrete CRUD controller used by resource('{name}') when no controller
 * class is given. The model arrives through the constructor.
 */
class ResourceController extends CrudController
{
    public function __construct(string $modelClass)
    {
        $this->model = $modelClass;
    }
}
