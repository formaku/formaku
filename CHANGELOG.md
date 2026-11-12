# Changelog

## Unreleased

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
