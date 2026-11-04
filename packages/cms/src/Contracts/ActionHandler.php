<?php

namespace Formaku\Cms\Contracts;

interface ActionHandler
{
    public function execute(object $user, array $input): array;
}
