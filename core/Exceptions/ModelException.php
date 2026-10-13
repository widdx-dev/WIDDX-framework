<?php

declare(strict_types=1);

namespace Widdx\Exceptions;

/** Thrown on model misuse (e.g. deleting an unsaved record). */
class ModelException extends WiddxException {}
