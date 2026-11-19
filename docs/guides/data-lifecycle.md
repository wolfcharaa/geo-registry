# Получение, Импорт И Нормализация Данных

Результат этого руководства — наполненная application PostgreSQL с canonical
GAR и partitioned `address_search` для регионов `50` и `77`. Data.Mos,
OSM aliases, Nominatim и OpenSearch подключаются после базовой проверки GAR.

Обычный Git/offline release не содержит datasets. Full-data offline release
может по явному флагу включить application PostgreSQL dump; исходные архивы,
распаковки и рабочие backups всё равно хранятся вне repository.

Если уже есть проверенный application PostgreSQL backup, не повторяйте GAR
import: используйте [development bootstrap из готового backup](development-data-bootstrap.md),
а затем заново постройте OpenSearch из восстановленного `address_search`.

## Линейный Production-Путь

Production Compose предоставляет одноразовый maintenance service. После
`make deploy-init` и `make deploy-image` полный fresh-data путь не требует
session exports:

```shell
make data-init
make data-gar-releases
make data-gar-download GAR_URL='<full_xml_url>'
make data-gar-import
make data-address-rebuild
make data-datamos-download   # optional, key берётся из secret-файла
make data-datamos-import     # optional
make data-address-rebuild    # после enrichment
make data-opensearch         # optional projection
make deploy-start
```

Несекретные параметры и resource limits меняются в Compose YAML/ignored
`compose.override.yaml`; `config/prod.secrets.env` содержит только passwords и
API keys. Разделы ниже объясняют происхождение данных, checkpoints и отдельные
низкоуровневые команды development runtime.

## Какие Данные Нужны

| Слой | Источник | Для чего |
| --- | --- | --- |
| Canonical GAR | полная XML-выгрузка ФНС | адресные объекты, дома, помещения, hierarchies |
| `address_search` | rebuild из canonical GAR | быстрый региональный поиск PostgreSQL |
| Data.Mos | официальный реестр адресов Москвы | координаты и московские metadata |
| OSM aliases | подготовленный JSONL с `addr2:*` | альтернативные написания адресов |
| Nominatim | OSM PBF | reverse geocoding |
| OpenSearch | rebuild из `address_search` | optional fuzzy/concurrent read model |

Source of truth приложения — application PostgreSQL. OpenSearch является
перестраиваемой проекцией. Nominatim использует отдельную PostgreSQL database.

## 1. Подготовить Runtime

Сначала выполните [первый запуск](getting-started.md), включая migrations:

```shell
make up
make migrate
```

Для большого import заранее проверьте свободное место и профиль PostgreSQL:

```shell
make postgres-doctor
```

## 2. Выбрать Официальный GAR Release

Приложение читает официальный metadata endpoint ФНС и показывает прямой URL
полной XML-выгрузки:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:releases --json
```

Команда ничего не скачивает. Выберите `full_xml_url`, а не delta archive.
Исходный каталог releases также доступен на
[портале ФИАС ФНС](https://fias.nalog.ru/Updates).

`delta_xml_url` пока является только metadata для будущего incremental
lifecycle. Не применяйте один последний delta-архив поверх произвольной full
выгрузки: delta содержит изменения только очередного release и предполагает
непрерывную цепочку всех предыдущих обновлений. Текущие команды ещё не
фиксируют predecessor release, не обнаруживают пропуск в цепочке и не
доказывают parity с новым full snapshot, поэтому поддерживаемый operator path
остаётся full archive.

Зафиксируйте для provenance:

- `version_id`, дату и URL из команды;
- имя скачанного файла;
- SHA-256 архива;
- дату скачивания.

## 3. Скачать И Проверить Архив

Ниже `<full_xml_url>` — URL из `gar:releases`, а каталог можно заменить на
свой:

```shell
mkdir -p /srv/geo-registry-data/gar
cd /srv/geo-registry-data/gar

