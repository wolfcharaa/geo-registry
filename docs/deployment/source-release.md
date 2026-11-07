# Source Release Трёх Репозиториев

Версия приложения состоит из трёх согласованных Git tags:

- `wolfcharaa/message-bus-async`;
- `wolfcharaa/message-bus-async-worker`;
- `wolfcharaa/geo-registry`.

Для первой линии используется один номер `v0.1.0`. Теги библиотек создаются
раньше application tag, потому что `app/composer.lock` обязан фиксировать их
точные commits.

## Release Gate Библиотек

В каждом library repository:

```shell
composer validate --strict
composer analyse
composer test
git diff --check
git status --short
```

Затем проверить `README.md`, `CHANGELOG.md`, лицензию, package name и GitHub
support URLs. Annotated tag ставится только на clean `main`:

```shell
git tag -a v0.1.0 -m "Release v0.1.0"
git push origin main
git push origin v0.1.0
```

Регистрация Composer packages в Packagist является отдельной внешней операцией.
До неё README использует Composer VCS repositories, поэтому GitHub tag уже
достаточен для установки.

## Release Gate Приложения

1. Переключить соседние library checkout на их `v0.1.0` commits.
2. Обновить path references в `app/composer.lock` и проверить совпадение через
   `make server-source-bundle` либо production build preflight.
3. Выполнить:

```shell
make doctor
make check
make contract
make test-integration
git diff --check
git status --short
```

4. Проверить [data lifecycle](../guides/data-lifecycle.md),
   [release parity](../migration/release-parity.md), worker lifecycle и открытые
   operator approvals. Source tag не означает, что production data cutover уже
   выполнен.
5. Создать annotated tag на clean `main` и отправить branch/tag:

```shell
git tag -a v0.1.0 -m "Release v0.1.0"
git push origin main
git push origin v0.1.0
```

## Source Release И Production Release

GitHub tag содержит исходники и документацию, но не содержит Docker image,
PostgreSQL/Nominatim dumps, OpenSearch index, GAR XML или secrets.

Deployable production release дополнительно требует exact application image,
digest, frozen `docker/compose/prod/release.env`, data backup/restore gate и,
если сервер изолирован, offline transport archive. Этот отдельный процесс
описан в [Offline release и rollback](offline-release.md). Нельзя записывать
случайный или непубликованный image digest только ради source tag.
