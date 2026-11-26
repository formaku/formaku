<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Jobs\RunPortalAcceptance;
use Formaku\Cms\Services\PortalAcceptance;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PortalCheckController
{
    public function __invoke(Request $request, PortalAcceptance $checks): JsonResponse
    {
        $input = $request->validate(['project_id' => 'required_without:installed|prohibits:installed|integer|min:1', 'installed' => 'sometimes|accepted']);
        $id = isset($input['project_id']) ? (int) $input['project_id'] : null;
        $report = $checks->report($id, $request->user()->getAuthIdentifier());
        if ($request->isMethod('POST')) {
            abort_unless($report['browser']['available'] && $report['static_passed'], 422, 'A configured disposable acceptance installation and passing configuration checks are required.');
            if (! in_array($report['browser']['status'], ['queued', 'running'], true)) {
                $scope = $id === null ? 'installed' : (string) $id;
                $checks->record($scope, $report['fingerprint'], 'queued', 'Oczekiwanie na pracownika kolejki.');
                try {
                    RunPortalAcceptance::dispatch($id, $report['fingerprint']);
                } catch (\Throwable $exception) {
                    $checks->record($scope, $report['fingerprint'], 'failed', 'Nie udało się zlecić testu kolejce.');
                    throw $exception;
                }
            }
            $report = $checks->report($id, $request->user()->getAuthIdentifier());
        }

        return response()->json($report)->header('Cache-Control', 'no-store');
    }
}
