# Benchmark Явного Разрешения Native PHP Functions

## Цель

Проверить стоимость трёх форм вызова встроенной функции из namespace:

```php
array_sum($values);
```

```php
use function array_sum;

array_sum($values);
```

```php
\array_sum($values);
```

PHP разрешает unqualified function name сначала в текущем namespace и затем
делает fallback к global function. Явный `\` устраняет fallback. Formatter
проекта поэтому использует risky rule `native_function_invocation` только для
`@internal`, `scope=namespaced`, `strict=true`. Перед включением rule source
inventory не нашёл namespaced overrides встроенных функций.

Primary references:
[PHP name resolution rules](https://www.php.net/manual/en/language.namespaces.rules.php),
[PHP-CS-Fixer native_function_invocation](https://cs.symfony.com/doc/rules/function_notation/native_function_invocation.html).

## Воспроизведение

Benchmark находится в
`app/benchmarks/php-native-function-resolution.php`. Каждый вариант получает
один и тот же массив из восьми integer, проходит warm-up `2 000 000` calls,
затем выполняет `20 000 000` calls в каждом из девяти repetitions. Порядок
вариантов циклически меняется; результат проверяется checksum. Основная метрика
— median nanoseconds per call.

```shell
docker/compose/dev.sh run --rm --no-deps \
  app composer benchmark:native-functions

docker/compose/dev.sh run --rm --no-deps \
  app php -d opcache.enable_cli=1 \
  benchmarks/php-native-function-resolution.php
```

Среда: PHP `8.4.27` NTS, Alpine `3.24`, JIT disabled.

## Результат

| CLI OPcache | Variant | Median | ns/call | Delta к FQN |
| --- | --- | ---: | ---: | ---: |
| off | unqualified | `284.564 ms` | `14.228` | `-5.431%` |
| off | imported | `288.985 ms` | `14.449` | `-3.962%` |
| off | fully qualified | `300.908 ms` | `15.045` | baseline |
| on | unqualified | `280.837 ms` | `14.042` | `+11.454%` |
| on | imported | `255.471 ms` | `12.774` | `+1.387%` |
| on | fully qualified | `251.976 ms` | `12.599` | baseline |

Два предыдущих container repeats с OPcache ON дали unqualified delta
`+11.525% / +12.773%`; direct global target воспроизводимо быстрее. При
OPcache OFF знак не подтверждает выигрыш FQN: предыдущие unqualified deltas
были `-2.274% / -3.183%`. Следовательно, разница относится к OPcache call
optimization, а не является безусловным свойством CLI interpreter.

## Решение

В namespaced project code встроенные функции записываются через global
namespace, например `\array_sum()`. Это даёт однозначное разрешение и
воспроизводимый call-level выигрыш в production-подобном OPcache profile.

Microbenchmark не доказывает application E2E gain. Development GAR importer
сейчас использует `opcache.enable_cli=0`, а q67 отклонил глобальный OPcache для
его process fan-out. Поэтому FQN migration проходит correctness/style gate, но
не добавляет заявленный GAR performance gain без отдельного whole-run A/B.
