<?php

declare(strict_types=1);

namespace Widdx\Tests;

use Widdx\Model;

/** Form-field inference fixture: one rule per numeric/text shape. */
class Pricing extends Model
{
    protected ?string $table = 'pricing';

    protected array $fillable = ['label', 'unit', 'qty', 'cover', 'note'];

    protected array $rules = [
        'label' => 'required|string|max:255',
        'unit' => 'required|numeric',
        'qty' => 'required|integer',
        'cover' => 'nullable|image',
        'note' => 'nullable|text',
    ];
}
