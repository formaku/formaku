<?php

namespace Formaku\Cms\Contracts;

interface AiProvider
{
    public function generate(string $prompt): array;
}
