---
module: AddressRegistry
version: 0.13.0
updated: 2026-11-07
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
Модуль также владеет состоянием CLI-операций импорта и перестройки производной
региональной projection. Эти blocking adapters доступны только в CLI runtime.

## Async Runtime Boundary

Application registry объявляет две durable точки подключения:

- `address_async` → `postgres/address` для будущих неблокирующих фоновых
  address-сценариев;
- `runtime_async` → `postgres/runtime` для runtime enrichment events.

Это transport boundaries, а не пустые business capabilities. `runtime_async`
содержит production binding `gar.address_coordinates.update` для stable event
alias `gar.address_coordinates.resolved`. Explicit JSON codec v1 входит в общий
registry HTTP/CLI/worker; async binding без codec не позволяет собрать producer
или worker graph.

GAR import, региональная `address_search` rebuild и OpenSearch rebuild остаются
прямыми CLI-only операциями. Они используют blocking COPY/PDO либо долгий
operator-controlled lifecycle и не могут быть подключены к `address_async` без
отдельного решения о process isolation.

Queue/control tables принадлежат application migration history, а worker
lifecycle — `Application/Worker`. Модуль владеет только message/handler/effect
будущего сценария; он не импортирует consumer loop или control plane.

- **Postman collection:** [HTTP-контракты модуля](../../../../docs/api/postman/geo-registry.postman_collection.json).

## Загрузка Данных GAR

### Потоковый импорт XML — `Capability/ImportGar`

Оператор загружает один поддерживаемый GAR XML-файл или каталог выгрузки.
Каталог распознаёт известные семейства, упорядочивает справочники и зависимые
региональные данные, а команда сообщает идентификатор операции и итоговые
счётчики. Сценарий намеренно блокирующий и доступен только в CLI runtime.

**Правила**

- XML читается `XMLReader` только вперёд; каждый worker держит в памяти не
  больше выбранного batch и служебного состояния одного файла. Совокупная
  верхняя граница растёт пропорционально `--workers`.
- Supervisor сохраняет advisory lock и запускает отдельные worker-процессы с
  собственными PostgreSQL-соединениями. Файлы одной dependency-фазы могут
  обрабатываться параллельно; следующая фаза начинается только после завершения
  всех workers текущей.
- Worker создаёт temporary staging table один раз на XML-файл и переиспользует
  её между batches. Каждый batch одной PostgreSQL-транзакцией выполняет native
  `COPY FROM`, set-based upsert и обновляет checkpoint; `ON COMMIT DELETE ROWS`
  очищает staging после успешного commit.
- `gar_import_operations` и `gar_import_files` хранят progress/recovery state;
  `parser_logs` не участвует в принятии решений о возобновлении.
- Повтор завершённого неизменного manifest является успешным no-op. Незавершённая
  операция продолжает тот же файл после числа уже зафиксированных строк.
- Manifest связывает descriptor, относительный путь, размер и mtime файла.
  Изменённый источник при активном checkpoint требует явного `--restart`.
- `--restart` помечает незавершённую операцию `abandoned` и начинает новую; уже
  зафиксированные canonical rows безопасно проходят повторный upsert.
- Ошибка XML, COPY или merge помечает файл и операцию `failed`, возвращает
  ненулевой exit code и оставляет только ранее завершённые batches. Остальные
  workers текущей фазы получают завершение процесса: их незавершённая транзакция
  откатывается, а новая dependency-фаза не запускается. `SIGINT`/`SIGTERM`
  supervisor обрабатывает так же. Temporary staging удаляется при закрытии
  dedicated worker connection.
- Advisory lock запрещает два одновременных импорта одного source. Общей
  транзакции на всю выгрузку нет, поэтому partial state является намеренной
  основой resume, а не скрытым rollback всего release.
- Прямой XML-файл с неизвестным именем отклоняется. При обходе каталога файлы,
  для которых в canonical schema нет descriptor, не импортируются.

**Параметры запуска**

- `source` — обязательный путь к одному поддерживаемому XML-файлу или каталогу
  GAR; относительный путь разрешается в runtime-контексте.
- `--batch-size` — число строк в атомарном COPY/merge/checkpoint batch; default
  `50000`, допустимый диапазон `1..100000`. Меньшее значение чаще фиксирует
  progress, большее уменьшает число round trips ценой памяти текущего batch.
