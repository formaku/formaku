<?php

use Formaku\Cms\Http\Access;
use Formaku\Cms\Http\AgencyPackageController;
use Illuminate\Support\Facades\Route;

Route::middleware(Access::class.':build')->prefix('agency/packages')->controller(AgencyPackageController::class)->group(function (): void {
    Route::get('/', 'index');
    Route::post('/export', 'export');
    Route::post('/preview', 'preview');
    Route::post('/apply', 'apply');
    Route::post('/{deployment}/rollback', 'rollback')->whereNumber('deployment');
});
