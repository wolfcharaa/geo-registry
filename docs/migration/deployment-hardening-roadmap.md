# Roadmap Усиления Release И Deployment

- Статус: `Active`
- Обновлено: `2026-11-09`
- Операторский контракт: [release-deploy.md](../deployment/release-deploy.md)
- Производство релиза: [source-release.md](../deployment/source-release.md)
- Offline transport: [offline-release.md](../deployment/offline-release.md)
- Recovery: [data-backup-restore.md](../deployment/data-backup-restore.md)

## Назначение

Roadmap переводит release и deployment tooling на единый проверяемый lifecycle:
immutable source и image identity, отдельная публикация, явная цель, защита от
параллельной мутации, bounded verification и сохранённое evidence. Изменения
выполняются совместимыми slices; существующие релизы `v0.2.x` остаются
проверяемыми своими versioned scripts и manifest formats.

Документ не разрешает сам по себе push Git refs или images, deployment,
migrations, restore, cleanup либо удаление старых artifacts. Каждая внешняя или
production mutation требует отдельного запуска на явно подтверждённой цели.

## Проверенная Точка Старта

Уже реализованы exact application tag/digest, local runtime verification,
production `--no-build --pull never`, атомарные offline archives, annotated-tag
Git bundle, fail-closed restore в отсутствующую БД, migration/GAR gates и
application-only release switch без удаления persistent volumes.

Открытые ограничения после H3:

- offline payload переносит application image, но не обязательный PostgreSQL
  image для полностью изолированного first install;
- health smoke должен иметь общий deadline и representative business check;
- полный production-like backup/restore rehearsal ещё не сохранён как recovery
  evidence.

## Порядок Реализации

| Этап | Результат | Статус |
| --- | --- | --- |
| H1 | Immutable manifest и удалённо проверяемая публикация | `Completed in main` |
| H2 | Source preparation до build и единый final tag OID | `Completed in main` |
| H3 | Target plan, capabilities, lock и deployment evidence | `Completed in main` |
| H4 | Явные offline scopes и полный local image identity | `Completed in main` |
| H5 | Bounded health/business smoke и observation result | `Planned` |
| H6 | Production-like recovery rehearsal и сохранённые RPO/RTO | `External gate` |

## H1 — Manifest И Registry Publication

### H1.1 — Совместимый manifest v3

- [x] Сохранить поддержку чтения legacy manifest v1/v2.
- [x] Добавить в v3 `release_tag`, full release/config OID, archive size,
  explicit image set, registry digest, local image ID/config digest и
  `assurance=integrity-only`.
- [x] После `docker load` сверять фактический image ID с manifest, а не доверять
  одному tag.
- [x] Покрыть принятие v1/v2/v3 и отказ при несовпадающем image ID contract
  test-ом.

Success evidence: `make release-check` проходит; новый `source-manifest.txt`
имеет `manifest_format=3`. Последняя безопасная точка остановки — до
`docker load`; при несовпадении после load services и data не изменяются,
загруженный объект не считается verified.

### H1.2 — Удалённая проверка connected publication

- [x] После `docker push` разрешать success только когда registry tag и exact
  digest удалённо разрешаются в ожидаемый platform digest.
- [x] Атомарно записывать ignored `phase-manifest.txt` только после успешной
  remote verification.
- [x] Сохранять release/source OID, config revision, platform, image identity,
  assurance, actor и UTC verification time.
- [x] Проверять success и remote digest mismatch через fake-registry contract.

Phase evidence не публикует Git refs и не является deployment evidence. Если
push завершился, а remote verification или запись state не прошла, повторный
`release-publish` должен проверить тот же immutable digest; Git tag/release
наружу до успешного gate не публикуется.

### H1.3 — Verified resume и retention

- [x] Расширить phase manifest состояниями `prepared`, `built`,
  `registry-published`, `offline-exported` и строгими reuse predicates.
- [x] Ввести output lock для одного release/platform во всех build, freeze,
  publish и offline artifact entrypoints.
- [x] Добавить preview-only список release candidates с TTL и защитой current,
  tagged и actively locked artifacts;
  удаление оставить отдельной явно подтверждаемой операцией.

Не требуется отдельный registry cleanup daemon. Достаточны machine-readable
state, protected references и безопасный preview.

`make release-candidates` использует TTL семь дней по умолчанию, показывает
state/protection/expiration/lock и никогда не удаляет файлы. Lock реализован
через kernel `flock`: аварийное завершение освобождает владение автоматически,
а оставшийся lock-файл хранит только последнее диагностическое metadata.