- `--workers` — максимальное число параллельных worker-процессов внутри одной
  dependency-фазы; default `2`, допустимый диапазон `1..16`. Каждый worker имеет
  отдельное PostgreSQL-соединение, XMLReader и temporary staging table.
- `--restart` — не продолжать незавершённый checkpoint выбранного source, а
  начать новую операцию. По умолчанию команда возобновляет совместимый manifest.
- Стандартные Symfony Console flags управляют только консолью; их актуальный
  список показывает `--help`.

**Справка с хоста**

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:import --help
```

**Справка внутри runtime-контекста**

```shell
bin/app gar:import --help
```

**Результат запуска**

Успех печатает status, operation id, число завершённых файлов, обработанных и
merged rows и признак resume. Ошибка печатается в console output и возвращает
exit code `1`.

**Подключение/условие**

- Команда зарегистрирована только `GarImportCliModule` внутри `CliRuntimeModule`.
  `HttpRuntimeModule`, `WorkerRuntimeModule` и MessageBus registry не экспортируют
  импортёр или его blocking `ext-pgsql` connection.
- Runtime обязан иметь `ext-xmlreader` и `ext-pgsql`; перед импортом должны быть
  применены migrations, создающие canonical GAR и operation tables.

## Перестройка Региональной Проекции

### Shadow rebuild — `Capability/RebuildAddressSearch`

Оператор пересобирает `address_search` из активных административной и
муниципальной GAR hierarchies. Новый physical parent и его LIST partitions
строятся рядом с active table, поэтому online search не видит частичных batches.

**Правила**

- `address_search_rebuild_operations` хранит фазу операции, benchmark counters
  и parity result; `address_search_rebuild_regions` хранит атомарный checkpoint
  для каждой пары `(region_code, hierarchy_type)`.
- Верхняя граница hierarchy id фиксируется в начале operation. Запись batch и
  продвижение checkpoint выполняются одной PostgreSQL-транзакцией.
- Shadow parent разделён `PARTITION BY LIST(region_code)`; unique/primary keys
  включают partition key. Региональный predicate online query обеспечивает
  partition pruning до ranking.
- `region_code` берётся только из активной административной GAR hierarchy
  (`AS_ADM_HIERARCHY.REGIONCODE`). ОКАТО остаётся отдельным классификатором и
  не используется для вычисления partition key.
- После build создаются read indexes, выполняется `ANALYZE`, затем проверяются
  завершённость source checkpoints, пропущенные documents, duplicates и
  некорректные region codes. Cutover запрещён при любой ошибке parity.
- `--build-only` оставляет проверенную operation в `ready`, не меняя active
  table. Следующий запуск продолжает её и выполняет atomic rename под
  `ACCESS EXCLUSIVE` lock; interruption до switch не влияет на online reads.
- `--restart` помечает незавершённую operation `abandoned`, удаляет только её
  shadow table и начинает новый snapshot. Advisory lock допускает один rebuild.
- После успешного switch прежняя physical table удаляется; если процесс
  завершится между commit и cleanup, новый active parent уже однозначно выбран,
  а оставшаяся previous table не участвует в read path.
- После partition cutover откат migration к прежнему вычисляемому из ОКАТО
  столбцу запрещён: обратный data path требует отдельного restore/rebuild, а не
  schema-only `migrate:down`.
- Время build, scanned/written rows и physical row count сохраняются как
  benchmark baseline. Canonical GAR partitioning остаётся запрещённым без
  отдельного production-like сравнения этого baseline и query plans.

**Параметры запуска**

- `--batch-size` — число hierarchy rows в одном transaction batch; default
  `50000`, допустимый диапазон `1..1000000`.
- `--build-only` — завершить indexes/parity и остановиться в `ready` до cutover.
- `--restart` — отказаться от существующего checkpoint и построить новый shadow.

**Справка с хоста**

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:address-search:rebuild --help
```

**Справка внутри runtime-контекста**

```shell
bin/app gar:address-search:rebuild --help
```

**Результат запуска**

Команда печатает status, operation id, progress region/hierarchy tasks,
scanned/written rows, итоговый physical row count, elapsed milliseconds и
признак resume. Ошибка возвращает exit code `1`, active table не переключается.

**Подключение/условие**

- `AddressSearchRebuildCliModule` подключён только к `CliRuntimeModule`;
  HTTP/worker graphs и MessageBus registry не содержат blocking rebuild adapter.
