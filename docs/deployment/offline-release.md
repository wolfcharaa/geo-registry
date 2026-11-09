# Offline Release И Rollback

Offline release всегда переносит immutable application runtime. По умолчанию
он code-only; явный `RELEASE_INCLUDE_APPLICATION_DATA=1` дополнительно включает
проверенный logical dump application PostgreSQL. Dump не встраивается в image.
Nominatim и OpenSearch сохраняют отдельный lifecycle: Nominatim переносится
своим backup, OpenSearch полностью перестраивается из PostgreSQL.

Текущий runtime payload содержит только application image. Это offline
application release, а не полный air-gapped bootstrap нового host: exact
PostgreSQL image не входит в archive, а current init/restore tooling явно
выполняет registry pull. Полностью изолированный first install пока не
поддерживается; не обходите gate редактированием versioned scripts. Отдельные
`app-update`, `new-host-core` и optional OpenSearch scopes запланированы в
[roadmap усиления delivery](../migration/deployment-hardening-roadmap.md).

## Артефакты

Code-only flat archive содержит пять файлов в корне:

```text
release-geo-registry-vX.Y.Z.bundle
source-linux-amd64.tar
source-manifest.txt
RELEASE.md
DEPLOY.md
```

Для release identity format 2 добавляется шестой файл
`release-artifact.env`; full-data archive соответственно содержит семь файлов.
Он связывает prepared source OID/config revision с exact image digest и ID и
должен лежать рядом с `source-manifest.txt` в
`docker/data/release/<tag>/` на target.

Рядом создаётся отдельный checksum-файл
`release-geo-registry-vX.Y.Z-offline.tar.sha256`. Он не вкладывается внутрь
проверяемого tar и передаётся по согласованному доверенному каналу вместе с
ним. Без подписи или другого identity binding это `integrity-only`: checksum
сам по себе не подтверждает издателя, если атакующий может заменить и archive,
и checksum.

Full-data вариант содержит шестой файл:

```text
application-postgres-backup.tar
```

`source-linux-amd64.tar` содержит self-contained application image с HTTP и
worker runtime. `source-manifest.txt` связывает release commit, исходный commit
image, source references двух locked MessageBus packages, platform, frozen
tag, config revision, registry digest, local image ID/config digest, размер и
SHA-256 Docker archive и достигнутый assurance level. После `docker load`
tooling требует совпадения загруженного image ID с manifest. Git bundle
содержит application release branch и annotated tag. Библиотеки уже находятся
в `vendor` внутри image; для сборки нового image нужен application repository
и доступ к Composer dist archives, но не соседние library checkout.

Новые payloads используют `manifest_format=3`. Legacy v1/v2 остаются читаемыми
из versioned releases, но v2 не даёт отдельного image-ID proof и не должен
выдаваться за новый manifest contract.

`application-postgres-backup.tar` содержит ровно custom-format
`application-postgres.dump` и его `manifest.txt`. Внешний `source-manifest.txt`
дополнительно фиксирует SHA-256 data archive, dump SHA-256, время backup и
release commit. Архив содержит production data без пароля: передавайте и
храните его как защищённый backup, а не как публичный release asset.

## Подготовка Релиза

Release version использует формат `vMAJOR.MINOR.PATCH`. Это должна быть новая,
ещё не существующая версия. Начинать нужно с clean `main` и актуального
`app/composer.lock`. Сначала подготовьте source identity:

```shell
make prepare-release-source \
  DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry \
  RELEASE_VERSION=v1.0.0 \
  DOCKER_PLATFORM=linux/amd64

git diff -- docker/compose/prod/compose.yaml docker/compose/prod/release.env
git add docker/compose/prod/compose.yaml docker/compose/prod/release.env
git commit -m "CHORE(release): Подготовлен релиз v1.0.0"
git tag -a v1.0.0 -m "Release v1.0.0"
```

Только после того как annotated tag указывает на текущий HEAD, соберите image:

```shell
make build-production \
  DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry \
  RELEASE_VERSION=v1.0.0 \
  DOCKER_PLATFORM=linux/amd64
```

