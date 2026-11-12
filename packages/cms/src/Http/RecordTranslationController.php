<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordTranslations;
use Illuminate\Http\Request;

class RecordTranslationController
{
    public function show(Request $request, Record $record, RecordTranslations $translations): array
    {
        return $translations->current($record, $request->user());
    }

    public function update(Request $request, Record $record, RecordTranslations $translations): array
    {
        $data = $request->validate([
            'version' => 'required|integer|min:0',
            'locale' => ['present', 'nullable', 'required_with:group', 'string', 'regex:/\A[A-Za-z]{2,3}(?:-[A-Za-z]{4})?(?:-(?:[A-Za-z]{2}|[0-9]{3}))?\z/'],
            'group' => ['present', 'nullable', 'required_with:locale', 'string', 'max:100', 'regex:/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/i'],
        ]);

        return $translations->save(
            $record,
            $request->user(),
            (int) $data['version'],
            $data['locale'] === null ? null : $this->normalizeLocale($data['locale']),
            $data['group'] === null ? null : strtolower($data['group']),
        );
    }

    private function normalizeLocale(string $locale): string
    {
        $parts = explode('-', strtolower($locale));
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $parts[$index] = strlen($part) === 4 ? ucfirst($part) : strtoupper($part);
            }
        }

        return implode('-', $parts);
    }
}
