<?php

namespace Formaku\Cms\Console;

use Formaku\Cms\Models\Media;
use Formaku\Cms\Services\MediaVariants;
use Illuminate\Console\Command;
use Throwable;

class MediaVariantsCommand extends Command
{
    protected $signature = 'formaku:media-variants';

    protected $description = 'Generate WebP variants for existing public images';

    public function handle(MediaVariants $variants): int
    {
        if (! $variants->canGenerate()) {
            $this->warn('GD with WebP support is unavailable. Existing files were left as they are.');

            return self::SUCCESS;
        }

        $processed = $generated = $failed = 0;
        foreach (Media::where('visibility', 'public')->whereIn('mime', MediaVariants::MIMES)->lazyById(100) as $media) {
            $processed++;
            try {
                $generated += $variants->generate($media);
            } catch (Throwable $error) {
                report($error);
                $failed++;
                $this->error("Could not generate variants for media {$media->id}.");
            }
        }
        $this->info("Checked $processed public images. Generated $generated WebP variants.");

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
