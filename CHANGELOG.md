# Changelog

## Unreleased

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
