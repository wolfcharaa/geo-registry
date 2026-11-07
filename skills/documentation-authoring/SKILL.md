---
name: documentation-authoring
description: "Писать и реорганизовывать русскую документацию приложений и PHP-библиотек: короткие README, task-oriented guides, operator runbooks и точные reference-контракты. Использовать для README/docs, инструкций запуска, подключения пакетов и data lifecycle; не использовать для migration diary без пользовательской документации."
---

# Documentation Authoring

Стройте документацию вокруг первого результата читателя, а не вокруг порядка
появления кода или внутренних namespaces.

## Сначала Установите Факты

До редактирования проверьте package metadata, executable `--help`, config
example, Makefile/Composer scripts, public API, tests и существующие runbooks.
Source и исполняемые проверки важнее старого текста. Не придумывайте команды,
defaults, форматы, URL или гарантии.

Если внешняя ссылка или актуальный источник данных может измениться, проверяйте
его по официальному первичному источнику. Не превращайте временный mirror в
рекомендуемый источник.

## Разделите Типы Документов

- Root README отвечает: что это, кому нужно, как установить/запустить, какой
  первый результат и куда идти дальше.
- `docs/guides/` ведут к одной пользовательской цели в порядке выполнения.
- `docs/reference/` фиксируют contracts, defaults, invariants, errors и limits.
- `docs/deployment/` описывает эксплуатацию, backup, restore и release.
- `docs/migration/` хранит переходы, benchmarks и исторические evidence.

Не переносите migration diary, длинный architecture audit, benchmark counters
или полный список внутренних классов в README. Дайте короткое следствие для
пользователя и ссылку на профильный документ.

Пишите объяснения по-русски. Public identifiers, команды, option names,
protocol terms и имена типов оставляйте в исходном виде.

## Выберите Режим

- Для application README, запуска, импорта, нормализации, backup/restore или
  data provenance прочитайте
  [application-documentation.md](references/application-documentation.md).
- Для reusable package README, installation, integration и runtime contracts
  прочитайте [library-documentation.md](references/library-documentation.md).
- Если change затрагивает application и его библиотеки, прочитайте оба
  reference и сохраните один сквозной маршрут между repository.

## Проверка

Перед handoff:

1. Сопоставьте каждую команду и option с executable source или `--help`.
2. Проверьте все относительные Markdown links и anchors.
3. Syntax-check копируемые snippets, когда это возможно.
4. Убедитесь, что README не дублирует полные reference sections.
5. Проверьте, что examples не содержат secrets, private hosts, dumps или
   локальные runtime paths, выданные за универсальные defaults.
6. Запустите repository checks, пропорциональные изменённому слою.

В итоговом отчёте укажите новый порядок чтения, проверенные evidence и
непроверяемые внешние предположения.
