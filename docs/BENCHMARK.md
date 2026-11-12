# Formaku reference benchmark

Run **Formaku reference benchmark** in GitHub Actions with a published Formaku image reference of the form `ghcr.io/formaku/formaku@sha256:…`. The Linux AMD64 runner measures three independent stacks and uploads the JSON reports. Use the digest from the tagged release.

Pushes that change the benchmark workflow or scripts run the same procedure against the pinned alpha.65 image so changes can be compared using a fixed reference.

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

Use the following procedure for an optional native source build on another architecture. It reports `source_build: true`. Keep those results separate from measurements of the published AMD64 image.

```sh
revision=$(git rev-parse HEAD)
git archive "$revision" | docker build -f docker/Dockerfile \
  --label "org.opencontainers.image.revision=$revision" \
  -t "formaku-benchmark:$revision" -
bash scripts/benchmark.sh "formaku-benchmark:$revision" > benchmark-source.json
```
