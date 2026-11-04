<?php

namespace Formaku\Cms\Contracts;

interface BlockType
{
    public function key(): string;

    public function schema(): array;

    public function render(array $props): string;
}