- Перед запуском применяются migrations и загружаются canonical GAR tables.
  PostgreSQL runtime обязан предоставлять `pg_trgm` и `ext-pgsql`.

## Операционный Статус GAR

`bin/app gar:status` читает последний import manifest, file checkpoints,
последнюю shadow rebuild operation и региональные checkpoints. По умолчанию
используются регионы `50,77`; другой ограниченный набор задаётся через
`--regions=50,77`.

Быстрый режим не сканирует многомиллионную `address_search`: актуальные
projection/Data.Mos/alias/geocode counters выводятся как `not-scanned`, а
последний зафиксированный rebuild count остаётся в `checkpoint_rows`. Для
release-сверки `--exact` выполняет точные региональные агрегаты:

```shell
bin/app gar:status --regions=50,77 --exact
```

Команда read-only. Неизвестная строка `gar_versions` отображается как
`gar_version=unknown`: import manifest hash при этом остаётся доступен и не
подменяется вымышленной версией релиза.

## Release Integrity GAR

`bin/app gar:integrity --regions=50,77` выполняет быстрый fail-closed
gate: проверяет завершённые import/rebuild checkpoints, сохранённую
rebuild parity, LIST parent, непустые `adm`/`mun` projections и фактическую
маршрутизацию строк в physical partitions.

`--deep` добавляет точные проверки дубликатов, обязательных полей,
canonical GAR references, владения регионом, полноты активных
hierarchies и alias references. Тяжёлые SQL выполняются не более двух
одновременно. Режим предназначен для release window:

```shell
bin/app gar:integrity --regions=50,77 --deep --only-failed
```

Любое число issues больше нуля или недоступная SQL-проверка возвращает
exit code `1`; опции, разрешающей проигнорировать issues, нет.

## Текущая Активность GAR

`bin/app gar:activity` даёт один read-only snapshot для оперативной диагностики:

- активные statements текущей application database из `pg_stat_activity`;
- последние durable `gar_import` и `address_search_rebuild` operations;
- зарегистрированные worker instances без `stopped_at`;
- незавершённые или failed queue jobs с target binding prefix `gar.`.

`--limit` ограничивает каждую динамическую секцию диапазоном `1..100`, default
`20`. Полный worker control plane остаётся в `worker:status` и
`worker:control`; `gar:activity` не посылает команды и не меняет operation
state.

Legacy `parser_logs` намеренно не читается и не является recovery/progress
источником target. Состояние import/rebuild берётся только из их checkpoint
tables, а worker activity — из versioned MessageBus runtime schema.

## Optional Metadata GAR Releases

`bin/app gar:releases` читает официальный metadata endpoint ФНС через native
AMPHP HTTP client. По умолчанию возвращается последний release; `--all` и
`--limit=1..50` показывают ограниченную историю, `--json` даёт
machine-readable output. Response ограничен 4 MiB, timeout — диапазоном
`1..60` секунд; redirects и неявные retry отключены.

Команда только помогает оператору выбрать источник загрузки. Она не скачивает
архив, не меняет import state и не участвует в `gar:integrity`: release с уже
зафиксированным manifest не зависит от доступности внешнего metadata endpoint.
`--url` оставлен для явного operator override и тестового mirror.

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
literal contains fallback. Trigram similarity ранжирует уже отобранные
документы, но не инициирует полный fuzzy scan. Сценарий учитывает canonical
address и aliases, возвращает evidence совпавшего alias и применяет
детерминированный порядок результатов.

**Правила**

- `q` обязателен, `limit` ограничен диапазоном `1..50`, а `hierarchy` отделяет
  административное представление от муниципального.
- Переданный `region_code` ограничивает projection до ranking; региональный
  downstream path не использует online nationwide `DISTINCT`.
- Региональный ключ строки хранится явно и совпадает с активной
  административной GAR hierarchy; префикс ОКАТО не является его fallback.
- Строка без подтверждённого GAR `region_code` не попадает в региональную
  выдачу и должна быть восстановлена управляемым rebuild, а не эвристикой.
