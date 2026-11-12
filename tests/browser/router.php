<?php

use Formaku\Cms\Contracts\PanelBlockType;
use Formaku\Cms\Fields\Field;
use Formaku\Cms\Services\Registry;
use Illuminate\Http\Request;

$starter = dirname(__DIR__, 2).'/apps/starter';
$path = rawurldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?? '');
if ($path !== '' && is_file($starter.'/public'.$path)) {
    return false;
}

define('LARAVEL_START', microtime(true));

if (is_file($maintenance = $starter.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

require $starter.'/vendor/autoload.php';
$app = require $starter.'/bootstrap/app.php';

$app->booted(function () use ($app): void {
    if (! $app->environment('testing')
        || config('database.default') !== 'sqlite'
        || ! str_ends_with((string) config('database.connections.sqlite.database'), 'formaku-e2e.sqlite')) {
        return;
    }

    $registry = $app->make(Registry::class);
    $registry->field(new Field('qa_price_cents', 'Testowa cena w groszach', 'number', ['integer', 'min:0']));
    $registry->block(new class implements PanelBlockType
    {
        public function key(): string
        {
            return 'qa_container';
        }

        public function label(): string
        {
            return 'Testowy układ rozszerzenia';
        }

        public function schema(): array
        {
            return [];
        }

        public function panelSchema(): array
        {
            return [
                ['key' => 'count', 'label' => 'Testowy licznik', 'input' => 'number'],
                ['key' => 'enabled', 'label' => 'Testowe włączenie', 'input' => 'boolean'],
            ];
        }

        public function acceptsChildren(): bool
        {
            return true;
        }

        public function render(array $props, string $children = ''): string
        {
            $count = e($props['count'] ?? '');
            $enabled = ($props['enabled'] ?? null) === false ? 'false' : '';

            return '<section data-qa-count="'.$count.'" data-qa-enabled="'.$enabled.'">'.$children.'</section>';
        }
    });
});

$app->handleRequest(Request::capture());
