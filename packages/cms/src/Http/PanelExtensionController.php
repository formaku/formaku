<?php

namespace Formaku\Cms\Http;

use Formaku\Cms\Services\Registry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use ReflectionException;
use ReflectionProperty;

class PanelExtensionController
{
    private const PAGE_SIZE = 25;

    protected string $afterQueryCallbacksProperty = 'afterQueryCallbacks';

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

        $input = $this->validateActionInput($request, $panel['fields']);

        return ['result' => $registry->actions()[$key]->execute($user, $input)];
    }

    public function rowAction(Request $request, Registry $registry, string $key, string $action): array
    {
        $user = $request->user();
        $resolved = $registry->panelRowAction($key, $action, $user);
        abort_if($resolved === null, 404);

        $row = $request->input('row');
        if ((! is_int($row) && ! is_string($row)) || (is_string($row) && (trim($row) === '' || mb_strlen($row) > 255))) {
            throw ValidationException::withMessages(['row' => 'Invalid row identifier.']);
        }

        $inputKey = $resolved['input_key'];
        $input = $this->validateActionInput($request, $resolved['action']['fields'], $inputKey);

        $builder = (clone $registry->resources()[$key]->query($user))->applyScopes();
        $query = $builder->getQuery();
        $query->applyBeforeQueryCallbacks();
        abort_if(
            $query->groupLimit !== null
            || $query->getLimit() !== null
            || $query->getOffset() !== null
            || $this->hasAfterQueryCallbacks($builder, $query),
            422,
            'Row actions cannot run on bounded or post-filtered resources.',
        );

        $rowKey = $resolved['row_key'];
        $column = str_contains($rowKey, '.') ? $rowKey : $builder->qualifyColumn($rowKey);
        $attributeKey = str_contains($rowKey, '.') ? substr($rowKey, strrpos($rowKey, '.') + 1) : $rowKey;

        if ($resolved['input_type'] === 'integer') {
            if (is_int($row)) {
                $lookup = $row;
            } elseif (is_string($row) && preg_match('/\A-?\d+\z/', $row) === 1) {
                $parsed = filter_var($row, FILTER_VALIDATE_INT);
                abort_if($parsed === false, 404);
                $lookup = $parsed;
            } else {
                abort(404);
            }
        } else {
            $lookup = (string) $row;
        }

        $models = $this->fetchRowModels($builder, $column, $lookup);
        abort_if($models === [], 404);
        abort_if(count($models) > 1, 422, 'Row identifier matched multiple rows.');
        abort_unless($models[0] instanceof Model, 422, 'Panel resources must return a collection of models.');

        $rawValue = $models[0]->getAttributes()[$attributeKey] ?? null;
        if (! is_scalar($rawValue) || is_bool($rawValue)) {
            abort(422, 'Row identifier must be a scalar value.');
        }

        if ($resolved['input_type'] === 'integer') {
            $intValue = is_int($rawValue)
                ? $rawValue
                : (is_string($rawValue) && preg_match('/\A-?\d+\z/', $rawValue) === 1 ? filter_var($rawValue, FILTER_VALIDATE_INT) : false);
            abort_if($intValue === false, 422, 'Row identifier must be an integer.');
            abort_if($intValue !== $lookup, 404);
            $input[$inputKey] = $intValue;
        } else {
            abort_if((string) $rawValue !== $lookup, 404);
            $input[$inputKey] = (string) $rawValue;
        }

        return ['result' => $registry->actions()[$action]->execute($user, $input)];
    }

    /** @return list<Model> */
    private function fetchRowModels(Builder $builder, string $column, int|string $lookup): array
    {
        $connection = $builder->getConnection();
        $run = static fn (): array => (clone $builder)->where($column, '=', $lookup)->limit(2)->getModels();

        try {
            return $connection->transactionLevel() > 0 ? $connection->transaction($run) : $run();
        } catch (QueryException $exception) {
            if ($this->isInvalidLookupException($exception)) {
                abort(404);
            }

            throw $exception;
        }
    }

    private function isInvalidLookupException(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);

        return in_array($sqlState, ['22P02', '22003', '22007'], true)
            || ($sqlState === 'HY000' && in_array($driverCode, [1292, 1366], true));
    }

    /** @param list<array{key: string, label: string, type: string, required: bool}> $fields */
    private function validateActionInput(Request $request, array $fields, ?string $skipRequiredKey = null): array
    {
        $keys = array_column($fields, 'key');
        $rules = ['input' => ['present', $keys === [] ? 'array' : 'array:'.implode(',', $keys)]];
        if ($keys === []) {
            $rules['input'][] = 'max:0';
        }
        foreach ($fields as $field) {
            $required = $field['required'] && $field['key'] !== $skipRequiredKey;
            $rules['input.'.$field['key']] = [
                $required ? 'required' : 'nullable',
                ...match ($field['type']) {
                    'text' => ['string', 'max:2000'],
                    'integer' => ['integer:strict'],
                    'boolean' => ['boolean:strict'],
                },
            ];
        }
        $validated = $request->validate($rules);

        return array_filter($validated['input'] ?? [], static fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function hasAfterQueryCallbacks(Builder $builder, QueryBuilder $query): bool
    {
        foreach ([$builder, $query] as $target) {
            if (! property_exists($target, $this->afterQueryCallbacksProperty)) {
                return true;
            }
            try {
                $callbacks = (new ReflectionProperty($target, $this->afterQueryCallbacksProperty))->getValue($target);
            } catch (ReflectionException) {
                return true;
            }
            if (! is_array($callbacks) || $callbacks !== []) {
                return true;
            }
        }

        return false;
    }
}
