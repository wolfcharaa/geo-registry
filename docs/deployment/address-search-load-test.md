# Нагрузочная проверка поиска адресов

Сценарий `tests/load/address-search.js` проверяет настоящий HTTP-контракт
`GET /api/addresses/search` на `30` уникальных запросах по регионам `50` и `77`:
по десять exact, alias и fuzzy cases. В workload входят Москва, Московская
область, реальные OSM aliases, опечатки и варианты `ё/е`. Каждый ответ должен:

- иметь HTTP status `200`;
- содержать успешный JSON envelope для endpoint `search`;
- возвращать профиль `search` и иерархию `adm`; target дополнительно обязан
  вернуть тот же `region_code`;
- содержать хотя бы одно предложение и согласованные `count`/`suggestions`.

Для каждого case закреплён ожидаемый `object_id`. Target contract требует найти
его в top-10; legacy прогон измеряет ту же expected-match failure rate, но не
падает из-за осознанных ranking/recall различий старой реализации.

Тест запускается отдельным pinned-контейнером `k6`. Он не добавляет benchmark
packages в application image и измеряет HTTP path снаружи application
container. Для production evidence предпочтителен отдельный load-generator
host, чтобы клиент не конкурировал с приложением за CPU и сеть.

## Быстрый запуск

Сначала убедитесь, что подготовлены PostgreSQL projection и, если проверяется
OpenSearch, read alias прошёл parity/status gate. Базовый запуск:

```shell
make load-search \
  ADDRESS_LOAD_BASE_URL=http://host.docker.internal:8080 \
  ADDRESS_LOAD_VARIANT=opensearch
```

Development stack с другим опубликованным портом проверяется так:

```shell
make load-search \
  ADDRESS_LOAD_BASE_URL=http://host.docker.internal:8900 \
  ADDRESS_LOAD_CONTRACT=target \
  ADDRESS_LOAD_VARIANT=opensearch \
  ADDRESS_LOAD_VUS=16 \
  ADDRESS_LOAD_DURATION=30s
```

Legacy runtime сравнивается тем же workload без target-only `region_code`:

```shell
make load-search \
  ADDRESS_LOAD_BASE_URL=http://host.docker.internal:8899 \
  ADDRESS_LOAD_CONTRACT=legacy \
  ADDRESS_LOAD_VARIANT=legacy
```

`ADDRESS_LOAD_VARIANT` — только evidence label. Фактический backend выбирает
конфигурация приложения: `OPENSEARCH_SEARCH_ENABLED=0` оставляет PostgreSQL,
`OPENSEARCH_SEARCH_ENABLED=1` включает проверенную OpenSearch projection.
Изменяйте backend до запуска теста и подтверждайте его по runtime config/logs;
label не переключает приложение. Расширенный relevance gate требует fuzzy
возможности OpenSearch; PostgreSQL fallback не обязан находить все 30 cases.

## Gate и параметры

По умолчанию тест требует:

- нулевой доли HTTP и contract failures;
- `p95 < 750 ms` как общий и отдельный порог для регионов `50`/`77`;
- `p99 < 1500 ms` для всего workload;
- `16` virtual users в течение `30s`.

Параметры можно менять без редактирования сценария:

| Переменная | Default | Назначение |
| --- | ---: | --- |
| `ADDRESS_LOAD_BASE_URL` | `http://host.docker.internal:8080` | Внешний URL приложения |
| `ADDRESS_LOAD_CONTRACT` | `target` | Валидация target или legacy envelope |
| `ADDRESS_LOAD_VARIANT` | `unspecified` | Метка измеряемой конфигурации |
| `ADDRESS_LOAD_VUS` | `16` | Число постоянных virtual users |
| `ADDRESS_LOAD_DURATION` | `30s` | Длительность measured run |
| `ADDRESS_LOAD_TIMEOUT` | `10s` | Timeout одного HTTP request |
| `ADDRESS_LOAD_MAX_P95_MS` | `750` | Общий и региональный p95 gate |
| `ADDRESS_LOAD_MAX_P99_MS` | `1500` | Общий p99 gate |
| `K6_IMAGE` | pinned `grafana/k6:2.3.0` | Явная замена runner image |

Нарушение threshold завершает `make load-search` с ненулевым exit code. Перед
сохранением результата сделайте отдельный warm-up, затем measured run с теми же
host, dataset, revision, worker/pool limits, VU и duration. PostgreSQL и
OpenSearch сравниваются только на одном workload и одинаковых budgets.

Для cache evidence нужны два отдельных запуска одного revision. Backend-only
baseline выполняется с `ADDRESS_SEARCH_CACHE_ENABLED=0`. Затем application
workers перезапускаются с `ADDRESS_SEARCH_CACHE_ENABLED=1`, чтобы cache начинал
с пустого состояния; первый measured run одновременно показывает cold fill и
hot path. Снимайте OpenSearch `query_total` до и после каждого запуска: один
HTTP request может увеличить shard-level counter больше чем на один, поэтому
учитывайте число primary shards.

Текущие 30 cases являются намеренно горячим малым набором. Такой run доказывает
ценность cache для повторов, но не capacity на уникальных пользовательских
строках. Перед production tuning нужен отдельный high-cardinality workload,
который превышает `ADDRESS_SEARCH_CACHE_MAX_ENTRIES`, и mixed distribution с
небольшим популярным head и длинным tail.

Для поиска saturation повторите measured run с `64` VU, не меняя остальные
условия. Небольшой прирост throughput при кратном росте p95 означает, что
система достигла текущего capacity ceiling:

```shell
make load-search \
  ADDRESS_LOAD_BASE_URL=http://host.docker.internal:8900 \
  ADDRESS_LOAD_CONTRACT=target \
  ADDRESS_LOAD_VARIANT=opensearch-64 \
  ADDRESS_LOAD_VUS=64 \
  ADDRESS_LOAD_DURATION=15s \
  ADDRESS_LOAD_MAX_P95_MS=5000 \
  ADDRESS_LOAD_MAX_P99_MS=10000
```

Во время saturation run снимайте `docker stats` application, PostgreSQL и
OpenSearch. Высокий CPU одного компонента, plateau throughput и рост latency
нужно рассматривать вместе; один p95 не указывает причину bottleneck.

Legacy comparison по умолчанию является сравнением готовых продуктов, а не
чистых поисковых движков. В legacy включён application-level query cache с TTL
`300s`; после прогрева он почти полностью обходит PostgreSQL/OpenSearch. Для
backend-only сравнения cache необходимо отключить отдельной контролируемой
конфигурацией и повторить одинаковый workload — hot-cache результаты нельзя
выдавать за скорость legacy OpenSearch.

`server:load-check` остаётся быстрым bounded release gate внутри application
container. `k6` дополняет его смешанным бизнес-сценарием и проверкой JSON, но не
заменяет smoke, parity и relevance tests.
