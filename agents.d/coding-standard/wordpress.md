# Coding standard — WordPress

Read before writing or changing a WordPress plugin or theme.

Read `agents.d/coding-standard/php.md` first; the rules below override parts of it.

On any conflict between this file and another, the file listed last in the References section of AGENTS.md wins.

Extends the PHP rules with rules specific to WordPress plugins and themes; applies in addition to, and in places overrides, the PHP rules.

### Surface style — WordPress flavour (overrides PSR-12)

WordPress code follows the WordPress Coding Standards, not PSR-12:

| Question | Convention |
|---|---|
| Indentation | Tabs (display as 4 cols) |
| Inside `(` / `)` | Padded: `if ( $x === null )`, `foo( $a, $b )` |
| Variables / properties | `$snake_case` |
| Methods / functions | `snake_case` |
| Classes / interfaces / enums / traits | `Pascal_Snake_Case` (e.g. `User_Repository`) |
| Class constants | `SCREAMING_SNAKE_CASE` |
| Namespace segments | `Pascal_Snake_Case` |

`Pascal_Snake_Case` (`User_Repository`) is the WordPress flavour: WordPress underscore readability plus a valid PSR-4 class name. File: `classes/User_Repository.php` — exact match, case-sensitive.

### Deliberate deviations from WP-CS — do not "fix" these

These four points deliberately depart from WP-CS; they are not oversights. Do not "correct" them toward upstream WP-CS in reviews, refactors, or new files:

- **`[ ... ]` over `array(...)`** — modern PHP.
- **PSR-4 filenames over `class-classname.php`** — the autoloader maps `User_Repository` to `User_Repository.php`, not `class-user-repository.php`.
- **Namespaces over global function prefixes** — PHP code lives inside `\Kntnt\<Project>` rather than under a `kntnt_` function-name prefix. The prefix is still used for identifiers that live in a global registry — see *Naming and prefixes* in the universal rules.
- **Yoda is not required** — natural order by default, Yoda only when it genuinely improves readability (see the PHP rules).

### File layout in WordPress projects

WordPress plugins use `classes/`, not `src/`, as the PSR-4 source directory:

```
\Kntnt\<Project>\Click_Handler              →  classes/Click_Handler.php
\Kntnt\<Project>\Conversion\Reporter        →  classes/Conversion/Reporter.php
```

Otherwise the PSR-4 rules from the PHP section apply.

### Security and i18n

- All SQL via `$wpdb->prepare()`. No raw interpolation.
- All admin URLs via `admin_url()` / `wp_nonce_url()`.
- Sanitise every superglobal access. No bare `$_GET['foo']`.
- All user-facing strings translatable: `__()`, `_e()`, `esc_html__()`, `esc_attr_e()` with the correct text domain.
- Output is escaped at the point of output: `esc_html`, `esc_attr`, `esc_url`, `wp_kses_post`.
- Errors are silent toward visitors. Diagnostics go to a plugin-managed log file or `error_log()`.
- Capabilities, not roles, gate admin actions.

### Hook registration callables

`add_action()` / `add_filter()` callbacks are exempt from the PHP rules' first-class-callable default (*Required modern features*): register with `[ $this, 'method' ]`, not `$this->method(...)`. A first-class callable builds a fresh `Closure` at the call site, and a `Closure`'s hook id (`_wp_filter_build_unique_id()`) is tied to that unreachable instance, so nothing — not even the same object elsewhere — can call `remove_action()` / `remove_filter()` against it. The array-callable form stays individually removable, which is the whole point of exposing a hook.

### Plugin and theme headers