curl --fail --location --output gar-full.zip '<full_xml_url>'
sha256sum gar-full.zip
unzip -t gar-full.zip
unzip -q gar-full.zip -d full
```

Полная распаковка значительно больше ZIP. Проверяйте свободное место до
`unzip`; не распаковывайте архив внутри Git workspace.

## 4. Выбрать Регионы 50 И 77 Без Копирования

Оставьте полную распаковку неизменной. `gar:import --regions=50,77` до чтения
XML строит catalog из:

- поддерживаемых общих XML в корне release;
- поддерживаемых XML внутри числовых каталогов `50/` и `77/`;
- без XML из остальных числовых региональных каталогов.

Команда fail-closed отклоняет отсутствующий запрошенный регион. Для одного
XML-файла `--regions` запрещён: региональный scope доказывается структурой
полного release directory. Предварительно копировать десятки гигабайт в
отдельный source tree больше не нужно.

## 5. Импортировать Canonical GAR

Importer использует streaming XMLReader, нормализует XML attributes в
типизированные canonical tables, передаёт rows через PostgreSQL `COPY` во
временные staging tables и запускает несколько worker processes. Каждый batch
фиксирует checkpoint.

```shell
docker/compose/dev.sh run --rm --no-deps \
  --volume /srv/geo-registry-data/gar/full:/data/gar:ro \
  app bin/app gar:import /data/gar \
  --regions=50,77 \
  --batch-size=50000 \
  --workers=4
```

Повтор команды продолжает совместимую незавершённую operation. `--restart`
используйте только если нужно сознательно отказаться от checkpoint. Source,
изменившийся после начала operation, отклоняется.

Для эксперимента на новой пустой выделенной БД доступен `--initial-load`:

```shell
docker/compose/dev.sh run --rm --no-deps \
  --volume /srv/geo-registry-data/gar/full:/data/gar:ro \
  app bin/app gar:import /data/gar \
  --regions=50,77 \
  --batch-size=50000 \
  --workers=4 \
  --index-workers=4 \
  --initial-load
```

Для принятой fresh-load ветки без staging/UPSERT добавьте `--direct-copy`:

```shell
docker/compose/dev.sh run --rm --no-deps \
  --volume /srv/geo-registry-data/gar/full:/data/gar:ro \
  app bin/app gar:import /data/gar \
  --regions=50,77 \
  --batch-size=50000 \
  --workers=4 \
  --initial-load \
  --direct-copy
```

Перед включением batch pipeline получите manifest и подходящие target-файлы
read-only planner-ом:

```shell
docker/compose/dev.sh run --rm --no-deps \
  --volume /srv/geo-registry-data/gar/full:/data/gar:ro \
  app bin/app gar:import:plan /data/gar --regions=50
```

Первая строка содержит manifest hash, число XML и их суммарный размер в bytes;
это не число адресов или canonical rows. `pipeline_candidate` отсортированы по
убыванию размера; `priority` показывает dependency phase, а `auto=yes` — файл,
который выберет автоматический режим. Planner не создаёт operation и не меняет
checkpoints.

Для initial-load на dedicated server предпочтителен автоматический bounded
pipeline: он выбирает все совместимые XML data phases, не смешивает
dependency phases и делит общий process budget с ordinary files:

```shell
docker/compose/dev.sh run --rm --no-deps \
  --volume /srv/geo-registry-data/gar/full:/data/gar:ro \
  app bin/app gar:import /data/gar \
  --regions=50 \
  --batch-size=100000 \
  --workers=12 \
  --index-workers=4 \
  --initial-load \
  --direct-copy \
  --pipeline-file=auto \
  --pipeline-writers=4 \
  --pipeline-parsers=8 \
  --pipeline-fused-ranges \
  --pipeline-file-concurrency=2 \
  --pipeline-buffer-mib=64 \
  --pipeline-fragment-mib=16 \
  --pipeline-table-aware-files \
  --overlap-table-indexes \
  --overlap-index-workers=1 \
  --overlap-index-start-percent=50 \
  --persistent-index-workers \
  --defer-foreign-keys \
  --defer-primary-keys \
  --foreign-key-workers=4
```

Порог `50` принят только для измеренного dedicated NVMe host: ранний index
worker ждёт завершения не менее половины исходных XML-байтов, но по-прежнему
строит индекс только после полного закрытия соответствующей canonical table.
Для неизвестного или shared host безопасный default `0` сохраняет немедленный
table-ready admission, пока собственный crossover A/B не подтвердит late I1.
`--persistent-index-workers` также принят только для измеренного host: вместо
нового CLI bootstrap и соединения на каждый из `77` snapshots он сохраняет до
четырёх Amp worker processes и их PostgreSQL-соединения. Две q73 crossover-пары
сократили E2E на `3.56%` и `2.42%`; durable index recovery и table-aware
concurrency остались прежними.

Вместо `auto` можно явно передать один совместимый XML. Значение — точный
относительный путь из проверенного manifest, а не путь внутри контейнера:

```shell
PIPELINE_FILE='50/AS_HOUSES_PARAMS_20260831_dc096bf8-6bce-4d35-8158-61fe7bdf77e1.XML'
docker/compose/dev.sh run --rm --no-deps \
  --volume /srv/geo-registry-data/gar/full:/data/gar:ro \
  app bin/app gar:import /data/gar \
  --regions=50 \
  --batch-size=50000 \
  --workers=4 \
  --initial-load \
  --direct-copy \
  --pipeline-file="$PIPELINE_FILE" \
  --pipeline-writers=4
