<?php

declare(strict_types=1);

namespace Widdx;

/**
 * Concrete JSON API controller used by apiResource('{name}') when no
 * controller class is given. The model arrives through the constructor.
 */
class ResourceApiController extends ApiCrudController
{
    public function __construct(string $modelClass)
    {
        $this->model = $modelClass;
    }
}
