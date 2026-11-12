<?php

namespace Formaku\Cms\Services;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalAttachment;
use Formaku\Cms\Models\Record;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class PortalAttachments
{
    public const MAX_FILES = 3;

    public const MAX_MIB = 5;

    private const MIME_TYPES = [
        'pdf' => 'application/pdf',
        'png' => 'image/png',
        'jpeg' => 'image/jpeg',
    ];

    private const INPUT = '_portal_attachments';

    private const DIRECTORY = 'formaku/portal-attachments';

    public function __construct(private CollectionAccess $access) {}

    public function canUpload(Collection $collection, object $user): bool
    {
        if (! PortalFields::supportsCollection($collection->key, $collection->kind)
            || ! in_array($collection->definition['portal_attachments'] ?? false, [true, 1, '1'], true)) {
            return false;
        }

        $rule = $this->access->rule($collection, $user);

        return ($rule['write'] ?? false) && in_array($rule['scope'], ['all', 'own'], true);
    }

    /** @return list<string> */
    public static function supportedTypes(): array
    {
        return array_keys(self::MIME_TYPES);
    }

    /** @return array{max_files: int, max_mib: int, types: list<string>, accept: string} */
    public function limits(Collection $collection): array
    {
        $settings = $collection->definition['portal_attachment_limits'] ?? [];
        if (! is_array($settings) || ! PortalFields::supportsCollection($collection->key, $collection->kind)) {
            $settings = [];
        }

        $types = $settings['types'] ?? null;
        if (! is_array($types) || ! array_is_list($types) || $types === []
            || array_filter($types, fn ($type): bool => is_string($type) && isset(self::MIME_TYPES[$type])) !== $types
            || count(array_unique($types)) !== count($types)) {
            $types = self::supportedTypes();
        }

        $extensions = [];
        foreach ($types as $type) {
            if ($type === 'jpeg') {
                $extensions[] = '.jpg';
            }
            $extensions[] = '.'.$type;
        }

        return [
            'max_files' => $this->boundedLimit($settings['max_files'] ?? null, self::MAX_FILES),
            'max_mib' => $this->boundedLimit($settings['max_mib'] ?? null, self::MAX_MIB),
            'types' => $types,
            'accept' => implode(',', [...$extensions, ...$this->mimeTypes($types)]),
        ];
    }

    private function boundedLimit(mixed $limit, int $maximum): int
    {
        return is_int($limit) && $limit >= 1 && $limit <= $maximum ? $limit : $maximum;
    }

    /**
     * @param  list<string>  $types
     * @return list<string>
     */
    private function mimeTypes(array $types): array
    {
        return array_map(fn (string $type): string => self::MIME_TYPES[$type], $types);
    }

    /** @return list<UploadedFile> */
    public function validate(Request $request, Collection $collection, object $user): array
    {
        $limits = $this->limits($collection);
        $validated = $request->validate([
            self::INPUT => ['sometimes', 'array', 'list', 'max:'.$limits['max_files']],
            self::INPUT.'.*' => [
                'required', 'file', 'max:'.($limits['max_mib'] * 1024),
                'mimetypes:'.implode(',', $this->mimeTypes($limits['types'])),
            ],
        ]);
        $files = $validated[self::INPUT] ?? [];

        if ($files !== [] && ! $this->canUpload($collection, $user)) {
            throw ValidationException::withMessages([self::INPUT => 'Załączniki są niedostępne dla tego zgłoszenia.']);
        }

        return $files;
    }

    /** @param list<string> $paths */
    public function store(Record $record, UploadedFile $file, array &$paths): void
    {
        $filename = (string) Str::ulid();
        $path = self::DIRECTORY.'/'.$filename;
        $paths[] = $path;

        if ($file->storeAs(self::DIRECTORY, $filename, ['disk' => 'local', 'visibility' => 'private']) !== $path) {
            throw new RuntimeException('Could not store the portal attachment.');
        }

        PortalAttachment::create([
            'record_id' => $record->id,
            'path' => $path,
            'name' => $this->safeName($file),
            'size' => $file->getSize(),
        ]);
    }

    /** @param list<string> $paths */
    public function discard(array $paths): void
    {
        if ($paths === []) {
            return;
        }

        try {
            $linked = PortalAttachment::whereIn('path', $paths)->pluck('path')->all();
            $unlinked = array_diff($paths, $linked);
            if ($unlinked !== [] && ! Storage::disk('local')->delete($unlinked)) {
                report(new RuntimeException('Could not remove uncommitted portal attachments.'));
            }
        } catch (Throwable $error) {
            report($error);
        }
    }

    /** @return list<array{id: int, name: string, size: int, url: string}> */
    public function metadata(Record $record, string $route): array
    {
        return PortalAttachment::where('record_id', $record->id)->orderBy('id')->get()->map(
            fn (PortalAttachment $attachment): array => [
                'id' => $attachment->id,
                'name' => $attachment->name,
                'size' => $attachment->size,
                'url' => route($route, ['record' => $record, 'attachment' => $attachment->id], false),
            ],
        )->all();
    }

    public function download(Record $record, string $attachmentId): StreamedResponse
    {
        $id = filter_var($attachmentId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        abort_if($id === false, 404);
        $attachment = PortalAttachment::where('record_id', $record->id)->findOrFail($id);
        $disk = Storage::disk('local');
        abort_unless($disk->fileExists($attachment->path), 404);

        return $disk->download($attachment->path, $attachment->name, [
            'Cache-Control' => 'private, no-store',
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function safeName(UploadedFile $file): string
    {
        $name = preg_replace('~[/\\\\\p{C}]~u', '_', $file->getClientOriginalName()) ?? '';
        $name = mb_substr(trim($name, " .\t"), 0, 200);

        return $name !== '' ? $name : 'attachment';
    }
}
