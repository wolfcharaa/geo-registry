# Changelog

Все значимые изменения проекта документируются в этом файле.

## [Unreleased]

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
[Unreleased]: https://github.com/wolfcharaa/geo-registry/compare/v0.1.0...HEAD
