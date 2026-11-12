<?php

namespace Formaku\Cms\Blocks;

use Formaku\Cms\Contracts\PanelBlockType;

/** Defaults for a block without settings or nested blocks. */
abstract class Block implements PanelBlockType
{
    public function schema(): array
    {
        return [];
    }

    public function panelSchema(): array
    {
        return [];
    }

    public function acceptsChildren(): bool
    {
        return false;
    }
}
