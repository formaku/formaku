# Changelog

## Unreleased

Site assistant pairing for a selected public content collection. The assistant can work with versioned drafts and can publish only when the administrator grants that permission. The starter requires a database migration for the new connection tables.

Docker Compose now checks the queue worker and scheduler processes separately from the web service, so the full stack can start with `docker compose up --wait`.

## 0.1.0 alpha — 2026-11-04

Initial Laravel package and starter; versioned collections, draft publication, browser admin, basic page blocks, customer requests, provider adapters and Docker/CI scaffolding. See docs/STATUS.md for remaining v1 work and evidence.
