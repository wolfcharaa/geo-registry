---
module: AddressRegistry
version: 0.38.1
updated: 2026-11-19
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
Raw `object_params.value` не является online fuzzy-search boundary:
нормализация выбирает параметры по object/type/date и только читает значение,
поэтому schema не поддерживает trigram GIN для этой колонки. Новый прямой
raw-search consumer должен отдельно обосновать и измерить свой index.
Canonical hierarchy также не хранит GIN по `path`/`path_arr`: текущие
normalization, suggestion, integrity и DataMos paths находят hierarchy по PK,
`object_id` и `region_code`, а membership проверяют выражением
`value = ANY(path_arr)`, которое PostgreSQL 17 не обслуживает array GIN.
Будущий containment/fuzzy consumer должен принести совместимый оператор,
проверенный план и отдельную migration вместо профилактического индекса.
Остальные presentation columns canonical GAR tables также не получают
`gin_trgm_ops` без фактического raw fuzzy consumer. Текущий suggestion SQL даже
для `address_objects.name` использует active/current composite candidate set и
фильтрует similarity после него. Search indexes нормализованных
`address_search`, aliases и DataMos остаются частью соответствующих read models.
Lookup актуальной строки в `address_objects`, `houses`, `apartments`, `rooms`,
`steads` и `car_places` обслуживают шесть partial active/current composites по
object identity, update date и id. Отдельные B-tree по start/end/update date и
active/actual flags не являются запасными индексами «на будущее»; новый broad
lifecycle query должен вернуть только подтверждённый своим планом access path.
`prev_id`/`next_id` остаются raw GAR lineage values: их self-FK намеренно сняты,
потому что текущая выгрузка может не содержать связанную версию. Текущие
normalization, search, integrity и recovery paths не обходят эти links, поэтому
отдельные B-tree также не сохраняются «на будущее». Новый version traversal
consumer должен вернуть минимальный индекс собственной migration и подтвердить
его планом/benchmark.
Чтение `object_params` для normalizer выбирает типы `5/6/7/16` по object id,
фильтрует `end_date` и сортирует по `update_date/id`; этот access path обслуживает
partial `idx_object_params_denorm_type_object_update`. Отдельные B-tree по
`start_date`, `end_date` и `update_date` не являются broad-query contract и не
сохраняются без самостоятельного consumer/плана.
`change_id_end` сохраняется как исходное GAR-значение, но не имеет FK: ссылка
может вести в историю изменений за пределами загруженного среза. Текущие
normalization, search, integrity и recovery paths не выполняют по ней join или
traversal, поэтому отдельный `idx_object_params_change_id_end` также не
поддерживается профилактически. Будущий consumer должен вернуть минимальный
access path собственной migration и подтвердить его планом и benchmark.
Дублирующие UUID в `address_objects`, `apartments`, `car_places`, `houses`,
`rooms` и `steads` также остаются canonical raw values, но не несут отдельных
B-tree: нормализация получает UUID через уникальный
`reestr_objects.object_guid`, а unit rows связывает по индексированным числовым
FK. Новый прямой unit-GUID lookup должен сначала подтвердить преимущество перед
маршрутом `reestr_objects.object_guid → id → unit FK`.
`parent_obj_id` и `change_id` административной и муниципальной иерархий также
сохраняются как raw GAR cross-link values. Их FK намеренно сняты, потому что
региональный срез может не содержать родительский объект или запись истории, а
текущие normalization, integrity, recovery и search paths не выполняют по этим
полям join/traversal. Поэтому отдельные B-tree по ним не поддерживаются без
фактического consumer; используемые `object_id` и административный
`region_code` access paths остаются индексированными.
`start_date` и `update_date` обеих hierarchy tables сохраняются как canonical
GAR values, но не имеют отдельных B-tree: текущие rebuild, integrity, DataMos,
search и recovery paths не фильтруют и не сортируют hierarchy rows по этим
полям. `end_date`, напротив, входит в active-current predicates и сохраняет
свои два индекса; новый start/update consumer должен вернуть только нужный
access path с populated plan и read/write benchmark.
`reestr_objects.change_id`, `create_date` и `update_date` также сохраняются как
canonical GAR metadata без отдельных B-tree: runtime, normalization, integrity
и recovery paths по ним не фильтруют и не сортируют. `is_active` проверяется
только после точечного соединения по PK `reestr_objects.id`; в region-50
fixture значение `true` занимает `95.1924%` строк, поэтому отдельный полный
boolean B-tree не обслуживает текущий predicate. PK, unique `object_guid` и
FK/access path `level_id` остаются индексированными. Будущий broad metadata или
inactive-object query должен вернуть только свой измеренный access path;
редкий `false` predicate сам по себе не оправдывает профилактический partial
index без production consumer.

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

