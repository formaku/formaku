<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Media;
use GdImage;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class MediaVariants
{
    public const MIMES = ['image/jpeg', 'image/png', 'image/webp'];

    private const WIDTHS = [480, 960];

    private const MAX_BYTES = 10 * 1024 * 1024;

    private const MAX_DIMENSION = 8192;

    private const MAX_PIXELS = 12_000_000;

    public function canGenerate(): bool
    {
        return extension_loaded('gd') && (gd_info()['WebP Support'] ?? false);
    }

    public function generate(Media $media): int
    {
        if (! $this->eligible($media) || $media->size > self::MAX_BYTES || ! $this->canGenerate()) {
            return 0;
        }

        $disk = Storage::disk($media->disk);
        $directory = $this->directory($media);
        $knownWidth = $this->originalWidth($disk, $directory);
        if ($knownWidth !== null && $this->missingWidths($disk, $directory, $knownWidth) === []) {
            return 0;
        }

        $source = $this->readSource($disk, $media->path);
        $dimensions = $source !== null ? @getimagesizefromstring($source) : false;
        if ($dimensions === false || $dimensions['mime'] !== $media->mime) {
            return 0;
        }
        [$width, $height] = $dimensions;
        if ($width <= min(self::WIDTHS) || $height < 1 || max($width, $height) > self::MAX_DIMENSION
            || $width * $height > self::MAX_PIXELS || ! $this->hasMemoryFor($width, $height)
            || ! $this->preservesAppearance($source, $media->mime)) {
            return 0;
        }

        $original = @imagecreatefromstring($source);
        if ($original === false) {
            return 0;
        }
        unset($source);

        $generated = 0;
        $this->writeAtomically($disk, "$directory/width", (string) $width);
        foreach ($this->missingWidths($disk, $directory, $width) as $target) {
            $this->resize($disk, $original, "$directory/$target.webp", $target, max(1, (int) round($height * $target / $width)));
            $generated++;
        }

        return $generated;
    }

    /** @return array{original: int, variants: list<int>}|null */
    public function available(Media $media): ?array
    {
        if (! $this->eligible($media)) {
            return null;
        }

        try {
            $disk = Storage::disk($media->disk);
            $directory = $this->directory($media);
            $original = $this->originalWidth($disk, $directory);
            if ($original === null) {
                return null;
            }

            $variants = array_values(array_filter(self::WIDTHS, fn (int $width) => $width < $original && $disk->fileExists("$directory/$width.webp")));

            return $variants === [] ? null : ['original' => $original, 'variants' => $variants];
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }

    public function availablePath(Media $media, int $width): ?string
    {
        if (! $this->eligible($media) || ! in_array($width, self::WIDTHS, true)) {
            return null;
        }

        $path = $this->directory($media)."/$width.webp";

        return Storage::disk($media->disk)->fileExists($path) ? $path : null;
    }

    private function eligible(Media $media): bool
    {
        return $media->visibility === 'public' && in_array($media->mime, self::MIMES, true);
    }

    private function directory(Media $media): string
    {
        return 'formaku/variants/v1/'.$media->id.'/'.hash('sha256', $media->path);
    }

    private function originalWidth(FilesystemAdapter $disk, string $directory): ?int
    {
        if (! $disk->fileExists("$directory/width")) {
            return null;
        }
        $width = $disk->get("$directory/width");

        return filter_var($width, FILTER_VALIDATE_INT, ['options' => [
            'min_range' => min(self::WIDTHS) + 1,
            'max_range' => self::MAX_DIMENSION,
        ]]) ?: null;
    }

    /** @return list<int> */
    private function missingWidths(FilesystemAdapter $disk, string $directory, int $original): array
    {
        return array_values(array_filter(self::WIDTHS, fn (int $width) => $width < $original && ! $disk->fileExists("$directory/$width.webp")));
    }

    private function readSource(FilesystemAdapter $disk, string $path): ?string
    {
        $stream = $disk->readStream($path);
        if (! is_resource($stream)) {
            return null;
        }

        try {
            $bytes = stream_get_contents($stream, self::MAX_BYTES + 1);

            return $bytes !== false && strlen($bytes) <= self::MAX_BYTES ? $bytes : null;
        } finally {
            fclose($stream);
        }
    }

    private function hasMemoryFor(int $width, int $height): bool
    {
        $limit = ini_parse_quantity((string) ini_get('memory_limit'));
        $target = min(max(self::WIDTHS), $width);
        $estimated = 8 * ($width * $height + $target * ceil($height * $target / $width)) + 16 * 1024 * 1024;

        return $limit < 0 || memory_get_usage(true) + $estimated < $limit;
    }

    private function preservesAppearance(string $bytes, string $mime): bool
    {
        return match ($mime) {
            'image/jpeg' => $this->hasDefaultJpegOrientation($bytes),
            'image/png' => ! str_contains($bytes, 'acTL') && ! str_contains($bytes, 'eXIf'),
            'image/webp' => ! str_contains($bytes, 'ANIM') && ! str_contains($bytes, 'EXIF'),
            default => false,
        };
    }

    private function hasDefaultJpegOrientation(string $bytes): bool
    {
        if (! function_exists('exif_read_data') || str_contains($bytes, 'tiff:Orientation')) {
            return false;
        }

        $stream = tmpfile();
        if ($stream === false) {
            return false;
        }
        try {
            if (fwrite($stream, $bytes) !== strlen($bytes) || ! rewind($stream)) {
                return false;
            }
            $exif = @exif_read_data($stream, as_arrays: true);

            return $exif !== false && (! str_contains($bytes, "Exif\0\0") || isset($exif['IFD0']))
                && ($exif['IFD0']['Orientation'] ?? 1) === 1;
        } finally {
            fclose($stream);
        }
    }

    private function resize(FilesystemAdapter $disk, GdImage $original, string $path, int $width, int $height): void
    {
        $image = imagecreatetruecolor($width, $height);
        if ($image === false) {
            throw new RuntimeException('Could not allocate an image variant.');
        }
        imagealphablending($image, false);
        imagesavealpha($image, true);
        if (! imagecopyresampled($image, $original, 0, 0, 0, 0, $width, $height, imagesx($original), imagesy($original))) {
            throw new RuntimeException('Could not resize an image variant.');
        }

        $stream = tmpfile();
        if ($stream === false) {
            throw new RuntimeException('Could not create a temporary image.');
        }
        try {
            $file = stream_get_meta_data($stream)['uri'];
            $written = @imagewebp($image, $file, 80) ? @getimagesize($file) : false;
            if ($written === false || $written[0] !== $width || $written[1] !== $height || $written['mime'] !== 'image/webp') {
                throw new RuntimeException('Could not encode a WebP variant.');
            }
            $this->writeAtomically($disk, $path, $stream);
        } finally {
            fclose($stream);
        }
    }

    /** @param resource|string $contents */
    private function writeAtomically(FilesystemAdapter $disk, string $path, mixed $contents): void
    {
        $temporary = $path.'.'.Str::uuid().'.tmp';
        try {
            if ($disk->put($temporary, $contents) && $disk->move($temporary, $path)) {
                return;
            }
            throw new RuntimeException('Could not store an image variant.');
        } finally {
            if ($disk->fileExists($temporary)) {
                $disk->delete($temporary);
            }
        }
    }
}
