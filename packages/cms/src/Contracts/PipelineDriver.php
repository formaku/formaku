<?php

namespace Formaku\Cms\Contracts;

interface PipelineDriver
{
    public function dispatch(string $releaseId, string $commit): void;

    public function status(string $releaseId): array;
}