Команда печатает `APP_IMAGE_DIGEST`. Freeze фиксирует digest в ignored artifact
identity, не меняя tracked source или tag:

```shell
make freeze-release \
  DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry \
  RELEASE_VERSION=v1.0.0 \
  APP_IMAGE_DIGEST=sha256:<digest> \
  DOCKER_PLATFORM=linux/amd64
```

Freeze проверяет exact local image, platform, runtime extensions, autoload,
совпадение image source revision с peeled tag OID и атомарно создаёт
`docker/data/release/v1.0.0/release-artifact.env`. После него tracked tree и refs
должны остаться неизменными:

```shell
git status --short
test -f docker/data/release/v1.0.0/release-artifact.env
```

Image строится непосредственно из final tagged commit; post-build release
commit отсутствует. Существующий tag перемещать или переиспользовать запрещено.
Полный tag gate находится в
`docs/deployment/source-release.md` в repository checkout.

После commit/tag:

```shell
make release-offline DOCKER_PLATFORM=linux/amd64
```

Команда повторно проверяет image, создаёт atomic Docker archive и manifest,
проверяет Git bundle в пустом bare repository и собирает flat transport tar в
корне проекта. Все промежуточные payloads находятся в ignored
`docker/data/release/<tag>/`. Готовые `.tar` и `.tar.sha256` не
перезаписываются: существующий artifact нужно сначала перенести в versioned
protected storage.

Payload и transport assembly сериализованы ignored `flock` на пару
release/platform. Одновременный producer завершается до записи artifacts.
Список retained candidates проверяется без удаления:

```shell
make release-candidates
```

Успешные `offline-payload` и `offline-exported` записываются в накопительный
`phase-manifest.txt`. Повторный запуск проверяет release/config identity,
platform, image digest/ID, archive path, size и SHA-256. Совпавший artifact
переиспользуется без `docker save` или повторной упаковки; отсутствие либо
изменение verified файла является ошибкой, а не разрешением молча пересобрать
тот же release.

Чтобы сразу снять transaction-consistent dump из запущенного application
PostgreSQL и включить его в тот же transport archive, сначала остановите новые
jobs/import/rebuild согласно backup runbook, затем выполните:

```shell
make release-offline \
  DOCKER_PLATFORM=linux/amd64 \
  RELEASE_INCLUDE_APPLICATION_DATA=1 \
  RELEASE_DATA_CONTAINER=<application-postgres-container> \
  RELEASE_DATA_RESERVE_GB=10
```

Если verified backup уже создан, исключите повторный `pg_dump`:

```shell
make release-offline \
  DOCKER_PLATFORM=linux/amd64 \
  RELEASE_INCLUDE_APPLICATION_DATA=1 \
  RELEASE_DATA_BACKUP_DIR=/srv/backups/geo-registry/application-postgres-<UTC> \
  RELEASE_DATA_CONTAINER=<compatible-postgres-container>
```

Оба пути проверяют SHA-256, `pg_restore --list`, PostgreSQL major и точное
совпадение `application_revision` с release commit. Без явного флага data
archive не создаётся и не включается.

Проверка состава:

```shell
tar -tf release-geo-registry-v1.0.0-offline.tar
sha256sum --check release-geo-registry-v1.0.0-offline.tar.sha256
```

## Установка С Носителя

Перенесите `.tar` и `.tar.sha256` в один каталог. Каждый release
распаковывается в свой versioned directory:

```shell
cd /srv/releases/geo-registry
sha256sum --check release-geo-registry-v1.0.0-offline.tar.sha256
mkdir -p /srv/releases/geo-registry/v1.0.0
tar -xf release-geo-registry-v1.0.0-offline.tar \
  -C /srv/releases/geo-registry/v1.0.0
```

Создать новый checkout из bundle можно без заранее клонированного repository:

