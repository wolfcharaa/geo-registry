# HTTP Contract Baseline

- Версия baseline: `0.3.0`
- Дата: `2026-11-04`
- Structural source: [OpenAPI 3.1](openapi.yaml)
- Executable companion: [Postman collection](postman/geo-registry.postman_collection.json)
- Safe local values: [Postman environment](postman/geo-registry.local.postman_environment.json)

## Назначение

Baseline фиксирует публичное поведение, разрешённое к переносу из Spiral-приложения,
до независимой реализации handlers на AMPHP. Код реализации не копируется.
Исходные имена, идентификаторы и координаты в примерах заменены синтетическим
двухрегиональным fixture проекта.

Health operations, `POST /api/addresses/find-by-id` и
`GET /api/addresses/search` со статусом `x-migration-status: current`
обслуживаются целевым AMPHP runtime. Остальные address operations со статусом
`planned` являются целью следующих parity slices и пока не маршрутизируются.
Переходные `/geocode` и `/search` не входят в публичный AddressRegistry contract.

## Правила Совместимости

- HTTP method, path, обязательность input и перечисленные status codes меняются
  только как осознанное breaking change.
- Поля envelope `success`, `count`, `query`, `suggestions`, `meta` обязательны;
  для ошибки также обязательны `error` и стабильный `error_code`.
- Удаление или переименование поля — breaking change. Новое nullable/optional
  поле допустимо как additive change, если старые клиенты могут его игнорировать.
- `suggestions` упорядочены по релевантности. При равном ranking target обязан
  применять детерминированный tie-breaker; перестановка неэквивалентных элементов
  считается изменением поведения.
- `limit` ограничивает число элементов одной страницы. Cursor/offset pagination
  в baseline отсутствует; её добавление не меняет смысл существующего запроса.
- Empty/not-found для `find-by-id` — успешный ответ `200` с пустым массивом.
- Malformed transport input возвращает `400`; отсутствующий suggest context —
  `422`; недоступность зависимого сервиса — `503` с route-specific error code.
- `region_code` — additive optional filter legacy search route. Региональный
  downstream path передаёт его явно; отсутствие сохраняет совместимый nationwide
  fallback без online `DISTINCT`.
- Response examples не являются production data и сами по себе не доказывают
  наличие route в target. Исполняемость `find-by-id` и `search` подтверждают
  handler/contract tests и карточки сценариев в `MODULE.md`; остальные address
  examples остаются baseline для следующих slices.

## Автоматическая Проверка

Contract suite проверяет YAML/JSON parsing, уникальность `operationId`, наличие
schema и examples у JSON responses, отсутствие dangling schema references,
соответствие method/path между OpenAPI и Postman, полное покрытие операций и
отсутствие запрещённых legacy-значений. `make contract` запускает этот gate без
внешних сервисов.
