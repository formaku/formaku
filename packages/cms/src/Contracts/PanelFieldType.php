<?php

namespace Formaku\Cms\Contracts;

interface PanelFieldType extends FieldType
{
    public function label(): string;

    public function input(): string;
}