`phase_manifest_format=2` обновляется атомарно и накапливает независимые
bindings `prepared`, `built`, `frozen`, `registry-published`,
`offline-payload`, `offline-exported`. Verified build, registry publication и
offline artifacts переиспользуются только после проверки source/config OID,
platform, image reference/digest/ID и фактических checksum/size. Потерянный или
изменённый verified artifact вызывает fail-closed; tooling не пересобирает его
молча под той же release identity.

Contract tests подтверждают atomic merge, сохранение ранних фаз, binding
mismatch, lock contention, remote publication reuse и retention preview.
Реальные production image build и offline transport tar в этом slice не
создавались; их runtime rehearsal остаётся частью следующего разрешённого
release window.

## H2 — Source Preparation До Build

- [x] Добавить read-only `release-plan` с version, platform, intended image
  reference, source OID и config revision.
- [x] Отделить локальную подготовку final release commit/tag от artifact build;
  preparation не строит и не публикует artifacts.
- [x] Строить image только из существующего prepared OID/tag; build не создаёт,
  не двигает и не публикует refs.
- [x] Убрать необходимость post-build release commit, не ослабляя проверку
  exact runtime image.
- [x] Обновить source/offline runbooks и failure tests для нового tag-контракта.

`prepare-release-source` атомарно готовит tracked source identity format 2 без
ещё не существующего digest и оставляет commit/tag оператору для review.
`build-production` принимает этот формат только когда annotated tag указывает
на текущий clean HEAD. `freeze-release` записывает exact digest, image ID,
source OID и config revision в ignored `release-artifact.env`, не меняя source
или refs. Connected и offline production проверяют этот binding; offline
transport переносит artifact identity отдельным файлом.

Contract test проходит success path от source preparation до build, freeze,
fake-registry publication и offline export, а также отклоняет dirty source и
tag OID mismatch до Docker build. Реальный tag, image, push и transport archive
в этом slice не создавались. Legacy `v0.2.x` сохраняет прежнюю допустимую
границу в своих versioned scripts; существующие tags никогда не перемещаются.

## H3 — Target Plan, Lock И Evidence

- [x] Добавить `deploy-doctor` и read-only `deploy-plan`: target label, Docker
  context/endpoint, project, host platform, current/desired identity, rendered
  config checksum, service scope, data mode, backup gate и last safe abort.
- [x] Не допускать production mutation с неявным или неподтверждённым target.
- [x] Ввести один per-target lock для init, restore, update, migrations и
  release switch; lock охватывает bounded smoke и запись результата.
- [x] Привязать update к проверенному backup manifest текущего deployment и
  явно классифицировать schema compatibility.
- [x] Сохранять current/previous/desired identity, actor, timing, checks,
  migration/backup/smoke status и final/partial state.
- [x] Перенести полную orchestration из Make recipes в testable scripts; Make
  остаётся тонким facade.

Автоматический rollback или restore после неоднозначной ошибки запрещён.
Partial state сохраняет diagnostics и recovery branch; data restore выполняется
только отдельной подтверждённой процедурой.

Первый slice H3 добавляет `make deploy-doctor` и `make deploy-plan`. Обе команды
требуют явные `DEPLOY_TARGET` и `DEPLOY_EXPECTED_DOCKER_CONTEXT`, не выбирают
context сами и не выполняют `pull`, `load`, запуск containers, migrations или
запись evidence. Endpoint печатается без userinfo. Plan считает checksum по
отсортированным service hashes итоговой Compose-модели, поэтому secret values
не попадают в вывод или временный state. Для `DEPLOY_DATA_MODE=update` и
`restore` требуется локально проверяемый `DEPLOY_BACKUP_DIR`; update также
связывает `application_revision` backup с source OID текущего container.

Недоступный Docker Engine, отсутствующие secrets, несовпавший context,
неполная artifact identity, invalid backup или невозможность получить
rendered config дают `status=blocked`. Это discovery gate, а не разрешение на
mutation. Следующие slices подключают тот же target contract, lock и evidence
непосредственно к mutating entrypoints.

Второй slice H3 вводит единый target gate в production Compose wrapper и в
entrypoints, способные изменить secret file, local image store, backup либо
data до первого Compose mutation. Оператор явно задаёт `DEPLOY_TARGET`,
`DEPLOY_EXPECTED_DOCKER_CONTEXT` и совпадающий `DEPLOY_CONFIRM_TARGET`.
Read-only `config`, `ps`, `logs`, doctor, plan и update/backup preflight не
требуют confirmation; mutating commands fail-closed до обращения к Docker.
Wrapper теперь распознаёт Compose command после global options, поэтому
`--profile opensearch up` получает те же `--no-build --pull never` и target
gate, что обычный `up`. `build`, `publish` и `push` через production wrapper
запрещены и остаются отдельными release operations.

