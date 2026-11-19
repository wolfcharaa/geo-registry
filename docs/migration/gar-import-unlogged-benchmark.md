# GAR Import: UNLOGGED Benchmark

- Дата evidence: `2026-11-10`
- Код: `53131cde67038297d6b68a58343a1c203cbf031a`
- Dataset: GAR `2026.09.01`, `v.288`, регион `50` и общие справочники
- Scope: `28` XML-файлов, `24 975 511 491` bytes source, `122 809 172` rows
- Решение: не переводить штатный canonical import в `UNLOGGED`; отдельно
  исследовать lifecycle indexes и fresh-load path

Этот benchmark проверяет узкую гипотезу: станет ли импорт и разбор GAR в разы
быстрее, если canonical tables на время загрузки сделать `UNLOGGED`. Результаты
относятся к указанному dataset, PostgreSQL и текущей реализации importer; их
нельзя переносить на другой storage или схему без повторного замера.

## Условия Эксперимента

Оба прогона выполнены на одном host и одном storage в двух свежих базах данных.
В каждой базе применены одни и те же `26` migrations. Отличался только режим
`23` canonical GAR tables:

- baseline оставался обычным `LOGGED`;
- experimental schema использовала `UNLOGGED` для canonical tables и их
  indexes;
- `gar_import_operations` и `gar_import_files` в обоих вариантах оставались
  `LOGGED`, чтобы checkpoints не теряли durability;
- после experimental import все canonical tables последовательно переведены
  обратно через `ALTER TABLE ... SET LOGGED` в безопасном для foreign keys
  порядке.

Конфигурация прогона:

- PostgreSQL `17.11`;
- `fsync=on`, `synchronous_commit=on`, `full_page_writes=on`;
- `max_wal_size=64GB`, WAL archiving и replicas отсутствуют;
- `gar:import --regions=50 --batch-size=50000 --workers=4`;
- source и параметры importer между прогонами не менялись.

Первый experimental запуск был остановлен и исключён из выборки. Перед
повторным запуском experimental database создана заново, а source pages были
удалены из page cache через `POSIX_FADV_DONTNEED`. `fincore` подтвердил
`0` resident bytes из `24 975 511 491` bytes выбранных XML до старта. Поэтому
ни прерванный прогон, ни прогретый source cache не попали в итоговый результат.

## Результаты

| Этап | Время | Throughput | WAL delta | Размер БД после этапа |
| --- | ---: | ---: | ---: | ---: |
| `LOGGED` import | `3702.854 s` (`61:42.9`) | `33 166 rows/s` | `172.92 GB` | `35.36 GB` |
| `UNLOGGED` import | `3325.352 s` (`55:25.4`) | `36 931 rows/s` | `0.004 GB` | `35.35 GB` |
| `SET LOGGED` finalization | `662.522 s` (`11:02.5`) | — | `27.18 GB` | `31.83 GB` |
| Полный `UNLOGGED -> LOGGED` путь | `3987.874 s` (`66:27.9`) | — | `27.19 GB` | `31.83 GB` |

По времени raw import:

- `UNLOGGED` завершился на `377.502 s` (`6:17.5`) раньше;
- duration уменьшилась на `10.19%`;
- throughput вырос на `11.35%`.

После обязательного возврата durability полный experimental путь оказался на
`285.020 s` (`4:45.0`), или `7.70%`, медленнее baseline. Одновременно общий
объём WAL уменьшился на `84.28%`: с `172 923 463 736` до
`27 188 286 744` bytes. WAL измерялся как cluster-wide LSN delta, поэтому
может включать небольшой объём фоновой активности помимо benchmark database.

Уменьшение размера после `SET LOGGED` является наблюдаемым следствием rewrite
таблиц и indexes, но не означает сокращения логического dataset.

### Срез Физического I/O

На одном 10-секундном интервале фазы `change_history` experimental import дал:

| Метрика | Скорость |
| --- | ---: |
| PostgreSQL physical write | `10.75 MB/s` |
| Чтение XML importer-ом | `8.88 MB/s` |
| Рост размера database | `5.84 MB/s` |
| Обработка строк | `29 992 rows/s` |

Это локальный срез одной фазы, а не средняя скорость всего импорта. Разница
между physical write и ростом database ожидаема из-за indexes, temporary data,
rewrites и write amplification.

## Проверка Корректности

Оба импорта завершились одним результатом:

- `28/28` файлов;
- `122 809 172` processed rows;
- `122 809 172` merged rows;
- одинаковый digest путей файлов и их counters:
  `e31919564f209ba3046dfaee2eead28b`;
- `gar:integrity --regions=50 --deep --only-failed`:
  `13` checks, `0` failed, `0` unavailable;
- после finalization в experimental database осталось `0` `UNLOGGED` tables.

Следовательно, measured difference не объясняется пропущенными файлами,
строками или отказом от финальной durability.

## Вывод

Гипотеза о многократном ускорении не подтвердилась. `UNLOGGED` не меняет
XML parsing и дал текущему raw import только около `10%` выигрыша. Основная
стоимость остаётся в `COPY`, merge/upsert, indexes, constraints и неодинаковой
длительности файлов внутри dependency phases.

Для штатного импорта canonical GAR `UNLOGGED` невыгоден: возврат в `LOGGED`
требует полного rewrite и делает end-to-end путь медленнее baseline. Кроме того,
PostgreSQL не обеспечивает crash safety и replication для `UNLOGGED` relations:
[CREATE TABLE](https://www.postgresql.org/docs/17/sql-createtable.html),
[ALTER TABLE](https://www.postgresql.org/docs/17/sql-altertable.html).

Режим может быть полезен только для disposable shadow storage, если повторный
импорт после crash приемлем, replication не требуется, а `SET LOGGED` не входит
в критический путь. Главный доказанный эффект этого эксперимента — снижение WAL,
а не ускорение в разы.

## Следующие Гипотезы

Этот benchmark не выбирает следующую реализацию. Для отдельного A/B сравнения
остаются:

1. загрузка без secondary indexes с их созданием после initial import;
2. отдельный append-only fresh-load path вместо общего UPSERT path;
3. изменение разбиения больших XML-файлов между workers для уменьшения tail;
4. матрица `batch-size` и `workers` при фиксированных durability settings.

Каждый вариант следует сравнивать на fresh database, с cold source и тем же
набором integrity checks. Общие рекомендации PostgreSQL для bulk loading
описаны в [Populating a Database](https://www.postgresql.org/docs/17/populate.html).

## Cleanup

Обе временные benchmark databases удалены после проверки. Эксперимент работал
в существующем `postgres-data` volume и не создавал отдельного Docker volume;
рабочие volumes PostgreSQL, OpenSearch и Nominatim не удалялись.
