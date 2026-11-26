<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Models\AgencyDeployment;
use Formaku\Cms\Models\PortalProject;
use Formaku\Cms\Services\AgencyPackage;
use Formaku\Cms\Services\AgencyPackageInstaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AgencyPackageController
{
    public function index(): array
    {
        $active = DB::table('formaku_agency_package_head')->where('id', 1)->value('deployment_id');

        return ['deployments' => AgencyDeployment::latest('id')->limit(25)->get(['id', 'revision', 'previous_id', 'rolled_back_at'])->map(fn ($row) => [
            'id' => $row->id, 'revision' => $row->revision, 'active' => $row->id === (int) $active,
            'can_rollback' => $row->id === (int) $active && $row->previous_id !== null,
        ])->all()];
    }

    public function export(Request $request, AgencyPackage $packages): array
    {
        $data = $request->validate(['proposal_id' => 'required|integer|min:1', 'revision' => 'required|integer|min:1|max:2147483647', 'include_field_text' => 'sometimes|boolean']);
        $project = PortalProject::where('actor_id', $request->user()->getAuthIdentifier())->findOrFail($data['proposal_id']);
        abort_unless(in_array($project->state, ['applied', 'published'], true), 409, 'Apply and review the project before exporting its structure.');

        return $packages->export($project, (int) $data['revision'], (bool) ($data['include_field_text'] ?? false));
    }

    public function preview(Request $request, AgencyPackageInstaller $installer): array
    {
        $data = $this->inputs($request);

        return $installer->preview($data['package'], $data['target'] ?? []);
    }

    public function apply(Request $request, AgencyPackageInstaller $installer): array
    {
        $data = $this->inputs($request);
        $token = $request->validate(['token' => 'required|string|size:64'])['token'];

        return $installer->apply($data['package'], $data['target'] ?? [], $token, $request->user());
    }

    public function rollback(int $deployment, Request $request, AgencyPackageInstaller $installer): array
    {
        return $installer->rollback($deployment, $request->user());
    }

    private function inputs(Request $request): array
    {
        return $request->validate([
            'package' => 'required|array', 'target' => 'sometimes|array:name,body',
            'target.name' => 'sometimes|required|string|max:100', 'target.body' => 'sometimes|required|string|max:2000',
        ]);
    }
}
