<?php
use Illuminate\Support\Facades\Route;use Formaku\Cms\Http\{Access,ApiController,PublicController,IntegrationController,SitemapController};
Route::middleware('web')->prefix(config('formaku.prefix'))->group(function(){
 Route::get('/',fn()=>view('formaku::admin'))->middleware([ 'auth:'.config('formaku.guard'),Access::class.':work']);
 Route::get('/media/{media}',[PublicController::class,'file']);
 Route::prefix('api/v1')->middleware(Access::class.':work')->group(function(){
  Route::get('/bootstrap',[ApiController::class,'bootstrap']);Route::get('/collections',[ApiController::class,'collections']);
  Route::post('/collections',[ApiController::class,'schema'])->middleware(Access::class.':build');
  Route::get('/collections/{collection}/records',[ApiController::class,'records']);Route::post('/collections/{collection}/records',[ApiController::class,'save']);
  Route::get('/collections/{collection}/relation-options',[ApiController::class,'relationOptions']);
  Route::get('/records/{record}',[ApiController::class,'show']);Route::get('/records/{record}/preview',[ApiController::class,'preview']);
  Route::post('/records/{record}/publish',[ApiController::class,'publish'])->middleware(Access::class.':publish');
  Route::post('/records/{record}/schedule',[ApiController::class,'schedule'])->middleware(Access::class.':publish');
  Route::post('/records/{record}/transition',[ApiController::class,'transition']);Route::post('/records/{record}/restore/{revision}',[ApiController::class,'restore']);
  Route::get('/media',[ApiController::class,'media']);Route::post('/media',[ApiController::class,'upload']);
  Route::get('/media/{mediaId}/usage',[ApiController::class,'mediaUsage'])->whereNumber('mediaId');
  Route::patch('/media/{mediaId}',[ApiController::class,'updateMedia'])->whereNumber('mediaId');
  Route::get('/project',[ApiController::class,'export'])->middleware(Access::class.':build');Route::post('/project/import',[ApiController::class,'import'])->middleware(Access::class.':build');
  Route::get('/ai',[IntegrationController::class,'tasks']);Route::post('/ai',[IntegrationController::class,'generate'])->middleware('throttle:10,1');Route::post('/ai/{task}/cancel',[IntegrationController::class,'cancel']);
  Route::get('/releases',[IntegrationController::class,'releases'])->middleware(Access::class.':build');Route::post('/releases',[IntegrationController::class,'release'])->middleware(Access::class.':build');
 });
});
if (config('formaku.public_routes')) {
    Route::middleware('web')->group(function () {
        Route::get('/', [PublicController::class, 'home'])->name('formaku.public.home');
        Route::get('/p/{slug}', [PublicController::class, 'page'])->name('formaku.public.page');
        Route::middleware('auth:'.config('formaku.guard'))->group(function () {
            Route::get('/portal', [PublicController::class, 'portal']);
            Route::post('/portal/requests', [PublicController::class, 'submit'])->middleware('throttle:10,1');
            Route::get('/portal/requests/{record}', [PublicController::class, 'request']);
        });
    });

    if (config('formaku.sitemap')) {
        Route::get('/sitemap.xml', SitemapController::class);
        Route::get('/sitemap-{page}.xml', SitemapController::class)
            ->whereNumber('page')->name('formaku.public.sitemap');
    }
}
