---
module: AddressRegistry
version: 0.1.0
updated: 2026-11-04
language: ru
---

# Модуль AddressRegistry

Модуль владеет бизнес-контрактами адресного реестра: поиском и идентификацией
адресов, подсказками, импортом GAR и построением производных поисковых данных.
Application runtime, внутренняя база Nominatim и технический lifecycle
OpenSearch ему не принадлежат. PostgreSQL приложения остаётся источником истины,
а внешние поисковые и геокодирующие системы подключаются только как заменяемые
адаптеры или features.

## Текущее Состояние

В модуле пока нет исполняемых capabilities, features, HTTP/CLI entrypoints,
MessageBus messages или публичных read boundaries. Поэтому Postman collection и
карточки сценариев в этой версии отсутствуют. Текущие `/geocode` и `/search`
остаются переходными scaffold routes вне модуля и не считаются его контрактом.

Первый реализованный vertical slice добавит собственную scenario-карточку и
повысит minor-версию документа. Порядок реализации и ещё не исполняемые сценарии
ведутся в [roadmap](../../../../docs/migration/implementation-roadmap.md), а
устойчивые границы — в [архитектуре](../../../../docs/architecture.md).
