<?php

use Formaku\Cms\Services\Registry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->prefix('extension-examples')->group(function (): void {
    Route::get('/profile', function (Request $request, Registry $registry): array {
        $resource = $registry->resources()['example_own_profile'] ?? abort(404);
        $profile = $resource->query($request->user())->firstOrFail();

        return ['id' => $profile->getKey(), 'name' => $profile->name];
    });

    Route::post('/preview-notice', function (Request $request, Registry $registry): array {
        $action = $registry->actions()['example_preview_notice'] ?? abort(404);

        return $action->execute($request->user(), $request->only('message'));
    });
});