Третий slice H3 добавляет ignored kernel `flock` на target и наследование его
descriptor дочерними production-wrapper вызовами. Init, image acquisition,
restore, fresh database initialization, backup, update и release switch
сериализуются по `DEPLOY_TARGET`; конкурентный owner получает fail-fast и
metadata активной операции. `deploy-start` перенесён из многострочного Make
recipe в `deploy-start.sh`, а update smoke — внутрь `update-deployment.sh`,
поэтому один lock теперь охватывает data gates, activation и smoke.

Финальный slice H3 переносит lock перед фактический update-preflight, связывает
update с bytes/SHA-256 backup, его `application_revision` и текущим source OID,
а также требует классификацию schema compatibility. Каждая activation/update
пишет отдельное operation evidence, атомарно продвигает `current.env` и
`previous.env`, сохраняет статусы artifact/backup/migrations/data/smoke. Ошибка
до service/data mutation завершается `safe-abort`; ошибка после начала mutation
публикует `partial.env` и блокирует последующие изменения. Снять блок можно
только отдельным подтверждённым `deploy-handover`, который архивирует выбранную
recovery branch, но сам не выполняет rollback, restore или service mutation.

## H4 — Offline Scopes

- [x] Назвать текущий минимальный scope `app-update`: application image и
  optional application PostgreSQL backup, но без support images.
- [x] Добавить `new-host-core` с exact application и PostgreSQL images,
  полученными из rendered production model.
- [x] Заменить обязательный registry pull в offline init/restore на fail-closed
  проверку exact уже загруженного PostgreSQL image.
- [x] Оставить OpenSearch отдельным optional scope, Nominatim — отдельным data
  lifecycle.
- [x] Проверять count/reference/digest/ID/platform каждого image после load.
- [x] Зафиксировать, что checksum обеспечивает integrity только относительно
  доверенно полученного значения и не является publisher authentication.

Первый slice H4 добавляет обязательный producer contract
`RELEASE_OFFLINE_SCOPE=app-update`. Scope записывается в `source-manifest.txt`,
phase evidence и имя transport archive; повторное использование проверяет его
вместе с release/config/platform identity. Legacy manifest v3 без поля
читается как `app-update`, но новый producer всегда пишет поле явно.

Финальный slice H4 добавляет `new-host-core`: producer получает application и
PostgreSQL references из rendered production Compose без optional profiles,
требует digest-pinned support image и сохраняет для обоих role, reference,
digest, local image ID и platform. Target либо повторно использует оба уже
совпавших local objects, либо проверяет archive и после `docker load` снова
сверяет обе identity. `data-init` и `data-restore` пропускают registry pull
только для валидного `new-host-core`; отсутствие или mismatch PostgreSQL image
завершается fail-closed. OpenSearch остаётся отдельным optional profile и
перестраиваемой проекцией, Nominatim — отдельным data lifecycle. Transport
сохраняет `assurance=integrity-only`: соседний checksum не объявляется
аутентификацией издателя.

## H5 — Bounded Verification

- [ ] Добавить connect и total timeout ко всем deployment smoke requests.
- [ ] Проверять health и один representative address-search flow через
  публичный HTTP contract.
- [ ] Записывать deadline, endpoint без credentials и результат в deployment
  evidence.
- [ ] Проверить timeout/failure branch без production mutation.

Полный Postman suite остаётся release/integration gate и не переносится в
короткий deployment smoke.

## H6 — Recovery Gate

- [ ] На утверждённой production-like цели выполнить полный application
  PostgreSQL backup и restore в новый target.
- [ ] Применить migrations из exact release image, выполнить GAR integrity и
  exact status gates.
- [ ] Перестроить OpenSearch из восстановленного PostgreSQL и проверить parity.
- [ ] Зафиксировать размер, длительность, RPO/RTO, operator, off-host copy,
  checksums и post-restore checks.
- [ ] Отдельно подтвердить Nominatim backup/restore либо утверждённый rebuild
  path.

Tooling tests не закрывают этот gate. До сохранённого rehearsal evidence
backup остаётся проверенным artifact, но не доказанным production recovery.

## Общий Completion Gate

Каждый slice завершается:

```shell
git diff --check
make release-check
make doctor
```

Изменённый shell дополнительно проходит `sh -n` и `shellcheck`. Build, push,
live deployment и recovery rehearsal указываются как выполненные только если
реально запускались на соответствующей цели.