- Обычный путь читает XML через `XMLReader` только вперёд. Opt-in parallel
  parser сначала потоково планирует непересекающиеся byte ranges по имени
  depth-1 element, а каждый child повторно разбирает свой fragment через libxml;
  fragment с неверным числом строк или malformed XML не достигает COPY.
- Supervisor сохраняет advisory lock и запускает отдельные worker-процессы с
  собственными PostgreSQL-соединениями. Файлы одной dependency-фазы могут
  обрабатываться параллельно; следующая фаза начинается только после завершения
  всех workers текущей.
- В обычном write mode worker создаёт temporary staging table один раз на
  XML-файл и переиспользует её между batches. Каждый batch одной
  PostgreSQL-транзакцией выполняет native `COPY FROM`, set-based upsert и
  обновляет checkpoint; `ON COMMIT DELETE ROWS` очищает staging после commit.
- Opt-in `--direct-copy` доступен только для `initial-load`: batch выполняет
  native `COPY` сразу в canonical table и продвигает checkpoint той же
  транзакцией, без staging и `ON CONFLICT`. PK, unique и CHECK остаются
  активными; по умолчанию FK также активны, поэтому duplicate, неизвестный
  address-object type или нарушенная ссылка откатывают весь batch.
- Opt-in `--defer-foreign-keys` для direct initial-load фиксирует
  exact definitions/hash только canonical FK и снимает их в той же транзакции,
  что переводит operation из `prepare` в `import`. После index build все FK
  восстанавливаются как `NOT VALID`, затем bounded process pool выполняет
  `VALIDATE CONSTRAINT`, начиная с крупнейших referencing tables и не проверяя
  одновременно два ограничения одной таблицы. Без отдельного PK option primary,
  unique, CHECK и `NOT NULL` в этом режиме не снимаются.
- Opt-in `--defer-primary-keys` требует одновременно `--initial-load`,
  `--direct-copy` и `--defer-foreign-keys`. Snapshot сохраняет exact backing
  index SQL, primary constraint definition и оба SHA-256; FK снимаются раньше
  PK. После raw каждый PK восстанавливается bulk unique build плюс
  `ADD CONSTRAINT ... PRIMARY KEY USING INDEX` и проходит definition,
  validity и ownership gates до начала FK restore. Unique, CHECK и `NOT NULL`
  остаются активными.
- Для одного явно выбранного compatible XML или автоматически для всех
  compatible XML каждой data phase direct initial-load может включить bounded
  batch pipeline. Auto-selection упорядочен по priority, size и path;
  выбранные XML одной фазы последовательно используют общий bounded
  importer, а incompatible siblings могут идти ordinary workers. Без
  `--pipeline-parsers` parent последовательно читает файл
  одним `XMLReader`; с option он передаёт byte ranges parser pool-у, принимает
  нумерованные encoded batches и направляет их в прежний durable COPY pool.
  С `--pipeline-fused-ranges` каждый child кодирует около `1 MiB`, отправляет
  parent только row/byte metadata, ждёт bounded writer permit, затем продолжает
  generator и передаёт row-aligned `1 MiB` chunks напрямую в PostgreSQL. Один
  XML-safe planned range фиксируется одной atomic COPY/checkpoint transaction.
  Это исключает двойную сериализацию rows между процессами и не удерживает весь
  encoded range до writer permit; parent по-прежнему владеет точными in-flight
  limits и метриками. Каждый writer переиспользует PostgreSQL-соединение и
  выполняет direct COPY независимо от порядка завершения соседних ranges.
- `--overlap-table-indexes` запускает отдельный bounded scheduler OS-процессом:
  после завершения последнего manifest-файла конкретной canonical table её
  secondary indexes могут восстанавливаться, пока raw import других таблиц ещё
  продолжается. Raw coordinator и index scheduler не делят event loop или
  process watchers; готовность и завершение raw согласуются через durable
  PostgreSQL state. Admission повторно проверяется транзакционно перед каждым
  index build. Опциональный `--overlap-index-start-percent` откладывает первый
  ранний index build, пока завершённые manifest-файлы не составят заданную
  долю исходных XML-байтов; это устойчивый к разному числу и размеру файлов
  admission barrier, а не wall-clock delay. Во время raw используется
  отдельный лимит `--overlap-index-workers`; после raw обычная index phase
  продолжает с полным `--index-workers` и подбирает оставшийся backlog.
