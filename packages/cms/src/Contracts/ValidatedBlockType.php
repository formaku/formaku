<?php

namespace Formaku\Cms\Contracts;

interface ValidatedBlockType extends BlockType
{
    /** @return array<string, mixed> */
    public function rules(): array;
}
