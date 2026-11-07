# Offline Release И Rollback

Offline release переносит только application runtime. PostgreSQL приложения,
Nominatim и OpenSearch имеют отдельный lifecycle и никогда не сохраняются в
image archive приложения. Их volumes, dumps и indexes не входят в transport
tar.

## Артефакты

Готовый flat archive содержит ровно пять файлов в корне:

```text
release-geo-registry-vX.Y.Z.bundle
source-linux-amd64.tar
source-manifest.txt
RELEASE.md
DEPLOY.md
```

`source-linux-amd64.tar` содержит self-contained application image с HTTP и
worker runtime. `source-manifest.txt` связывает release commit, исходный commit
image, commits двух MessageBus packages, platform, image tag/digest/id и
SHA-256 Docker archive. Git bundle содержит application release branch и
annotated tag. Исходники библиотек уже встроены в image; для сборки нового image
по-прежнему нужна трёхрепозиторная workspace.

## Подготовка Релиза

Release version использует формат `vMAJOR.MINOR.PATCH`. Начинать нужно с clean
application и library repositories:

```shell
make build-production \
  DOCKER_IMAGE_PREFIX=registry.example/namespace/geo-registry \
  RELEASE_VERSION=v1.0.0 \
  DOCKER_PLATFORM=linux/amd64
```

Команда печатает `APP_IMAGE_DIGEST`. Этот digest фиксируется отдельно:

```shell
cp config/prod.env.example config/prod.env
# Указать в config/prod.env тот же полный DOCKER_IMAGE_PREFIX.
make freeze-release \
  RELEASE_VERSION=v1.0.0 \
  APP_IMAGE_DIGEST=sha256:<digest> \
  DOCKER_PLATFORM=linux/amd64
```

Freeze проверяет exact local image, platform, runtime extensions, autoload и
совпадение image source revision с текущим HEAD. Он меняет только tracked
`docker/compose/prod/release.env`. После review нужно создать отдельный release
commit и annotated tag по Git conventions. Release commit не должен содержать
никаких других изменений: image уже построен из его родительского source
commit, а manifest проверяет эту границу.

После commit/tag:

```shell
make release-offline DOCKER_PLATFORM=linux/amd64
```

Команда повторно проверяет image, создаёт atomic Docker archive и manifest,
проверяет Git bundle в пустом bare repository и собирает flat transport tar в
корне проекта. Все промежуточные payloads находятся в ignored
`docker/data/release/<tag>/`. Напечатанный SHA-256 внешнего tar нужно хранить и
передавать отдельно от самого файла.

Проверка состава:

```shell
tar -tf release-geo-registry-v1.0.0-offline.tar
```

## Установка С Носителя

Каждый release распаковывается в свой каталог:

```shell
mkdir -p /srv/releases/geo-registry/v1.0.0
tar -xf /media/release-geo-registry-v1.0.0-offline.tar \
  -C /srv/releases/geo-registry/v1.0.0
```

Сначала сверяется внешний SHA-256. Затем source checkout получает tag из
bundle, а payload копируется в ignored release directory:

```shell
git fetch /srv/releases/geo-registry/v1.0.0/release-geo-registry-v1.0.0.bundle \
  refs/tags/v1.0.0:refs/tags/v1.0.0
git switch --detach v1.0.0

mkdir -p docker/data/release/v1.0.0
cp /srv/releases/geo-registry/v1.0.0/source-linux-amd64.tar \
  docker/data/release/v1.0.0/
cp /srv/releases/geo-registry/v1.0.0/source-manifest.txt \
  docker/data/release/v1.0.0/
```

Перед изменением runtime создать ignored `config/prod.env`, выполнить
[data backup/restore gate](data-backup-restore.md), проверить schema
compatibility и выполнить read-only release gate:

```shell
make verify-release
```

Если exact digest уже загружен, archive не требуется. Если image отсутствует,
команда fail-closed проверяет manifest и SHA-256 до `docker load`. Фактическое
переключение:

```shell
make change-release
```

Оно проверяет MessageBus registry в одноразовом container и пересоздаёт только
`app`, `worker-address`, `worker-runtime` с `--no-build --pull never`. Data
services и их volumes не затрагиваются. Migrations/import/rebuild не запускаются
неявно и должны быть выполнены в согласованном release window до switch.

## Rollback

Rollback использует тот же механизм, а не отдельную упрощённую команду:

1. проверить совместимость текущей schema/data с предыдущим кодом;
2. восстановить backup, если migration runbook требует data rollback;
3. распаковать предыдущий offline archive в отдельный каталог;
4. получить предыдущий annotated tag из его bundle и checkout tag;
5. положить предыдущие payload/manifest в его versioned ignored directory;
6. выполнить `make verify-release`, затем `make change-release`;
7. повторить health, worker и release parity smoke.

Code rollback никогда не удаляет PostgreSQL, Nominatim или OpenSearch volumes.
Если schema несовместима назад, переключение старого image без утверждённого
data restore запрещено.
