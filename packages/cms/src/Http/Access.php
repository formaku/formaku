<?php

namespace Formaku\Cms\Http;

use Closure;
use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\CollectionAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class Access
{
    public function handle(Request $r, Closure $next, string $ability = 'work')
    {
        Auth::shouldUse(config('formaku.guard'));
        $user = auth(config('formaku.guard'))->user();
        abort_unless($user, 401);
        $authorize = config('formaku.authorize');
        abort_unless(is_callable($authorize) && $authorize($user, $ability), 403);

        $policy = app(CollectionAccess::class);
        $record = $r->route('record');
        $collection = $r->route('collection');
        if ($record instanceof Record) {
            $collection = Collection::findOrFail($record->collection_id);
        }
        if ($collection instanceof Collection) {
            $policy->check($collection, $user, ! $r->isMethod('GET'), $record instanceof Record ? $record : null);
            if ($r->isMethod('POST') && ! $record && ! $r->input('id')) {
                abort_if($policy->rule($collection, $user)['scope'] === 'assigned', 403);
            }
            if ($r->input('id')) {
                $target = Record::where('collection_id', $collection->id)->findOrFail($r->input('id'));
                $policy->check($collection, $user, true, $target);
            }
        }

        return $next($r);
    }
}
