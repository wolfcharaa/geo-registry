# Repository Guidelines

Use the global `development` skill for implementation, review, testing, project
setup, and debugging. Use the global `git-conventions` skill as the single entry
point for branch, staging, commit, history, tag, push, and pull-request rules.
Until that global skill can be installed, its canonical prepared source is
`skills/git-conventions/SKILL.md`.
Before README, guides, reference, operator runbooks, library integration, or
application data-lifecycle changes, read
`skills/documentation-authoring/SKILL.md`.

Before changing Thesis DIC composition, service lifetime, scope, provider, or
disposer behavior, read `docs/development/thesis-dic.md`.

## Project Structure & Module Organization

`app/bin/app` is the executable Symfony Console entrypoint; its default
`server:run` command starts the HTTP runtime, and all CLI/worker operations use
the same executable. Keep environment parsing in
`app/src/Config`, external systems in `app/src/Infrastructure/<Integration>`,
and HTTP routing/presentation in `app/src/UserInterface/Http`. Tests mirror
source namespaces under `app/tests/`.
Development containers and smoke scripts live in `docker/`; local settings are
copied from `config/.env.example` to the ignored `config/.env`.

## Build, Test & Development Commands

- `make config` creates the local environment file without overwriting it.
- `make build && make install` builds PHP 8.4 and installs locked dependencies.
- `make up` starts the app, application PostgreSQL, Nominatim, and OpenSearch.
- `make check` runs formatting checks, strict PHPStan, and Testo.
- `make doctor` validates Compose; `make smoke` probes a running stack.

## Coding Style & Naming Conventions

Use `declare(strict_types=1)`, PSR-4 namespaces under `App\`, four-space
indentation, and final classes unless extension is intentional. Name services
and factories by responsibility, for example `NominatimClient` and
`OpenSearchRequestFactory`. Run `make fix` before submitting. Request handlers
must not perform blocking I/O; use AMPHP clients and propagate cancellation for
long operations.

## Testing Guidelines

Use Testo and name files `*Test.php`; test methods should describe observable
behavior (`testItRejectsAnEmptyHttpMethod`). Unit-test request construction and
protocol handling without live services. Use `make smoke` for integration
readiness. Keep the application database separate from Nominatim's internal
PostgreSQL lifecycle and data.

## Commit & Pull Request Guidelines

Use `TYPE(scope): Результат изменения на русском`, with an uppercase type, a
short area scope, and an impersonal result-form description. Keep one reason per
commit and add `Kaiten: #...` only when the number is known. Follow the local
prepared `git-conventions` source as the authoritative commit gate. Pull requests
should explain behavior and trade-offs, list commands run, link relevant
issues, and note configuration or API changes. Include example
requests/responses when HTTP contracts change.

## Security & Integration Rules

Never commit secrets, `config/.env`, imported OSM data, indexes, dumps, or
volumes. Keep OpenSearch native to `amphp/http-client`; adding a generated SDK,
Guzzle, retries, bulk streaming, or alternative authentication requires an
explicit design review.
