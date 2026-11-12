# Extend Formaku in Laravel

[Po polsku](README.pl.md)

These examples use the Laravel 13 starter. The panel integration requires Formaku alpha.32 or later. Use a local installation with a disposable database. For a standalone host application, first configure the host guard and Formaku authorization as described in [Package integration](../../README.md#package-integration).

## Install in an existing Laravel app

On Laravel 13 with PHP 8.4 or newer, add the [Formaku Composer repository](https://formaku.eu/composer/packages.json) and install the release.

```sh
composer config repositories.formaku composer https://formaku.eu/composer
composer require 'formaku/cms:0.1.0-alpha.54' --prefer-dist
php artisan formaku:install
php artisan migrate
```

In `config/formaku.php`, set `guard` to the session guard your app uses for login. Set `authorize` to a callable that accepts the signed-in user and an ability string and returns a boolean. For example, if your app defines a public static `allows` method on `App\Support\FormakuAccess`, configure it as follows.

```php
'guard' => 'web',
'authorize' => [\App\Support\FormakuAccess::class, 'allows'],
```

Use your app's permissions in the method. Grant `work` to staff who may enter the panel, `build` to staff who may manage models, and `publish` to staff who may publish. Use a route named `login` for Laravel's default guest redirect, or set `redirectGuestsTo` in `bootstrap/app.php` to your existing sign-in page. Sign in through the host app, then open `/formaku`. For an app with login already configured, use the Formaku starter. Leave Formaku's public routes disabled until you want to publish through them.

### Check the Formaku installation

Run the read-only check from the Laravel application directory after installation or an upgrade.

```sh
php artisan formaku:doctor
```

The command checks the configured guard and authorization callback, Formaku’s migration history, and whether the published `app.js` and `main.css` match the installed package. It also prints the state of public routes. An installation check that fails produces a nonzero exit code.

If the frontend files are missing from a source checkout, build them from the Formaku repository root. If the published files are missing or outdated, run `php artisan formaku:install` again. For pending migrations, follow the [upgrade guide](../DEPLOYMENT.md#upgrade-an-existing-alpha-installation). To verify access for a particular role, sign in to the host app and open the panel as a user with that role.

### Upgrade an existing Composer installation

After updating to alpha.54, run these commands from the host Laravel app before serving traffic.

```sh
php artisan formaku:install
php artisan migrate --force
```

Starting with alpha.31, `formaku:install` removes cached copies of the package Blade templates. Compiled host views stay in place. Run `php artisan view:cache` afterward if your deployment precompiles views. If you are installing alpha.30, run `php artisan view:clear` after the update.

### Enable scheduled publication in a Composer host

Add the following to the host application’s `routes/console.php` after installing Formaku.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('formaku:publish-scheduled')->everyMinute()->withoutOverlapping();
```

Run `php artisan schedule:list` to check the registration, then run Laravel’s scheduler in your environment. The command publishes saved content whose publication time has passed. If your host already invokes a custom Formaku publication callback, replace that registration with this command. The Formaku starter registers it for you.

Run `php artisan formaku:publish-scheduled` manually to see the counts. A failed draft stays scheduled, and the command returns a nonzero exit code after processing the rest. Use the record ID in the output and the application log to diagnose the failure. Fix the draft and set a new publication time, or cancel the schedule. Concurrent version changes are skipped.

### Install from a local ZIP

On Laravel 13 with PHP 8.4 or newer, download `formaku-cms-v0.1.0-alpha.54.zip` and its `.sha256` file from [GitHub release assets](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.54). Put both files in a `formaku-packages` directory at the application root. Verify the checksum, then register the directory as a [Composer artifact repository](https://getcomposer.org/doc/05-repositories.md#artifact).

```sh
(cd formaku-packages && sha256sum -c formaku-cms-v0.1.0-alpha.54.zip.sha256)
composer config repositories.formaku-artifacts artifact ./formaku-packages
composer require 'formaku/cms:0.1.0-alpha.54' --prefer-dist
php artisan formaku:install
php artisan migrate
```

On macOS, use `shasum -a 256 -c` in the checksum command. Keep the ZIP available at the same relative path in environments where you run `composer install`. The archive includes the compiled panel assets, fonts and their license notices.


## Provide a role catalog to the model editor

Set `roles` in the host's `config/formaku.php` to an array of role keys and display labels.

```php
'roles' => [
    'case_manager' => 'Case manager',
    'reviewer' => 'Reviewer',
],
```

Use the same keys that your `role` resolver returns for users. When the resolver is absent, Formaku reads the user's `role` property. The catalog supplies suggestions in the model editor for users with `build` access. Your Laravel application assigns roles, and its Formaku authorization callback and collection or field rules govern access. The editor also accepts role keys that are absent from the catalog.

## Configure field permissions

A field definition can include `access.read` and `access.write`, each a list of role keys from the host’s Formaku role resolver. Leave a list absent to inherit collection access. An empty list restricts the operation to users with the `build` ability. Write access also requires read access to the field and permission to edit the record. Use `private: true` for fields with a read list. The address field `slug` has no read list.

A model update preserves existing field rules if a client omits `access`. To clear a field's rules, send `access: {}` explicitly. The current model controls access to historical revisions as well as current data. For a collection with field rules, a direct call to `RecordService::save()` must pass the authenticated user as `actorUser` and the matching actor ID.

Before rolling back to a version that predates field permissions, restrict each affected collection to users allowed to see all its fields. Verify collection access before serving traffic with the earlier version.

## Build the CMS stylesheet

Run `npm run build` from the Formaku repository root. The bundled stylesheet scans the CMS admin components, Blade views, and server-side renderer sources declared in `packages/cms/resources/admin/style.css`. For a host extension, compile the extension's styles with the host app when it needs utilities outside the bundled set. Run `bash tests/css-sources.sh` to check that unrelated files leave the CSS unchanged.

## Search collection records

With an authenticated panel session, send `GET /formaku/api/v1/collections/{collection}/records?label=Example` to search the latest saved title or name across the collection. The endpoint checks collection access and field read permissions. Use up to 200 characters and URL-encode the value. Characters such as `%` and `_` are matched literally. Case and accent matching depend on the database.

The `label` filter combines with `state` and, for content collections, `scheduled=1`. Follow the response’s pagination links to keep the filters. The existing `search` parameter continues to search published titles. In content collections, add `publication=unpublished`, `publication=published` or `publication=changed` to filter by the publication status. `published` includes records with newer drafts. `changed` selects only published records with a different saved draft.

## Preview a saved revision

In an authenticated panel session, use `GET /formaku/api/v1/records/{record}/revisions/{revision}/preview` for a saved revision of a content record. The response is HTML with `Cache-Control: no-store` and `X-Robots-Tag: noindex`. It uses the current collection definition and field read permissions. The request preserves the draft, published revision and schedule.

## Withdraw a publication through the API

Send `DELETE /formaku/api/v1/records/{record}/publish` with the record’s current `version` in the JSON body. Use an authenticated web session with the CSRF token, publish permission and write access to the record. A stale version returns `409`.

Withdrawing a published content record clears its public revision and scheduled publication in one operation and preserves its saved draft and revision history. A record that is already unpublished keeps its current version and any scheduled first publication.

## Configure site branding through the API

The versioned site navigation settings accept `brandName` (up to 80 characters) and `brandLogoId` (an ID of a public JPEG, PNG or WebP file). Include the current `version` when saving. Omitted branding keys keep their saved values. Send `null` to clear a setting. Branding uses the existing site settings table and does not require a new migration.

## Configure the customer request form

For the `requests` data collection, set `definition.portal_fields` to an array of additional field keys. For example, `"portal_fields": ["preferred_date", "contact_method"]` includes those two fields if they exist in `definition.fields` with **Private** cleared. Supported types are `text`, `number`, `date`, `boolean` and `select`. Supply distinct nonempty string options for select fields. Use plain values with commas reserved as separators. The list is empty by default and the portal always includes its existing `title` and `description` inputs.

The form includes additional fields the signed-in customer can write. Request detail displays saved values the owner can read. Use the same collection and field rules as for other Formaku records.

### Enable request attachments

Set `definition.portal_attachments` to `true` on the `requests` data collection. A request can contain up to three PDF, JPEG or PNG files of up to 5 MiB each. Files use the Laravel `local` disk. Keep that disk private and include it in the same backup schedule as the database. For a Composer host, configure PHP with `upload_max_filesize` of at least `5M`, `post_max_size` of at least `20M` and `max_file_uploads` of at least `4`. Check matching request limits at the reverse proxy. The Docker image configures PHP for these uploads.

Apply the request attachments migration introduced in alpha.37 if it is pending. For a code rollback to alpha.36, retain the attachment table and files to make them available again after re-upgrading.

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

## Show Laravel resources and actions in the panel

Starting with alpha.32, use the optional panel configuration in `Registry::resource()` and `Registry::action()`. The [example provider](examples/app/Providers/FormakuExamplesServiceProvider.php) registers **My profile** and **Preview notice**. Install the example provider, sign in with permission to use Formaku, and open **Extensions**. You can view your own profile and submit a notice to get a preview. The panel uses Formaku's existing session routes.

For a resource, provide a label, a list of columns with `key` and `label`, and an `authorize` callback. The adapter receives the signed-in user. Scope and order its query in the host application, and select the raw attributes or SQL aliases the panel displays. The panel shows scalar values and empty cells, with up to 25 rows per page. Apply row authorization in SQL. Post-query filtering can leave a page with fewer rows. Use a resource query without Laravel’s per-group limit.

For an action, provide a label, a list of fields with `key`, `label`, `type`, and optional `required`, and an `authorize` callback. Supported types are `text`, `integer`, and `boolean`. The handler receives the signed-in user and validated inputs. Add business validation and record-level authorization inside the handler. Return only data the caller may see. The panel renders the returned data as text.

The callback runs for discovery and for requests to the extension. Only extensions with explicit panel configuration can be accessed from the panel. Existing registrations and host routes keep working.

## Call a resource and an action from the host

The registry stores [OwnProfileResource](examples/app/Formaku/OwnProfileResource.php) and [PreviewNoticeAction](examples/app/Formaku/PreviewNoticeAction.php). The optional host routes expose the same examples outside the panel. Copy [the example routes](examples/routes/formaku-extension-examples.php) into the host's `routes/` directory and add the following line to the end of `routes/web.php`.

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

The test covers draft creation, preview, publication, HTML escaping, and extension authentication, resource scope, and action validation in both the panel API and host routes.
