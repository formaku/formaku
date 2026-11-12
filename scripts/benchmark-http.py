#!/usr/bin/env python3
"""Measure Formaku's public page and authenticated record list on loopback."""

from __future__ import annotations

import argparse
from collections import Counter
from concurrent.futures import ThreadPoolExecutor
from contextlib import closing
from html.parser import HTMLParser
import http.client
from http.cookies import CookieError, SimpleCookie
import json
import math
import os
import sys
from threading import Barrier
from time import perf_counter
from urllib.parse import urlencode


class PreflightError(Exception):
    pass


class LoginForm(HTMLParser):
    token: str | None = None

    def handle_starttag(self, tag: str, attrs: list[tuple[str, str | None]]) -> None:
        attributes = dict(attrs)
        if tag == "input" and attributes.get("name") == "_token":
            self.token = attributes.get("value")


def connection(port: int) -> http.client.HTTPConnection:
    return http.client.HTTPConnection("127.0.0.1", port, timeout=5)


def read_response(
    client: http.client.HTTPConnection, method: str, path: str,
    headers: dict[str, str], body: str | None = None,
) -> tuple[http.client.HTTPResponse, bytes]:
    client.request(method, path, body=body, headers=headers)
    response = client.getresponse()
    return response, response.read()


def cookie_header(cookies: SimpleCookie) -> str:
    return "; ".join(f"{name}={cookie.coded_value}" for name, cookie in cookies.items())


def update_cookies(cookies: SimpleCookie, response: http.client.HTTPResponse) -> None:
    for name, value in response.getheaders():
        if name.lower() == "set-cookie":
            cookies.load(value)


def login(port: int, password: str) -> dict[str, str]:
    cookies = SimpleCookie()
    with closing(connection(port)) as client:
        response, body = read_response(client, "GET", "/login", {"Accept": "text/html"})
        form = LoginForm()
        form.feed(body.decode("utf-8", errors="replace"))
        update_cookies(cookies, response)
        if response.status != 200 or not form.token or not cookies:
            raise PreflightError("Login page did not return a session cookie and CSRF token.")

        response, _ = read_response(
            client, "POST", "/login",
            {"Accept": "text/html", "Content-Type": "application/x-www-form-urlencoded",
             "Cookie": cookie_header(cookies)},
            urlencode({"_token": form.token, "email": "benchmark@formaku.test", "password": password}),
        )
        if response.status not in (302, 303):
            raise PreflightError(f"Login POST returned HTTP {response.status}.")
        update_cookies(cookies, response)

    return {"Accept": "application/json", "Cookie": cookie_header(cookies)}


def preflight(port: int, records_path: str, auth_headers: dict[str, str]) -> None:
    with closing(connection(port)) as client:
        public, html = read_response(client, "GET", "/p/start", {"Accept": "text/html"})
        if public.status != 200 or b"FORMAKU / OPEN SOURCE" not in html:
            raise PreflightError(f"Public demo preflight did not match the expected page (HTTP {public.status}).")

        records, body = read_response(client, "GET", records_path, auth_headers)
        content_type = records.getheader("Content-Type", "").partition(";")[0].strip().lower()
        if records.status != 200 or content_type != "application/json":
            raise PreflightError(f"Authenticated records preflight did not return JSON (HTTP {records.status}).")
        data = json.loads(body)
        if (
            not isinstance(data, dict)
            or data.get("current_page") != 1
            or data.get("per_page") != 25
            or data.get("total") != 251
            or data.get("last_page") != 11
            or not isinstance(data.get("data"), list)
            or len(data["data"]) != 25
            or not all(isinstance(record, dict) for record in data["data"])
        ):
            raise PreflightError("Authenticated records preflight expected 251 records with 25 on the first page.")


def timed_get(client: http.client.HTTPConnection, path: str, headers: dict[str, str]) -> str:
    try:
        response, _ = read_response(client, "GET", path, headers)
        return str(response.status)
    except (OSError, http.client.HTTPException):
        client.close()
        return "transport_error"


def percentile(sorted_samples: list[float], percent: int) -> float | None:
    if not sorted_samples:
        return None
    rank = (percent * len(sorted_samples) + 99) // 100
    return round(sorted_samples[rank - 1] * 1000, 3)


