# Нагрузочная проверка поиска адресов

Сценарий `tests/load/address-search.js` проверяет настоящий HTTP-контракт
`GET /api/addresses/search` на семи запросах по регионам `50` и `77`. В workload
входят Москва, Московская область и alias-кейс Самаркандского бульвара. Каждый
ответ должен:

- иметь HTTP status `200`;
- содержать успешный JSON envelope для endpoint `search`;
- возвращать тот же `region_code`, профиль `search` и иерархию `adm`;
- содержать хотя бы одно предложение и согласованные `count`/`suggestions`.

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
  ADDRESS_LOAD_VARIANT=postgres
```

Development stack с другим опубликованным портом проверяется так:

```shell
make load-search \
  ADDRESS_LOAD_BASE_URL=http://host.docker.internal:8900 \
  ADDRESS_LOAD_VARIANT=opensearch \
  ADDRESS_LOAD_VUS=16 \
  ADDRESS_LOAD_DURATION=30s
```

`ADDRESS_LOAD_VARIANT` — только evidence label. Фактический backend выбирает
конфигурация приложения: `OPENSEARCH_SEARCH_ENABLED=0` оставляет PostgreSQL,
`OPENSEARCH_SEARCH_ENABLED=1` включает проверенную OpenSearch projection.
Изменяйте backend до запуска теста и подтверждайте его по runtime config/logs;
label не переключает приложение.

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

`server:load-check` остаётся быстрым bounded release gate внутри application
container. `k6` дополняет его смешанным бизнес-сценарием и проверкой JSON, но не
заменяет smoke, parity и relevance tests.
