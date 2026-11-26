<?php

use Formaku\Cms\Http\Access;
use Formaku\Cms\Http\PortalCheckController;
use Formaku\Cms\Http\SharePortalReplyController;
use Illuminate\Support\Facades\Route;

Route::match(['GET', 'POST'], '/agency/check', PortalCheckController::class)->middleware(Access::class.':build');
Route::post('/records/{record}/share-reply', SharePortalReplyController::class)->whereNumber('record');
