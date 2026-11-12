<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

class InstallCommandTest extends TestCase
{
    public function test_install_invalidates_both_formaku_blade_paths_and_keeps_host_views(): void
    {
        $directory = sys_get_temp_dir().'/formaku-install-'.Str::random(16);
        $compiledDirectory = $directory.'/compiled';
        $hostViews = $directory.'/views';
        $overrideViews = $hostViews.'/vendor/formaku';
        File::ensureDirectoryExists($compiledDirectory);
        File::ensureDirectoryExists($overrideViews);
        config(['view.compiled' => $compiledDirectory, 'view.relative_hash' => true]);

        try {
            $hostView = $hostViews.'/host.blade.php';
            $overrideView = $overrideViews.'/page.blade.php';
            File::put($hostView, '<p>Host view {{ 1 }}</p>');
            File::put($overrideView, '<p>Host override {{ 2 }}</p>');
            app('view')->addLocation($hostViews);
            app('view')->prependNamespace('formaku', $overrideViews);

            $compiler = app('blade.compiler');
            $formakuView = view('formaku::admin')->getPath();
            $canonicalView = realpath($formakuView);
            $literalView = dirname($canonicalView, 3).'/src/../resources/views/admin.blade.php';
            $onDemandCache = $compiler->getCompiledPath($formakuView);
            $compiledViews = [
                $compiler->getCompiledPath($canonicalView),
                $compiler->getCompiledPath($literalView),
            ];
            $this->assertNotSame(...$compiledViews);

            foreach ($compiledViews as $cacheFile) {
                File::put($cacheFile, 'Stale Formaku panel');
                touch($cacheFile, max(time(), filemtime($formakuView)) + 60);
            }

            $this->assertFalse($compiler->isExpired($formakuView));
            $this->assertSame('Stale Formaku panel', trim(view('formaku::admin')->render()));

            $preservedFiles = [config_path('formaku.php') => File::get(config_path('formaku.php'))];
            foreach (['host', 'formaku::page'] as $viewName) {
                $path = view($viewName)->getPath();
                $compiler->compile($path);
                $cacheFile = $compiler->getCompiledPath($path);
                $preservedFiles[$cacheFile] = File::get($cacheFile);
            }

            $this->artisan('formaku:install')->assertSuccessful();
            foreach ($compiledViews as $cacheFile) {
                $this->assertFileDoesNotExist($cacheFile);
            }
            $this->assertStringContainsString('id="formaku"', view('formaku::admin')->render());
            $this->assertFileExists($onDemandCache);

            $this->artisan('formaku:install')->assertSuccessful();
            $this->assertFileDoesNotExist($onDemandCache);
            foreach ($preservedFiles as $path => $contents) {
                $this->assertSame($contents, File::get($path));
            }
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
