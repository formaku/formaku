<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Models\Collection;
use Formaku\Cms\Models\Record;
use Formaku\Cms\Services\RecordService;
use Formaku\Cms\Services\Renderer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AssistantController
{
    public function settings(Request $request): JsonResponse
    {
        $connection = DB::table('formaku_assistants')->where('user_id', $request->user()->getAuthIdentifier())
            ->first(['collection_id', 'can_publish', 'pairing_expires_at', 'expires_at']);
        $expires = $connection?->expires_at ?? $connection?->pairing_expires_at;
        $canPublish = (bool) (config('formaku.authorize'))($request->user(), 'publish');

        return response()->json([
            'collections' => Collection::where('kind', 'content')->orderBy('name')->get()
                ->filter($this->eligible(...))->map->only(['id', 'name'])->values(),
            'can_publish' => $canPublish,
            'connection' => $connection ? [
                'collection_id' => $connection->collection_id,
                'can_publish' => $connection->can_publish && $canPublish,
                'status' => ! $expires || Carbon::parse($expires)->isPast()
                    ? 'expired'
                    : ($connection->expires_at ? 'active' : 'pending'),
                'expires_at' => $expires ? Carbon::parse($expires)->toIso8601String() : null,
            ] : null,
        ]);
    }

    public function start(Request $request): JsonResponse
    {
        $data = $request->validate(['collection_id' => 'required|integer', 'can_publish' => 'required|boolean']);
        $collection = Collection::findOrFail($data['collection_id']);
        abort_unless($this->eligible($collection), 422, 'Wybierz publiczną kolekcję treści bez pól prywatnych i relacji.');
        abort_if($data['can_publish'] && ! (config('formaku.authorize'))($request->user(), 'publish'), 403);

        $code = bin2hex(random_bytes(32));
        $expires = now()->addMinutes(5);
        $connection = [
            'user_id' => $request->user()->getAuthIdentifier(),
            'connection_id' => (string) Str::uuid(),
            'collection_id' => $collection->id,
            'can_publish' => (bool) $data['can_publish'],
            'pairing_hash' => hash('sha256', $code),
            'pairing_expires_at' => $expires,
            'token_hash' => null,
            'expires_at' => null,
        ];
        DB::table('formaku_assistants')->upsert([$connection], ['user_id'], array_keys(array_diff_key($connection, ['user_id' => true])));

        return response()->json(['code' => $code, 'expires_at' => $expires->toIso8601String()], 201)
            ->header('Cache-Control', 'no-store');
    }

    public function revoke(Request $request): JsonResponse
    {
        $user = $request->user(config('formaku.guard'));
        abort_unless($user, 401);
        DB::table('formaku_assistants')->where('user_id', $user->getAuthIdentifier())->delete();

        return response()->json(['revoked' => true]);
    }

    public function adapter(): BinaryFileResponse
    {
        return response()->download(__DIR__.'/../../resources/assistant/formaku-assistant.mjs', 'formaku-assistant.mjs', [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function exchange(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/D']]);

        return DB::transaction(function () use ($data): JsonResponse {
            $connection = DB::table('formaku_assistants')->where('pairing_hash', hash('sha256', $data['code']))
                ->where('pairing_expires_at', '>', now())->lockForUpdate()->first();
            $this->authorize($connection, true);

            $token = 'fma_'.bin2hex(random_bytes(32));
            $expires = now()->addDays(30);
            $exchanged = DB::table('formaku_assistants')->where('connection_id', $connection->connection_id)
                ->where('pairing_hash', $connection->pairing_hash)->update([
                    'pairing_hash' => null,
                    'pairing_expires_at' => null,
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => $expires,
                ]);
            abort_unless($exchanged === 1, 401);

            return response()->json(['access_token' => $token, 'expires_at' => $expires->toIso8601String()])
                ->header('Cache-Control', 'no-store');
        }, 3);
    }

    public function describe(Request $request): JsonResponse
    {
        [$connection, $collection, $canPublish] = $this->connection($request);

        return response()->json([
            'collection' => $collection->only(['id', 'key', 'name', 'version']),
            'fields' => $collection->definition['fields'],
            'can_publish' => $canPublish,
            'expires_at' => Carbon::parse($connection->expires_at)->toIso8601String(),
        ]);
    }

    public function pages(Request $request): JsonResponse
    {
        [, $collection] = $this->connection($request);
        $data = $request->validate(['page' => 'sometimes|integer|min:1|max:100000']);
        $pages = $this->records($collection)->with('draft')->orderByDesc('id')->paginate(25, page: (int) ($data['page'] ?? 1))
            ->through($this->summary(...));

        return response()->json(['items' => $pages->items(), 'next_page' => $pages->hasMorePages() ? $pages->currentPage() + 1 : null]);
    }

    public function page(Request $request, int $page): JsonResponse
    {
        [, $collection] = $this->connection($request);
        $record = $this->records($collection)->findOrFail($page);
        $keys = array_flip(array_column($collection->definition['fields'], 'key'));

        return response()->json($this->summary($record) + [
            'data' => array_intersect_key($record->draft?->data ?? [], $keys),
        ]);
    }

    public function create(Request $request, RecordService $records, Renderer $renderer): JsonResponse
    {
        return DB::transaction(function () use ($request, $records, $renderer): JsonResponse {
            [$connection, $collection] = $this->connection($request, true);
            $data = $this->draft($request, $renderer, ['request_id' => 'required|uuid']);
            $requestId = strtolower($data['request_id']);
            $fingerprint = hash('sha256', json_encode([$collection->id, $data['data']], JSON_THROW_ON_ERROR));
            $creates = DB::table('formaku_assistant_creates')->where('user_id', $connection->user_id)->where('request_id', $requestId);
            if ($previous = $creates->first()) {
                abort_unless($previous->fingerprint === $fingerprint, 409, 'Ten identyfikator został użyty dla innego szkicu.');

                return response()->json($this->summary($this->records($collection)->findOrFail($previous->record_id)));
            }

            $record = $records->save($collection, null, $data['data'], 0, $connection->user_id, $connection->connection_id);
            DB::table('formaku_assistant_creates')->insert([
                'user_id' => $connection->user_id,
                'request_id' => $requestId,
                'fingerprint' => $fingerprint,
                'record_id' => $record->id,
            ]);

            return response()->json($this->summary($record), 201);
        }, 3);
    }

    public function update(Request $request, int $page, RecordService $records, Renderer $renderer): JsonResponse
    {
        return DB::transaction(function () use ($request, $page, $records, $renderer): JsonResponse {
            [$connection, $collection] = $this->connection($request, true);
            $record = $this->records($collection)->lockForUpdate()->findOrFail($page);
            abort_if($record->publish_at !== null, 409, 'Ta strona ma zaplanowaną publikację. Zmień harmonogram w panelu przed edycją przez asystenta.');
            $data = $this->draft($request, $renderer, ['version' => 'required|integer|min:0']);
            $record = $records->save($collection, $record, $data['data'], (int) $data['version'], $connection->user_id, $connection->connection_id);

            return response()->json($this->summary($record));
        }, 3);
    }

    public function publish(Request $request, int $page, RecordService $records): JsonResponse
    {
        try {
            return DB::transaction(function () use ($request, $page, $records): JsonResponse {
                [$connection, $collection, $canPublish] = $this->connection($request, true);
                abort_unless($canPublish, 403, 'To połączenie nie ma prawa publikować.');
                $record = $this->records($collection)->findOrFail($page);
                $data = $request->validate(['version' => 'required|integer|min:1']);
                $record = $records->publish($record, (int) $data['version'], $connection->user_id, $connection->connection_id);

                return response()->json($this->summary($record));
            }, 3);
        } catch (UniqueConstraintViolationException) {
            abort(409, 'Adres strony jest już używany.');
        }
    }

    private function eligible(Collection $collection): bool
    {
        return $collection->kind === 'content' && ($collection->definition['public'] ?? false)
            && ! collect($collection->definition['fields'])->contains(fn (array $field) => ($field['private'] ?? false) || $field['type'] === 'relation');
    }

    private function records(Collection $collection): Builder
    {
        return Record::where('collection_id', $collection->id);
    }

    private function summary(Record $record): array
    {
        return [
            'id' => $record->id,
            'version' => $record->version,
            'draft_title' => $record->draft?->data['title'] ?? $record->draft?->data['name'] ?? null,
            'published_title' => $record->published_title,
            'published_slug' => $record->published_slug,
            'public_path' => config('formaku.public_routes') && $record->published_slug ? '/p/'.$record->published_slug : null,
            'has_unpublished_changes' => $record->draft_id !== $record->published_id,
        ];
    }

    private function draft(Request $request, Renderer $renderer, array $rules): array
    {
        abort_if(strlen($request->getContent()) > 262144, 413);
        $data = $request->validate($rules + ['data' => 'required|array']);
        $request->validate([
            'data.slug' => ['sometimes', 'nullable', 'string', 'max:255', 'regex:/^[a-z0-9][a-z0-9-]*$/D'],
            'data.blocks' => 'sometimes|nullable|array|list|max:100',
        ]);

        try {
            $renderer->blocks($data['data']['blocks'] ?? []);
        } catch (\TypeError|\ErrorException) {
            throw ValidationException::withMessages(['data.blocks' => 'Nieprawidłowa struktura bloków.']);
        }

        return $data;
    }

    /** @return array{object, Collection, bool} */
    private function connection(Request $request, bool $lock = false): array
    {
        $token = $request->bearerToken();
        abort_unless(is_string($token) && preg_match('/^fma_[a-f0-9]{64}$/D', $token), 401);

        return $this->authorize(DB::table('formaku_assistants')->where('token_hash', hash('sha256', $token))
            ->where('expires_at', '>', now())->when($lock, fn ($query) => $query->lockForUpdate())->first(), $lock);
    }

    /** @return array{object, Collection, bool} */
    private function authorize(?object $connection, bool $lock): array
    {
        abort_unless($connection, 401);
        $user = Auth::guard(config('formaku.guard'))->getProvider()?->retrieveById($connection->user_id);
        $authorize = config('formaku.authorize');
        abort_unless($user && is_callable($authorize) && $authorize($user, 'work') && $authorize($user, 'build'), 401);

        $collection = Collection::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($connection->collection_id);
        abort_unless($collection && $this->eligible($collection), 403, 'Kolekcja nie jest dostępna dla asystenta.');

        return [$connection, $collection, (bool) $connection->can_publish && $authorize($user, 'publish')];
    }
}