- `--persistent-index-workers` заменяет отдельный Symfony CLI bootstrap для
  каждого index snapshot на bounded Amp process pool. Каждый процесс
  последовательно выполняет несколько index tasks и переиспользует своё
  PostgreSQL-соединение; одноимённый snapshot/result проверяется parent-ом.
  Отдельный scheduler process, запрет параллельных индексов одной таблицы и
  durable `pending/building/completed/failed` recovery state не меняются.
- `--workers` остаётся общим budget активной работы: ordinary file workers,
  range parsers и pipeline writers вместе не превышают лимит. Пока в
  dependency-фазе работают соседние XML, pipeline получает свободные слоты, а
  после их завершения динамически расширяется не выше суммы parser/writer caps.
- Каждый fused pipeline writer одной транзакцией на planned range выполняет COPY, записывает
  непересекающийся committed range в `gar_import_file_batches`, складывает
  только непрерывный префикс в file checkpoint и обновляет точные operation
  counters. Завершение файла запрещено, пока остаётся range backlog или
  `processed_rows` не равен `merged_rows`.
- `gar_import_operations` и `gar_import_files` хранят progress/recovery state;
  `gar_import_files.pipeline_enabled` фиксирует точный набор pipeline XML.
  Legacy single-file operations backfill-ятся migration; `parser_logs` не
  участвует в принятии решений о возобновлении.
- Повтор завершённого неизменного manifest является успешным no-op. Незавершённая
  операция продолжает тот же файл после числа уже зафиксированных строк.
  `write_mode` входит в durable identity operation, поэтому незавершённый
  direct-copy import нельзя продолжить через merge или наоборот.
- Pipeline selection также входит в durable identity: один XML использует его
  file key, multi-file selection — SHA-256 упорядоченного набора file keys. При
  resume persisted selection сверяется целиком, а parser пропускает contiguous
  checkpoint и уже committed ranges впереди него; число parsers/writers и batch
  size можно безопасно изменить, не меняя selection. После восстановления
  непрерывного префикса сложенные ledger ranges удаляются.
- Manifest связывает descriptor, относительный путь, размер и mtime файла.
  Изменённый источник при активном checkpoint требует явного `--restart`.
- `--restart` помечает незавершённую операцию `abandoned` и начинает новую; уже
  зафиксированные canonical rows безопасно проходят повторный upsert.
- Ошибка XML, COPY или merge помечает файл и операцию `failed`, возвращает
  ненулевой exit code и оставляет только ранее завершённые batches. Остальные
  workers текущей фазы получают завершение процесса: их незавершённая транзакция
  откатывается, а новая dependency-фаза не запускается. Temporary staging
  удаляется при закрытии dedicated worker connection.
- `SIGINT`/`SIGTERM` supervisor и потеря PostgreSQL backend завершают текущий
  attempt как `failed` после bounded cleanup. Следующий совместимый запуск
  продолжает тот же operation id, увеличивает attempt и может изменить batch
  size и предел writers; committed ranges не повторяются, незакоммиченный COPY
  откатывается, orphan writer после завершения supervisor не остаётся.
- Неожиданное завершение одного pipeline child при работающем supervisor
  восстанавливается worker pool. Операция может завершиться в том же attempt и
  не требует ручного resume, если потерянная задача успешно выполнена заменой.
- При pipeline-ошибке supervisor прекращает приём новых batches, даёт уже
  отправленным задачам до пяти секунд на завершение и затем принудительно
  останавливает pool. Любой COPY без зафиксированного range откатывается вместе
  с транзакцией; успевшие commit ranges остаются основанием точного resume.
- Advisory lock запрещает два одновременных импорта одного source. Общей
  транзакции на всю выгрузку нет, поэтому partial state является намеренной
  основой resume, а не скрытым rollback всего release.
- Каждый обычный import дополнительно держит shared database advisory lock.
  `--initial-load` получает exclusive lock, требует пустые canonical GAR tables
  и отклоняется до изменения schema, если обнаруживает данные или index/FK
  snapshot backlog. Обычный import также отклоняется при snapshot backlog и не
  позволяет обойти незавершённое восстановление через `--restart`.
