# Implementation status

This is **0.1.0-alpha.82**. The remaining v1 work is listed below.

## Implemented and locally exercised

- The panel draws its menu and dashboard once it has the user's collections and permissions, so its controls stay in place while it loads. A failed start shows the reason and a retry button.

- Agency B2B portals: build a quote-request portal from an editable template or an AI proposal, share a saved staff reply with the customer, check the portal and transfer versioned configurations between installations. The pilot comparison in the [benchmark](BENCHMARK.md) is still to be run with agencies.

- Check a saved public draft before publication for image alt text, incomplete links, heading structure, a missing SEO description and unpublished language versions.

- `formaku:doctor --json` reports installation diagnostics with the reason for each failed check. `formaku:notify` delivers portal notifications from any host's scheduler.

- Extension resources can offer actions for a single row. After alpha.82 the panel shows a button for each of them and asks for a confirmation and the remaining fields in a dialog. Draft saves validate the settings of extension blocks, and published pages skip a block whose extension was removed.

- The starter can run one managed merchant per process group, bound to a hostname and a dedicated database, with isolated sessions, cache, queues and files.

- The optional [Formaku Monitor](https://github.com/formaku/monitor) package, kept in a private repository, watches the database, scheduler, queue worker and the installation check. Its readiness and status addresses and its host probe are described in the [deployment guide](DEPLOYMENT.md#monitor-the-running-services).

- Browse revision history in pages of 25. Opening a record loads its current draft independently of the history. PostgreSQL and SQLite use a composite index to find revisions for one record.

- Open saved records from a panel link and reopen the same record after refreshing.

- Recover from a draft version conflict with local edits preserved in the editor and available as a JSON download.

- Preview a saved content revision from the version history in a separate tab, with current field permissions.

- Compare a saved revision with the current saved draft, showing changes only in fields the viewer can read.

- Withdraw published content and its scheduled publication while retaining the saved draft and revision history.

- Filter the content list by publication status and see when an already published record has a newer saved draft.

- Search current record titles and names across a collection’s pages, respecting field permissions.

- Filter content and data records by workflow state across pages. Content records can also be filtered by scheduled publication.

- Filter scheduled content drafts in the panel and browse paginated collection records.

- A read-only Artisan command checks Formaku migrations, panel authorization configuration, and whether all bundled panel assets match the published files.

- The starter runs the package’s scheduled publication command every minute. Composer hosts can register the same command in their Laravel scheduler.

- The editor displays the scheduled publication time and lets publishers cancel it without changing the draft.

- Filter customer request history by exact request number and state. The list shows readable request subjects and stays scoped to the signed-in customer’s own requests.

- Site name and optional public media logo in the shared site header.

- Optional private request attachments in the customer portal, with configurable file count, size and formats and scoped downloads for owners and staff.

- Configurable scalar request fields and field order in the customer portal, with collection and field permission checks.

- Host-configured role catalog and custom role keys in the collection and field permission editor.

- Per-field role restrictions for reading private fields and writing fields in the panel and API, with protected values retained across draft writes and restores.

- The public page, catalog, cards and sitemap respect private fields and reject public content collections with private URL slugs.

- Opt-in Laravel resources and actions in the admin panel, with per-extension authorization, paginated scalar columns, and validated action inputs.

- Choose registered custom field types in the collection model. Add and configure registered custom blocks in the page editor, including blocks with nested content.

- The Composer installer refreshes compiled Formaku Blade templates during upgrades while preserving cached views owned by the host Laravel application.

- Linked language versions of pages in a public content collection, with independent drafts, a public language switcher and HTML `hreflang` links.

- Laravel 13 / PHP 8.4+ Composer package, isolated configuration, opt-in public routing and fail-closed administrative authorization; starter with role-based access.
- Vue 3 / TypeScript panel with Formaku branding, Reka provider, Tailwind/daisyUI components and locally bundled fonts. The header offers a Polish/English interface switch remembered by the browser.
- Versioned collection definitions, generated record forms/lists, paginated selection of related records under collection access rules, relation storage, basic workflow transitions and assignment.
- Revision conflicts (409), immutable content revisions, separate draft/public pointers, public slug isolation, atomic publication, history restoration and scheduled publication API/UI.
- Canonical JSON renderer, Tiptap rich text, hero/text/CTA/image/cards/FAQ/container/columns blocks; reorder controls and drag/drop; delayed autosave for existing records.
- Customer login, optional registration, verification and password reset; own-request list and details, private-field filtering; staff-only admin API.
- Public/private uploads, owner/staff file authorization; server-side OpenAI/Gemini adapters, queued proposals, cancellation and a review step before applying a page proposal to a new unsaved draft.
- Staff can browse the media library by page, choose files for record fields and edit filenames and alternative text. Public media metadata requires publishing permission. Media usage lists accessible records with references in their current draft, published revision, or both.
- Definition-only project import/export with format version and conflict checks.
- Website assistant pairing for one eligible public collection, with draft access, optional publishing permission and revocation from the admin panel. The MCP adapter can compare a saved draft with its current published revision, validate a draft with built-in blocks, return its preview path, read templates and list public images referenced by current published revisions in that collection, and read the optional public Commerce catalog, including variants and published categories when the host exposes the corresponding v2 endpoints. The local adapter can be replaced while retaining an existing connection. Pairing can print a stdio configuration for clients other than Codex.
- Public content collections can map non-private text fields to the SEO title and description of published revisions.
- Administrators can configure header and footer links, plain footer text, and a light, dark, or system theme and a color accent in the shared site layout. Draft previews use the selected theme.
- The starter exposes an XML sitemap based on the configured public URL and published records in public collections.
- Publishing a public page under a new slug records a permanent redirect to its current URL.
- The editor renders the saved draft in a desktop, tablet or phone viewport using the public page renderer.
- The editor supports nested blocks with sibling reordering, duplication, and moves between the page level and layouts, stacked containers and responsive two- and three-column layouts. Editors can undo their latest block removal while the draft stays open. Image blocks pick from the paginated public image library and can serve smaller WebP variants while retaining the original URL. Administrators can save a draft’s blocks as a collection template. Editors with create access can use it in a new draft.
- Docker runtime with PHP/HTTP/queue/scheduler, pinned base-image digests, non-root execution, and Formaku OCI labels verified on published images by digest. GitHub build/GHCR and deployment workflow templates pin action SHAs.
- GitHub alpha releases include a Composer ZIP with compiled panel assets and a SHA-256 checksum for Laravel 13 hosts. The public Formaku Composer repository serves release metadata for direct installation.
- The Releases panel can refresh the state of a dispatched image build from its GitHub Actions run and open that run directly.
- Releases can read the historical deployment receipt from GitHub Actions for a matching run ID, release UUID and image digest. The host observation and SSH exit code are shown separately.
- Host deployment and encrypted backup helper, restricted SSH dispatch, and rollback status matched by release UUID and image digest in GitHub Actions. CI checks deployment failures, backup retention and status reporting with synthetic Compose and SSH fixtures. Target VPS acceptance remains pending.

## Evidence

- PHP CI runs on PHP 8.4 and 8.5 with PostgreSQL 17 and MySQL 8.4. The starter also supports running tests on SQLite.
- Playwright CI covers Chromium, Firefox and WebKit, including authoring and publishing with registered field and block extensions.
- Assistant adapter: the Node stdio test exercises validation errors, collection templates, image listing, public v1 and v2 catalog requests without the CMS token, filtering by category, and local adapter replacement.
- Frontend: vue-tsc and Vite production build pass. A real rendered dashboard screenshot is in test-results/dashboard-chromium.png (ignored build artifact).
- Docker: image built; Laravel command runs, HTTP login returns 200, runtime UID is 33 (www-data). GitHub CI verifies that the web process, database, queue worker and scheduler start with Compose.
- PostgreSQL upgrade CI uses the published alpha.60 image to create a page and a newer draft, applies the revision history index, and rolls back that migration. It compares the stored revisions and checks the public page before and after both steps.
- Clean-host Composer integration: installed in a separate unmodified Laravel project; original root route retained, Formaku admin registered, public routes absent by default.
- The alpha.65 Linux AMD64 benchmark completed three independent samples at a combined 2-vCPU and 4-GiB container profile. The [reference benchmark](BENCHMARK.md) records both scenarios, p95 ranges and the raw reports.
- Live paid AI calls, SMTP delivery and VPS deployment are still pending. Database restoration has been exercised with synthetic data.

## Remaining accepted v1 work

- Broader configurable role management and a broader portal form and attachment builder.
- Further page composition controls and theme management.
- AI schema/process/translation/SEO/data-generation tasks, structural diff review, configurable token/cost budgets and live-provider acceptance.
- GitHub project snapshot PR creation, live deployment status reconciliation, UI deployment wizard, fully automated host bootstrap/HTTPS, migration compatibility gates and tested rollback/recovery.
- Composer VCS split and Packagist registration, full PL/EN manuals, public synthetic demo and release/security review.

## Public release and documentation

The source is public in [`formaku/formaku`](https://github.com/formaku/formaku). The [GitHub prereleases](https://github.com/formaku/formaku/releases) list tagged source and, where available, verified [GHCR](https://github.com/formaku/formaku/pkgs/container/formaku) image digests. The [Compose guide](DEPLOYMENT.md#run-the-alpha-locally-with-docker-compose) covers running this version and upgrading an existing installation. CMS runtime deployment remains pending.

The static project website and its bilingual documentation are maintained separately in Forgejo and published on OVH MultiSite. The [English](https://formaku.eu/docs/) and [Polish](https://formaku.eu/pl/docs/) guides link to the tagged alpha.

The pre-existing docs/commerce documents were preserved unchanged and remain outside this alpha implementation.

## Row action buttons after alpha.82 — 2026-11-26

- The alpha.81 and alpha.82 panels list extension resources without their row actions. The API accepts a row action, but a user cannot reach it from the panel.
- The resource table now ends with an **Actions** column. A button opens a dialog with the values of the row and the remaining fields of the action. The action runs after the user confirms it. The dialog then shows the returned data and the row as the reloaded list returns it. A 4xx refusal shows the message of the response.
- The bootstrap API gives each row action the fields the panel asks for. The panel could not take them from the list of standalone actions, which leaves out a `row_only` action.
- `tests/browser/panel-extensions.spec.ts` runs an action without further fields, an action with a required field and an action that the handler refuses. Each browser changes a row of its own. A second test submits the standalone action form, whose fields moved into a component shared with the dialog, and checks the types of the values sent.
- Chromium and Firefox return the focus to the row's button when the dialog closes. WebKit does not, so the panel sets it. Without that call the test fails in WebKit only.
- With the change, the browser suite passed locally in Chromium, Firefox and WebKit together with the agency acceptance spec, and the PHP suite passed on SQLite. The panel type check and build passed, and the Composer archive built from this tree passed its installation check.

## Alpha.82 verification — 2026-11-26

- The [prerelease](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.82) and [release workflow](https://github.com/formaku/formaku/actions/runs/45899942370) identify source commit `48fae0df911a87aa93c3ba9c155926d88cdfb65e` and public image `ghcr.io/formaku/formaku@sha256:652300cf2f1c66ae54b46dbbf33d126d464ffc6816e30236b47b1a0708b13546`.
- The release differs from alpha.81 in the panel sources, the browser tests and the documentation. PHP sources, migrations and Composer requirements are unchanged.
- [CI for that commit on `main`](https://github.com/formaku/formaku/actions/runs/45899357483) passed PHP 8.4 and 8.5 with PostgreSQL 17 and MySQL 8.4, the panel build with the Composer archive check, the deployment controller tests and the Compose stack. The browser job passed 72 tests in Chromium, Firefox and WebKit, including the panel loading spec in each, and the agency acceptance spec. The three tests that write shared site settings run in Chromium only and were skipped in the other two browsers.
- The Composer archive built locally from the tagged commit is byte-identical to the published one. Both have the SHA-256 given in the release notes, `fb0d1393e0f452c1fce90e14d551eefc19c19bb86cf4922ca218e08083e6f90a`. Before the tag was pushed, an archive with the same bytes passed the installation check with `--check-missing-chunk`, `--check-missing-table` and `--check-doctor-json`.
- The image index was read from GHCR without credentials. Its SHA-256 equals the published digest. The image configuration names version `0.1.0-alpha.82` and the tagged commit.
- The [manual Compose test](https://github.com/formaku/formaku/actions/runs/45900358369) ran from the tag. It pulled the released image by digest, checked the installation against a fresh SQLite database and started the Compose services against a fresh PostgreSQL database. `formaku:doctor`, `/up` and `/login` passed. The controller reported `verified`, `mismatch` after stopping the scheduler, and `verified` after restarting it. CI removed its test stack.

## Panel start after alpha.81 — 2026-11-26

- The alpha.81 panel drew its menu and dashboard before it had the user's collections and permissions. With the bootstrap response held back in the browser test installation, **Media** moved 176 px, **Releases** and **Settings** 264 px and the dashboard's **Open builder** 130 px when the response arrived. Chromium, Firefox and WebKit gave the same figures at 1280 px. At 390 px every control below the menu moved, by up to 718 px.
- The panel now draws after the first bootstrap response and shows a loading message until then. If the request fails, it shows the message from the API and **Try again**.
- `tests/browser/panel-loading.spec.ts` holds the response back at both widths and compares where each visible control sits before and after. It fails against the alpha.81 panel in the three browsers and passes with the change.
- The deployment receipt, runtime observation, settings and media tests waited for the menu to settle before a sidebar click. Those waits are removed. With the bootstrap response delayed by a random 0 to 400 ms, the receipt and observation tests and the new spec passed 75 of 75 runs across the three browsers.
- The lost click itself is rare. Without the wait, the receipt test still passed 60 of 60 Firefox runs against the alpha.81 panel with the same delay.
- With the change, the browser suite passed locally in Chromium, Firefox and WebKit together with the agency acceptance spec. The panel type check and build passed, and the Composer archive built from this tree passed its installation check.

## Alpha.81 verification — 2026-11-26

- The [prerelease](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.81) and [release workflow](https://github.com/formaku/formaku/actions/runs/45868495742) identify source commit `3706411062b61c611fc3d850969a0333cf73c01e` and public image `ghcr.io/formaku/formaku@sha256:8459c6bf2705ff7ed7788eb37d87206d877d5e6be84915730558199d61a13231`.
- [CI for that commit on `main`](https://github.com/formaku/formaku/actions/runs/45867676610) passed PHP 8.4 and 8.5 with PostgreSQL 17 and MySQL 8.4, the panel build with the Composer archive check, the browser tests in Chromium, Firefox and WebKit with the agency acceptance spec, the deployment controller tests and the Compose stack.
- The run for the preceding commit, which prepared the release, failed once in Firefox. The deployment receipt test clicked the sidebar while the panel was still receiving its collections and permissions, and the click missed the button. With a randomly delayed bootstrap response the test failed in 2 of 60 local runs. With the wait added in the tagged commit it passed 120 of 120. The settings and media tests clicked the sidebar the same way and wait as well.
- The Composer archive built locally from the tagged commit is byte-identical to the published one. Both have the SHA-256 given in the release notes, `dc0801570ba69c3d520990bdab266e1935f688045758efb6370d4175312c23e6`. Before the tag was pushed, that archive passed the installation check with `--check-missing-chunk`, `--check-missing-table` and `--check-doctor-json`.
- The image index was read from GHCR without credentials. Its SHA-256 equals the published digest.
- The [manual Compose test](https://github.com/formaku/formaku/actions/runs/45869088563) pulled the released image by digest, checked the installation against a fresh SQLite database and started the Compose services against a fresh PostgreSQL database. `formaku:doctor`, `/up` and `/login` passed. The controller reported `verified`, `mismatch` after stopping the scheduler, and `verified` after restarting it. CI removed its test stack.

## Integration of unreleased work — 2026-11-26

- `main` now contains the work that waited on separate branches since alpha.80: JSON diagnostics, unique browser fixtures, row actions for extension resources, extension block validation, the publication check, managed merchant isolation ([#1](https://github.com/formaku/formaku/pull/1)) and the agency B2B portals. The B2B portal source is the candidate with manifest `3e4af512ba21b8ddb6a38e181f96b7e4db0d918e05876cf967c8daf5a1a4f1f1`, which passed its Compose acceptance scenario with PostgreSQL and mail delivery on a QA host on 2026-11-21.
- The Brand Kit branch was not merged. It was written against alpha.24, and `main` has since gained its own accent, logo and theme settings that it would duplicate. Its style presets and font choice remain open.
- Merging exposed one conflict that Git did not report. The publication check and the portal check translated the same Polish label differently, which broke the panel build. A failed portal check now has its own label.
- The merged tree passed 223 starter tests on PHP 8.5.11 with SQLite, the panel type check and build, 23 Chromium browser tests and the agency acceptance spec. The Commerce suite passed against this tree's panel registry, and the two-merchant isolation test passed 90 assertions with the Commerce integration branch and Platform `75218f1` on PostgreSQL 14. The PostgreSQL and MySQL matrix, Firefox, WebKit, the deployment controller and the Compose jobs run in GitHub CI.
- Live paid AI calls, SMTP delivery outside the QA host, the agency pilot and target VPS acceptance are still pending.

## Alpha.78 verification — 2026-11-13

- The [prerelease](https://github.com/formaku/formaku/releases/tag/v0.1.0-alpha.78) and [release workflow](https://github.com/formaku/formaku/actions/runs/43466103263) identify source commit `17e44d6ee75007579edebae212e3f71cc7d1d920` and public image `ghcr.io/formaku/formaku@sha256:a026fcbea6e2a4ce38fc631ad6352fb8e0f78c416294bc1b2638dfe51979832e`.
- The [manual Compose test](https://github.com/formaku/formaku/actions/runs/43467147251/job/132029213089#step:10:1) used the corrected test on `main` at `1db7f85` to check that released image. The controller reported `verified`, `mismatch` after stopping the scheduler, and `verified` after restarting it. CI removed its test stack.
- A separate Laravel host upgraded from alpha.77 using the [public Composer index](https://formaku.eu/composer/packages.json). The downloaded archive matched the indexed SHA-1 and the [published SHA-256 checksum](https://github.com/formaku/formaku/releases/download/v0.1.0-alpha.78/formaku-cms-v0.1.0-alpha.78.zip.sha256). The upgrade republished the panel assets, retained the host configuration, routes and SQLite data, and had no pending migrations.

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
