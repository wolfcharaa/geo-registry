# Документация Библиотеки

## README

Рекомендуемый порядок:

1. один абзац: какую проблему решает пакет и какова execution model;
2. ситуации, когда пакет действительно нужен;
3. capabilities с ссылками на guides/reference;
4. runtime requirements;
5. реальная команда установки, включая VCS repository до Packagist;
6. минимальный copyable пример или ссылка на него;
7. явная граница ответственности package/host application;
8. порядок чтения документации;
9. compatibility, development checks и license.

Не повторяйте полный runtime contract в README. Не называйте integration
«простой», если для неё требуются registry, container scope, migrations или
process supervisor — перечислите эти обязанности.

## Guides

Один guide решает одну задачу. Он содержит prerequisites, полный bootstrap с
imports, command, ожидаемый результат и следующий шаг. Placeholder functions
обозначайте как application-owned и объясняйте, что они должны вернуть.

Installation example должен соответствовать реальному состоянию distribution:
не показывайте обычный `composer require` без VCS config, если package ещё не
доступен через Packagist.

## Reference

Фиксируйте:

- public types, inputs, outputs и defaults;
- validation timing и exception/result shape;
- scope, lifecycle и resource ownership;
- concurrency, ordering, delivery и cancellation guarantees;
- retry, idempotency и ambiguous outcome;
- compatibility-sensitive aliases, versions, schemas и artifacts;
- intentional omissions и ответственность host application.

Сохраняйте точные термины `at-least-once`, `bindingId`, `scope`,
`correlation`, `lease` и имена public symbols. Русский текст должен
объяснять их потребительское следствие, а не маскировать переводом.

## Семейство Пакетов

Для core/extension пары документируйте dependency direction и владельца каждого
контракта. Installation extension должна включать repositories всех
неопубликованных dependencies. Версии и cross-repository links должны указывать
на совместимую release line.
