<?php

declare(strict_types=1);

namespace Widdx\Exceptions;

/** Thrown when no route matches the requested URI/method. */
class RouteNotFoundException extends WiddxException {}
