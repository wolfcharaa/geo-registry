# Source Release

Версия приложения состоит из application tag и зафиксированных Composer
releases:

- `wolfcharaa/geo-registry` — source release приложения;
- `romanfedorskij/message-bus-async` — Packagist package;
- `romanfedorskij/message-bus-async-worker` — Packagist package.

Для первой линии все три проекта выпущены как `v0.1.0`. Library tags и
Packagist metadata публикуются раньше application tag, потому что
`app/composer.lock` обязан фиксировать их версии, source references и dist
archives.

## Release Gate Библиотек

В каждом library repository:

```shell
composer validate --strict
composer analyse
composer test
git diff --check
git status --short
```

Затем проверить обе языковые редакции документации, `CHANGELOG.md`, лицензию,
package name и GitHub support URLs. Annotated tag ставится только на clean
`main`:

```shell
git tag -a v0.1.0 -m "Release v0.1.0"
git push origin main
git push origin v0.1.0
```

После тега package регистрируется в Packagist. Release gate закрыт только после
того, как официальный P2 endpoint возвращает `v0.1.0`, dist archive и ожидаемый
source reference. Прямые VCS/path repositories больше не являются installation
path приложения.

## Release Gate Приложения

При первом подключении добавьте опубликованные packages из каталога `app/`:

```shell
cd app
composer require \
  'romanfedorskij/message-bus-async:^0.1' \
  'romanfedorskij/message-bus-async-worker:^0.1'
cd ..
```

Для последующих releases обновляйте только выбранную package family и
review-ьте изменения `composer.lock`. Затем:

1. Проверить, что lock содержит Packagist `zip` distributions, а не `path`, и
   фиксирует утверждённые source references.
2. Выполнить:

```shell
make doctor
make check
make contract
make test-integration
git diff --check
git status --short
```

3. Проверить [data lifecycle](../guides/data-lifecycle.md),
   [release parity](../migration/release-parity.md), worker lifecycle и открытые
   operator approvals. Source tag не означает, что production data cutover уже
   выполнен.
4. Создать annotated tag на clean `main` и отправить branch/tag:

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
