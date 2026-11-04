---
module: AddressRegistry
version: 0.4.0
updated: 2026-11-04
language: ru
---

# Модуль AddressRegistry

Модуль владеет чтением нормализованных адресов и последовательным выбором
объектов GAR/FIAS. Главная identity — числовой `object_id` и соответствующий
`object_guid`; административное и муниципальное представления одного объекта
различаются `hierarchy`.

Модуль не владеет HTTP runtime, lifecycle PostgreSQL, Nominatim и OpenSearch.
Canonical GAR tables остаются источником истины, а online-сценарии читают
application-owned `address_search` и связанные иерархии через capability ports.
Импорт и физическая перестройка partitioned projection относятся к отдельным
CLI-сценариям следующих slices.

- **Postman collection:** [HTTP-контракты модуля](../../../../docs/api/postman/geo-registry.postman_collection.json).

## Поиск И Идентификация Адреса

### Поиск адреса по устойчивому идентификатору — `Capability/FindAddressById`

HTTP-клиент запрашивает адрес по GAR/FIAS UUID, `object_id` или поддержанному
числовому идентификатору Data.Mos. Сценарий возвращает все найденные
административные и муниципальные документы в стабильном порядке; отсутствие
объекта является успешным пустым результатом.

**Правила**

- Effective limit ограничивается диапазоном `1..20`; legacy-поля `count` и
  `limit` остаются совместимыми.
- Операция только читает PostgreSQL и не создаёт состояния или внешних эффектов.
- Storage failure и исчерпание cancellation budget возвращаются как
  `503/search_unavailable`; автоматического retry нет.
- Ручной повтор безопасен, потому что операция read-only.

**Вход**

- HTTP: `POST /api/addresses/find-by-id` через `FindAddressByIdAction`; внутри —
  query `FindAddressById`.
- Postman: [HTTP-контракты модуля](../../../../docs/api/postman/geo-registry.postman_collection.json),
  folder `Addresses`, request `Find By Id`.

### Полнотекстовый поиск адреса — `Capability/SearchAddress`

HTTP-клиент ищет нормализованные адресные документы по русскому full-text и
trigram ranking. Сценарий учитывает canonical address и aliases, возвращает
evidence совпавшего alias и применяет детерминированный порядок результатов.

**Правила**

- `q` обязателен, `limit` ограничен диапазоном `1..50`, а `hierarchy` отделяет
  административное представление от муниципального.
- Переданный `region_code` ограничивает projection до ranking; региональный
  downstream path не использует online nationwide `DISTINCT`.
- Отсутствие `region_code` сохраняет только legacy nationwide fallback.
- Exact match, Russian FTS, trigram с порогом `0.3`, level и стабильные
  tie-breakers определяют порядок.
- Storage failure и timeout возвращаются как `503/search_unavailable`; запись,
  автоматический retry и частичное состояние отсутствуют.

**Вход**

- HTTP: `GET /api/addresses/search` через `SearchAddressAction`; внутри — query
  `SearchAddress`.
- Postman: [HTTP-контракты модуля](../../../../docs/api/postman/geo-registry.postman_collection.json),
  folder `Addresses`, requests `Search` и `Search Missing Query`.

## Прогрессивный Выбор Адреса

### Переход по стадиям адреса — `Capability/SuggestAddress`

HTTP-клиент последовательно выбирает административную территорию, улицу и затем
готовый адрес. Parent orchestration проверяет выбранный context, вызывает
вложенный MessageBus-сценарий текущей стадии и возвращает `next_stage` вместе с
новым `parent_id`; стадия `search` переиспользует публичный `SearchAddress`.

**Правила**

- Допустимые стадии — `administrative_area`, `thoroughfare` и `search`; `count`
  ограничен диапазоном `1..7`.
- Первая административная стадия может работать без parent; `thoroughfare` и
  `search` требуют существующий context.
- Неизвестный либо отсутствующий обязательный parent возвращает
  `422/suggestion_context_not_found`; пустая выдача существующей стадии остаётся
  успешным `200`.
- После определения parent все downstream reads получают его `region_code` и
  ограничивают projection до ranking.
- Сценарий только читает PostgreSQL. Timeout/storage failure возвращается как
  `503/suggest_unavailable`; автоматического retry и частичного состояния нет.

**Вход**

- HTTP: `POST /api/addresses/suggest` через `SuggestAddressAction`; внутри —
  query `SuggestAddress`.
- Postman: [HTTP-контракты модуля](../../../../docs/api/postman/geo-registry.postman_collection.json),
  folder `Addresses`, requests `Suggest City`, `Suggest District`,
  `Suggest Thoroughfare` и `Suggest Invalid Parent`.

**Подключение/условие**

- Runtime обязан зарегистрировать parent и все три nested bindings в одном
  MessageBus registry. Физический LIST partition cutover и shadow rebuild
  остаются gate этапа C2; текущая граница использует индексированный
  `address_search.region_code`.

### Определение регионального parent context — `Capability/ResolveSuggestionContext`

Parent orchestration проверяет выбранный объект в требуемой иерархии и получает
его регион, уровень и ближайший район. Неизвестный object не подменяется
fallback-регионом, чтобы следующая стадия не искала по чужой части projection.

**Правила**

- Совпасть должны и `parent_id`, и `hierarchy`; inactive hierarchy node не
  образует context.
- Результат только читается и не требует транзакции.
- Storage failure прерывает весь parent-сценарий; ручной read-only повтор
  безопасен.

**Вход**

- MessageBus: query `ResolveSuggestionContext` через nested dispatch из
  `Capability/SuggestAddress`; собственного HTTP route нет.

### Выбор административной территории — `Capability/SuggestAdministrativeArea`

Parent orchestration ищет регион, район или населённый пункт по имени. Без
parent поиск служит глобальной входной стадией; после выбора parent кандидаты
ограничиваются его регионом и descendants, а город переводит клиента к выбору
улицы.

**Правила**

- Сценарий рассматривает только уровни административных объектов до улицы и не
  возвращает дома.
- При наличии context `region_code` применяется до fuzzy ranking, а parent
  должен присутствовать в hierarchy path кандидата.
- Равные совпадения упорядочиваются по level, имени и `object_id`.
- Пустое совпадение является успешным результатом; автоматический retry
  отсутствует.

**Вход**

- MessageBus: query `SuggestAdministrativeArea` через nested dispatch из
  `Capability/SuggestAddress`; собственного HTTP route нет.

### Выбор улицы — `Capability/SuggestThoroughfare`

После выбора территории parent orchestration ищет улицы только внутри
вычисленного региона и hierarchy path. Найденная улица возвращает
`next_stage=search` и явно сообщает готовность перейти к поиску полного адреса.

**Правила**

- Parent context обязателен; сценарий рассматривает только уровень улицы.
- Региональный predicate применяется до ranking и исключает одноимённые улицы
  другого региона.
- Район в результате берётся из hierarchy path и сохраняется в context
  следующей стадии.
- Операция read-only: пустой результат успешен, storage failure прерывает parent
  flow, ручной повтор безопасен.

**Вход**

- MessageBus: query `SuggestThoroughfare` через nested dispatch из
  `Capability/SuggestAddress`; собственного HTTP route нет.
