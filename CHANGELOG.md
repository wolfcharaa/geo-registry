# Changelog

Все значимые изменения проекта документируются в этом файле.

## [Unreleased]

## [0.2.2] - 2026-11-08

- Production deployment переведён на native `docker compose up -d`: tracked
  YAML хранит non-secret runtime config, ignored env содержит только secrets,
  а PostgreSQL 17/PostGIS входит в основной Compose stack.
- Добавлен линейный restore готового backup одной командой с автоматическими
  extraction, checksum, preflight, role lifecycle и shadow restore.
- Добавлен production maintenance lifecycle для fresh GAR download/import,
  partitioned `address_search`, Data.Mos и rebuild OpenSearch projection.
- Добавлен отдельный update lifecycle существующего managed deployment с
  read-only preflight, worker quiescence, migrations, data gates и smoke.
- Application image подготавливается одной командой `make deploy-image`,
  автоматически выбирающей verified offline payload либо exact registry image.

## [0.2.1] - 2026-11-08

- Исправлен offline release на чистом Docker Engine: checksummed `docker load`
  больше не требует отсутствующий registry `RepoDigest`, а production Compose
  запускает проверенный frozen tag с `pull_policy: never`.
- Добавлен executable release regression test для manifest v1/v2 и реального
  сценария tag-only image после offline load.
- PostgreSQL backup из `v0.2.0` совместим с `v0.2.1`: схема приложения и
  формат данных в patch release не изменялись.

## [0.2.0] - 2026-11-08

- MessageBus dependencies переведены с соседних Composer path repositories на
  опубликованные Packagist releases `v0.1.0`; development, production build и
  source handoff больше не требуют отдельных library checkout.
- `gar:import` получил fail-closed `--regions`, сохраняющий общие справочники и
  отбрасывающий XML остальных регионов до запуска COPY workers.
- Добавлена встроенная выгрузка официального Data.Mos dataset `60562` в
  JSONL.GZ с version/release/checksum manifest и обязательной проверкой
  manifest при импорте.
- Offline release получил opt-in application PostgreSQL dump, проверяемое
  извлечение на новом сервере и явный restore/rebuild OpenSearch runbook.
- Release workflow получил единый immutable tag-контракт, явные registry
  publish/pull команды, внешний SHA-256 файл transport archive и автономный
  runbook checkout → shadow restore → OpenSearch rebuild → запуск → smoke.
- Добавлен fail-closed development bootstrap из PostgreSQL backup: manifest
  проверяет application revision, restore идёт в новую БД, а OpenSearch
  перестраивается из `address_search` до включения search.

## [0.1.0] - 2026-11-07

Первый публичный source release.

- Добавлен AMPHP HTTP runtime с единым `bin/app` для server, migrations, GAR,
  OpenSearch и worker operations.
- Перенесены search, staged suggest, find-by-id и reverse-geocode contracts.
- Реализован checkpointed GAR XML import через bounded `COPY` workers.
- Реализована региональная LIST-partitioned `address_search` shadow rebuild с
  parity и атомарным cutover.
- Добавлены optional Data.Mos/OSM enrichment, Nominatim и OpenSearch projection.
- Интегрированы `message-bus-async` и `message-bus-async-worker` `0.1.x` с
  PostgreSQL durable queues и worker control plane.
- Добавлены production Compose, backup/restore tooling, source handoff, strict
  Postman parity и offline application release workflow.

[0.1.0]: https://github.com/wolfcharaa/geo-registry/releases/tag/v0.1.0
[0.2.0]: https://github.com/wolfcharaa/geo-registry/compare/v0.1.0...v0.2.0
[0.2.2]: https://github.com/wolfcharaa/geo-registry/compare/v0.2.1...v0.2.2
[0.2.1]: https://github.com/wolfcharaa/geo-registry/compare/v0.2.0...v0.2.1
[Unreleased]: https://github.com/wolfcharaa/geo-registry/compare/v0.2.2...HEAD
