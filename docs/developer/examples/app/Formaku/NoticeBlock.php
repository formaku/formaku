<?php

namespace App\Formaku;

use Formaku\Cms\Contracts\BlockType;
use Illuminate\Support\Facades\Validator;

final class NoticeBlock implements BlockType
{
    public function key(): string
    {
        return 'example_notice';
    }

    public function schema(): array
    {
        return ['message' => ['required', 'string', 'max:160']];
    }

    public function render(array $props): string
    {
        $data = Validator::make($props, $this->schema())->validate();

        return '<p>'.e($data['message']).'</p>';
    }
}