- Отсутствие `region_code` сохраняет только legacy nationwide fallback.
- Сначала формируется FTS candidate set; literal contains fallback запускается
  только если FTS вернул меньше запрошенного `limit`. Символы `\`, `%` и `_`
  во входе не интерпретируются как SQL wildcard.
- Exact match, Russian FTS, trigram ranking, level и стабильные tie-breakers
  определяют порядок только внутри candidate set.
- HTTP PostgreSQL pool устанавливает `statement_timeout` из
  `HTTP_TIMEOUT_SECONDS`; CLI/import/worker pool не получает этот лимит.
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
- Parent context и `address_search` используют один региональный классификатор
  `AS_ADM_HIERARCHY.REGIONCODE`, поэтому ОКАТО не может направить следующую
  стадию в другую physical partition.
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
  MessageBus registry. После C2 active `address_search` является LIST-partitioned
  projection, поэтому обязательный downstream `region_code` выбирает одну
  physical partition до построения candidate set.

### Определение регионального parent context — `Capability/ResolveSuggestionContext`

Parent orchestration проверяет выбранный объект в требуемой иерархии и получает
его регион, уровень и ближайший район. Неизвестный object не подменяется
fallback-регионом, чтобы следующая стадия не искала по чужой части projection.

**Правила**

- Совпасть должны и `parent_id`, и `hierarchy`; inactive hierarchy node не
  образует context.
- Регион context берётся из активной административной GAR hierarchy; ОКАТО и
  ОКТМО не подменяют отсутствующий `REGIONCODE`.
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
следующий этап в зависимости от GAR level: планировочная структура level `7`
оставляет `next_stage=thoroughfare`, а улично-дорожная сеть level `8` возвращает
`next_stage=search` и явно сообщает готовность перейти к поиску полного адреса.

**Правила**

- Parent context обязателен; сценарий рассматривает GAR уровни `7` и `8`:
  элементы планировочной структуры и улично-дорожной сети.
- Региональный predicate применяется до ranking и исключает одноимённые улицы
  другого региона.
- Район в результате берётся из hierarchy path и сохраняется в context
  следующей стадии.
- Операция read-only: пустой результат успешен, storage failure прерывает parent
  flow, ручной повтор безопасен.

**Вход**

- MessageBus: query `SuggestThoroughfare` через nested dispatch из
  `Capability/SuggestAddress`; собственного HTTP route нет.

## Optional Reverse Geocode Feature

### Поиск адреса по координатам — `Feature/ReverseGeocode`

HTTP-клиент передаёт координаты, feature получает ближайшую location через
Nominatim и вызывает публичный core-сценарий `SearchAddress` вложенным
MessageBus dispatch. Совпавший GAR document имеет приоритет; если совпадения
нет, transport возвращает отдельную Nominatim fallback suggestion с пустой GAR
identity.

**Правила**

- `lat` и `lon` обязательны и ограничены географическими диапазонами; `num`
  ограничен `1..20`, `hier` принимает `adm` или `mun`.
- Raw JSON Nominatim нормализуется в `ReverseGeocodeLocation` внутри feature
  adapter и не попадает в Capability или HTTP presenter.
- GAR match выполняется только через public `SearchAddress`; handler не
  инжектирует core handler и наследует cancellation текущего MessageBus scope.
- Nominatim miss является успешным пустым результатом. HTTP/protocol/malformed
  failures и timeout возвращаются как `503/geocoding_unavailable`.
- Fallback location не создаёт `AddressDocument`: nullable GAR identity и
  `search_backend=nominatim` формируются только transport presenter-ом.

**Вход**

- HTTP: `GET /api/addresses/reverse-geocode` через `ReverseGeocodeAction`;
  внутри — query `ReverseGeocode`.
- Postman: [HTTP-контракты модуля](../../../../docs/api/postman/geo-registry.postman_collection.json),
  folder `Addresses`, requests `Reverse Geocode` и
  `Reverse Geocode Missing Coordinates`.

**Подключение/условие**

- Feature binding объявлен только в `config/message_bus_http.php`. Базовый
  `message_bus_registry.php` для CLI/worker его не содержит; удаление binding-а
  из HTTP composition одновременно убирает handler и route.

## Runtime Coordinate Enrichment Feature

### Обновление координат — `Feature/CoordinateEnrichment`

После Nominatim lookup reverse-geocode publisher выбирает первый ранжированный
GAR match. Если у document отсутствует хотя бы одна координата, он публикует
`AddressCoordinatesResolved` в `runtime_async`; HTTP response не ожидает
выполнения business effect.

**Правила**

- Wire identity сохраняет проверенные alias `gar.address_coordinates.resolved`
  и binding ID `gar.address_coordinates.update`; payload кодируется explicit
  codec v1, PHP serialization не используется.
- Event несёт object ID, координаты, Nominatim identity/metadata и optional
  `region_code`. Последний ограничивает PostgreSQL update нужной региональной
  партицией; codec принимает legacy payload без этого поля.
- PostgreSQL обновляет обе hierarchy rows только при неполной паре
  `geo_lat/geo_lon`. Duplicate/replay получает `updated_rows=0` и не
  перезаписывает canonical координаты.
- Отсутствующий object является успешным no-op. Существующий object всегда
  допускает повтор projection effect, поэтому replay после outage чинит
  OpenSearch без повторной записи PostgreSQL.
- OpenSearch `_update_by_query` включается только через
  `OPENSEARCH_COORDINATE_UPDATES_ENABLED=1`. Любая его transport/protocol ошибка
  логируется bounded metadata и не блокирует ACK уже сохранённого canonical
  effect; cancellation при этом не подавляется.
- Automatic retry OpenSearch не добавлен. Ошибка обязательного PostgreSQL
  effect остаётся worker failure и использует snapshot retry policy queue job.

**Проверка**

- Scenario проверяет публикацию только для matched document без координат.
- Real PostgreSQL/OpenSearch test выполняет first delivery, duplicate, outage и
  replay recovery через production codec, producer и worker runtime.

**Эксплуатация**

- Runtime worker читает `postgres/runtime`; OpenSearch updater выключен по
  умолчанию и требует существующего read alias/physical target.
- Legacy bulk forward-geocode `gar:geocode` намеренно не входит в первый release
  scope. Его нельзя считать скрытой обязанностью reverse-geocode flow: массовый
  запуск требует отдельного Nominatim capacity budget, durable checkpoint и
  data-quality gate.
- Drain/restart/cutover и legacy BoltDB gate описаны в
  [worker-cutover.md](../../../../docs/migration/worker-cutover.md).

## Optional OpenSearch Search Feature

### Декоратор полнотекстового поиска — `Feature/OpenSearch`

При включённой projection composition оборачивает PostgreSQL
`SearchAddressPort` декоратором OpenSearch. Feature строит region-aware query,
нормализует hits в `SearchAddressMatch` и возвращает тот же HTTP envelope;
PostgreSQL остаётся fallback и источником истины.

**Правила**

- Feature включается только через `OPENSEARCH_SEARCH_ENABLED=1`; значение по
  умолчанию `0` оставляет core reader без decorator-а.
- Query всегда фильтрует `hierarchy_type`, а переданный `region_code`
  применяется до ranking. Порядок стабилизируют score, level, address и
  `object_id`.
- Запрос с явным либо завершающим номером дома без маркера квартиры/помещения
  получает совместимый с legacy boost для GAR level `10`, чтобы вложенные
  level `11` units не вытесняли базовый дом.
- Projection хранит нормализованные keyword sets улицы, дома, корпуса и
  строения из canonical address и aliases. Exact house key и полная
  street/house/building/structure комбинация получают отдельные boosts; новые
  поля начинают участвовать только после blue-green rebuild индекса.
- Strict index mapping содержит только поля core document, regional key и typed
  aliases. Raw OpenSearch hit/response не покидает feature infrastructure.
- Непустые hits получают `search_backend=opensearch`. Empty result, HTTP
  failure или malformed payload вызывают PostgreSQL fallback; response body не
  попадает в warning log.
- Per-request cancellation передаётся OpenSearch client-у и не подменяется
  fallback-вызовом.
- Typed client operations включают mapping/index lifecycle, bounded `_bulk`,
  settings, refresh, count, search и atomic alias operations. HTTP и каждый
  bulk item проверяются отдельно; неявного retry нет.

**Подключение/условие**

- Decorator собирается только в `HttpRuntimeModule`; CLI и worker продолжают
  получать PostgreSQL `SearchAddressPort`.
- Read alias и versioned physical indexes создаёт CLI rebuild ниже; включение
  online feature после успешного первого cutover остаётся операторским решением.

### Blue-green перестройка projection — `Feature/OpenSearch/Capability/RebuildProjection`

Оператор строит новый physical index из активной региональной
`address_search`, проверяет его и только затем переключает стабильный read
alias. Команда сохраняет имя `opensearch:reindex` из проверенной версии 6, но
выполняет конкурентную загрузку fiber-ами одного AMPHP CLI process без
`proc_open` worker-ов.

**Правила**

- PostgreSQL читается из одной repeatable-read read-only snapshot; документы
  обходятся стабильным курсором `(region_code, id)`.
- Document ID имеет вид `region_code:hierarchy_type:object_id`, поэтому повтор
  того же batch идемпотентен по identity. Команда сама retry не выполняет.
- `--batch-size` ограничивает один NDJSON payload диапазоном `1..10000`, а
  `--concurrency` — число in-flight `_bulk` requests диапазоном `1..32`.
- `OPENSEARCH_REINDEX_MEMORY_LIMIT` применяется командой до чтения snapshot и
  ограничивает совокупную память hydrated documents, NDJSON и in-flight bodies;
  HTTP/worker memory limit не изменяется.
- Target получает имя `<alias>-v<UTC timestamp>`, strict mapping и отдельные
  bulk refresh/replica settings. После загрузки восстанавливаются normal
  settings и выполняется refresh.
- Source count, число подтверждённых bulk items и physical target count должны
  совпасть. Только после этого одна `_aliases` operation удаляет прежние alias
  bindings и добавляет новый.
- Ошибка до cutover не меняет active alias. Неуспешный target сохраняется для
  диагностики и явной cleanup operation; предыдущий успешный index также не
  удаляется автоматически.
- Если transport оборвался уже во время `_aliases`, исход атомарной operation
  считается неоднозначным: команда требует сначала проверить текущий alias и
  не разрешает слепой retry.
- `--no-alias-switch` завершает verified build без cutover.

**Параметры запуска**

- `--batch-size` — документов в одном `_bulk`, default `1000`.
- `--concurrency` — максимум in-flight requests, default `4`.
- `--no-alias-switch` — проверить target, но не переключать alias.

**Справка**

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app opensearch:reindex --help
bin/app opensearch:reindex --help
```

