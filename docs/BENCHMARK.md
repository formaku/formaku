# Formaku reference benchmark

Run **Formaku reference benchmark** in GitHub Actions with a published Formaku image reference of the form `ghcr.io/formaku/formaku@sha256:…`. The Linux AMD64 runner measures three independent stacks and uploads the JSON reports. Use the digest from the tagged release.

Branch pushes that change the benchmark workflow or scripts run the same procedure against the pinned alpha.65 image so changes can be compared using a fixed reference.

To run one sample locally on a native Linux AMD64 Docker engine, use Docker Compose with support for `!override`, Python 3 and OpenSSL.

```sh
bash scripts/benchmark.sh 'ghcr.io/formaku/formaku@sha256:…' > benchmark.json
```

The script creates a separate Compose project, PostgreSQL database, local administrator and 251 published pages. It binds the web service to a random loopback port and removes the project and its volumes at the end. Each run uses the following container limits.

| Service | vCPU | MiB |
| --- | ---: | ---: |
| Web | 1 | 1536 |
| PostgreSQL | 0.75 | 2048 |
| Worker | 0.125 | 256 |
| Scheduler | 0.125 | 256 |
| Total | 2 | 4096 |

The script checks the effective limits, service health and restart counts before and after the run. Each service has its own quota and swap is disabled. The load generator runs outside those quotas. Results describe this container profile on the reported runner. A dedicated 2-vCPU VM can produce different results.

The two scenarios read the public `/p/start` page and the authenticated first page of the content records API. Sessions are created before timing. Each scenario uses four closed-loop clients, ten seconds of warmup and thirty seconds of measurement. The report gives attempts, failures, HTTP status counts, throughput and nearest-rank p50, p95 and p99 latencies in milliseconds. These timings include reading the HTTP response body and exclude browser assets. An unsuccessful request fails the run. Compare all three reports for the same image, fixture and resource profile.

## Alpha.65 reference results

On 12 November 2026, three GitHub Actions Linux AMD64 samples of `v0.1.0-alpha.65` (`1bd1db0781fcf7cd8c8b1caef795d91047d96828`) completed 7,976 requests without errors. Each range is the minimum and maximum across the three samples.

| Scenario | Requests | Requests/s | p95 (ms) |
| --- | ---: | ---: | ---: |
| Public page | 4529 | 49.677–50.571 | 94.627–97.269 |
| Authenticated record list | 3447 | 37.222–39.159 | 122.021–144.352 |

[GitHub Actions summary and JSON reports](https://github.com/formaku/formaku/actions/runs/43243596702#summary-131270875050)

## Native source builds

Use the following procedure for an optional native source build on another architecture. It reports `source_build: true`. Keep those results separate from measurements of the published AMD64 image.

```sh
revision=$(git rev-parse HEAD)
git archive "$revision" | docker build -f docker/Dockerfile \
  --label "org.opencontainers.image.revision=$revision" \
  -t "formaku-benchmark:$revision" -
bash scripts/benchmark.sh "formaku-benchmark:$revision" > benchmark-source.json
```

## Agency delivery pilot

Measure active work from opening an approved brief to accepting a working B2B service portal on a client-hosted installation. Run both Formaku and self-hosted WordPress with Gravity Forms, Gravity Flow and the AI tools normally used by the agency. Give both operators the same source materials, machine profile, mail test account, familiar reusable templates and access to their normal extensions. Record exact versions, model, templates and extensions in each run's `stack`.

### Two synthetic client briefs

| Brief | Approved material and required behaviour |
| --- | --- |
| **Meris** | Meris Serwis offers industrial pump inspection and valve maintenance. Build one service page with an entry point to its quote-request portal. A logged-in buyer submits the service, a subject, a description and a preferred date. The coordinator assigns a salesperson who writes an internal note and shares a reply. The buyer sees the response and the request status. Use the three stages received, in progress and closed. The response time and prices are unknown. Request these details for review before making a commercial promise. |
| **Lumet** | Lumet Instalacje offers lighting surveys and lighting retrofits for warehouses. Deliver one service page and an account-based quote portal for facilities managers. Collect service, subject, project description and desired date. A coordinator assigns an estimator who adds an internal note and explicitly shares a response. Show the requester their status through received, in progress and closed. Materials give neither a response deadline nor prices. Record both questions for the reviewer. |

### Run and score the comparison

1. Generate the blank local worksheet with `python3 scripts/benchmark-agency.py template > pilot.json`. Three agencies each complete both briefs on both systems. Run the rows in their generated order, with a fresh site for each delivery. The first platform alternates between briefs and agencies. Use the same operator for a matched pair. Start timing at the brief. Include configuration, fixes, repeated tests, client review and handover in active person-minutes. Sum concurrent participants' time. Record each cycle of corrections in `correction_rounds`.
2. Require an independent reviewer to submit a request on a phone, navigate the form with a keyboard, assign it to a salesperson and receive an explicitly shared reply as the original buyer. Check that the buyer cannot see the private note, an unshared reply or a second buyer's request. Confirm delivery to an actual test mailbox. Restore on a separate installation and verify the page, open request and shared reply there. Record unresolved checks as false and missing evidence as null. Store a non-identifying private receipt reference. Preserve failed checks in the receipt after a successful retest.
3. Record AI, allocated license and hosting costs per run in the same currency. For both tools use the same allocation convention, for example one month of license/hosting cost divided by the number of applicable client installations. Enter zero for a measured zero. Leave unavailable costs blank. Keep the worksheet and receipts private if they include operator or commercial information.
4. After the comparison, record whether each agency used Formaku for another genuine client engagement and a receipt identifier. Set `evidence` to `pilot` for observed agency work or `synthetic` for a rehearsal. Run `python3 scripts/benchmark-agency.py score pilot.json`. The report computes the median active person-minutes across the six accepted deliveries for each platform, percentage reduction against WordPress, total correction rounds, and costs by category. An incomplete or failed final acceptance does not produce a comparable delivery-time result.
5. The proposed pilot threshold is at least a **40% reduction in median active time**, all twelve deliveries accepted and documented repeat use by **at least two agencies**. This small paired pilot supports a decision about the next release. Use a broader independent sample before generalising the result to the market.
