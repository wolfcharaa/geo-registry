---
module: AddressRegistry
version: 0.2.0
updated: 2026-11-04
language: ru
---

# Модуль AddressRegistry

Модуль владеет бизнес-контрактами адресного реестра: поиском и идентификацией
адресов, подсказками, импортом GAR и построением производных поисковых данных.
Application runtime, внутренняя база Nominatim и технический lifecycle
OpenSearch ему не принадлежат. PostgreSQL приложения остаётся источником истины,
а внешние поисковые и геокодирующие системы подключаются только как заменяемые
адаптеры или features.

## Текущее Состояние

В модуле исполняется первый read scenario `FindAddressById`. Его HTTP route,
MessageBus binding, PostgreSQL adapter и contract artifacts реализованы одним
вертикальным slice. Остальные address operations в
[migration baseline](../../../../docs/api/http-contract-baseline.md) пока
помечены `planned`. Текущие `/geocode` и `/search` остаются переходными scaffold
routes вне модуля и не считаются его контрактом.

Порядок следующих сценариев ведётся в
[roadmap](../../../../docs/migration/implementation-roadmap.md), а устойчивые
границы — в [архитектуре](../../../../docs/architecture.md).

## Сценарий `FindAddressById`

- Назначение: вернуть нормализованные документы адреса по GAR/FIAS UUID,
  `object_id` либо числовому идентификатору Data.Mos.
- Вход: `POST /api/addresses/find-by-id` с JSON-полями `query` и optional
  `count`/`limit`; effective limit ограничен диапазоном `1..20`.
- Публичный контракт: operation `findAddressById` в
  [OpenAPI](../../../../docs/api/openapi.yaml) и request `Addresses / Find By Id`
  в [Postman](../../../../docs/api/postman/geo-registry.postman_collection.json).
- MessageBus: query
  [`FindAddressById`](Capability/FindAddressById/FindAddressById.php), handler
  [`FindAddressByIdHandler`](Capability/FindAddressById/FindAddressByIdHandler.php),
  stable binding `address-registry.find-address-by-id.v1`.
- Поток: [`FindAddressByIdAction`](UserInterface/Http/FindAddressById/FindAddressByIdAction.php)
  выполняет transport mapping и dispatch; handler обращается к scenario-local
  [`FindAddressByIdPort`](Capability/FindAddressById/FindAddressByIdPort.php), а
  [`PostgresFindAddressByIdReader`](Infrastructure/Postgres/PostgresFindAddressByIdReader.php)
  нормализует строки `address_search`/`address_aliases` в typed
  [`AddressDocument`](Capability/AddressDocument.php).
- Состояние и эффекты: только чтение application PostgreSQL; записи, публикации
  событий и внешние HTTP-вызовы отсутствуют.
- Результаты: `200` с найденными suggestions либо пустым списком, `400` для
  malformed transport input, `503/search_unavailable` при storage failure.
- Cancellation/recovery: HTTP boundary создаёт ограниченный timeout budget,
  MessageBus context передаёт cancellation в reader, где она проверяется до
  запроса, при чтении строк и после него; автоматического retry нет, повтор
  безопасен как read-only operation.
- Проверки: [Unit mapper](../../../tests/Unit/Module/AddressRegistry/UserInterface/Http/FindAddressByIdRequestMapperTest.php),
  [Scenario dispatch](../../../tests/Scenario/Module/AddressRegistry/FindAddressByIdScenarioTest.php),
  [PostgreSQL integration](../../../tests/Integration/Module/AddressRegistry/PostgresFindAddressByIdReaderIntegrationTest.php)
  и [HTTP contract](../../../tests/Contract/FindAddressByIdHttpContractTest.php).
