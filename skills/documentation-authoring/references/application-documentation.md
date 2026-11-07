# Документация Приложения

## README

В первых экранах должны быть:

1. назначение и поддерживаемый scope приложения;
2. ссылка на первый запуск;
3. ссылка на получение/подготовку данных, если без них продукт пуст;
4. ссылка на основной API или пользовательский сценарий;
5. явное предупреждение, какие данные и artifacts не входят в release.

После этого допустимы краткие sections по optional services, console и
development checks. Внутреннюю composition, DIC lifetime, детали transport и
benchmark оставляйте в development/reference/migration docs.

## Первый Запуск

Пишите shortest successful path:

- host requirements;
- получение совместимых source/packages;
- создание локального config без secrets;
- build/install/start/migrate;
- health или другой наблюдаемый success;
- честное описание того, что ещё не работает без данных;
- ссылка на следующий guide.

Разделяйте «runtime поднят» и «продукт наполнен данными». Не обещайте готовый
поиск после одной migration.

## Data Lifecycle

Начинайте с карты слоёв:

```text
официальный source -> raw archive -> selected scope -> canonical storage
-> normalized runtime model -> optional projections
```

Для каждого внешнего dataset укажите:

- официальный owner и стабильную landing page/metadata endpoint;
- full/delta/snapshot choice и поддерживаемые форматы;
- где хранить source вне Git;
- checksum/version/date provenance;
- disk/memory prerequisites, если они существенны;
- команды download/verify/extract или честную ручную границу;
- способ ограничить scope до запуска importer;
- import, resume/restart и destructive flags;
- normalization/rebuild и atomic cutover semantics;
- integrity/status checks и ожидаемый наблюдаемый результат;
- порядок optional enrichment и projections;
- backup/restore boundary.

Если importer не умеет фильтровать region/entity, это должно быть явно сказано
до команды запуска. Не описывайте неподдерживаемый raw source как готовый input:
зафиксируйте необходимый промежуточный формат и откуда он берётся.

Source of truth, rebuildable projection и отдельные databases должны быть
различимы. Команда очистки или `--truncate` требует объяснения target и
recovery path.

## Operator Runbooks

Показывайте preconditions, command, success evidence, failure/retry behavior и
rollback. Concrete release counters и измерения храните отдельно как evidence,
если они не являются acceptance threshold текущей операции.
