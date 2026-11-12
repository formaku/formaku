<?php

namespace App\Providers;

use App\Formaku\NoticeBlock;
use App\Formaku\OwnProfileResource;
use App\Formaku\PreviewNoticeAction;
use App\Models\User;
use Formaku\Cms\Services\Registry;
use Illuminate\Support\ServiceProvider;

final class FormakuExamplesServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry): void
    {
        $registry->block($this->app->make(NoticeBlock::class));
        $authorize = static fn (object $user): bool => $user instanceof User && $user->exists;

        $registry->resource($this->app->make(OwnProfileResource::class), [
            'label' => 'My profile',
            'columns' => [
                ['key' => 'id', 'label' => 'ID'],
                ['key' => 'name', 'label' => 'Name'],
            ],
            'authorize' => $authorize,
        ]);
        $registry->action('example_preview_notice', $this->app->make(PreviewNoticeAction::class), [
            'label' => 'Preview notice',
            'fields' => [
                ['key' => 'message', 'label' => 'Message', 'type' => 'text', 'required' => true],
            ],
            'authorize' => $authorize,
        ]);
    }
}
