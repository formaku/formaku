<?php

namespace Formaku\Cms\Services;

use Dom\HTMLDocument;
use Formaku\Cms\Models\AgencyDeployment;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Models\Record;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PortalAcceptance
{
    public function available(): bool
    {
        return app()->environment('testing') && ! app()->configurationIsCached()
            && config('database.default') === 'sqlite'
            && str_ends_with((string) config('database.connections.sqlite.database'), 'formaku-e2e.sqlite')
            && is_file(base_path('../../tests/acceptance/b2b-portal.mjs'))
            && is_file(base_path('../../node_modules/@axe-core/playwright/package.json'));
    }

    public function report(?int $projectId, ?int $actorId = null): array
    {
        if ($projectId === null) {
            $deploymentId = DB::table('formaku_agency_package_head')->where('id', 1)->value('deployment_id');
            $pageIds = $deploymentId ? array_values(AgencyDeployment::findOrFail($deploymentId)->page_ids) : [];
            $scope = 'installed';
            $scopeVersion = $deploymentId;
        } else {
            $project = PortalProject::when($actorId !== null, fn ($q) => $q->where('actor_id', $actorId))->findOrFail($projectId);
            $pageIds = array_column($project->pages ?? [], 'id');
            $scope = (string) $projectId;
            $scopeVersion = $project->version;
        }
        $requests = Collection::where('key', 'requests')->first();
        $pages = Collection::where('key', 'agency_pages')->first();
        $records = $pages === null ? collect() : Record::where('collection_id', $pages->id)->whereIntegerInRaw('id', $pageIds)->with('draft')->orderBy('id')->get();
        $checks = [];
        $check = static function (string $key, string $label, bool $passed, string $failure) use (&$checks): void {
            $checks[] = ['key' => $key, 'label' => $label, 'status' => $passed ? 'pass' : 'fail', 'detail' => $passed ? '' : $failure];
        };
        $check('routes', 'Strona i portal', (bool) config('formaku.public_routes'), 'Włącz publiczne trasy CMS.');
        $configured = $requests ? array_column(PortalFields::configured($requests), 'type', 'key') : [];
        $check('fields', 'Usługa i preferowany termin', in_array($configured['service'] ?? null, ['select', 'text'], true) && ($configured['desired_date'] ?? null) === 'date', 'Udostępnij pola service i desired_date w formularzu klienta.');
        $check('workflow', 'Przekazanie sprawy handlowcowi', ! empty($requests?->definition['transitions']), 'Ustaw co najmniej jedno przejście między stanami.');
        $private = false;
        if ($requests && ($requests->definition['portal_reply'] ?? false)) {
            try {
                PortalReplies::validate($requests->key, $requests->kind, $requests->definition);
                $private = true;
            } catch (ValidationException) {
            }
        }
        $check('reply', 'Odpowiedź klienta i notatka wewnętrzna', $private, 'Włącz udostępnianie zapisanej odpowiedzi i prywatną notatkę.');
        $check('pages', 'Strony projektu', $pageIds !== [] && count($pageIds) === $records->count() && $pages && PublicPages::allowsCollection($pages), 'Zastosuj propozycję i sprawdź kolekcję stron.');
        $revisions = [];
        foreach ($records as $record) {
            $revisions[] = [$record->id, $record->version, $record->draft_id, $record->published_id];
            $path = '/'.trim(config('formaku.prefix'), '/').'/api/v1/records/'.$record->id.'/preview';
            $diagnostic = $this->pageCheck($pages, $record);
            $checks[] = $diagnostic + ['key' => 'page_'.$record->id, 'label' => 'Strona #'.$record->id, 'path' => $path];
        }

        $fingerprint = hash_hmac('sha256', json_encode([
            'b2b-rfq-v1', config('database.default'), config('database.connections.'.config('database.default').'.database'), $scope, $scopeVersion,
            $requests?->definition, $pages?->definition, $revisions, app(SiteNavigation::class)->current()['version'],
            is_file(public_path('vendor/formaku/app.js')) ? hash_file('sha256', public_path('vendor/formaku/app.js')) : null,
            is_file(public_path('vendor/formaku/main.css')) ? hash_file('sha256', public_path('vendor/formaku/main.css')) : null,
            hash_file('sha256', app('view')->getFinder()->find('formaku::portal')),
            hash_file('sha256', app('view')->getFinder()->find('formaku::request')),
            is_file(base_path('../../tests/acceptance/b2b-portal.mjs')) ? hash_file('sha256', base_path('../../tests/acceptance/b2b-portal.mjs')) : null,
        ], JSON_THROW_ON_ERROR), (string) config('app.key'));
        $receipt = $this->receipt($scope);
        $status = $receipt['status'] ?? 'not_run';
        $expired = in_array($status, ['queued', 'running'], true) && strtotime($receipt['checked_at']) < time() - 300;
        if ($expired || (isset($receipt['fingerprint']) && $receipt['fingerprint'] !== $fingerprint)) {
            $status = 'stale';
        }
        $staticPass = ! in_array('fail', array_column($checks, 'status'), true);

        return ['passed' => $staticPass && $status === 'passed', 'static_passed' => $staticPass, 'checks' => $checks, 'fingerprint' => $fingerprint,
            'browser' => ['status' => $status, 'available' => $this->available(), 'checked_at' => $receipt['checked_at'] ?? null,
                'detail' => $receipt['detail'] ?? '', 'command' => 'php artisan formaku:check-portal '.($projectId ?? '--installed')]];
    }

    /** Validate the saved revision using the same block renderer as public pages. */
    private function pageCheck(Collection $pages, Record $record): array
    {
        if (! $record->draft || (int) $record->draft->record_id !== (int) $record->id) {
            return ['status' => 'fail', 'detail' => 'Brakuje zapisanego szkicu.'];
        }
        $data = PublicPages::data($pages->definition, $record->draft->data);
        try {
            $render = app(Renderer::class)->page($pages->definition, $data, $data['title'] ?? null, $data['slug'] ?? '');
        } catch (ValidationException|HttpExceptionInterface) {
            return ['status' => 'fail', 'detail' => 'Nie można wyrenderować zapisanej strony. Sprawdź bloki w edytorze.'];
        }
        $doc = HTMLDocument::createFromString('<!doctype html><html><body>'.$render['html'].'</body></html>', LIBXML_NOERROR, 'UTF-8');
        $issues = [];
        $portalLink = false;
        foreach ($doc->querySelectorAll('a') as $link) {
            $target = trim($link->getAttribute('href') ?? '');
            $label = trim($link->getAttribute('aria-label') ?? '') ?: trim($link->textContent);
            foreach ($link->querySelectorAll('img[alt]') as $image) {
                $label .= trim($image->getAttribute('alt') ?? '');
            }
            if ($label === '' || $target === '' || $target === '#') {
                $issues[] = 'Uzupełnij opis lub adres odnośnika.';
            }
            if ($target === '/portal' && $label !== '') {
                $portalLink = true;
            }
        }
        if (! $portalLink) {
            return ['status' => 'fail', 'detail' => 'Dodaj opisany odnośnik prowadzący do /portal w treści strony.'];
        }
        if (! $render['description']) {
            $issues[] = 'Uzupełnij opis SEO.';
        }
        $translation = DB::table('formaku_record_translations')->where('record_id', $record->id)->first();
        if ($translation?->group_key !== null && ! DB::table('formaku_record_translations')
            ->where('collection_id', $record->collection_id)->where('group_key', $translation->group_key)
            ->where('record_id', '<>', $record->id)
            ->whereIn('record_id', PublicPages::publishedRecords()->where('collection_id', $record->collection_id)->select('id'))
            ->exists()) {
            $issues[] = 'Sprawdź publikację powiązanej wersji językowej.';
        }
        if ($doc->querySelector('img:not([alt])')) {
            $issues[] = 'Sprawdź opis alternatywny obrazu.';
        }
        $previous = 0;
        foreach ($doc->querySelectorAll('h1,h2,h3,h4,h5,h6') as $heading) {
            $level = (int) substr($heading->localName, 1);
            if ($level > $previous + 1 || trim($heading->textContent) === '') {
                $issues[] = 'Sprawdź tekst i kolejność nagłówków.';
            }
            $previous = $level;
        }

        return ['status' => $issues ? 'warning' : 'pass', 'detail' => implode(' ', array_unique($issues))];
    }

    public function receiptPath(string $scope): string
    {
        return storage_path('app/private/formaku-checks/'.hash_hmac('sha256', config('database.connections.'.config('database.default').'.database').'|'.$scope, (string) config('app.key')).'.json');
    }

    private function receipt(string $scope): array
    {
        $path = $this->receiptPath($scope);

        return is_file($path) ? (json_decode((string) file_get_contents($path), true) ?: []) : [];
    }

    public function record(string $scope, string $fingerprint, string $status, string $detail = ''): void
    {
        $path = $this->receiptPath($scope);
        $files = app('files');
        $files->ensureDirectoryExists(dirname($path), 0700);
        $files->replace($path, json_encode(['fingerprint' => $fingerprint, 'status' => $status, 'detail' => $detail, 'checked_at' => now()->toISOString()], JSON_THROW_ON_ERROR), 0600);
    }
}
