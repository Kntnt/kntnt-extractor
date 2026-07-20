# Distribution via GitHub releases with a self-hosted update checker; a separate API version for the contract

Kntnt Extractor has no WordPress.org listing — the same position as Novamira, the plugin it is meant to replace as `kntnt-wp-skills`'s control channel. Without a self-hosted update checker, a normal (non-MU) plugin distributed this way would never show an available update on the Plugins screen, forcing a manual file replacement on every release and defeating the "install and forget" goal a regular plugin was chosen to preserve ([0001](./0001-regular-plugin-dormant-by-capability-not-mu-or-timeboxed-token.md)). The plugin therefore bundles a self-hosted update-checker library pointed at its own GitHub releases, following the structure already used in [kntnt-transparent-header-ollie](https://github.com/Kntnt/kntnt-transparent-header-ollie), which is also this project's structural template more broadly (composer-managed dependencies, `autoloader.php`, a `classes/` directory, `build-release-zip.sh`).

An unauthenticated `status` endpoint exposes a separate **API version** — the REST contract's own version, not the plugin's release version — so a caller can refuse to drive an incompatible installation before attempting anything. The API version increments only when caller-visible behaviour changes, including a purely behavioural change with no signature change; a release that fixes a bug without changing what callers could already rely on does not bump it.

## Consequences

- The release asset is matched by name against the plugin's own GitHub release, never positionally, mirroring the `mkwp`/Novamira install pattern.
- A caller's compatibility check reads the `status` endpoint's API version field, not the plugin's semantic release version.
