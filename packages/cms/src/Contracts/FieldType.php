<?php

namespace Formaku\Cms\Contracts;

interface FieldType
{
    public function key(): string;

    public function rules(array $definition): array;
}
