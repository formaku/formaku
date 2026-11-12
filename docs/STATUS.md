# Implementation status

This is **0.1.0-alpha.33**. The remaining v1 work is listed below.

## Implemented and locally exercised

- The public page, catalog, cards and sitemap respect private fields and reject public content collections with private URL slugs.

- Opt-in Laravel resources and actions in the admin panel, with per-extension authorization, paginated scalar columns, and validated action inputs.

- The Composer installer refreshes compiled Formaku Blade templates during upgrades while preserving cached views owned by the host Laravel application.

- Linked language versions of pages in a public content collection, with independent drafts, a public language switcher and HTML `hreflang` links.

- Laravel 13 / PHP 8.4+ Composer package, isolated configuration, opt-in public routing and fail-closed administrative authorization; starter with role-based access.
- Vue 3 / TypeScript panel with Formaku branding, Reka provider, Tailwind/daisyUI components and locally bundled fonts. The header offers a Polish/English interface switch remembered by the browser.
- Versioned collection definitions, generated record forms/lists, paginated selection of related records under collection access rules, relation storage, basic workflow transitions and assignment.
- Revision conflicts (409), immutable content revisions, separate draft/public pointers, public slug isolation, atomic publication, history restoration and scheduled publication API/UI.
- Canonical JSON renderer, Tiptap rich text, hero/text/CTA/image/cards/FAQ/container/columns blocks; reorder controls and drag/drop; delayed autosave for existing records.
- Customer login, optional registration, verification and password reset; own-request list and details, private-field filtering; staff-only admin API.
- Public/private uploads, owner/staff file authorization; server-side OpenAI/Gemini adapters, queued proposals, cancellation and applying a page proposal as a draft.
- Staff can browse the media library by page, choose files for record fields and edit filenames and alternative text. Public media metadata requires publishing permission. Media usage lists accessible records with references in their current draft, published revision, or both.
- Definition-only project import/export with format version and conflict checks.
- Website assistant pairing for one eligible public collection, with draft access, optional publishing permission and revocation from the admin panel. The MCP adapter can validate a draft with built-in blocks, return its preview path, read templates and list public images referenced by current published revisions in that collection, and read the optional public Commerce catalog, including variants and published categories when the host exposes the corresponding v2 endpoints. The local adapter can be replaced while retaining an existing connection. Pairing can print a stdio configuration for clients other than Codex.
- Public content collections can map non-private text fields to the SEO title and description of published revisions.
- Administrators can configure header and footer links, plain footer text, and a light, dark, or system theme and a color accent in the shared site layout. Draft previews use the selected theme.
- The starter exposes an XML sitemap based on the configured public URL and published records in public collections.
- Publishing a public page under a new slug records a permanent redirect to its current URL.
- The editor renders the saved draft in a desktop, tablet or phone viewport using the public page renderer.
- The editor supports nested blocks with sibling reordering, duplication, and moves between the page level and layouts, stacked containers and responsive two- and three-column layouts. Editors can undo their latest block removal while the draft stays open. Image blocks pick from the paginated public image library and can serve smaller WebP variants while retaining the original URL. Administrators can save a draft’s blocks as a collection template. Editors with create access can use it in a new draft.
- Docker runtime with PHP/HTTP/queue/scheduler, pinned base-image digests, non-root execution; GitHub build/GHCR and deployment workflow templates with pinned action SHAs.
- GitHub alpha releases include a Composer ZIP with compiled panel assets and a SHA-256 checksum for Laravel 13 hosts. The public Formaku Composer repository serves release metadata for direct installation.
- The Releases panel can refresh the state of a dispatched image build from its GitHub Actions run and open that run directly.
- Host deployment/backup helper and restricted SSH-dispatch template. These scripts have only received syntax checks; they are not an accepted production deployment system.

## Evidence

- PHP: 58 tests and 601 assertions pass on local SQLite with PHP 8.5.11. The suite covers navigation access, address validation, version conflicts, theme compatibility with existing settings, and template access, validation and draft independence. Media usage checks also pass on PostgreSQL 17.11 and MySQL 8.4.
- Browser: 12 tests pass across Chromium, Firefox and WebKit. The suite covers authoring, media usage, responsive layouts, SEO, related records, private attachments, WebP variants and mobile login width. The suite also checks collection templates and their normal publication flow. Chromium verifies site navigation changes on a published page.
- Assistant adapter: the Node stdio test exercises validation errors, collection templates, image listing, public v1 and v2 catalog requests without the CMS token, filtering by category, and local adapter replacement.
- Frontend: vue-tsc and Vite production build pass. A real rendered dashboard screenshot is in test-results/dashboard-chromium.png (ignored build artifact).
- Docker: image built; Laravel command runs, HTTP login returns 200, runtime UID is 33 (www-data). GitHub CI verifies that the web process, database, queue worker and scheduler start with Compose.
- Clean-host Composer integration: installed in a separate unmodified Laravel project; original root route retained, Formaku admin registered, public routes absent by default.
- Live paid AI calls, SMTP delivery and VPS deployment are still pending. Database restoration has been exercised with synthetic data.