- `initial-load` по умолчанию сохраняет PK, unique/exclusion и constraint-owned
  indexes, а определения остальных вторичных индексов фиксирует в
  `gar_import_index_snapshots` и снимает одной транзакцией. После XML import
  bounded pool восстанавливает индексы, начиная с крупнейших heap tables; два
  индекса одной таблицы не строятся одновременно. При deferred PK тот же
  snapshot lifecycle сначала восстанавливает все primary indexes/constraints,
  и только затем допускает FK restore. Затем выполняются `ANALYZE` и fail-closed
  проверка checkpoints, счётчиков и index validity.
- Без deferred FK фазы остаются `prepare → import → index-build → analyze →
  integrity → ready`. С `--defer-foreign-keys` между index build и `ANALYZE`
  добавляются durable `foreign-key-restore → foreign-key-validate`. Snapshot
  хранит статус и attempt каждого ограничения; повтор принимает как откат
  незавершённого `VALIDATE`, так и уже завершённую server-side validation, но
  не допускает `ready` без exact definition parity и `convalidated=true`.
  Успешный `ready` очищает оба snapshot backlog.
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
  отдельное PostgreSQL-соединение и XMLReader; в обычном write mode также
  отдельную temporary staging table.
- `--index-workers` — ширина bounded worker pool для initial-load index build;
  default `2`, диапазон `1..4`. Это execution knob: его можно изменить при
  resume, а scheduler начинает очереди крупнейших heap tables первыми и
  по-прежнему не строит одновременно два индекса одной таблицы.
- `--persistent-index-workers` — opt-in reuse index worker processes и их
  PostgreSQL-соединений между snapshots; требует `--initial-load`. Это
  execution knob и не входит в durable identity: resume можно продолжить с
  ним или без него. Для измеренного dedicated host q73 принял persistent pool
  по двум crossover-парам; unknown/shared host проверяет собственный A/B.
- `--restart` — не продолжать незавершённый checkpoint выбранного source, а
  начать новую операцию. По умолчанию команда возобновляет совместимый manifest.
- `--initial-load` — offline-режим только для новой пустой
  выделенной БД. Временно снимает вторичные неуникальные индексы и восстанавливает
  их bounded pool-ом. Совместное использование с `--restart` запрещено: для
  отказа от recovery state нужно пересоздать disposable database.
- `--direct-copy` — opt-in write mode для `--initial-load`. Пишет batches сразу
  в canonical tables и сохраняет точный committed-row resume, но не выполняет
  UPSERT; без `--initial-load` отклоняется до запуска импорта.
- `--defer-foreign-keys` — opt-in только для
  `--initial-load --direct-copy`. Переносит проверки canonical FK после import,
  затем восстанавливает и валидирует их bounded process pool-ом; flag входит в durable
  identity операции. Region-50 W4 A/B принял режим для измеренного host, но он
  остаётся явным option, а не default для неизвестного schema/storage.
- `--defer-primary-keys` — opt-in только для
  `--initial-load --direct-copy --defer-foreign-keys`. Убирает per-row PK
  maintenance из raw, затем bulk-восстанавливает все primary constraints до
  FK; flag входит в durable identity. Region-50 q113 crossover сократил raw на
  `26.40%`, E2E на `15.16%` и WAL на `32.63%`.
- `--foreign-key-workers` — ширина deferred validation pool, default `2`,
  диапазон `1..4`; значение, отличное от default, требует
  `--defer-foreign-keys`. Это execution knob: при resume его можно изменить,
  durable definitions/checkpoints от ширины не зависят. Для измеренного
  dedicated host принят `4`, но неизвестный или shared host начинает с `2`.
- `--pipeline-file` — точный manifest-relative путь совместимого direct XML
  либо `auto`. Автоматический режим выбирает все XML с одинаковыми stage/target
  columns в data dependency phases выше priority `10`.
  Используется только вместе с `--initial-load`, `--direct-copy` и
  `--pipeline-writers`.
- `--pipeline-writers` — предел process writers для выбранного XML, `1..4` и не
  больше `--workers`. Это верхняя граница: доступная ёмкость динамически
  уменьшается на число обычных file workers текущей dependency-фазы.
- `--pipeline-parsers` — opt-in byte-range parser pool, `1..4`; требует оба
  pipeline option. С `--pipeline-fused-ranges` допустимо `1..8`, потому что
  parsed rows не пересылаются в отдельный writer process. Настроенная сумма
  parsers и writer permits должна помещаться в `--workers`. Это execution knob:
  durable identity и committed ranges от него не зависят.
