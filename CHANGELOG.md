# Changelog

## Unreleased

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
