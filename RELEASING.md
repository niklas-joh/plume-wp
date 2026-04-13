# Release Process

## How releases work

This repo uses **trunk-based development** with **semantic-release**. You do not manage
versions, tags, changelogs, or release branches manually — everything is derived automatically
from your commit messages.

### The full flow

```
feature/* ──PR──▶ main
                    │
                    ▼ (on every merge)
             release.yml runs
                    │
           reads conventional commits
           since last tag
                    │
         ┌──────────┴──────────────┐
         │ no feat/fix commits?    │ feat/fix commits?
         ▼                         ▼
       nothing                 semantic-release:
       happens                 1. bumps version (semver)
                               2. writes CHANGELOG.md
                               3. bumps wp-ai-mind.php (×2),
                                  readme.txt, package.json
                               4. builds plugin zip
                               5. commits "[skip ci]"
                               6. creates vX.Y.Z tag
                               7. publishes GitHub Release
                                  with zip attached
```

**You never touch versions, tags, or changelogs.**

---

## Commit message convention

Commit messages (and PR titles, which become the squash-merge commit) control versioning:

| Prefix | Example | Version bump |
|---|---|---|
| `fix(scope):` | `fix(api): handle rate limit errors` | patch `0.2.1` |
| `feat(scope):` | `feat(chat): add streaming support` | minor `0.3.0` |
| `feat!:` or `BREAKING CHANGE:` | `feat!: remove legacy endpoint` | major `1.0.0` |
| `chore:` / `docs:` / `refactor:` / `test:` | `chore(deps): update composer` | no release |

The scope is required (enforced by commitlint in CI).

---

## Multi-branch features

When a feature spans multiple branches, use an **umbrella branch**:

```
main
  └── feature/ai-streaming          ← umbrella; never merges to main until done
        ├── feature/ai-streaming-backend  → PR → feature/ai-streaming
        └── fix/streaming-timeout         → PR → feature/ai-streaming
```

When the whole feature is ready, open one PR from `feature/ai-streaming` → `main`
with a conventional PR title (`feat(chat): add streaming response support`).
The squash-merge produces one clean commit on main.

---

## Hotfixes

Branch off `main` directly as `fix/short-description`. PR to `main` with a `fix(scope):` title.
Semantic-release will cut a patch release automatically on merge.

---

## WP.org SVN submission

Stable releases (non-pre-release) still require a manual SVN push to WordPress.org.
Download the zip from the GitHub Release and submit via SVN as usual.

Pre-release tags (`v0.3.0-beta.1`) publish a GitHub Release marked as pre-release
and are not submitted to WP.org.

---

## Workflows in use

| File | Trigger | Purpose |
|---|---|---|
| `ci.yml` | PR to `main` | PHPCS, PHPUnit, JS lint, commitlint |
| `release.yml` | Push to `main` | Full semantic-release pipeline |
| `claude-code-review.yml` | PR opened/updated | Automated code review |
| `auto-fix-ci.yml` | CI failure | Auto-fix lint issues |
| `auto-fix-review-issue.yml` | Issue labelled | Auto-fix code review issues |
| `claude.yml` | Manual / comment | Claude Code agent tasks |

## Deleted workflows (no longer needed)

The following were removed as part of the trunk-based migration:

- `build-release-branch.yml` — release branches no longer exist
- `tag-feature-merge.yml` — `merged/*` tags no longer exist
- `tag-release-merge.yml` — semantic-release creates tags directly
- `backfill-merged-tags.yml` — one-time utility, removed
- `backfill-semantic-tags.yml` — one-time utility, removed