```

Имя в примере относится к измеренной выгрузке `2026-09-01 v.288`; для другого
release подставьте его точное имя из соответствующего регионального каталога.
Совместимый XML не требует stage-to-target transformation. В режиме `auto`
справочники priority `10` остаются ordinary, а совместимые файлы каждой
следующей phase проходят через общий bounded importer. `--pipeline-file` и
`--pipeline-writers` обязательны вместе. Opt-in `--pipeline-parsers=1..4`
добавляет byte-range XML parser pool. С `--pipeline-fused-ranges` предел parser
width повышается до `8`: child удерживает parsed rows локально, сообщает parent
только row/byte metadata, ждёт bounded COPY permit и выполняет atomic
COPY/checkpoint без двойной IPC-сериализации payload. Parser и writer counts в
сумме не могут превышать `--workers`. Каждый fragment повторно проверяется
libxml до COPY. На измеренном dedicated host приняты file concurrency `2`,
fragment `16 MiB` и общий writer window `64 MiB`. Общий task admission не даёт
двум producer Fibers заблокироваться до обработки readiness; общий writer
admission ограничивает одновременно и P4, и encoded bytes. Table-aware режим
параллелит siblings одной canonical table, но закрывает группы разных таблиц
последовательно, чтобы table-ready indexes начинались раньше. Development и
production PHP CLI используют `memory_limit=512M`.

Принятый opt-in direct initial-load может перенести проверку canonical foreign
keys после bulk load:

```shell
docker/compose/dev.sh run --rm --no-deps \
  --volume /srv/geo-registry-data/gar/full:/data/gar:ro \
  app bin/app gar:import /data/gar \
  --regions=50 \
  --batch-size=50000 \
  --workers=4 \
  --initial-load \
  --direct-copy \
  --defer-foreign-keys \
  --defer-primary-keys \
  --foreign-key-workers=4
