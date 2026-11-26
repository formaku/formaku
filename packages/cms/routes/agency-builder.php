<?php

use Formaku\Cms\Http\Access;
use Formaku\Cms\Http\PortalProjectController;
use Formaku\Cms\Http\PortalProjectOwner;
use Illuminate\Support\Facades\Route;

Route::prefix('portal-projects')->middleware(Access::class.':build')->group(function (): void {
    Route::get('/', [PortalProjectController::class, 'index']);
    Route::post('/', [PortalProjectController::class, 'create'])->middleware('throttle:10,1,formaku-portal-project');
    Route::prefix('{project}')->where(['project' => '[0-9]+'])->middleware(PortalProjectOwner::class)->group(function (): void {
        Route::get('/', [PortalProjectController::class, 'show']);
        Route::put('/', [PortalProjectController::class, 'update']);
        Route::post('/apply', [PortalProjectController::class, 'apply']);
        Route::match(['get', 'post'], '/preview-form', [PortalProjectController::class, 'previewForm'])->name('formaku.project.preview-form');
        Route::get('/preview/{index}', [PortalProjectController::class, 'preview'])->whereNumber('index');
        Route::get('/changes', [PortalProjectController::class, 'changes']);
        Route::post('/refresh', [PortalProjectController::class, 'refresh']);
    });
});
