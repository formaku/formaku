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

    /** @return list<UploadedFile> */
    public function validate(Request $request, Collection $collection, object $user): array
    {
        $validated = $request->validate([
            self::INPUT => ['sometimes', 'array', 'list', 'max:3'],
            self::INPUT.'.*' => ['required', 'file', 'max:5120', 'mimetypes:application/pdf,image/png,image/jpeg'],
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
