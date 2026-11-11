<?php

namespace App\Providers;

use App\Formaku\NoticeBlock;
use App\Formaku\OwnProfileResource;
use App\Formaku\PreviewNoticeAction;
use Formaku\Cms\Services\Registry;
use Illuminate\Support\ServiceProvider;

final class FormakuExamplesServiceProvider extends ServiceProvider
{
    public function boot(Registry $registry): void
    {
        $registry->block($this->app->make(NoticeBlock::class));
        $registry->resource($this->app->make(OwnProfileResource::class));
        $registry->action('example_preview_notice', $this->app->make(PreviewNoticeAction::class));
    }
}