- `--pipeline-fused-ranges` — оставляет XML decode, COPY-text encode и COPY в
  одном worker, использует row-aligned `1 MiB` transport chunks и одну atomic
  transaction на planned range; разрешает parser width `1..8` и требует
  `--pipeline-parsers`. Writer concurrency всё равно ограничена
  `--pipeline-writers`.
- `--pipeline-file-concurrency` — число одновременно активных XML внутри одной
  dependency phase, `1..2`; значение `2` требует fused ranges и делит единые
  parser/writer/byte limits между обоими файлами.
- `--pipeline-buffer-mib` — общий encoded in-flight COPY window, `16..64 MiB`;
  расширение требует fused ranges.
- `--pipeline-fragment-mib` — общий предел raw/encoded fragment, `8..16 MiB`;
  не может превышать buffer и требует fused ranges.
- `--pipeline-table-aware-files` — при file concurrency `2` группирует XML по
  canonical table: siblings одной таблицы идут параллельно, группы разных
  таблиц закрываются последовательно для раннего table-ready index build.
- `--overlap-table-indexes` — initial-load режим для раннего
  восстановления индексов таблиц, закрытых для дальнейшей raw-записи; требует
  `--initial-load --direct-copy`.
- `--overlap-index-workers` — предел ранних index workers во время raw import,
  default `1`, диапазон `1..4` и не больше `--index-workers`. Без
  `--overlap-table-indexes` значение, отличное от default, отклоняется. После
  raw снова действует полный `--index-workers`.
- `--overlap-index-start-percent` — минимальная доля завершённых исходных
  XML-байтов перед admission первого раннего индекса, default `0`, диапазон
  `0..99`; требует `--overlap-table-indexes`. Значение `0` сохраняет
  немедленный table-ready scheduler, а ненулевой порог позволяет измерять
  поздний overlap без привязки к именам таблиц или времени сервера.
- Pipeline ограничивает не только rows: planned range/transaction ограничен
  `--pipeline-fragment-mib`, parser task queue — общим parser width, а сумма
  prefetched bytes, ожидающих writer permit, — `--pipeline-buffer-mib`. В
  прежнем single-reader пути один oversized row остаётся допустимым и
  обрабатывается отдельным batch.
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

Успех печатает status, operation id, lifecycle mode, write mode, pipeline
target, parser/writer limits, fused-range mode, persistent index-worker mode,
phase, число завершённых файлов,
обработанных и merged
rows, configured file/index workers, восстановленных индексов, признак resume,
число/encoded bytes pipeline
batches, пиковое число/encoded bytes одновременно отправленных batches, режим
FK и число завершённых FK checkpoints. Ошибка печатается в console output и
возвращает exit code `1`.

**Подключение/условие**

- Команда зарегистрирована только `GarImportCliModule` внутри `CliRuntimeModule`.
  `HttpRuntimeModule`, `WorkerRuntimeModule` и MessageBus registry не экспортируют
  импортёр или его blocking `ext-pgsql` connection.
- Runtime обязан иметь `ext-xmlreader` и `ext-pgsql`; перед импортом должны быть
  применены migrations, создающие canonical GAR и operation tables.

### Экспериментальная raw-граница — `Capability/ImportGarRaw`

Оператор запускает отдельный work-orchestrator путь для сравнения только raw
части initial load. Сценарий использует тот же manifest, operation ledger,
advisory locks и direct-COPY schema, но намеренно не восстанавливает индексы и
FK, не выполняет `ANALYZE`/integrity и не переводит operation в `completed`.
Production-команда `gar:import` и её coordinator этим сценарием не заменяются.

**Правила**

- Сценарий всегда создаёт или возобновляет `initial-load/direct-copy`
  operation. Source manifest, pipeline selection, defer flags и committed range
  ledger проверяются теми же `PostgresGarImportStore` и
  `GarInitialLoadStore`, что и в production path.
- Manifest-файлы допускаются строгими группами dependency priority; внутри
  группы сначала выдаются самые большие файлы. Следующая группа не начинается,
  пока предыдущая полностью не завершена.
- Каждый primary process переиспользует одно PostgreSQL-соединение. Ordinary
  XML целиком импортируется в primary, а каждый range выбранного compatible
  pipeline XML выполняется managed child-ом, который также переиспользует одно
  собственное соединение.
