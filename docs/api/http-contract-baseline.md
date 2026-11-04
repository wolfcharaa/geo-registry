# HTTP Contract Baseline

- Версия baseline: `0.5.0`
- Дата: `2026-11-04`
- Structural source: [OpenAPI 3.1](openapi.yaml)
- Executable companion: [Postman collection](postman/geo-registry.postman_collection.json)
- Safe local values: [Postman environment](postman/geo-registry.local.postman_environment.json)

## Назначение

Baseline фиксирует публичное поведение, разрешённое к переносу из Spiral-приложения,
до независимой реализации handlers на AMPHP. Код реализации не копируется.
Исходные имена, идентификаторы и координаты в примерах заменены синтетическим
двухрегиональным fixture проекта.

Health operations, `POST /api/addresses/find-by-id`,
`GET /api/addresses/search`, `POST /api/addresses/suggest` и
`GET /api/addresses/reverse-geocode` со статусом `x-migration-status: current`
обслуживаются целевым AMPHP runtime.
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
- Reverse geocode сначала получает typed location из Nominatim, затем вызывает
  публичный `SearchAddress`. Если GAR match отсутствует, Nominatim location
  возвращается отдельной suggestion с nullable GAR identity и
  `search_backend=nominatim`; она не становится core address document.
- Response examples не являются production data и сами по себе не доказывают
  наличие route в target. Исполняемость `find-by-id`, `search`, `suggest` и
  `reverse-geocode`
  подтверждают handler/contract tests и карточки сценариев в `MODULE.md`;
  последующие optional backend/data operations остаются следующими slices.

## Автоматическая Проверка

Contract suite проверяет YAML/JSON parsing, уникальность `operationId`, наличие
schema и examples у JSON responses, отсутствие dangling schema references,
соответствие method/path между OpenAPI и Postman, полное покрытие операций и
отсутствие запрещённых legacy-значений. `make contract` запускает этот gate без
внешних сервисов.
