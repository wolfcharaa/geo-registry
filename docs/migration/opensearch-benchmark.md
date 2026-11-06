# PostgreSQL И OpenSearch: Server Benchmark

- Дата evidence: `2026-11-06`
- Dataset: GAR, регионы `50` и `77`
- Документы: `28 584 562`
- Aliases: `0`; Data.Mos/OSM enrichment ещё не выполнен
- Решение: OpenSearch projection построена, online decorator пока выключен

Этот benchmark отвечает на узкий migration-вопрос: даёт ли OpenSearch пользу
поверх уже оптимизированной региональной PostgreSQL projection. Результаты не
следует переносить на другой dataset, mapping или workload без повторного
измерения.

## Конфигурация

- host: `24 CPU`, `58.5 GiB RAM`;
- PostgreSQL pool: `10` connections;
- `address_search`: LIST partitions регионов 50/77, `24 GiB` вместе с indexes;
- OpenSearch `3.9.0`: `2` primary shards, `0` replicas, heap `8 GiB`;
- OpenSearch primary store после merge: `5.3 GiB`;
- search limit: `10`, hierarchy: `adm`, регион задавался явно;
- каждый sequential результат — 10 прогретых запросов после отдельного warm-up.

## Rebuild

Blue-green rebuild с `--batch-size=10000 --concurrency=16` завершился за
`361 570 ms`: source/index/target parity равна `28 584 562`, alias `places`
переключён атомарно. Средний end-to-end throughput составил около
`79 000 документов/с`.

Stress-профиль при стандартном PHP `memory_limit=128M` исчерпал память после
примерно `3.8 млн` документов. Alias при этом не переключился, partial target
был удалён guarded cleanup-командой. Для reindex введён отдельный
`OPENSEARCH_REINDEX_MEMORY_LIMIT=512M`; этот budget применяется только выбранной
CLI-командой и не меняет лимит HTTP/worker runtime.

## Последовательная Задержка

| Запрос | Регион | PostgreSQL avg | OpenSearch avg | PG / OS |
| --- | ---: | ---: | ---: | ---: |
| `Москва Тверская 1` | 77 | 178.5 ms | 67.2 ms | 2.65x |
| `Москва Арбат 10` | 77 | 28.7 ms | 60.4 ms | 0.48x |
| `Москва Ленинградский проспект 37` | 77 | 68.5 ms | 83.2 ms | 0.82x |
| `Московская область Химки Ленинградская 1` | 50 | 91.6 ms | 47.0 ms | 1.95x |
| `Балашиха Советская 10` | 50 | 38.0 ms | 73.0 ms | 0.52x |
| `Масква Тверская 1` | 77 | 65.6 ms, 0 hits | 64.9 ms, 10 hits | semantic gain |

OpenSearch выигрывает не на каждом одиночном запросе. Уже дешёвый региональный
PostgreSQL FTS быстрее сетевого OpenSearch path; OpenSearch окупается на более
тяжёлых запросах и даёт fuzzy recall для опечатки.

## Параллельная Нагрузка

Одинаковый запрос `Москва Тверская 1`, 100 requests, concurrency `16`:

| Backend | Average | p50 | p95 | p99 | Max |
| --- | ---: | ---: | ---: | ---: | ---: |
| PostgreSQL | 435.5 ms | 426.8 ms | 587.2 ms | 638.4 ms | 679.0 ms |
| OpenSearch | 66.4 ms | 70.5 ms | 78.1 ms | 87.9 ms | 89.1 ms |

При pool из 10 PostgreSQL connections OpenSearch дал примерно `6.6x` по
average и `7.5x` по p95. Это основное подтверждённое performance-преимущество:
вынос полнотекстового поиска разгружает PostgreSQL и лучше держит одновременные
тяжёлые запросы.

## Relevance

Top-10 overlap пяти корректно написанных запросов составил `29/50` (`58%`), а
первый результат совпал в трёх случаях из пяти. Fuzzy query полезен для опечаток,
но иногда ухудшает literal precision: для `Химки Ленинградская 1` OpenSearch
поставил `Ленинградское шоссе, влд. 1/1` выше документов точной улицы.

До включения online decorator нужны:

1. representative relevance fixture с ожидаемыми top results;
2. boosts точной фразы, exact/prefix street и номера дома перед fuzzy score;
3. повторная проверка после импорта Data.Mos/OSM aliases;
4. mixed-load test, а не только один тяжёлый запрос;
5. отдельное решение о hybrid policy: OpenSearch primary, PostgreSQL primary с
   fuzzy fallback либо объединение candidates с application-side reranking.

## Решение

PostgreSQL остаётся default backend: он уже достаточно быстр для большинства
региональных запросов, точнее следует literal input и не требует дополнительных
`~9.5 GiB` resident memory OpenSearch process. OpenSearch projection сохраняется
как rebuildable optional read model: она оправдана при высокой конкуренции,
fuzzy/alias search и когда снижение нагрузки на canonical PostgreSQL важнее
стоимости отдельного service.

`OPENSEARCH_SEARCH_ENABLED` остаётся `0` до relevance gate. Наличие построенного
alias само по себе не разрешает production cutover.