- Через managed IPC передаются только сериализуемый file/range locator и
  компактный result со счётчиками. XML fragments и encoded COPY rows остаются
  внутри child process; child сам выполняет parse, encode и atomic direct COPY
  с durable range checkpoint.
- Число одновременно ожидающих range tasks в каждом primary ограничено числом
  его child workers. Topology проверяется до запуска по формуле
  `primary_workers × (1 + child_workers_per_primary) ≤ 16`.
- Raw success разрешён только после durable completion каждого manifest-файла,
  отсутствия range backlog и равенства operation counters
  `rows_processed = rows_merged`. Успешная operation остаётся в
  `status=running`, `phase=import`; index/FK snapshot backlog сохраняется для
  отдельной финализации или удаления disposable database.
- Повтор совместимого запуска продолжает тот же operation и пропускает уже
  завершённые файлы/ranges. Сценарий не предоставляет `--restart`: конфликт
  manifest или durable identity отклоняется fail-closed.
- Ошибка managed task, XML/COPY, неправильный result или сигнал
  `SIGINT`/`SIGTERM` отменяет orchestration, помечает текущий файл/operation
  failed и оставляет только committed checkpoints. Primary и child bootstrap
  закрывают принадлежащие им PostgreSQL-соединения.
- `--defer-primary-keys` разрешён только вместе с
  `--defer-foreign-keys`. Snapshot definitions и hash gates не ослабляются;
  raw-сценарий лишь останавливается до их восстановления.

**Параметры запуска**

- `source` — обязательный поддерживаемый XML или каталог GAR release.
- `--batch-size` — число строк для планирования bounded ranges; default
  `50000`, диапазон `1..100000`.
- `--primary-workers` — число reusable primary processes; default `1`,
  диапазон `1..16` с учётом общего topology budget.
- `--child-workers-per-primary` — reusable managed children каждого primary;
  default `8`, диапазон `1..8` с учётом общего topology budget.
- `--regions` — уникальные коды `1..99` через запятую; без option выбирается
  весь manifest.
- `--pipeline-file` — compatible manifest-relative XML или `auto`; default
  `auto`, который выбирает все compatible data-phase XML.
- `--fragment-mib` — общий предел raw и encoded bytes одной range task;
  default `8`, диапазон `8..16 MiB`.
- `--defer-foreign-keys` — снять и сохранить canonical FK до raw stage.
- `--defer-primary-keys` — дополнительно снять и сохранить primary keys;
  требует `--defer-foreign-keys`.

**Справка с хоста**

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:import:raw --help
```

**Справка внутри runtime-контекста**

```shell
bin/app gar:import:raw --help
```

**Результат запуска**

Успех печатает `status=raw-completed`, `boundary=raw`, operation/phase,
file/row parity, topology budget, pipeline/range counters, serialized managed
IPC bytes, snapshot backlog, defer flags, resume marker и время raw stage.
Ошибка печатается в console output и возвращает exit code `1`. Failure внутри
orchestrator дополнительно печатает bounded JSON evidence: kind, process/task
origin, canonical work id, attempt, type/message/location, до пяти frames и
трёх causes с explicit omitted counters. Args, payload, env, locals и object
state в этот контракт не входят.

**Production-финализация raw-операции**

После raw success обычный `gar:import` с теми же source, regions, batch size,
initial-load/direct-copy mode, pipeline selection и defer-флагами возобновляет
ту же durable operation (`resumed=yes`). Завершённые XML и ranges повторно не
импортируются: команда восстанавливает primary/secondary indexes и foreign
keys, выполняет `ANALYZE`, integrity gate и переводит operation в
`status=completed`, `phase=ready`. Это две явные CLI-стадии; raw success сам по
себе не означает готовность production database.

**Подключение/условие**

- `GarWorkOrchestratedRawImportAdapter` реализует отдельный
  `ImportGarRawPort`; production `ImportGarPort` остаётся связан с
  `GarXmlImportAdapter`, который также завершает совместимую raw-операцию.
- Команда зарегистрирована только в `GarImportCliModule` CLI runtime и требует
  те же migrations, `ext-xmlreader`, `ext-pgsql`, `ext-pcntl` и disposable
  empty database, что и direct initial load.

### Выбор Кандидата Для Bounded Pipeline — `Capability/ImportGar/GarImportPlanner`

Оператор до импорта проверяет фактический manifest выбранного source и получает
упорядоченный список XML, совместимых с direct batch pipeline. Команда
показывает dependency priority и автоматический selection, но не запускает
import и не меняет checkpoints.

**Правила**

- Planner использует тот же catalog и regional filter, что и import, и печатает
  manifest hash, число файлов, суммарные source bytes и число кандидатов.
- Кандидатами становятся все XML, у которых stage и target columns совпадают;
  список сортируется по убыванию размера, затем по пути.
- `auto=yes` отмечает каждый compatible XML data phase. Priority `10` не
  включается автоматически; точный compatible path можно выбрать явно.
- Отсутствующий регион, неподдерживаемый source или некорректный список регионов
  завершают команду с exit code `1`; import state при этом не создаётся.

**Параметры запуска**

- `source` — обязательный путь к поддерживаемому XML или каталогу GAR release.
- `--regions` — уникальные коды `1..99` через запятую. Без опции анализируется
  весь выбранный manifest; с опцией — общие XML и только указанные каталоги.
- Стандартные Symfony Console flags управляют только консолью; их актуальный
  список показывает `--help`.

**Справка с хоста**

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:import:plan --help
```

