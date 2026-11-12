<?php

namespace Formaku\Cms\Fields;

use Closure;
use Formaku\Cms\Contracts\PanelFieldType;

/**
 * A field type described by values. Enough for types that only need
 * validation rules and one of the panel's existing controls.
 */
final class Field implements PanelFieldType
{
    /** @param array|Closure(array): array $rules */
    public function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $input,
        private readonly array|Closure $rules,
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function label(): string
    {
        return $this->label;
    }

    public function input(): string
    {
        return $this->input;
    }

    public function rules(array $definition): array
    {
        return $this->rules instanceof Closure ? ($this->rules)($definition) : $this->rules;
    }
}
