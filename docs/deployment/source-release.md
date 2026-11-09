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

## Единый Tag-Контракт Приложения

У приложения нет отдельного source tag и второго production tag с одинаковой
версией. Один новый annotated tag одновременно фиксирует исходники и tracked
`docker/compose/prod/release.env`. Tag создаётся только после сборки exact image
и freeze его digest. Существующий tag нельзя перемещать или переиспользовать.

Tag, в котором `RELEASE_VERSION=unreleased` или записан нулевой digest,
является source-only snapshot и не может быть превращён в deployable release
задним числом. Для production delivery нужно выбрать следующую свободную
версию.

При первом подключении опубликованные packages добавляются из каталога `app/`:

```shell
cd app
composer require \
  'romanfedorskij/message-bus-async:^0.1' \
  'romanfedorskij/message-bus-async-worker:^0.1'
cd ..
```

Для последующих releases обновляйте только выбранную package family и
review-ьте изменения `composer.lock`.

## Подготовка Нового Application Tag

Начинайте на clean `main`; ниже `v1.0.0` — пример новой, ещё не существующей
версии:

```shell
export RELEASE_VERSION=v1.0.0
export RELEASE_PLATFORM=linux/amd64

git switch main
test -z "$(git status --porcelain)"
test "$(git branch --show-current)" = main
if git show-ref --verify --quiet "refs/tags/${RELEASE_VERSION}"; then
  echo "Tag already exists: ${RELEASE_VERSION}" >&2
  exit 1
fi
```

До build выполните read-only plan с теми же inputs:

```shell
make release-plan \
  DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry \
  RELEASE_VERSION="${RELEASE_VERSION}" \
  DOCKER_PLATFORM="${RELEASE_PLATFORM}"
```

Plan печатает source OID, clean/branch/tag state, current non-secret config
revision, intended image reference, locked MessageBus OID и доступные Buildx
capabilities. `status=ready` не создаёт tag, image или artifact. Dirty source,
другая branch либо существующий tag дают `status=blocked` до любых mutations.

Проверить source и locked dependencies:

```shell
make doctor
make release-check
make check
make contract
make test-integration
git diff --check
git status --short
```

`app/composer.lock` должен содержать Packagist `zip` distributions, а не
`path`, и фиксировать утверждённые source references. Также проверяются
[data lifecycle](../guides/data-lifecycle.md),
[release parity](../migration/release-parity.md), worker lifecycle и открытые
operator approvals.

Собрать image из текущего source commit:

```shell
make build-production \
  DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry \
  RELEASE_VERSION="${RELEASE_VERSION}" \
  DOCKER_PLATFORM="${RELEASE_PLATFORM}"
```

Команда печатает `APP_IMAGE_DIGEST`. Зафиксировать именно его:

```shell
make freeze-release \
  DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry \
  RELEASE_VERSION="${RELEASE_VERSION}" \
  APP_IMAGE_DIGEST=sha256:<64-hex-digest> \
  DOCKER_PLATFORM="${RELEASE_PLATFORM}"
```

После freeze единственными tracked изменениями обязаны быть exact Compose
image/version и его release identity:

```shell
git status --short
git diff -- docker/compose/prod/compose.yaml docker/compose/prod/release.env
git diff --check
```

Создать отдельный release-коммит и annotated tag:

```shell
git add docker/compose/prod/compose.yaml docker/compose/prod/release.env
git commit -m "CHORE(release): Зафиксирован релиз ${RELEASE_VERSION}"
git tag -a "${RELEASE_VERSION}" -m "Release ${RELEASE_VERSION}"
test "$(git cat-file -t "refs/tags/${RELEASE_VERSION}")" = tag
test "$(git rev-parse HEAD)" = \
  "$(git rev-parse "refs/tags/${RELEASE_VERSION}^{commit}")"
```

Image label указывает на родительский source commit, а tag — на следующий
release-коммит, меняющий только exact image/version в production Compose и
согласованный `release.env`. Release tooling проверяет эту границу и отклоняет
любые дополнительные файлы между двумя commits.

Push branch, tag и image являются отдельными явно подтверждаемыми действиями.
Для connected delivery после registry login:

```shell
make release-publish DOCKER_PLATFORM="${RELEASE_PLATFORM}"
git push origin main
git push origin "${RELEASE_VERSION}"
```

`release-publish` сначала проверяет local exact image, выполняет `docker push`,
а затем независимо читает registry через Buildx и требует точного совпадения
remote platform digest. Только после этого атомарно создаётся ignored
`docker/data/release/<tag>/phase-manifest.txt` со статусом
`registry-published`, release/source OID, config revision, image identity,
actor и UTC verification time. Команда не публикует Git refs: `main` и tag
отправляются только после успешного registry gate.

Если push завершился, но remote digest не подтвердился, phase manifest не
создаётся и Git refs не публикуются. Повторите ту же команду после диагностики;
она обязана подтвердить тот же frozen digest, а не собирать новый image или
перемещать tag.

Phase manifest имеет накопительный `phase_manifest_format=2`. Build записывает
`prepared` и `built`, freeze добавляет config checksum, connected publication —
remote registry evidence. Если `built` либо `registry-published` уже verified,
повторная команда сначала сверяет весь binding и фактический artifact. При
полном совпадении image переиспользуется; при расхождении команда завершается и
не выполняет неявный rebuild или повторный push.

Build, freeze, publication и offline packaging используют один ignored
output lock на пару release/platform. Параллельная операция завершается до
изменения artifacts и показывает metadata владельца. Kernel lock снимается при
завершении процесса, включая аварийное; lock-файл остаётся только как
диагностическое evidence и не блокирует следующий запуск сам по себе.

Read-only retention preview:

```shell
make release-candidates
make release-candidates RELEASE_CANDIDATE_TTL_DAYS=14
```

Команда показывает состояние, protection, TTL и активный lock. Current release,
существующие Git tags и actively locked directories всегда защищены. Preview
не удаляет artifacts; cleanup остаётся отдельной явно подтверждаемой операцией.

Для offline/full-data delivery вместо обязательного registry push собирается
transport archive по [offline release](offline-release.md).

## Source Release И Production Release

GitHub tag содержит исходники и документацию, но не содержит Docker image,
PostgreSQL/Nominatim dumps, OpenSearch index, GAR XML или secrets.

Deployable production release требует frozen application tag, его registry
digest, frozen `docker/compose/prod/release.env`, data backup/restore gate и,
если сервер изолирован, checksummed offline transport archive. Connected gate
проверяет registry digest, а offline gate связывает tag с SHA-256 archive.
Сборка transport описана в
[offline release](offline-release.md), а новый target — в
[сквозном руководстве развёртывания](release-deploy.md). Нельзя записывать
случайный digest только ради tag и нельзя перепривязывать уже опубликованный
tag к более позднему release-коммиту.