**Справка внутри runtime-контекста**

```shell
bin/app gar:import:plan --help
```

**Результат запуска**

Успех печатает одну сводную строку manifest и по строке `pipeline_candidate` с
относительным путём и размером. Пустой список кандидатов является успешным
read-only результатом, а не разрешением выбрать произвольный XML.

**Подключение/условие**

- Команда зарегистрирована тем же `GarImportCliModule` только в CLI runtime.
- Planner читает filesystem catalog и не зависит от PostgreSQL import store.

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

### Сводка Импорта И Проекции — `Capability/Status`

Оператор получает один read-only snapshot последнего GAR import, последней
перестройки `address_search` и покрытия выбранных регионов. Снимок показывает,
можно ли продолжать release path, требуется ли recovery и насколько свеж
последний durable progress.

**Правила**

- Import выводит status, operation id, mode, phase, write mode, attempt, batch
  size, source, manifest, file states, processed/merged rows, `updated_at` и
  `progress_age`, а также режим и completed/total counters deferred FK.
- Pipeline выводит target, writer limit, batches/bytes, пиковое окно,
  pending ranges/rows и возраст progress target-файла. Состояние не хранится
  отдельной машиной: оно вычисляется из operation, target file и range ledger.
- Приоритет вычисления: `off` без target; `recovery-required` для failed или
  abandoned operation; `waiting-for-gap` при pending ranges; `completed` после
  завершения; `recovering` для running attempt больше первого; затем `running`
  или `pending` по состоянию target-файла.
- Быстрый режим не сканирует многомиллионную `address_search`: актуальные
  projection/Data.Mos/alias/geocode counters выводятся как `not-scanned`, а
  последний rebuild count остаётся в `checkpoint_rows`. `--exact` выполняет
  точные региональные агрегаты.
- Неизвестная строка `gar_versions` отображается как `gar_version=unknown`:
  manifest hash остаётся доступен и не подменяется вымышленной версией.

**Параметры запуска**

- `--regions` — коды `1..99` через запятую; default `50,77`.
- `--exact` — добавить дорогие точные projection/Data.Mos/alias/geocode counts.

**Справка с хоста**

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:status --help
```

**Справка внутри runtime-контекста**

```shell
bin/app gar:status --help
```

**Результат запуска**

Команда ничего не меняет и возвращает ненулевой exit code при некорректных
регионах или недоступном operational snapshot.

## Release Integrity GAR

### Fail-Closed Проверка Релиза — `Capability/Integrity`

Оператор проверяет, что последний import и rebuild можно принять как основу
release. Команда агрегирует независимые checks и не исправляет найденное
состояние.

**Правила**

- Быстрый gate требует завершённых import/rebuild checkpoints, равных import
  counters, сохранённой rebuild parity, LIST parent, непустых `adm`/`mun`
  projections и маршрутизации строк в physical partitions.
- Последний import обязан иметь пустой `gar_import_file_batches`: любой
  committed range backlog является issue даже при внешне завершённых counters.
- Последний import обязан иметь пустой FK snapshot backlog, а canonical GAR FK
  должны иметь `convalidated=true`; это quick gate и для режима с активными FK,
  и для deferred lifecycle.
- Этот быстрый backlog check не заменяет exact digest, recovery и constraint
  evidence полного benchmark/test gate.
- `--deep` добавляет точные проверки дубликатов, обязательных полей, canonical
  references, владения регионом, полноты active hierarchies и alias references.
  Тяжёлые SQL выполняются не более двух одновременно.
- Любое число issues больше нуля или недоступная SQL-проверка возвращает exit
  code `1`; опции игнорировать issues нет.

**Параметры запуска**

- `--regions` — коды `1..99` через запятую; default `50,77`.
- `--deep` — добавить точные проверки projection и canonical ownership.
- `--only-failed` — не печатать успешные checks.

**Справка с хоста**

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:integrity --help
```