def benchmark(
    name: str, port: int, path: str, worker_headers: list[dict[str, str]],
    warmup: float, duration: float,
) -> dict[str, object]:
    warmup_deadline = 0.0
    started = 0.0

    def start_warmup() -> None:
        nonlocal warmup_deadline
        warmup_deadline = perf_counter() + warmup

    def start_measurement() -> None:
        nonlocal started
        started = perf_counter()

    warmup_barrier = Barrier(len(worker_headers), action=start_warmup)
    measurement_barrier = Barrier(len(worker_headers), action=start_measurement)

    def worker(headers: dict[str, str]) -> tuple[Counter[str], list[float], float]:
        statuses: Counter[str] = Counter()
        latencies: list[float] = []
        with closing(connection(port)) as client:
            warmup_barrier.wait()
            while perf_counter() < warmup_deadline:
                timed_get(client, path, headers)

            measurement_barrier.wait()
            deadline = started + duration
            request_started = perf_counter()
            while request_started < deadline:
                status = timed_get(client, path, headers)
                completed = perf_counter()
                statuses[status] += 1
                if status == "200":
                    latencies.append(completed - request_started)
                request_started = perf_counter()
        return statuses, latencies, request_started

    statuses: Counter[str] = Counter()
    latencies: list[float] = []
    ended = 0.0
    with ThreadPoolExecutor(max_workers=len(worker_headers)) as executor:
        for worker_statuses, worker_latencies, worker_ended in executor.map(worker, worker_headers):
            statuses.update(worker_statuses)
            latencies.extend(worker_latencies)
            ended = max(ended, worker_ended)

    latencies.sort()
    attempted = sum(statuses.values())
    succeeded = len(latencies)
    elapsed = ended - started
    return {
        "name": name,
        "attempted": attempted,
        "succeeded": succeeded,
        "failed": attempted - succeeded,
        "statuses": dict(statuses),
        "p50_ms": percentile(latencies, 50),
        "p95_ms": percentile(latencies, 95),
        "p99_ms": percentile(latencies, 99),
        "requests_per_second": round(attempted / elapsed, 3) if elapsed else 0.0,
        "elapsed_seconds": round(elapsed, 6),
    }


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--port", required=True, type=int, help="Local HTTP port")
    parser.add_argument("--collection", required=True, type=int, help="Benchmark collection ID")
    parser.add_argument("--concurrency", type=int, default=4, help="Workers per scenario (1–8, default: 4)")
    parser.add_argument("--warmup", type=float, default=10.0, help="Warmup seconds per scenario (default: 10)")
    parser.add_argument("--duration", type=float, default=30.0, help="Measured seconds per scenario (default: 30)")
    args = parser.parse_args()
    if not 1 <= args.port <= 65535 or args.collection < 1:
        parser.error("--port must be 1–65535 and --collection must be positive")
    if not 1 <= args.concurrency <= 8:
        parser.error("--concurrency must be 1–8")
    if not math.isfinite(args.warmup) or args.warmup < 0 or not math.isfinite(args.duration) or args.duration <= 0:
        parser.error("--warmup must be finite and nonnegative and --duration must be finite and positive")

    records_path = f"/formaku/api/v1/collections/{args.collection}/records"
    scenarios = []
    try:
        password = os.environ.get("FORMAKU_BENCHMARK_PASSWORD")
        if not password:
            raise PreflightError("Set FORMAKU_BENCHMARK_PASSWORD to the synthetic account password.")
        auth_headers = [login(args.port, password) for _ in range(args.concurrency)]
        preflight(args.port, records_path, auth_headers[0])
    except PreflightError as error:
        print(f"Preflight failed: {error}", file=sys.stderr)
    except (OSError, http.client.HTTPException, CookieError, ValueError):
        print("Preflight failed to read a valid loopback HTTP response.", file=sys.stderr)
    else:
        scenarios = [
            benchmark("public_page", args.port, "/p/start", [{"Accept": "text/html"}] * args.concurrency,
                      args.warmup, args.duration),
            benchmark("authenticated_records", args.port, records_path, auth_headers, args.warmup, args.duration),
        ]

    print(json.dumps({
        "concurrency": args.concurrency,
        "warmup_seconds": args.warmup,
        "duration_seconds": args.duration,
        "scenarios": scenarios,
    }, indent=2, sort_keys=True))
    return int(not scenarios or any(result["failed"] or not result["succeeded"] for result in scenarios))


if __name__ == "__main__":
    raise SystemExit(main())
