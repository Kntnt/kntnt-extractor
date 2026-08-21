# kntnt-extractor — agent guide

## Ground rules (authoritative)

Precedence over any conflicting skill, README, or other doc unless the user overrides
in the moment.

- Authoritative: only this file, the files it references, and the actual code/state.
  Ignore `README*` and other narrative docs unless referenced here or pointed to.

## References
- `docs/adr/` — the settled architectural decisions with rationale; never re-open one as an oversight
- `docs/container-format.md` — the normative specification of the sealed container's byte format; read before changing anything in `classes/Crypto/Sealed_Writer.php` or reasoning about what a reader must do
- `docs/release-procedure.md` — the evidence-based procedure for cutting a release: pre-tag checks, the version decision, the changelog step, building and publishing the archive, and the coordinated release a version move obliges wherever a client pins a verified ceiling
- `docs/measurements/` — measured facts about how this plugin behaves on a real production host, with the raw sample series beside each write-up; read before predicting or claiming a performance outcome
- `CONTEXT.md` — the project glossary; use its terms in code, docs, and dialogue
- `agents.d/coding-standard/general.md` — read before writing or changing any code
- `agents.d/coding-standard/php.md` — read before writing or changing PHP
- `agents.d/coding-standard/wordpress.md` — read before writing or changing a WordPress plugin or theme
