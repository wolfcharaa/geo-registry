# Target-Owned Parity Fixture

- Версия: `2`
- SHA-256: `af1ed6992f1c772ae23985c71e06f33e4bbf66f05a647524986dc92e4e2a15b8`
- Source: `app/tests/Fixture/Database/address-registry-v2.sql`
- Scope: deterministic integration data, не production seed

## Назначение

Fixture задаёт минимальный стабильный набор данных для сравнения PostgreSQL и
OpenSearch adapters, `find-by-id`, search ranking и staged suggest без полного
импорта GAR. Все названия, идентификаторы и UUID синтетические; production dumps,
персональные данные и Nominatim network state не используются.

## Матрица Данных

| Случай | Регион 77 | Регион 78 | Проверяемое свойство |
| --- | --- | --- | --- |
| Регион | `Альфа` / `770001` | `Бета` / `780001` | Явный корень hierarchy |
| Район | `Центральный` / `770002` | `Центральный` / `780002` | Одинаковое имя в разных регионах |
| Город | `Тестоград` / `770003` | `Тестоград` / `780003` | Региональный suggest context |
| Улица | `Общая` / `770004` | `Общая` / `780004` | Ambiguous nationwide search |
| Дом | `10` / `770005` | `10` / `780005` | UUID lookup и полный hierarchy path |

Объект `770006` (`ул. Старая`) присутствует в canonical tables как неактивный,
но намеренно отсутствует в `address_search`. Для всех активных уровней существуют
отдельные `adm` и `mun` документы. Это позволяет проверять staged hierarchy
selection и региональную фильтрацию на одной projection.

## Lifecycle

`AddressRegistryParityFixture` перед загрузкой сверяет SHA-256 source, а затем
выполняет statements в одной PostgreSQL transaction. `reset()` очищает только
таблицы fixture через `TRUNCATE ... RESTART IDENTITY CASCADE`; schema и migration
history сохраняются для быстрого повторного запуска.

Integration helper разрешает полный schema reset только для базы `integration`
с `application_name=geo-registry-integration`. При несовпадении identity операция
останавливается до destructive SQL.

Любое изменение строк fixture требует одновременно:

1. поднять версию;
2. обновить checksum;
3. скорректировать ожидаемые IDs/documents в integration test;
4. записать compatibility impact в roadmap.

Malformed input не является строкой dataset и будет закреплён в G2 как часть
transport/OpenAPI contract. Fixture предоставляет данные для empty/not-found и
ambiguous результатов, но не определяет HTTP status или error payload.