```

`--defer-foreign-keys` разрешён только вместе с `--initial-load --direct-copy`
и остаётся явным option. Region-50 W4 A/B принял его для измеренного host;
pipeline-флаги можно добавить отдельно с теми же значениями, что в предыдущем
примере. `--foreign-key-workers` имеет диапазон `1..4` и безопасный default
`2`; для измеренного dedicated host принят `4`. На другом schema/storage
сначала выполните собственный парный A/B.

`--defer-primary-keys` дополнительно требует `--defer-foreign-keys`: importer
сначала снимает FK, затем PK, чтобы не использовать `CASCADE` и не терять exact
constraint definitions. После raw backing indexes строятся как unique B-tree и
прикрепляются исходными primary constraints; все PK проходят hash, validity и
ownership gates раньше восстановления FK. На измеренном region-50 q113
сократил raw на `26.40%`, E2E на `15.16%` и WAL на `32.63%`. Опция относится
только к disposable empty-database bootstrap; merge/import с `ON CONFLICT`
сохраняет активный PK как unique arbiter.

`--index-workers` управляет только bounded index-build pool (`1..4`, default
`2`) и может быть изменён при resume. Он не ослабляет запрет на одновременную
сборку двух индексов одной таблицы; scheduler начинает очереди крупнейших heap
tables первыми, чтобы длинная очередь не осталась одиночным хвостом. На
измеренном `12C/24T, 58 GiB, NVMe` host I4+LPT сократил index build
`354.755 → 208.455 s`, а multi-file auto pipeline затем сократил raw
`558.872 → 370.380 s` и E2E `804.030 → 638.827 s`; полный протокол — в
[resource benchmark](../migration/gar-import-resource-saturation-benchmark.md).
`--persistent-index-workers` требует `--initial-load`, но не входит в durable
identity операции: при resume execution topology можно изменить. На измеренном
host q73 persistent pool сократил средний E2E `245.233 → 237.915 s`
(`-2.98%`) и средний peak RSS `693.75 → 667.30 MiB`; на неизвестном/shared
host опцию сначала проверяют собственным crossover A/B.
Parallel parser R4 сначала сократил raw `370.380 → 273.849 s`, крупнейший XML
`109.400 → 49.613 s` и E2E `638.827 → 553.456 s`. Two-stage R8 потребовал PHP
`1G`, поднял max RSS до `1 242 MiB` и не улучшил whole raw. Fused R8 устранил
двойную IPC-сериализацию и при штатных `512M` дополнительно сократил raw до
`258.664 s` (`-5.545%`), крупнейший XML до `32.826 s` (`-33.835%`) и E2E до
`513.304 s` (`-7.255%`). All-compatible auto затем применил тот же bounded
transport ко всем совместимым data XML: raw сократился до `190.187 s`
(`-26.473%` к fused R8), E2E — до `458.953 s` (`-10.588%`), max RSS importer
составил `428.5 MiB`. q39 затем удалил неиспользуемый raw-value trigram GIN:
index build сократился `228.471 → 183.966 s`, E2E — до `397.210 s`, а index
storage — примерно на `724.7 MiB` для region-50. q41 дополнительно убрал четыре
неиспользуемых GIN по hierarchy `path/path_arr`: index build сократился
`183.966 → 149.424 s`, E2E — до `366.205 s`, index storage — ещё на
`2 466 037 760 B`. q42 убрал оставшиеся `28` canonical trigram GIN: index build
сократился до `145.245 s`, E2E — до `362.674 s`, index storage — ещё на
`323 084 288 B`. q43 убрал `30` standalone unit lifecycle B-tree, уже заменённых
partial active/current composites: повторная index-фаза сократилась до
`136.046 s`, index storage — ещё на `367 616 000 B`. Измеренный E2E
`370.127 s` содержит не связанную с индексами raw variance. q44 затем убрал
`16` `prev_id`/`next_id` B-tree после ранее снятых self-FK: index build
сократился до `114.900 s`, E2E — до `358.321 s`, index storage — ещё на
`732 004 352 B`. q45 убрал три standalone lifecycle B-tree `object_params`,
заменённых partial denorm composite: повторная index-фаза сократилась до
`108.691 s`, E2E — до `345.505 s`, index storage — ещё на `1 030 930 432 B`.
q46 отдельно убрал `idx_object_params_change_id_end`, не имевший FK или
production query consumer: index-фаза сократилась до `98.366 s`, E2E — до
`336.922 s`, index storage — ещё на `496 091 136 B`.
q47 убрал шесть B-tree по дублирующим UUID unit tables: принятый repeat
сократил index-фазу до `85.301 s`, E2E — до `323.592 s`, index storage — ещё
на `281 010 176 B`; reverse-order q46 control подтвердил index gain `12.472%`.
q48 убрал четыре B-tree по detached `parent_obj_id`/`change_id` обеих GAR
иерархий. Две соседние crossover-пары с обратным порядком дали index gain
`3.553%` и `11.442%`; representative repeat завершил index build за
`78.046 s`, E2E — за `319.045 s`. Unpaired median дала только `0.281%` и
зафиксирована как влияние временного дрейфа host, а не как заявленный gain.
Парный control подтвердил сокращение index storage на `691 732 480 B`.
q49 проверил шесть classifier-code B-tree обеих иерархий. Несмотря на экономию
`630 988 800 B` index storage, обе A/B-пары ухудшили изменяемую index-фазу на
`11.913%` и `14.837%`, поэтому удаление отклонено, а индексы сохранены.
q50 изолировал четыре неиспользуемых B-tree по `start_date`/`update_date`,
оставив оба predicate-bearing `end_date` индекса. Две соседние пары с обратным
порядком сократили index-фазу на `26.053%` и `4.427%`; representative repeat
завершил index build за `76.990 s`, а index storage уменьшился ещё на
`358 817 792 B`. Его E2E `329.456 s` остаётся диагностическим из-за raw
variance и не заявляется как эффект schema change.
Для этого host измеренный профиль — `batch=100000`,
W12/all-compatible R8-fused/P4/I4, table-aware F2, fragment guard `16 MiB`,
writer window `64 MiB`. Прежний standalone `batch=100000` был отклонён, но
q62/q63 повторно проверили его вместе с расширенным fragment: переход G8/M32 к
G16/M64 сократил batches `2979 → 1527`; две пары дали средний raw gain `8.13%`
и E2E `5.54%`. Table-aware F2 против соседнего broad-F2 control сократил
post-raw index tail `27.041 → 10.756 s` и E2E `287.105 → 274.152 s`
(`-4.51%`). R12, P6/M96, F3 и G32/M128 gate не прошли.
Дополнительные P8 writers дали менее `1%`. Отдельные q28–q32 не подтвердили пользу PostgreSQL worker
limits `24/20/8`, I/O concurrency `32/16`, `maintenance_work_mem=4GB` или
`shared_buffers=12GB`; профиль сохраняет `16/12/4`, `200/100`, `2GB` и `8GB`
соответственно. Параметры I/O concurrency управляют prefetch, а не числом
одновременных COPY writers.
Для неизвестного/shared host начинайте с default `2` и повышайте после замеров.

Режим до чтения XML проверяет пустоту всех 23 canonical GAR tables, сохраняет
PK/unique/constraint indexes и временно снимает только вторичные неуникальные
индексы. После import он восстанавливает их bounded pool-ом, не строя два
индекса одной таблицы одновременно, выполняет `ANALYZE` и закрывает внутренний
integrity gate. Фаза и определения индексов хранятся в PostgreSQL, поэтому
повтор команды продолжает `index-build`/`analyze`, если процесс был прерван.
`--restart` с `--initial-load` запрещён; при отказе от незавершённого эксперимента
пересоздайте только эту disposable database. Не используйте режим для активной
или уже наполненной БД и не запускайте приложение до `phase=ready`.

Raw GAR tables предназначены для нормализации, а online search читает
`address_search`, aliases и optional OpenSearch projection. Поэтому текущая
schema не поддерживает trigram GIN на `object_params.value`: нормализатор
выбирает параметры по object/type/date через composite index и только читает
`value`. Также отсутствуют GIN по hierarchy `path/path_arr`: текущий SQL читает
hierarchy через PK/`object_id`/`region_code`, а `value = ANY(path_arr)` не
поддерживается PostgreSQL 17 array GIN. Остальные raw canonical presentation
columns также не несут trigram GIN без фактического fuzzy consumer; normalized
`address_search`, aliases и DataMos сохраняют собственные search indexes. После
актуальных migrations шесть object/unit tables также не несут отдельных
`start_date/end_date/update_date/is_active/is_actual` B-tree: их текущие lookup
contracts обслуживают retained partial active/current composites. Q44 inventory
содержал `127` canonical indexes. Canonical `prev_id`/`next_id`
сохраняются как raw GAR lineage, но после снятия необязательных self-FK не
индексируются без фактического traversal/join consumer. `object_params` также
не несёт standalone `start_date/end_date/update_date` B-tree: текущие reads по
object/type/date/order обслуживает retained partial denorm composite. Integrity
inventory после q46 ожидает `123` canonical indexes. Raw `change_id_end`
сохраняется, но не индексируется без FK или фактического change-history
join/traversal consumer; `change_id`, `object_id` и `type_id` indexes остаются
обязательными FK access paths.
После q47 integrity inventory ожидает `117` canonical indexes. UUID-копии в
шести unit tables сохраняются как raw data, но normalizer читает unique
`reestr_objects.object_guid` и связывает units по сохранённым numeric FK
indexes; отдельный unit-GUID B-tree требует фактического direct consumer.
После q48 integrity inventory ожидает `113` canonical indexes. Raw
`parent_obj_id`/`change_id` обеих hierarchy tables сохраняются, но не несут
FK или отдельного B-tree без фактического cross-link traversal. Индексы по
`object_id` обеих иерархий и административному `region_code` сохранены.
После q50 integrity inventory ожидает `109` canonical indexes. Значения
hierarchy `start_date`/`update_date` сохраняются, но отдельных B-tree не имеют,
пока нет фактического predicate/order consumer. Оба `end_date` индекса
сохранены: current rebuild, integrity и DataMos paths фильтруют по этому полю,
а populated counters подтверждают их использование. q49 не изменил schema:
classifier-code индексы остались после воспроизводимой регрессии index-фазы.
q59 удалил три неиспользуемых `change_history` B-tree и снизил inventory до
`106`. q64 удалил ещё четыре standalone B-tree `reestr_objects` по
`change_id/create_date/is_active/update_date`; текущий inventory — `102`.
В неизменном region-50 fixture `is_active=true` у `7 304 357` из `7 673 256`
registry rows (`95.1924%`), а runtime проверяет флаг только после PK lookup.
PK, unique UUID и `level_id` index сохранены.
Secondary indexes не добавляются «на будущее»: новый raw query должен сначала
подтвердить свой предикат, совместимый оператор и план, затем отдельно измерить
read gain против import/WAL/storage cost.

`--direct-copy` разрешён только вместе с `--initial-load`. Он не создаёт
temporary staging table и не выполняет `ON CONFLICT`: batch записывается native
`COPY` сразу в canonical table, а его file/operation checkpoint фиксируется той
же транзакцией. PK, unique, CHECK и FK остаются активными, поэтому duplicate или
нарушенная ссылка останавливают batch без продвижения checkpoint. Повтор
продолжает тот же `write_mode` и пропускает ровно уже зафиксированное число XML
rows; переключить незавершённую operation обратно на merge нельзя.

Исключение — явно заданный `--defer-foreign-keys`. В `prepare` importer
сохраняет exact catalog definitions и SHA-256 только canonical FK, затем снимает
их одной транзакцией; без `--defer-primary-keys` PK, unique, CHECK и `NOT NULL`
остаются активными. С deferred PK importer затем сохраняет exact definitions и
SHA-256 primary constraint/backing index и снимает PK в той же prepare
транзакции. После raw все PK восстанавливаются и проверяются до FK restore. После
`index-build` фазы `foreign-key-restore` и `foreign-key-validate` возвращают FK
как `NOT VALID` и проверяют их bounded pool-ом, не валидируя одновременно два
ограничения одной таблицы. При `--foreign-key-workers=4` работают до четырёх
процессов, а очередь начинает крупнейшие referencing tables первой.
Статус/attempt каждого FK durable; повтор безопасно
обрабатывает и откатившийся `VALIDATE`, и validation, успевшую завершиться в
PostgreSQL после потери worker. До exact definition parity, завершённых
checkpoints и `convalidated=true` операция не переходит в `ready`.

Pipeline сохраняет каждый независимо committed batch как диапазон XML-строк и
продвигает file checkpoint только через непрерывный префикс. Поэтому batches
могут завершаться не по порядку, а повтор после ошибки пропускает как checkpoint,
так и уже committed ranges впереди него. Файл считается завершённым только при
пустом range ledger и равенстве processed/merged counters. Target является
частью identity незавершённой operation; число pipeline writers и batch size при
resume можно изменить. Число range parsers также является execution knob и
может быть изменено или отключено: durable selection и committed row ranges от
него не зависят.

`SIGINT`/`SIGTERM` supervisor или потеря PostgreSQL backend переводят текущий
attempt в `failed` после bounded cleanup. Повтор той же совместимой команды
продолжает тот же operation id, увеличивает attempt и использует durable
checkpoint/ranges; незакоммиченный COPY откатывается. Неожиданно завершившийся
pipeline child восстанавливается pool-ом и может закончить работу в том же
attempt без ручного resume. Если нужно сознательно отказаться от initial-load
recovery, пересоздайте только disposable database: `--restart` в этом режиме
запрещён.

Обычные dependency-safe XML-файлы по умолчанию распределяет
`GarAsyncFileImporter`: `async-parser` поднимает bounded process pool, один раз
создаёт PostgreSQL connection и Handler graph внутри каждого worker и
переиспользует их для следующих файлов. XML rows и COPY payload остаются внутри
worker; parent получает только проверенный file key. `GarProcessFileImporter`
сохраняет прежний hidden-CLI/proc-open transport как implementation fallback;
это DIC binding, а не дополнительный operator option и не часть durable
operation identity.

Обычные file workers, range parsers и pipeline writers делят единый лимит
`--workers`. В fused-режиме parser и COPY исполняются последовательно в одном
child process, но parent отдельно ограничивает число одновременно допущенных
COPY через `--pipeline-writers`.
При `--pipeline-file-concurrency=2` два XML одной dependency phase делят один
process pool, общий non-blocking task admission и общий byte/writer admission.
`--pipeline-table-aware-files` сохраняет F2 только внутри одной canonical
table, чтобы завершение другой таблицы не задерживало её ранние indexes.
Supervisor сначала оставляет pipeline хотя бы один слот, затем динамически
увеличивает его ёмкость по мере завершения соседних XML, но не выше
суммы parser/writer limits. Метрики `pipeline_batches`, `pipeline_bytes` и пиковое
число/encoded bytes in-flight batches сохраняются в operation и печатаются в
итоговой строке команды.

Обычный `--initial-load` без `--direct-copy` сохраняет staging/UPSERT и остаётся
более консервативным fresh-load вариантом. Обычный режим без `--initial-load`
остаётся единственным поддержанным вариантом для непустой БД.

Это оптимизация только canonical import. После неё по-прежнему обязательны
`gar:address-search:rebuild` и release-проверка `gar:integrity` из следующего
раздела. A/B региона 50 подтвердил end-to-end ускорение на `25.81%`; условия,
delta и ограничения зафиксированы в
[benchmark lifecycle вторичных индексов](../migration/gar-import-index-lifecycle-benchmark.md),
а direct COPY добавил ещё `18.51%` end-to-end и уменьшил WAL на `28.76%`:
[benchmark direct COPY](../migration/gar-import-direct-copy-benchmark.md).
Контрольный cold-cache A/B с физическим iostat подтвердил bounded pipeline с
четырьмя writers: direct COPY `2421.921 → 1925.790 s`, то есть `20.49%` и
`8:16.131` end-to-end выигрыша. Target XML ускорился на `52.49%`:
[benchmark bounded pipeline](../migration/gar-import-pipeline-benchmark.md).
Это измеренная рекомендация для данного host и region-50 manifest, а не новый
default: на другом storage/CPU сначала сравните `2` и `4` writers. Дальнейшие
Deferred FK lifecycle затем сократил тот же W4 полный путь
`1850.893 → 919.935 s` (`50.30%`, `15:30.958`) при exact parity:
[benchmark deferred FK](../migration/gar-import-foreign-key-lifecycle-benchmark.md).
На текущем оптимизированном профиле расширение FK pool `2 → 4` и
largest-table-first queue сократили validation в среднем
`44.155 → 30.959 s`; reverse-order пара дала E2E
`307.686 → 292.742 s` (`-4.86%`) при том же manifest и exact parity.
Дальнейшие ступени — в
[плане оптимизации](../migration/gar-import-optimization-plan.md).

Production-like сравнение `LOGGED` и `UNLOGGED` для региона 50 зафиксировано в
[GAR import UNLOGGED benchmark](../migration/gar-import-unlogged-benchmark.md).
Оно показало более быстрый raw import, но более медленный полный путь после
обязательного возврата durability, поэтому этот режим не входит в штатную
процедуру.

Посмотреть progress:

```shell
docker/compose/dev.sh run --rm --no-deps app bin/app gar:activity
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77
```

Обе команды показывают mode, phase, write mode, attempt, batch size и возраст
durable progress. Pipeline snapshot дополнительно содержит target, batches/bytes,
peak in-flight window и pending ranges/rows. Состояния `pending`, `running`,
`recovering`, `waiting-for-gap`, `recovery-required`, `completed` и `off`
вычисляются из operation, target file и range ledger; отдельной persisted state
machine нет. После успешного import `pending_ranges=0`, processed и merged rows
совпадают, FK snapshot backlog пуст, все canonical FK validated, а
`phase=ready`. `gar:status` и `gar:activity` также показывают режим FK и
completed/total counters; `gar:integrity` проверяет отсутствие FK backlog и
unvalidated canonical constraints.

## 6. Построить Поисковую Проекцию

Canonical tables повторяют структуру GAR. Для runtime search приложение
денормализует активные administrative/municipal hierarchies в
`address_search`, разделённую LIST partitions по `region_code`.

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:address-search:rebuild --batch-size=50000

docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:integrity --regions=50,77 --deep --only-failed

docker/compose/dev.sh run --rm --no-deps app \
  bin/app gar:status --regions=50,77 --exact
```