**Справка внутри runtime-контекста**

```shell
bin/app gar:integrity --help
```

**Результат запуска**

Успех означает только прохождение выбранного quick/deep набора на текущем
snapshot. Команда read-only и не удаляет backlog или partial state.

## Текущая Активность GAR

### Оперативный Read-Only Snapshot — `Capability/Activity`

Оператор сопоставляет активные PostgreSQL statements, durable GAR operations,
worker heartbeats и GAR queue backlog в одном снимке. Команда помогает отличить
работающий import от stalled/recovery состояния, но не управляет процессами.

**Правила**

- Snapshot содержит активные statements текущей application database, последние
  `gar_import` и `address_search_rebuild` operations, worker instances без
  `stopped_at` и незавершённые/failed jobs с binding prefix `gar.`.
- Import operation показывает mode, phase, write mode, attempt, batch size,
  steps, rows, `progress_age`, deferred FK mode/counters и ошибку. Отдельная
  pipeline-строка показывает то же inferred state и counters, что `gar:status`,
  включая pending ranges/rows.
- `--limit` ограничивает SQL, worker и queue секции, но не скрывает последние
  две durable target operations.
- Полный control plane остаётся в `worker:status`/`worker:control`;
  `gar:activity` не посылает команд и не меняет operation state.
- Legacy `parser_logs` не читается. Import/rebuild progress берётся только из
  checkpoint tables, worker activity — из versioned MessageBus runtime schema.

**Параметры запуска**

- `--limit` — максимум строк в каждой динамической секции, `1..100`, default
  `20`.

**Справка с хоста**

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:activity --help
```

**Справка внутри runtime-контекста**

```shell
bin/app gar:activity --help
```

**Результат запуска**

Команда read-only; некорректный limit или недоступный snapshot возвращает
ненулевой exit code.

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

## Optional Search Features

### Кэширование повторных поисков — `Feature/SearchCache`

HTTP runtime может сохранить итоговый typed result `SearchAddressPort` в
локальном bounded LRU каждого worker process. Повтор полностью обходит
OpenSearch и PostgreSQL fallback, не меняя MessageBus и HTTP-контракт поиска.

**Правила**

- Feature включается только через `ADDRESS_SEARCH_CACHE_ENABLED=1`; development
  default `0` позволяет измерять backend без скрытого hot cache.
- Identity включает нормализованный query, hierarchy, profile, limit,
  `region_code`, версию формата ключа и операторский namespace.
- Cache хранит не более `ADDRESS_SEARCH_CACHE_MAX_ENTRIES` на один worker;
  успешный result живёт `ADDRESS_SEARCH_CACHE_TTL_SECONDS`, пустой — отдельный
  короткий `ADDRESS_SEARCH_CACHE_EMPTY_TTL_SECONDS`.
- Ошибка и cancellation не создают entry. Concurrent cold misses одного ключа
  внутри worker-а используют один in-flight lookup; разные worker processes не
  разделяют память и могут выполнить по одному cold lookup каждый.
- Cache оборачивает уже собранную цепочку OpenSearch → PostgreSQL fallback,
  поэтому hit не обращается ни к одному backend-у, а miss сохраняет прежнюю
  fallback/cancellation семантику.
- Переключение alias или обновление координат может быть видно через старый
  entry только до TTL. Namespace bump либо restart workers сразу создаёт новую
  локальную generation.

**Вход**

- HTTP остаётся владельцем `Capability/SearchAddress`: `GET
  /api/addresses/search` через `SearchAddressAction`.
- Postman: [HTTP-контракты модуля](../../../../docs/api/postman/geo-registry.postman_collection.json),
  folder `Addresses`, requests `Search` и `Search Missing Query`.

**Подключение/условие**

- Decorator и process singleton store собираются только в `HttpRuntimeModule`;
  CLI и worker graph не получают cache state.
- Лимит записей умножается на `APP_HTTP_WORKERS`; worker recycle естественно
  очищает только локальную часть cache.

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
