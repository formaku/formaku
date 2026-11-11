# Implementation status

This is **0.1.0-alpha.2**. The remaining v1 work is listed below.

## Implemented and locally exercised

- Laravel 13 / PHP 8.4+ Composer package, isolated configuration, opt-in public routing and fail-closed administrative authorization; starter with role-based access.
- Vue 3 / TypeScript panel with Formaku branding, Reka provider, Tailwind/daisyUI components and locally bundled fonts.
- Versioned collection definitions, generated record forms/lists, relation storage, basic workflow transitions and assignment.
- Revision conflicts (409), immutable content revisions, separate draft/public pointers, public slug isolation, atomic publication, history restoration and scheduled publication API/UI.
- Canonical JSON renderer, Tiptap rich text, hero/text/CTA/image/cards/FAQ/container/columns blocks; reorder controls and drag/drop; delayed autosave for existing records.
- Customer login, optional registration, verification and password reset; own-request list and details, private-field filtering; staff-only admin API.
- Public/private uploads, owner/staff file authorization; server-side OpenAI/Gemini adapters, queued proposals, cancellation and applying a page proposal as a draft.
- Definition-only project import/export with format version and conflict checks.
- Website assistant pairing for one eligible public collection, with draft access, optional publishing permission and revocation from the admin panel.
- Development version: public content collections can map non-private text fields to the SEO title and description of published revisions.
- Docker runtime with PHP/HTTP/queue/scheduler, pinned base-image digests, non-root execution; GitHub build/GHCR and deployment workflow templates with pinned action SHAs.
- Host deployment/backup helper and restricted SSH-dispatch template. These scripts have only received syntax checks; they are not an accepted production deployment system.

## Evidence

- PHP: 20 tests / 59 assertions pass on local SQLite; the preceding 18-test suite passed on PostgreSQL 17 and MySQL 8.4, running PHP 8.5.11. Includes 10,000-record pagination, not a reference-hardware p95 benchmark.
- Browser: 6 tests pass across Chromium, Firefox and WebKit: real login → create page → save draft → publish → read public page, plus mobile login width.
- Frontend: vue-tsc and Vite production build pass. A real rendered dashboard screenshot is in test-results/dashboard-chromium.png (ignored build artifact).
- Docker: image built; Laravel command runs, HTTP login returns 200, runtime UID is 33 (www-data). GitHub CI verifies that the web process, database, queue worker and scheduler start with Compose.
- Clean-host Composer integration: installed in a separate unmodified Laravel project; original root route retained, Formaku admin registered, public routes absent by default.
- Live paid AI calls, SMTP delivery and VPS deployment are still pending. Database restoration has been exercised with synthetic data.

## Remaining accepted v1 work

- Per-field permissions and broader configurable role management (collection scopes and read/write rules are implemented); broader portal form/attachment builder.
- Complete page composition/visual preview fidelity, post templates, menus/footer/theme management, redirects and sitemap.
- Complete panel PL/EN localization and linked content translation workflow.
- Media usage tracking, asset metadata editing, image variants and integrated media/relation pickers with pagination.
- AI schema/process/translation/SEO/data-generation tasks, structural diff review, configurable token/cost budgets and live-provider acceptance.
- Registered extension contracts are present; complete developer examples and ResourceAdapter/ActionHandler UI integration still need delivery.
- GitHub project snapshot PR creation, build/deploy status reconciliation, UI deployment wizard, fully automated host bootstrap/HTTPS, migration compatibility gates and tested rollback/recovery.
- Composer split-release automation, Packagist registration, full PL/EN manuals, public synthetic demo and release/security review.
- Reference 2-vCPU/4-GB p95 benchmark.

## Public release and documentation

The source is public in [`formaku/formaku`](https://github.com/formaku/formaku). [Alpha.2](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.2) points to `7975288`, and the [first prerelease](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha) points to `340828e`. Both Linux AMD64 images are available from [GHCR](https://github.com/formaku/formaku/pkgs/container/formaku). The [Compose guide](DEPLOYMENT.md#run-the-alpha-locally-with-docker-compose) covers both alphas and upgrading an existing installation. CMS runtime deployment remains pending.

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