The plugin header docblock (the main plugin file's opening comment) and the theme `style.css` header are metadata, not prose comments — a fixed WordPress format that `get_file_data()` parses one field per line, with no continuation syntax. The comment-width rule (*Line wrapping*, general module) does not apply to the header block — the **whole** block, not only its `Field:` lines, is exempt, because the rule is unsatisfiable there at any width.

A field is never wrapped across multiple lines. `get_file_data()` matches a field's value up to the end of that single line; a line break inside a value does not reformat it, it silently truncates it — the continuation text is dropped with no error and no warning.

Never place a linter annotation (`@since`, `@phpstan-ignore`, or similar) inside the header block. `get_file_data()`'s parsing regex treats `@` as an ordinary header-line prefix, on a par with `*`, `#`, and whitespace, so an annotation line risks being parsed as, and shadowing, a real field.

Column alignment of field values (lining up `Version:` under `Plugin Name:`, and so on) is left to WordPress convention — this standard neither requires nor forbids it. The general module's ban on vertical alignment of `=` / `=>` (*Whitespace*) does not extend here: header fields are metadata, not code.

### WordPress plugin project structure

```
kntnt-<name>/
├── kntnt-<name>.php          ← Main plugin file: header, PHP version
│                                guard, autoloader, Plugin::get_instance()
├── autoloader.php            ← PSR-4 autoloader for the plugin namespace
├── install.php               ← Activation: capabilities, migrator, cron,
│                                rewrite flush. Not autoloaded.
├── uninstall.php             ← Complete data removal. Runs without
│                                autoloader; uses fully qualified calls.
├── README.md                 ← Human-facing documentation
├── CLAUDE.md                 ← `@AGENTS.md` bridge for Claude Code
├── AGENTS.md                 ← AI agent instructions; References point
│                                to agents.d/
├── agents.d/                 ← Kntnt coding standard, on demand:
│                                coding-standard/<module>.md (scaffolded)
├── classes/                  ← PSR-4: <Class_Name>.php
│   ├── Plugin.php            ← Singleton, component wiring, hooks
│   ├── Migrator.php
│   ├── Settings.php
│   ├── Logger.php
│   └── …
├── migrations/               ← Version-based migrations: <X.Y.Z>.php,
│                                each returns function(\wpdb): void
├── js/                       ← Plain ES2022 scripts, no build
├── css/
├── languages/                ← .pot, .po, generated .mo
├── docs/                     ← Specs the AI and humans both read
│   ├── architecture.md
│   ├── file-structure.md
│   ├── security.md
│   ├── testing-strategy.md
│   └── …
└── tests/
    ├── Unit/                 ← Pest + Brain Monkey + Mockery
    ├── JS/                   ← Vitest + happy-dom (or jsdom)
    └── Integration/          ← Bash + WordPress Playground / DDEV
```

Bootstrap path is fixed: `kntnt-<name>.php` → guard PHP version → require `autoloader.php` → register activation/deactivation hooks → call `Plugin::get_instance()`. The `Plugin` constructor instantiates all components in dependency order and registers their WordPress hooks.

### WordPress-specific tooling

Complement the general PHP tooling.

- **Brain Monkey** + **Mockery** for mocking WordPress functions and collaborator dependencies in unit tests.
- **`szepeviktor/phpstan-wordpress`** as the PHPStan extension teaching static analysis about WordPress core. With `phpVersion` pinned to the plugin's declared floor (see the PHP module's *PHP tooling*), PHPStan constant-folds a bootstrap `version_compare( PHP_VERSION, '8.1', '<' )` guard to a fixed outcome instead of treating it as a live branch — harmless when the guard only documents the floor, but wrong when the guard exists to defend against a host loading the plugin outside the activation-check path, where it must stay a real runtime check. `treatPhpDocTypesAsCertain: false` does not touch this — that setting governs certainty PHPStan derives from PHPDoc annotations, not the `phpVersion`-derived constant-folding at work on this guard. Suppress the resulting always-true/false finding on that one line with a scoped `@phpstan-ignore` comment instead, so the guard keeps running at the host while PHPStan stays quiet only about that line.
- **WordPress Playground** (WASM PHP + SQLite) for end-to-end integration tests. Spins up in 1–2 seconds without a server. Default; use it whenever it suffices, the great majority of cases.

  Fall back to **DDEV-based** integration tests only when Playground cannot exercise the behaviour under test: MySQL-specific SQL, database-level concurrency, transaction or locking semantics, missing PHP extensions, or multi-process scenarios such as cron jobs and queue workers. DDEV-based tests are the exception, scoped narrowly to the case that requires them, and stay out of the fast PR-time test suite. Run Playground via `@wp-playground/cli`.
- **phpcs** + **WPCS** (`wp-coding-standards/wpcs`) — recommended, not required: adopt it when the project's size justifies a mechanical style gate, same proportionality call as the rest of this section's tooling. Where it is adopted, it is the only mechanical enforcement of the WP surface style above (tabs, padded parens, `Pascal_Snake_Case`, `SCREAMING_SNAKE_CASE` constants) — see the dedicated section below for the ruleset it needs and what it cannot cover.

### phpcs / WPCS ruleset

At WPCS defaults, phpcs actively fights this standard rather than merely staying silent about it. Write a project's ruleset from this section, not by copy-pasting an older project's `phpcs.xml.dist` — copying carries forward whatever that project's ruleset happened to get wrong (a stray line-length cap, warnings that never fail the build) along with the exclusions it actually needed.

**Required exclusions.** Six sniffs must be excluded: four to encode the *Deliberate deviations from WP-CS* above, two more because WPCS otherwise actively contradicts a universal rule from the general module.

- `Universal.Arrays.DisallowShortArraySyntax` — WPCS demands `array(...)`; this standard's array literals are `[ ... ]`.
- `WordPress.Files.FileName` — WPCS demands `class-user-repository.php`; this standard's PSR-4 filename is `User_Repository.php`, exact case.
- `WordPress.NamingConventions.PrefixAllGlobals` — WPCS demands a `kntnt_` prefix on every global function and class; this standard uses namespaces instead (see *Naming and prefixes*, general module).
- `WordPress.PHP.YodaConditions` — WPCS demands Yoda ordering; this standard uses natural order by default.
- `WordPress.Arrays.MultipleStatementAlignment` — WPCS demands `=>` alignment in a multi-line array; the general module's *Whitespace* rules forbid vertical alignment of `=` / `=>` outright.
- `PSR2.Methods.FunctionClosingBrace` — WPCS forbids a blank line before a function's closing `}`; the paragraphing rule (general module) requires exactly that blank line so a block's last paragraph sits flush against `}`.

**What phpcs cannot enforce.** Excluding a sniff only stops phpcs from flagging the *opposite* of what it demands — it never becomes a check for the rule this standard actually wants. Two rules have no sniff on either side, so phpcs cannot check them at all:

- The **comment-width** rule (*Line wrapping*, general module) — a standalone comment never passes column 80. phpcs's line-length sniffs (e.g. `Generic.Files.LineLength`) measure a physical line regardless of whether it holds code or a comment; there is no comment-specific width sniff, so this rule is enforced by review, not tooling.
- The **no-alignment** rule (*Whitespace*, general module) — no vertical alignment of `=` / `=>`. `WordPress.Arrays.MultipleStatementAlignment` above, like every alignment-adjacent sniff, only ever demands alignment; excluding it silences the false positive but adds no check of its own. Nothing in phpcs flags code a developer manually aligned by hand. This rule, too, is enforced by review, not tooling.

A green phpcs run therefore means the code conforms to the subset phpcs can see — never that it conforms outright. Treat the tick mark accordingly.

**The ruleset lives per project.** No central `kntnt/coding-standard` Composer package exists; each project's own `phpcs.xml.dist` encodes the exclusions above directly, written from this section. Should the duplication across projects become expensive enough to justify a shared package, that is a separate decision for a future ticket, not one this section makes.