## Remaining accepted v1 work

- Per-field permissions and broader configurable role management (collection scopes and read/write rules are implemented); broader portal form/attachment builder.
- Further page composition controls and theme management.
- AI schema/process/translation/SEO/data-generation tasks, structural diff review, configurable token/cost budgets and live-provider acceptance.
- GitHub project snapshot PR creation, deployment status reconciliation, UI deployment wizard, fully automated host bootstrap/HTTPS, migration compatibility gates and tested rollback/recovery.
- Composer VCS split and Packagist registration, full PL/EN manuals, public synthetic demo and release/security review.
- Reference 2-vCPU/4-GB p95 benchmark.

## Public release and documentation

The source is public in [`formaku/formaku`](https://github.com/formaku/formaku). The [GitHub prereleases](https://github.com/formaku/formaku/releases) list tagged source and, where available, verified [GHCR](https://github.com/formaku/formaku/pkgs/container/formaku) image digests. The [Compose guide](DEPLOYMENT.md#run-the-alpha-locally-with-docker-compose) covers running this version and upgrading an existing installation. CMS runtime deployment remains pending.

The static project website and its bilingual documentation are maintained separately in Forgejo and published on OVH MultiSite. The [English](https://formaku.eu/docs/) and [Polish](https://formaku.eu/pl/docs/) guides link to the tagged alpha.

The pre-existing docs/commerce documents were preserved unchanged and remain outside this alpha implementation.

## Release candidate verification — 2026-11-07

- Built the Linux AMD64 container locally from a clean archive of `340828e1d32ca676ae527900ba30e9b3d1756cc3`. The local image ID is `sha256:d0f0afa687d202453923e080a1b729e8e4524ef870b7a6eb53716c97ff579491`. Use the registry digest reported by the release workflow for public distribution.
- In a temporary container running as `www-data` (UID 33), SQLite migrations completed and the project test fixtures created the demo. The health check, login page, published demo page and bundled JavaScript each returned HTTP 200. The admin bootstrap API returned HTTP 401 to a guest.
- Composer and npm audits reported no known advisories for the locked production dependency sets at the time of the check.
- The 20-test alpha suite passed on PHP 8.5.11 with PostgreSQL 17.11 and MySQL 8.4.13. The development suite with website assistant pairing passed 27 tests and 168 assertions on PHP 8.4.27 and 8.5.11 with both databases, and on PHP 8.5.11 with SQLite.
- Rehearsed the assistant upgrade locally with a synthetic PostgreSQL 17 backup from the alpha image. After restoring the backup and applying the two-table assistant migration with `8f72976`, the existing published page and its revision number were preserved. Both images served the health check, login, demo page and frontend JavaScript on the migrated database. The assistant image contained the Node adapter and returned HTTP 401 for an unauthenticated assistant connection request. This rehearsal used local containers and synthetic data.

## Alpha.2 verification — 2026-11-11

- [GitHub CI for `eb24643`](https://github.com/formaku/formaku/actions/runs/43069365822) passed the PHP matrix, frontend, browser and Docker Compose jobs.
- In a local Chromium session, downloaded the adapter through the admin panel, paired it in an interactive terminal and exercised the MCP tools with a synthetic collection. Creating and editing a draft left the public URL unavailable. A draft-only connection received HTTP 403 when asked to publish and HTTP 401 after revocation in the panel.
- Repeated the upgrade from a synthetic PostgreSQL 17.11 backup of the first alpha. The migration added the two assistant tables, preserved the original rows and published revision, and made no changes on a second run. Restoring the same backup into a fresh database also let the original image serve the existing published page again.
- The local application and migration checks used image `8f72976`. Application source, dependency lockfiles and container build inputs match the alpha.2 candidate.
- The [release CI](https://github.com/formaku/formaku/actions/runs/43069947391) and [image build](https://github.com/formaku/formaku/actions/runs/43070468924) passed for `7975288`. The public GHCR image was pulled by digest and started with the tagged Compose configuration in an isolated local stack. PostgreSQL migrations, administrator creation and demo seeding succeeded. All four services reported healthy, `/up`, `/login` and `/p/start` returned HTTP 200, and a guest request to the assistant endpoint returned HTTP 401.
