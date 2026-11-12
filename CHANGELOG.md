# Changelog

## Unreleased

## 0.1.0-alpha.57 — 2026-11-12

- Browse saved revisions 25 at a time. Preview or restore a revision from any page.
- The editor loads the current draft without downloading the full revision history.
- No new migrations since alpha.37.

## 0.1.0-alpha.56 — 2026-11-12

- Open a saved record from a panel link or refresh the editor to reopen the same record. Existing collection and record access rules apply.
- Open a saved content or data record in a new tab directly from the editor.
- No new migrations since alpha.37.

## 0.1.0-alpha.55 — 2026-11-12

- A draft save conflict preserves local edits and pauses automatic and manual saves.
- Download the locally edited form values as JSON, or load the current server draft after confirmation to resume saving.
- The first field edit reliably starts autosave for an existing draft.
- No new migrations since alpha.37.

## 0.1.0-alpha.54 — 2026-11-12

- Preview a saved content revision in a new tab from revision history. The preview respects current access and field permissions.
- Opening revision history preserves unsaved editor changes.
- No new migrations since alpha.37.

## 0.1.0-alpha.53 — 2026-11-12

- Withdraw a published page from the editor while keeping its saved draft and revision history.
- Withdrawing a publication also cancels its scheduled publication. The record can be published again from its saved draft.
- No new migrations since alpha.37.

## 0.1.0-alpha.52 — 2026-11-12

- Filter content by publication status, including unpublished records and published records with a newer saved draft.
- Use the same publication filter to find scheduled records. Row badges show the publication status and whether a newer draft exists.
- The release workflow tests the Composer archive in Laravel before publication. No new migrations since alpha.37.

## 0.1.0-alpha.51 — 2026-11-12

- Find content drafts and data records by a fragment of the current title or name. Search respects field permissions and can be combined with workflow state and scheduled publication.
- No new migrations since alpha.37.

## 0.1.0-alpha.50 — 2026-11-12

- Filter collection records by workflow state. The panel keeps the filter when changing pages and returning from the editor.
- Combine the state filter with scheduled publication and see the workflow state alongside the publication badge.
- No new migrations since alpha.37.

## 0.1.0-alpha.49 — 2026-11-12

- Find scheduled content drafts in the collection list. The panel shows their publication times and keeps the selected filter when returning from the editor.
- Browse additional pages of records in the panel. The scheduled filter includes overdue drafts and orders them by earliest publication time.
- New tagged releases run an installation check in the public image by digest before publication.
- No new migrations since alpha.37.

## 0.1.0-alpha.48 — 2026-11-12

- Run `php artisan formaku:doctor` to check Formaku migrations, the panel guard and authorization callback, and the published JavaScript and CSS.
- The read-only check reports disabled public routes for information and returns a nonzero exit code when an installation check fails.
- No new migrations since alpha.37.

## 0.1.0-alpha.47 — 2026-11-12

- Scheduled publishing reports how many drafts were published, skipped after a concurrent change, or failed. A publication error returns a nonzero exit code after the remaining drafts have been processed.
- No new migrations since alpha.37.

## 0.1.0-alpha.46 — 2026-11-12

- Laravel hosts can schedule the `formaku:publish-scheduled` Artisan command when they install Formaku through Composer. The starter already runs it every minute.
- The command processes due drafts in batches and keeps version checks for drafts edited or cancelled during a run.
- No new migrations since alpha.37.

## 0.1.0-alpha.45 — 2026-11-12

- The editor shows the scheduled publication time in the browser’s local time and lets publishers cancel it without changing the draft.
- Scheduling preserves the intended instant when an API client sends a date with a time-zone offset.
- No new migrations since alpha.37.

## 0.1.0-alpha.44 — 2026-11-12

- Customers can filter their own request history by state. The filter is retained when moving between pages.
- No new migrations since alpha.37.

## 0.1.0-alpha.43 — 2026-11-12

- Show one set of pagination controls in the public catalog and customer portal. Narrow screens show previous and next links, and wider screens show page numbers and the result count.
- No new migrations since alpha.37.

## 0.1.0-alpha.42 — 2026-11-12

- Compile the bundled Tailwind and daisyUI stylesheet from the CMS runtime templates and renderer. Unrelated repository files no longer affect the generated CSS.
- CI checks that adding a utility-like token outside the CMS sources leaves the generated stylesheet unchanged. No new migrations since alpha.37.

## 0.1.0-alpha.41 — 2026-11-12

- The deploy workflow summarizes the host status for the requested release UUID and image digest, including the rollback outcome and completed backup when available.
- A restricted SSH status command reads the matching deployment without acquiring the deployment lock. The workflow keeps the original deployment exit code.
- No new migrations since alpha.37.

## 0.1.0-alpha.40 — 2026-11-12

- Record the outcome of a failed host deployment and make the current status readable during deployment. The controller attempts to bring the previous image back once and waits for Compose health checks.
- Keep finalized encrypted backups and their identifiers when deployment fails. The controller rejects reuse of a release UUID with an existing backup.
- No new migrations since alpha.37.

