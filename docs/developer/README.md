# Extend Formaku in Laravel

[Po polsku](README.pl.md)

These examples target Formaku v0.1.0-alpha.14 and its Laravel 13 starter. Use a local installation with a disposable database. For a standalone host application, first configure the host guard and Formaku authorization as described in [Package integration](../../README.md#package-integration).

## Install in an existing Laravel app

On Laravel 13 with PHP 8.4 or newer, add the [Formaku Composer repository](https://formaku.eu/composer/packages.json) and install the release.

```sh
composer repo add formaku composer https://formaku.eu/composer
composer require 'formaku/cms:0.1.0-alpha.24' --prefer-dist
php artisan formaku:install
php artisan migrate
```

Configure the host guard and the `authorize` callback in `config/formaku.php` using your application's policies, then open `/formaku` as an authorized user. Leave public Formaku routes disabled until you want to publish content through them.

### Install from a local ZIP

On Laravel 13 with PHP 8.4 or newer, download `formaku-cms-v0.1.0-alpha.24.zip` and its `.sha256` file from [GitHub release assets](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.24). Put both files in a `formaku-packages` directory at the application root. Verify the checksum, then register the directory as a [Composer artifact repository](https://getcomposer.org/doc/05-repositories.md#artifact).

```sh
(cd formaku-packages && sha256sum -c formaku-cms-v0.1.0-alpha.24.zip.sha256)
composer repo add formaku-artifacts artifact ./formaku-packages
composer require 'formaku/cms:0.1.0-alpha.24' --prefer-dist
php artisan formaku:install
php artisan migrate
```

On macOS, use `shasum -a 256 -c` in the checksum command. Keep the ZIP available at the same relative path in environments where you run `composer install`. The archive includes the compiled panel assets, fonts and their license notices.


## Register the extensions

1. Copy the PHP files under [examples/app](examples/app) into the corresponding paths under your host application's `app/` directory.
2. Add `App\Providers\FormakuExamplesServiceProvider::class` to the array returned by the host application's `bootstrap/providers.php`.
3. The [example provider](examples/app/Providers/FormakuExamplesServiceProvider.php) registers the extensions in `boot(Registry $registry)`. Formaku binds the shared registry during its provider's `register()`. Choose a project-specific prefix for every key. Registering a key again replaces the previous entry in that registry map.

## Publish a page with a custom block

The [notice block](examples/app/Formaku/NoticeBlock.php) validates one short text property and escapes it before returning HTML. Formaku calls the registered block's `render()` while validating a publication and while rendering public pages and draft previews. Keep rendering free of writes and external calls. The example calls its own `schema()` to validate properties. Draft saving validates the collection fields, and the built-in block editor defines its own forms.

Use a public `content` collection with text fields `title` and `slug` and a `richtext` field named `blocks`. Using an authenticated Formaku session with write access to the collection, send the following JSON to `POST /formaku/api/v1/collections/{collection}/records`. The routes use the configured Formaku prefix and Laravel's web session and CSRF protection.

```json
{
  "version": 0,
  "data": {
    "title": "Extension example",
    "slug": "extension-example",
    "blocks": [
      {"type": "example_notice", "props": {"message": "Notes for editors"}}
    ]
  }
}
```

Use the returned record `id` to open `GET /formaku/api/v1/records/{record}/preview` with the same session. To publish, use a session with publishing permission and send the returned `version` to `POST /formaku/api/v1/records/{record}/publish`. Open `/p/extension-example` to check the rendered block. The public URL requires `formaku.public_routes` to be enabled. The starter enables it.

The editor's built-in block picker has a fixed list. Use the API or an importer you control to supply the example block. Keep the registration available while any current or historical revision still needs to render it.

## Call a resource and an action from the host

The registry stores [OwnProfileResource](examples/app/Formaku/OwnProfileResource.php) and [PreviewNoticeAction](examples/app/Formaku/PreviewNoticeAction.php). The host application provides the interface and routes for both extensions. Copy [the example routes](examples/routes/formaku-extension-examples.php) into the host's `routes/` directory and add the following line to the end of `routes/web.php`.

```php
require __DIR__.'/formaku-extension-examples.php';
```

| Host route | Behavior |
| --- | --- |
| `GET /extension-examples/profile` | Takes the actor from the authenticated request, scopes the query to that account, and returns only its ID and name. |
| `POST /extension-examples/preview-notice` | Accepts JSON with a `message` string. Returns a validated and trimmed preview. |

The host routes use Laravel's session authentication and CSRF middleware. They look up fixed registry keys and get the actor from the session. Apply the host's policies before exposing business resources or executing side effects. Keep ownership or tenancy constraints inside the query and explicitly select the fields you expose. Treat action results as plain data in client code.

`FieldType` is also stored in the registry. The collection validator and editor currently use their own built-in type lists. `AiProvider` and `PipelineDriver` are interfaces without registry registration methods. The starter uses its concrete AI and GitHub integration services.

## Verify in the starter

After copying the files and registering the provider and routes, copy [FormakuExtensionsExampleTest.php](examples/tests/Feature/FormakuExtensionsExampleTest.php) to `tests/Feature/` in the starter. Run the test with the starter's in-memory SQLite test configuration and a test application key.

```sh
APP_KEY="base64:$(openssl rand -base64 32)" php artisan test --compact --filter=FormakuExtensionsExampleTest
```

The test covers draft creation, preview, publication, HTML escaping, authentication, resource scope, and action input validation.
