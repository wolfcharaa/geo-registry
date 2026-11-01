---
name: git-conventions
description: Apply the shared Git workflow for application development, including safe history, branch naming, staging, Russian Conventional Commit messages, release artifacts, and merge requests. Use for branch, commit, history, tag, push, or MR preparation and review.
metadata:
  short-description: Shared application Git workflow
---

# Git Conventions

Use this skill together with the global `development` skill before staging or
performing Git history work. Repository-local rules may add stricter constraints
but should not duplicate or weaken this shared convention.

## History And Authority

Treat shared branches as immutable history: do not force-push, silently squash,
or discard commits. A personal branch may be rewritten only while it has no
shared consumers. Commit, push, merge, rebase, tag, and MR creation are separate
actions; authorization for one does not authorize the others.

## Branches

Use `<type>/<name>` with one of `feature`, `bugfix`, `refactor`, `docs`, or
`test`. The name describes the area and goal using lowercase Latin words and
single hyphens. Do not use underscores, camelCase, uppercase letters, doubled
hyphens, or tracker IDs.

Examples: `feature/api-regular-search`, `bugfix/opensearch-query-encoding`.

## Commit Message

```text
TYPE(scope): Результат изменения на русском

Kaiten: #0000001
```

- Use an uppercase Conventional Commit type: `FEAT`, `FIX`, `REFACTOR`, `DOCS`,
  `TEST`, or `CHORE`.
- Keep `scope` short and name the affected area, not a task number.
- Write a short, concrete, impersonal Russian description.
- Use result form such as `Добавлен`, `Исправлена`, `Обновлено`, or
  `Интегрирован`; do not use first-person wording such as `Добавил`.
- Keep exactly one reason per commit. Split independent changes even when they
  touch adjacent files.
- Add the `Kaiten` footer only when the task number is known; never invent it.

Examples:

```text
CHORE(scaffold): Инициализировано AMPHP-приложение
```

```text
FIX(opensearch): Исправлено кодирование параметров запроса

Kaiten: #1234567
```

## Commit Gate

1. Inspect the branch, status, complete working-tree diff, and staged diff.
2. Exclude unrelated changes, secrets, local configuration, runtime data, and
   generated artifacts.
3. Run checks relevant to the changed area.
4. Stage only explicit intended paths; do not use blanket staging.
5. Re-read `git diff --cached` and verify the subject describes the resulting
   repository state.

## Release Artifacts

Do not commit generated release archives, bundles, dumps, manifests, runtime
logs, local environment files, credentials, or build output. A release commit
captures source, configuration, and version state; generated delivery artifacts
remain outside Git unless the repository explicitly defines them as source.

## Merge Requests

The MR title summarizes all included commits, not only the last subject. The
description records context, important trade-offs, verification, configuration
or contract changes, and a tracker reference when known. Do not merge until
required review is complete, comments are resolved, and no unreviewed commits
were added after approval.