**Результат запуска**

Команда печатает target, source/indexed/target counters, факт alias switch,
предыдущие physical indexes и elapsed milliseconds. Ошибка возвращает exit
code `1`, сообщает сохранённый target и не меняет текущий search.

**Подключение/условие**

- `OpenSearchProjectionCliModule` подключён только к `CliRuntimeModule`; HTTP
  использует тот же native client, но не получает rebuild capability.
- Перед запуском применяются migrations и завершена C2 перестройка
  `address_search`; каждая строка обязана иметь `region_code` в `1..99`.

### Очистка physical index — `Feature/OpenSearch/Capability/CleanupProjection`

Оператор отдельно удаляет старый успешный или неудачный physical index. Rebuild
намеренно не объединён с удалением, чтобы rollback оставался доступным после
cutover.

**Правила**

- Команда принимает только index с versioned prefix текущего configured alias.
- `--confirm` должен дословно совпасть с `--index`.
- Physical index, на который сейчас указывает read alias, удалить нельзя.
- Отсутствующий, чужой, неподтверждённый или active index даёт exit code `1` и
  не меняет OpenSearch state.

**Вход**

```shell
bin/app opensearch:index:cleanup \
  --index=places-v20261104123456789 \
  --confirm=places-v20261104123456789
```

### Операционная диагностика — `Feature/OpenSearch/Capability/ProjectionStatus`

Read-only команда сверяет текущую OpenSearch projection с PostgreSQL source of
truth и перечисляет physical indexes, которые не входят в active alias.

```shell
bin/app opensearch:status
```

Команда возвращает exit code `0`, только если cluster имеет `green` или
`yellow` status без timeout, read alias указывает ровно на один physical index,
в `address_search` нет строк с некорректным `region_code`, а точные source и
target document counts совпадают. Inactive indexes печатаются для последующей
явной cleanup operation, но сами по себе не делают status неуспешным.

`opensearch:status` входит в CLI composition независимо от
`OPENSEARCH_SEARCH_ENABLED`: оператор может проверить projection до включения
online search. HTTP client, PostgreSQL pool и их lifecycle остаются общими с
остальными lazy OpenSearch CLI commands.

Собственного HTTP route, Postman request или MessageBus binding у этих CLI
операций нет.
