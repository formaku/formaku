# Implementation status — 2026-11-04

This is a working **0.1.0 alpha**, not completion of the full accepted v1 plan.

## Implemented and locally exercised

- Laravel 13 / PHP 8.4+ Composer package, isolated configuration, opt-in public routing and fail-closed administrative authorization; starter with role-based access.
- Vue 3 / TypeScript panel with Formaku branding, Reka provider, Tailwind/daisyUI components and locally bundled fonts.
- Versioned collection definitions, generated record forms/lists, relation storage, basic workflow transitions and assignment.
- Revision conflicts (409), immutable content revisions, separate draft/public pointers, public slug isolation, atomic publication, history restoration and scheduled publication API/UI.
- Canonical JSON renderer, Tiptap rich text, hero/text/CTA/image/cards/FAQ/container/columns blocks; reorder controls and drag/drop; delayed autosave for existing records.
- Customer login, optional registration, verification and password reset; own-request list and details, private-field filtering; staff-only admin API.
- Public/private uploads, owner/staff file authorization; server-side OpenAI/Gemini adapters, queued proposals, cancellation and applying a page proposal as a draft.
- Definition-only project import/export with format version and conflict checks.
- Docker runtime with PHP/HTTP/queue/scheduler, pinned base-image digests, non-root execution; GitHub build/GHCR and deployment workflow templates with pinned action SHAs.
- Host deployment/backup helper and restricted SSH-dispatch template. These scripts have only received syntax checks; they are not an accepted production deployment system.

## Evidence

- PHP: 20 tests / 59 assertions pass on local SQLite; the preceding 18-test suite passed on PostgreSQL 17 and MySQL 8.4, running PHP 8.5.11. Includes 10,000-record pagination, not a reference-hardware p95 benchmark.
- Browser: 6 tests pass across Chromium, Firefox and WebKit: real login → create page → save draft → publish → read public page, plus mobile login width.
- Frontend: vue-tsc and Vite production build pass. A real rendered dashboard screenshot is in test-results/dashboard-chromium.png (ignored build artifact).
- Docker: image built; Laravel command runs, HTTP login returns 200, runtime UID is 33 (www-data). The final image is local only; no image was pushed.
- Clean-host Composer integration: installed in a separate unmodified Laravel project; original root route retained, Formaku admin registered, public routes absent by default.
- No real paid AI call, SMTP delivery, VPS deploy, rollback or backup restoration has been performed.

## Remaining accepted v1 work

- Per-field permissions and broader configurable role management (collection scopes and read/write rules are implemented); broader portal form/attachment builder.
- Complete page composition/visual preview fidelity, post templates, SEO fields, menus/footer/theme management, redirects and sitemap.
- Complete panel PL/EN localization and linked content translation workflow.
- Media usage tracking, asset metadata editing, image variants and integrated media/relation pickers with pagination.
- AI schema/process/translation/SEO/data-generation tasks, structural diff review, configurable token/cost budgets and live-provider acceptance.
- Registered extension contracts are present; complete developer examples and ResourceAdapter/ActionHandler UI integration still need delivery.
- GitHub project snapshot PR creation, build/deploy status reconciliation, UI deployment wizard, fully automated host bootstrap/HTTPS, migration compatibility gates and tested rollback/recovery.
- Composer split-release automation, Packagist registration, full PL/EN manuals, public synthetic demo and release/security review.
- Reference 2-vCPU/4-GB p95 benchmark and PHP 8.4 matrix execution (workflow prepared; local PHP is 8.5).

## External setup still needed

The formaku organization now exists on GitHub, owned by lukasz5. Repository creation is in progress; no code has been pushed. No VPS/domain, production credentials or provider keys were selected or copied from other projects.

The pre-existing docs/commerce documents were preserved unchanged and remain outside this alpha implementation.