## 0.1.0-alpha.39 — 2026-11-12

- Published Docker images carry Formaku OCI metadata, including the source repository, exact commit and release version. The workflow verifies these labels on the public AMD64 image by digest.
- Corrected the Composer upgrade guide to identify alpha.37 as the release that introduced the request attachments table. No new migrations since alpha.37.

## 0.1.0-alpha.38 — 2026-11-12

- Set a site name and choose a public image as the logo in Menu and footer settings. The shared header applies these settings to public pages and draft previews.
- Clear the name and logo to restore the default Formaku header. No new migrations since alpha.37.

## 0.1.0-alpha.37 — 2026-11-12

- Enable private attachments in the customer request model. Customers can send up to three PDF, JPEG or PNG files of up to 5 MiB each.
- Owners and staff with access to the request can download the files from its details.
- Apply the new request attachments table migration when upgrading.

## 0.1.0-alpha.36 — 2026-11-12

- Choose additional request fields in the model editor. The customer portal validates configured fields and shows saved values on the owner's request detail.
- Supports text, numbers, dates, yes/no values and select lists. Collection and field permissions apply.
- No new database migrations since alpha.30.

## 0.1.0-alpha.35 — 2026-11-12

- Choose host-configured or custom roles in the collection model. Add and remove individual collection rules and choose suggested roles for field permissions.
- New collection rules start with no access until a scope is selected. The editor distinguishes an empty restricted rule set from default collection access.
- No new database migrations since alpha.30.

## 0.1.0-alpha.34 — 2026-11-12

- Configure read and write permissions by role on individual fields. Record lists, history and previews respect field access. Saving or restoring drafts preserves protected values.
- Apply field rules in related workflows including templates, media usage, portal submissions and assistant pairing.
- Keep desktop navigation scrollable when collections exceed the viewport.
- No new database migrations since alpha.30.

## 0.1.0-alpha.33 — 2026-11-12

- Respect private title, name and block fields in public page output, catalogs and cards. Public pages use a visible name or URL slug when the title is private.
- Hide public collections whose URL slug field is private. The model editor can make a collection non-public or mark its slug public. Stored drafts and revisions are preserved.
- No new database migrations since alpha.30.

## 0.1.0-alpha.32 — 2026-11-12

- Expose selected Laravel resources and actions in the Formaku panel. The host app opts in per extension, supplies an authorization callback, and declares the visible columns or input fields.
- No new database migrations since alpha.30.

## 0.1.0-alpha.31 — 2026-11-12

- On Composer upgrades, `php artisan formaku:install` removes stale compiled Formaku Blade templates. Host application views stay cached.
- No new database migrations since alpha.30.

## 0.1.0-alpha.30 — 2026-11-12

- Link independently edited language versions of public pages. Published groups show a language switcher and reciprocal `hreflang` links.
- Apply the translation links migration before starting the updated CMS.

## 0.1.0-alpha.29 — 2026-11-12

Choose the Formaku, Blue, or Plum color accent in website settings. The accent applies to public pages and draft previews and adapts to light, dark, and system themes. This release adds no database migrations.

## 0.1.0-alpha.28 — 2026-11-12

The website theme can follow each visitor's system preference. This applies to public pages and draft previews. The Light and Dark choices still set an explicit theme. This release adds no database migrations.

## 0.1.0-alpha.27 — 2026-11-12

Draft edits made while the editor finishes a request are queued for autosave when it is ready. This includes removing a block during the refresh after publication. This release adds no database migrations.

## 0.1.0-alpha.26 — 2026-11-12

Undo the most recent block removal while editing a page draft, including after an autosave. The restored block keeps its nested content and media references. Publish the draft to apply the restoration to the public page. This release adds no database migrations.

## 0.1.0-alpha.25 — 2026-11-12

Move page blocks between the page level, containers, and columns. The editor shows valid destinations and places the moved block last in the selected list, preserving its content and nested blocks. This release adds no database migrations.

## 0.1.0-alpha.24 — 2026-11-12

Duplicate a block in the page editor, including a container or columns with their nested blocks. The copy appears after the source block and can be edited independently. Image blocks in the copy reference the same media files. This release adds no database migrations.

## 0.1.0-alpha.23 — 2026-11-12

Install `formaku/cms` in an existing Laravel 13 application from the Composer ZIP and SHA-256 checksum attached to the release. The archive includes the compiled panel and third-party license notices. Panel fonts now load relative to the published CSS, including under `/vendor/formaku/`. This release adds no database migrations.

## 0.1.0-alpha.22 — 2026-11-11

Refresh a requested image build in Application releases to see its status and open the matching GitHub Actions run for the digest. Refreshing status does not dispatch a build or deploy the image. This release adds no database migrations.

## 0.1.0-alpha.21 — 2026-11-11

The assistant adapter uses same-site public product paths for product calls to action when a compatible Commerce storefront provides them. The catalog reference remains the fallback. This CMS release adds no database migrations.

