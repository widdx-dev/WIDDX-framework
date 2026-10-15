<?php

declare(strict_types=1);

namespace Widdx\Tests;

/**
 * 2.11 fixture: both classes share the parameter NAME $name, so if the
 * container stores contextual params in instance state they leak into the
 * nested build. Correct behaviour: only the top-level constructor sees them.
 */
class CtxDep
{
    public function __construct(public string $name = 'default') {}
}

class CtxTop
{
    public function __construct(public CtxDep $dep, public string $name = 'default') {}
}
