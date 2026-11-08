# Сетевой Доступ К Geo Registry

Geo Registry не выполняет application-level аутентификацию HTTP-клиентов.
Поэтому способ публикации выбирается по trust boundary: loopback, закрытая
Docker network, private/LAN interface или reverse proxy. Прямой доступ из
недоверенной либо публичной сети не поддерживается без внешних TLS/auth/ACL.

Все host bindings задаются только в ignored `compose.override.yaml`. Команды
`make deploy-port-preflight` и `make deploy-smoke` читают итоговый Compose YAML,
поэтому отдельные session variables для порта не нужны.

## Выбрать Режим

| Клиент | Рекомендуемый binding | Base URL |
| --- | --- | --- |
| Host process или reverse proxy на сервере Geo Registry | `127.0.0.1:18080` | `http://127.0.0.1:18080` |
| Другой Compose на том же Docker host | общая external Docker network | `http://geo-registry:8080` |
| Отдельный физический сервер в закрытом контуре | точный private IP Geo Registry host | `http://<private-ip>:18080` |
| Публичная/недоверенная сеть | только reverse proxy с TLS, auth и ACL | утверждённый HTTPS URL |

Не используйте `0.0.0.0` как универсальный production default: он публикует
порт на всех host interfaces. Binding на private IP сам по себе также не
является авторизацией и всегда дополняется firewall allow-list.

## Отдельный Физический Сервер В Закрытом Контуре

На host Geo Registry определите адрес private/LAN interface:

```shell
ip -br -4 address
```

Выберите адрес, маршрутизируемый с application server, например
`10.20.30.40`. Не копируйте пример без проверки фактического interface.

`compose.override.yaml` на Geo Registry host:

```yaml
services:
  app:
    ports: !override
      - 10.20.30.40:18080:8080
```

Проверьте и примените:

```shell
docker compose --env-file config/prod.secrets.env config --quiet
make deploy-start DOCKER_PLATFORM=linux/amd64
```

`deploy-start` сам выполняет port preflight до дорогих data gates, повторяет его
перед activation и завершает запуск HTTP smoke. Отдельно повторять эти команды
не требуется.

У актуальной версии работающий managed app не требуется останавливать:
preflight печатает `occupied-by-managed-app-pending-rebind`, а `deploy-start`
пересоздаёт application container с новым адресом. Для `v0.2.3` используйте
[совместимый переход из troubleshooting](troubleshooting.md#ошибка-bind-address-already-in-use-возникла-в-конце-запуска).

На application server:

```dotenv
GEO_REGISTRY_BASE_URL=http://10.20.30.40:18080
```

Предпочтительнее закрепить private DNS name, например
`geo-registry.internal`, чтобы не связывать consumer config с IP:

```dotenv
GEO_REGISTRY_BASE_URL=http://geo-registry.internal:18080
```

На network firewall либо в Docker-aware host firewall разрешите TCP/18080
только с точных IP/CIDR доверенных application servers. После изменения
обязательно проверьте обе стороны:

```shell
# С разрешённого application server.
curl --fail http://10.20.30.40:18080/health/ready

# На Geo Registry host.
ss -H -ltnp 'sport = :18080'
```

Отдельно проверьте, что недоверенный host подключиться не может. Конкретные
firewall-команды не входят в Compose runbook: Docker может управлять iptables,
а authoritative ACL может находиться на внешнем firewall. Правило утверждает
владелец инфраструктуры для конкретного контура.

## Другой Compose На Том Же Host

Предпочтительный путь — общая external network без публикации Geo Registry на
LAN. Создайте её один раз:

```shell
docker network inspect geo-registry-clients >/dev/null 2>&1 ||
  docker network create geo-registry-clients
```

Geo Registry override сохраняет loopback port для host smoke и добавляет
стабильный network alias:

```yaml
services:
  app:
    ports: !override
      - 127.0.0.1:18080:8080
    networks:
      default: {}
      geo-registry-clients:
        aliases:
          - geo-registry

networks:
  geo-registry-clients:
    external: true
    name: geo-registry-clients
```

Consumer Compose подключает только нужный service к той же сети, сохраняя его
собственную default network:

```yaml
services:
  consumer:
    networks:
      default: {}
      geo-registry-clients: {}

networks:
  geo-registry-clients:
    external: true
    name: geo-registry-clients
```

Consumer использует Docker DNS без host port:

```dotenv
GEO_REGISTRY_BASE_URL=http://geo-registry:8080
```

## `host.docker.internal` Без Общей Network

Такой путь возможен для отдельного Compose на том же Linux host, но требует
публикации на host interface. Consumer service должен иметь host-gateway:

```yaml
services:
  consumer:
    extra_hosts:
      - host.docker.internal:host-gateway
```

`GEO_REGISTRY_BASE_URL=http://host.docker.internal:18080` не работает с
binding `127.0.0.1:18080`: container приходит через host gateway, а не через
host loopback. Для этого режима обычно требуется `0.0.0.0:18080:8080` и
обязательное ограничение доступа на network/firewall level. Поэтому общая
external network безопаснее для сервисов одного Docker host.

## Публичный Доступ

Не публикуйте container port напрямую в Internet. Оставьте loopback binding и
поставьте перед ним управляемый reverse proxy/API gateway, который завершает
TLS, выполняет authentication/authorization, rate limiting, request-size
limits и audit logging. Появление такого внешнего контракта требует отдельного
security review; текущий HTTP API рассчитан на доверенный закрытый контур.

## Смена Binding У Работающего Deployment

Port preflight отличает посторонний listener от текущего managed app. Поэтому
изменение `127.0.0.1:18080` на `<private-ip>:18080` допускается: release workflow
пересоздаёт application container с новым binding, но не затрагивает PostgreSQL
и persistent volumes.

После применения проверьте:

```shell
make deploy-start DOCKER_PLATFORM=linux/amd64
docker compose --env-file config/prod.secrets.env ps
```

## Обновление Release С Одновременной Сменой Binding

`compose.override.yaml` ignored и остаётся на сервере при смене Git tag.
Сначала запишите в нём новый точный private/LAN IP, затем используйте обычный
update workflow:

```shell
cd /var/www/geo-registry
git fetch --tags origin
git switch --detach <new-release-tag>
test "$(git rev-parse HEAD)" = "$(git rev-parse 'refs/tags/<new-release-tag>^{commit}')"
git status --short

make deploy-image DOCKER_PLATFORM=linux/amd64
make deploy-update-preflight DOCKER_PLATFORM=linux/amd64
make deploy-update DOCKER_PLATFORM=linux/amd64
```

Preflight должен показать `occupied-by-managed-app-pending-rebind`, если старый
managed app ещё слушает тот же port на другом host IP. Это допустимое состояние:
update сам остановит producer/workers и пересоздаст application container.
`State: conflict` означает постороннего владельца; до его устранения update не
изменяет running deployment.

После успешного update выполните проверки с обеих сторон:

```shell
# На Geo Registry host.
make deploy-smoke
ss -H -ltnp 'sport = :<published-port>'

# На разрешённом consumer host.
curl --fail http://<geo-registry-private-ip>:<published-port>/health/ready
```
