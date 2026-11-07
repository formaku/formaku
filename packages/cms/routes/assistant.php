<?php

use Formaku\Cms\Http\Access;
use Formaku\Cms\Http\AssistantController;
use Illuminate\Support\Facades\Route;

Route::middleware('web')
    ->prefix(config('formaku.prefix').'/api/v1/assistant')
    ->group(function (): void {
        Route::delete('/', [AssistantController::class, 'revoke']);
        Route::middleware([Access::class.':work', Access::class.':build'])->group(function (): void {
            Route::get('/', [AssistantController::class, 'settings']);
            Route::post('/pairing', [AssistantController::class, 'start'])->middleware('throttle:10,1,formaku-assistant-pairing');
            Route::get('/adapter', [AssistantController::class, 'adapter']);
        });
    });

Route::prefix(config('formaku.prefix').'/assistant/v1')->group(function () {
    Route::post('/exchange', [AssistantController::class, 'exchange'])->middleware('throttle:10,1,formaku-assistant-exchange');
    Route::middleware(['throttle:120,1,formaku-assistant-api', 'cache.headers:no_store'])->group(function () {
        Route::get('/connection', [AssistantController::class, 'describe']);
        Route::get('/pages', [AssistantController::class, 'pages']);
        Route::post('/pages', [AssistantController::class, 'create']);
        Route::get('/pages/{page}', [AssistantController::class, 'page'])->whereNumber('page');
        Route::put('/pages/{page}', [AssistantController::class, 'update'])->whereNumber('page');
        Route::post('/pages/{page}/publish', [AssistantController::class, 'publish'])->whereNumber('page');
    });
});
