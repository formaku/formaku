<?php

namespace App\Formaku;

use App\Models\User;
use Formaku\Cms\Contracts\ActionHandler;
use Illuminate\Support\Facades\Validator;

final class PreviewNoticeAction implements ActionHandler
{
    public function __construct(private NoticeBlock $block) {}

    public function execute(object $user, array $input): array
    {
        abort_unless($user instanceof User && $user->exists, 403);

        $data = Validator::make($input, $this->block->schema())->validate();

        return ['message' => trim($data['message'])];
    }
}