Pushing an alpha tag after successful CI on main now builds and verifies a public GHCR image and publishes a GitHub prerelease with its digest.

## 0.1.0-alpha.20 — 2026-11-11

Use the downloaded adapter with `update` and the existing Formaku endpoint to replace the local adapter while keeping the connection and token. The assistant can list published Commerce categories and filter catalog variants by category slug when the host exposes those public endpoints. This CMS release adds no database migrations.

## 0.1.0-alpha.19 — 2026-11-11

A paired assistant reads the public Commerce v2 variant catalog when the host exposes it. Each item keeps the product ID and includes a separate variant ID, optional label, price, and availability. Older installations still use the public v1 catalog. Catalog requests omit the CMS token. This CMS release adds no database migrations.

## 0.1.0-alpha.18 — 2026-11-11

A paired assistant can list and reuse public images referenced by current published revisions in its collection. Draft-only, historical, private, and out-of-collection images are excluded. Assistant draft validation and saves reject nested block lists that the renderer cannot show in full. This release adds no database migrations.

## 0.1.0-alpha.17 — 2026-11-11

Paired assistants can validate drafts made with built-in blocks before saving, return a draft preview path, and reuse page templates from the paired collection. When the host exposes the public Commerce catalog, the MCP adapter can read products without sending the CMS token. Pairing can print configuration for other stdio MCP clients. This release adds no database migrations.

## 0.1.0-alpha.16 — 2026-11-11

The CMS panel can switch between Polish and English. The selected language is remembered in the browser. Content, collection names, and custom field labels retain their original language. This release adds no database migrations.

## 0.1.0-alpha.15 — 2026-11-11

Administrators can save a draft’s blocks as a template for its collection. Editors with permission to create records can copy the blocks into a new draft, then edit and publish it through the normal workflow. A migration creates the templates table. Bilingual Laravel extension guides include runnable examples for blocks, resources, and actions.

## 0.1.0-alpha.14 — 2026-11-11

Administrators can choose a light or dark theme for public pages and draft previews in **Ustawienia → Menu i stopka**. Existing site settings default to the light theme.

## 0.1.0-alpha.13 — 2026-11-11

Administrators can configure and reorder header and footer links and set plain footer text. Saving applies the configuration to the shared site layout. The new migration preserves the default header.

## 0.1.0-alpha.12 — 2026-11-11

Public image blocks can serve 480 px and 960 px WebP variants without upscaling. The original URL remains available. Generate variants for existing public images with `php artisan formaku:media-variants`.

## 0.1.0-alpha.11 — 2026-11-11

The media library shows where a file appears in current drafts and published revisions that the user can read. An upgrade migration indexes existing references from record fields and image blocks.

## 0.1.0-alpha.10 — 2026-11-11

Media fields in record forms can select an existing file by name and visibility from the paginated library. Selecting a file keeps its current access permissions.

## 0.1.0-alpha.9 — 2026-11-11

Record forms can select related records across multiple pages. The chooser shows only records the user can read, omits private titles and names, and keeps pending selections when switching pages.

## 0.1.0-alpha.8 — 2026-11-11

Image blocks can pick public images by filename and thumbnail from a paginated library, including inside containers and columns. Existing image assignments and captions carry over.

## 0.1.0-alpha.7 — 2026-11-11

The media library lets staff edit filenames and alternative text and browse files beyond the first page. A public image's updated alternative text appears on pages that already use it.

The record list waits for refreshed data before opening another draft after a save.

## 0.1.0-alpha.6 — 2026-11-11

The page editor can add, reorder and remove nested blocks inside containers and columns. New layouts can stack blocks or use two or three responsive columns. Layouts saved without a setting keep their automatic grid.

## 0.1.0-alpha.5 — 2026-11-11

The editor shows the saved draft in an embedded page preview at desktop, tablet and phone widths. The preview uses the public renderer and the saved draft's SEO metadata.

## 0.1.0-alpha.4 — 2026-11-11

Publishing a public page under a new slug now redirects its former URL directly to the current one. Existing installations need the slug redirects migration.

## 0.1.0-alpha.3 — 2026-11-11

Public content collections can map text fields to the SEO title and description. Public pages use the published revision and omit private fields and blank descriptions.

The starter serves an XML sitemap of published pages. Larger sites receive an index of sitemap parts.

## 0.1.0-alpha.2 — 2026-11-11

Site assistant pairing for a selected public content collection. The assistant can work with versioned drafts and can publish only when the administrator grants that permission. Back up existing installations and run `php artisan migrate --force` before starting the updated services. The migration adds two connection tables.

Docker Compose now checks the queue worker and scheduler processes separately from the web service, so the full stack can start with `docker compose up --wait`.

## 0.1.0 alpha — 2026-11-04

Initial Laravel package and starter; versioned collections, draft publication, browser admin, basic page blocks, customer requests, provider adapters and Docker/CI scaffolding. See docs/STATUS.md for remaining v1 work and evidence.
