<!--
  SPDX-FileCopyrightText: 2026 APS Conecta
  SPDX-License-Identifier: AGPL-3.0-or-later
-->
# AGENTS.md — Auditoría

Nextcloud 34 app for APS Conecta Gestión, PHP only: one OCS route that writes each write the user confirms to the suite AI into admin_audit's audit.log (spec APS-Conecta/gestion-aio#75, ticket #106).

## Working rules

- **Trust:** the route believes only what Nextcloud authenticated: the user from the session, and the calling ExApp from `EX-APP-ID` once AppAPI has set `app_api` in the session. From the request body it reads the tool's name and the object's id, and nothing else: no user, no content.
- **audit.log:** admin_audit writes at info, so an instance logging at the default warn level drops every entry. `make instance-up` lets admin_audit's lines through with a `log.condition`.
- **Gate before opening a PR**, what `ci.yml` and `release.yml` run: `make test`; `make instance-up test-integration`, then `make instance-clean`; `reuse lint`. Give the instance its own compose project (`COMPOSE_PROJECT_NAME=auditoria-<ticket>`). A step you could not run is named in the PR, never reported as passed.
- **Never edit** `composer.lock` or the dependency lists in `composer.json`.
- **Strings:** what a person reads (`appinfo/info.xml`, OCS error messages) is Spanish, written as literals; this app uses no `t()`. Code, comments, log messages, commits and PR text are English.
- **Licensing:** every file carries an SPDX header (`2026 APS Conecta`, `AGPL-3.0-or-later`); a file that cannot hold a comment gets an entry in `REUSE.toml`.
- **One concern per PR**, under 50 lines of hand-written source, and it contains every change its description claims.
- **Release:** `release.yml` packs on every pull request and publishes on a `v*` tag. Tags, the `release` environment and its signing secrets are the owner's.