```shell
mkdir -p /srv/apps/geo-registry
cd /srv/apps/geo-registry
git init
git fetch /srv/releases/geo-registry/v1.0.0/release-geo-registry-v1.0.0.bundle \
  refs/heads/main:refs/remotes/release/main \
  refs/tags/v1.0.0:refs/tags/v1.0.0
git switch --detach v1.0.0
test "$(git cat-file -t refs/tags/v1.0.0)" = tag
test "$(git rev-parse HEAD)" = "$(git rev-parse 'refs/tags/v1.0.0^{commit}')"

mkdir -p docker/data/release/v1.0.0
cp /srv/releases/geo-registry/v1.0.0/source-linux-amd64.tar \
  docker/data/release/v1.0.0/
cp /srv/releases/geo-registry/v1.0.0/source-manifest.txt \
  docker/data/release/v1.0.0/
```

Если `source-manifest.txt` содержит `data_bundle=application-postgres`, сначала
проверьте data archive без публикации backup-каталога:

```shell
make extract-release-data \
  RELEASE_EXTRACT_DIR=/srv/releases/geo-registry/v1.0.0 \
  BACKUP_ROOT=/srv/backups/geo-registry \
  RELEASE_DATA_DRY_RUN=1
```

Затем атомарно извлеките проверенный backup:

```shell
make extract-release-data \
  RELEASE_EXTRACT_DIR=/srv/releases/geo-registry/v1.0.0 \
  BACKUP_ROOT=/srv/backups/geo-registry
```

Результат — `/srv/backups/geo-registry/application-postgres-v1.0.0`, совместимый
с обычными `verify-data-backup` и `restore-data-shadow`. Restore не выполняется
автоматически и не может затронуть действующую БД.

Перед изменением runtime создать ignored secret-файл, проверить schema
compatibility и выполнить read-only release gate:

```shell
export DEPLOY_TARGET=moscow-primary
export DEPLOY_EXPECTED_DOCKER_CONTEXT=production
export DEPLOY_CONFIRM_TARGET=moscow-primary

make deploy-init
make deploy-doctor
make deploy-image
```

Offline transport не ослабляет target gate: label повторяется явно, active
Docker context должен совпасть с ожидаемым, а endpoint печатается без userinfo
до `docker load` или Compose mutation.

Если tag уже связан с frozen registry digest, archive не требуется. В offline
сценарии `deploy-image` fail-closed проверяет manifest и SHA-256 до
`docker load`, а затем проверяет загруженный version tag, platform, OCI version
label, runtime и, для manifest v3, local image ID/config digest.
`docker load` не обязан восстанавливать registry `RepoDigest`, поэтому offline
проверка не пытается использовать `repository@digest` как локальное имя.
Фактическое переключение:

```shell
make change-release
```

Оно проверяет MessageBus registry в одноразовом container и пересоздаёт только
`app`, `worker-address`, `worker-runtime` с `--no-build --pull never`. Data
services и их volumes не затрагиваются. Migrations/import/rebuild не запускаются
неявно и должны быть выполнены в согласованном release window до switch.

Для нового контура порядок данных такой:

1. `make restore-data-preflight` для отсутствующей shadow-БД;
2. `make restore-data-shadow` с точным `RESTORE_CONFIRM`;
3. `gar:integrity --regions=50,77 --deep --only-failed` на восстановленной БД;
4. `opensearch:reindex`, затем `opensearch:status` и search smoke;
5. только после этих gates — переключение DSN/application runtime.

Точные copy-paste команды, включая требования к target PostgreSQL, DSN,
одноразовые application containers, OpenSearch rebuild и финальный smoke,
находятся в `DEPLOY.md` рядом с этим файлом. В repository это
`docs/deployment/release-deploy.md`.

## Rollback

Rollback использует тот же механизм, а не отдельную упрощённую команду:

1. проверить совместимость текущей schema/data с предыдущим кодом;
2. восстановить backup, если migration runbook требует data rollback;
3. распаковать предыдущий offline archive в отдельный каталог;
4. получить предыдущий annotated tag из его bundle и checkout tag;
5. положить предыдущие payload/manifest в его versioned ignored directory;
6. выполнить `make deploy-image`, затем `make change-release`;
7. повторить health, worker и release parity smoke.

Code rollback никогда не удаляет PostgreSQL, Nominatim или OpenSearch volumes.
Если schema несовместима назад, переключение старого image без утверждённого
data restore запрещено.
