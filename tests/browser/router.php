<?php

use Formaku\Cms\Contracts\ActionHandler;
use Formaku\Cms\Contracts\PanelBlockType;
use Formaku\Cms\Contracts\ResourceAdapter;
use Formaku\Cms\Fields\Field;
use Formaku\Cms\Services\Registry;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

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

    $rowItems = new class extends Model
    {
        public $incrementing = false;

        public $timestamps = false;

        protected $table = 'qa_row_items';

        protected $primaryKey = 'code';

        protected $keyType = 'string';
    };
    $isAdmin = static fn (object $user): bool => $user->role === 'admin';
    $registry->resource(new class($rowItems) implements ResourceAdapter
    {
        public function __construct(private Model $items) {}

        public function key(): string
        {
            return 'qa_row_items';
        }

        public function query(object $user): Builder
        {
            return $this->items->newQuery()->select(['code', 'status', 'note'])->orderBy('code');
        }
    }, [
        'label' => 'Testowe elementy',
        'columns' => [
            ['key' => 'code', 'label' => 'Kod'],
            ['key' => 'status', 'label' => 'Stan'],
            ['key' => 'note', 'label' => 'Notatka'],
        ],
        'authorize' => $isAdmin,
        'row_key' => 'code',
        'row_actions' => [
            ['action' => 'qa_row_close', 'label' => 'Zamknij element', 'input_key' => 'code'],
            ['action' => 'qa_row_reopen', 'label' => 'Otwórz ponownie', 'input_key' => 'code'],
        ],
    ]);
    $registry->action('qa_row_close', new class($rowItems) implements ActionHandler
    {
        public function __construct(private Model $items) {}

        public function execute(object $user, array $input): array
        {
            $closed = $this->items->newQuery()->whereKey($input['code'])->where('status', 'open')
                ->update(['status' => 'closed', 'note' => $input['note']]);
            if ($closed === 0) {
                throw ValidationException::withMessages(['row' => 'Element jest już zamknięty.']);
            }

            return ['code' => $input['code'], 'status' => 'closed'];
        }
    }, [
        'label' => 'Zamknij element',
        'fields' => [
            ['key' => 'code', 'label' => 'Kod', 'type' => 'text', 'required' => true],
            ['key' => 'note', 'label' => 'Powód zamknięcia', 'type' => 'text', 'required' => true],
        ],
        'authorize' => $isAdmin,
        'row_only' => true,
    ]);
    $registry->action('qa_row_reopen', new class($rowItems) implements ActionHandler
    {
        public function __construct(private Model $items) {}

        public function execute(object $user, array $input): array
        {
            $this->items->newQuery()->whereKey($input['code'])->update(['status' => 'open', 'note' => null]);

            return ['code' => $input['code'], 'status' => 'open'];
        }
    }, [
        'label' => 'Otwórz ponownie',
        'fields' => [['key' => 'code', 'label' => 'Kod', 'type' => 'text', 'required' => true]],
        'authorize' => $isAdmin,
        'row_only' => true,
    ]);
    $registry->action('qa_echo', new class implements ActionHandler
    {
        public function execute(object $user, array $input): array
        {
            return $input;
        }
    }, [
        'label' => 'Testowe echo',
        'fields' => [
            ['key' => 'message', 'label' => 'Wiadomość', 'type' => 'text', 'required' => true],
            ['key' => 'count', 'label' => 'Liczba', 'type' => 'integer'],
            ['key' => 'confirm', 'label' => 'Potwierdzenie', 'type' => 'boolean'],
        ],
        'authorize' => $isAdmin,
    ]);
});

$app->handleRequest(Request::capture());
