<?php

namespace Formaku\Cms\Http;

use Closure;
use Formaku\Cms\Models\PortalProject;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PortalProjectOwner
{
    public function handle(Request $request, Closure $next): Response
    {
        $project = $request->route('project');
        abort_unless($project instanceof PortalProject && (string) $project->actor_id === (string) $request->user()->getAuthIdentifier(), 404);

        return $next($request);
    }
}
