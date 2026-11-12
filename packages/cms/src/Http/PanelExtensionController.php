<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Services\Registry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class PanelExtensionController
{
    private const PAGE_SIZE = 25;

    public function resource(Request $request, Registry $registry, string $key): array
    {
        $user = $request->user();
        $panel = $registry->panelResource($key, $user);
        abort_if($panel === null, 404);

        $validated = $request->validate([
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:'.intdiv(PHP_INT_MAX, self::PAGE_SIZE)],
        ]);
        $page = (int) ($validated['page'] ?? 1);
        $builder = (clone $registry->resources()[$key]->query($user))->applyScopes();
        $query = $builder->getQuery();
        $query->applyBeforeQueryCallbacks();
        abort_if($query->groupLimit !== null, 422, 'Panel resources cannot paginate a per-group limit.');

        $skip = ($page - 1) * self::PAGE_SIZE;
        $offset = $query->getOffset() ?? 0;
        $limit = $query->getLimit();
        if ($skip > PHP_INT_MAX - $offset || ($limit !== null && $skip >= $limit)) {
            return ['data' => [], 'current_page' => $page, 'has_more' => false];
        }

        // Read one extra row for pagination without widening the adapter's original result window.
        $take = $limit === null ? self::PAGE_SIZE + 1 : min(self::PAGE_SIZE + 1, $limit - $skip);
        $query->offset($offset + $skip)->limit($take);
        $models = $builder->getModels();
        $rows = $builder->applyAfterQueryCallbacks($builder->getModel()->newCollection(array_slice($models, 0, self::PAGE_SIZE)));
        abort_unless($rows instanceof Collection, 422, 'Panel resources must return a collection of models.');

        return [
            'data' => $rows->take(self::PAGE_SIZE)->values()->map(function (mixed $row) use ($panel): array {
                abort_unless($row instanceof Model, 422, 'Panel resources must return a collection of models.');
                $attributes = $row->getAttributes();
                $values = [];
                foreach ($panel['columns'] as $column) {
                    $key = $column['key'];
                    $value = $attributes[$key] ?? null;
                    $values[$key] = is_scalar($value) ? $value : null;
                }

                return $values;
            })->all(),
            'current_page' => $page,
            'has_more' => count($models) > self::PAGE_SIZE,
        ];
    }

    public function action(Request $request, Registry $registry, string $key): array
    {
        $user = $request->user();
        $panel = $registry->panelAction($key, $user);
        abort_if($panel === null, 404);

        $fields = $panel['fields'];
        $keys = array_column($fields, 'key');
        $rules = ['input' => ['present', $keys === [] ? 'array' : 'array:'.implode(',', $keys)]];
        if ($keys === []) {
            $rules['input'][] = 'max:0';
        }
        foreach ($fields as $field) {
            $rules['input.'.$field['key']] = [
                $field['required'] ? 'required' : 'nullable',
                ...match ($field['type']) {
                    'text' => ['string', 'max:2000'],
                    'integer' => ['integer:strict'],
                    'boolean' => ['boolean:strict'],
                },
            ];
        }
        $validated = $request->validate($rules);
        $input = array_filter($validated['input'] ?? [], static fn (mixed $value): bool => $value !== null && $value !== '');

        return ['result' => $registry->actions()[$key]->execute($user, $input)];
    }
}
