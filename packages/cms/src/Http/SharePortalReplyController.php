<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\PortalReplies;
use Illuminate\Http\Request;

class SharePortalReplyController
{
    public function __invoke(Request $request, Record $record, PortalReplies $replies): array
    {
        $input = $request->validate(['version' => 'required|integer|min:0']);

        return ['version' => $replies->share($record, $request->user(), (int) $input['version'])];
    }
}