Rebuild строит shadow table, создаёт indexes, проверяет parity и атомарно
переключает active table. `--build-only` оставляет проверенную shadow table в
состоянии `ready`; следующий обычный запуск выполнит cutover.

## 7. Добавить Data.Mos

Официальный источник — набор `60562`
[«Адресный реестр объектов недвижимости города Москвы»](https://data.mos.ru/opendata/60562).
Приложение само читает официальный `apidata.mos.ru`, фиксирует version/release,
постранично сохраняет JSONL.GZ и создаёт companion manifest с UTC-датой,
числом строк, размером и SHA-256. Сторонний mirror и внешний XML/BSON converter
для поддержанного пути не нужны.

Получите API key в личном кабинете Data.Mos (контракт endpoints описан в
[официальной API-документации](https://apidata.mos.ru/Docs)) и передайте его
только через environment; команда не печатает и не записывает ключ:

```shell
export DATAMOS_API_KEY='<personal-api-key>'
make datamos-download DATAMOS_DOWNLOAD_FLAGS=--dry-run
make datamos-download
unset DATAMOS_API_KEY
```

Для внешнего каталога добавьте `DATA_HOST_PATH=/srv/geo-registry-data`; внутри
него команда сама создаст `datamos/`.

Результат находится вне Git:

```text
docker/data/datamos/
├── addressreestr.jsonl.gz
└── addressreestr.jsonl.gz.manifest.json
```

Для другого host path запустите ту же runtime-команду с writable mount. Полный
контракт и параметры доступны во встроенной справке; default dataset ID уже
равен `60562`:

```shell
bin/app datamos:address-registry:download --help
```

Сначала проверьте parser и manifest на ограниченном наборе без записи:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app datamos:address-registry:import \
  /data/datamos/addressreestr.jsonl.gz \
  --require-manifest --limit=1000 --dry-run
```

Затем загрузите staging и обогатите только регион `77`:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app datamos:address-registry:import \
  /data/datamos/addressreestr.jsonl.gz \
  --require-manifest --batch-size=5000 --truncate --enrich
```

Enrichment сопоставляет `N_FIAS` с GAR UUID, добавляет metadata/coordinates и
создаёт отсутствующие `datamos-only` адреса. Это WAL-heavy maintenance
operation; выполняйте её до OpenSearch rebuild.

## 8. Добавить OSM Aliases

Importer aliases не разбирает произвольный PBF. Ему нужен заранее
подготовленный JSONL/JSONL.GZ с основным `addr:*` и альтернативными
`addr2:*`. Этот artifact относится к legacy enrichment pipeline, а не к
официальному GAR download.

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app osm:address-aliases:import \
  /data/geodata/moscow-address-aliases.jsonl \
  --batch-size=1000 --truncate
```

Importer не создаёт адреса и изменяет только московскую partition. Формат и
контрольные SQL описаны в
[справочнике enrichment](../migration/address-source-enrichment.md).

## 9. Настроить Nominatim

Для Москвы и Московской области подходит OSM PBF Центрального федерального
округа с [Geofabrik](https://download.geofabrik.de/russia/central-fed-district.html).
Зафиксируйте имя и checksum выбранного snapshot и укажите локальный файл:

```dotenv
NOMINATIM_PBF_URL=
NOMINATIM_DATA_PATH=/absolute/host/path/to/pbf-directory
NOMINATIM_PBF_PATH=/data/nominatim/central-fed-district.osm.pbf
NOMINATIM_REPLICATION_URL=
NOMINATIM_UPDATE_MODE=none
```

Nominatim database не заполняет GAR tables и переносится отдельным backup.

## 10. Построить OpenSearch

После финального `address_search`, Data.Mos и aliases:

```shell
docker/compose/dev.sh run --rm --no-deps app \
  bin/app opensearch:reindex --batch-size=10000 --concurrency=8
docker/compose/dev.sh run --rm --no-deps app bin/app opensearch:status
```

`OPENSEARCH_SEARCH_ENABLED=1` включайте только после green parity/status и
relevance check. PostgreSQL остаётся fallback и source of truth.

## 11. Проверить И Сохранить Результат

```shell
make smoke
make smoke-release \
  POSTMAN_ENVIRONMENT=config/release.postman_environment.json
```

Готовый переносимый набор можно собрать вместе с application PostgreSQL:

```shell
make release-offline \
  RELEASE_INCLUDE_APPLICATION_DATA=1 \
  RELEASE_DATA_CONTAINER=<application-postgres-container> \
  RELEASE_DATA_RESERVE_GB=10
```

Команда создаёт и проверяет custom `pg_dump`, связывает его с release commit и
включает как `application-postgres-backup.tar`. Можно передать уже созданный
`RELEASE_DATA_BACKUP_DIR`. Nominatim по-прежнему переносится отдельным backup,
а OpenSearch экспортировать как canonical data не нужно — после `pg_restore`
он перестраивается. Полные команды находятся в
[offline release](../deployment/offline-release.md) и
[runbook backup/restore](../deployment/data-backup-restore.md). Сквозной новый
сервер — checkout tag, shadow restore, rebuild и запуск — описан в
[release deploy](../deployment/release-deploy.md).
