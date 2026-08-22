# Coding standard — PHP

Read before writing or changing PHP.

Applies whenever the project contains PHP code. WordPress projects additionally use the WordPress rules, which extend and override parts of this section.

### Baseline (all PHP)

- `declare( strict_types = 1 );` at the top of every PHP file.
- Use modern language features fully; no back-compatibility shims for older versions.

### Required modern features

- Typed properties.
- `readonly` properties (PHP 8.1) and `readonly class` declarations (PHP 8.2) wherever immutability after construction is meaningful.
- Constructor property promotion where it shortens the class.
- Union and intersection types: `string|false`, `Countable&Iterator`.
- Named arguments at call sites where they aid readability.
- `match` expressions instead of `switch` statements.
- Arrow functions (`fn() =>`) for short callbacks.
- `enum` for closed sets of values; backed enums when the values cross a boundary (DB, JSON, query string).
- Null-safe operator: `$user?->getProfile()?->getEmail()`.
- First-class callable syntax for immediate-consumption callables — `array_filter( $items, $this->is_valid(...) )`, `array_map`, `usort`, and the like — where the callable is used and discarded within the same call. **Exception:** not for `add_action()` / `add_filter()` callbacks, nor any registry where a callback must remain individually removable — `$this->method(...)` builds a fresh `Closure` on every call, and a `Closure`'s hook id (`_wp_filter_build_unique_id()`) is tied to that unreachable instance, so nothing can ever call `remove_action()` / `remove_filter()` against it; use the array-callable form, `[ $this, 'method' ]`, there instead.
- `str_contains()`, `str_starts_with()`, `str_ends_with()` instead of `strpos` comparisons.
- Array spread: `[ ...$existing, $new ]`.

### Universal style rules (all PHP)

Language-level preferences, regardless of project.

- `[ ... ]` for arrays. Never `array(...)`.
- Trailing commas in multi-line arrays, parameter lists, and argument lists.
- `?:` and `??` as appropriate; do not chain them into puzzles.
- **Conditions: natural order by default.** Yoda conditions are acceptable when they make intent clearer for an experienced reader — e.g. idiomatic null-checks (`if ( null === $value )`) or where the test is fundamentally a boolean assertion rather than a comparison. The choice is purely about readability.
- Line width for comments and code follows the universal *Line wrapping* rules in the general module.

### Surface style — PSR-12

PHP follows PSR-12 for indentation, spacing, and identifier casing. WordPress projects override this with the WordPress Coding Standards — see the WordPress rules.

| Question | Convention |
|---|---|
| Indentation | 4 spaces |
| Inside `(` / `)` | Tight: `if ($x === null)`, `foo($a, $b)` |
| Variables / properties | `$camelCase` |
| Methods / functions | `camelCase` |
| Classes / interfaces / enums / traits | `PascalCase` (e.g. `UserRepository`) |
| Class constants | `SCREAMING_SNAKE_CASE` |
| Namespace segments | `PascalCase` |

### File layout

PSR-4 autoloading. The autoloader maps the namespace prefix to a source directory and uses the class name verbatim as the filename:

```
\Kntnt\<Project>\UserRepository              →  src/UserRepository.php
\Kntnt\<Project>\Audit\Logger                →  src/Audit/Logger.php
```

One class, interface, enum, or trait per file. Filename equals the symbol name, case-sensitive.

Conventional source directory is `src/`. WordPress plugins use `classes/` instead — see the WordPress rules.

### Standalone scripts

A single-file PHP script meant to run from the terminal uses the env-based shebang `#!/usr/bin/env php`, and otherwise follows the universal command-style / internal packaging rules (see *Standalone-script packaging* in the general module).

### Doc comments

Every file, class, trait, interface, enum, method, function, property, and constant has a PHPDoc block. Include `@since` from the first release. Document the why and the contract; the type system already shows the shape.

```php
/**
 * Resolves an opaque token into the user it belongs to.
 *
 * Returns `null` for malformed tokens, expired tokens, or tokens that
 * point to a deleted user. Callers must distinguish "no such user"
 * from "no permission" themselves.
 *
 * @since 1.0.0
 *
 * @param string $token Opaque identifier from the authenticator.
 * @return User|null
 */
public function resolveUser( string $token ): ?User { … }
```

### PHP tooling

Defaults when applicable, not a mandate that every project carries every tool — the same conditionality DDEV already states below. The general module's TDD rule — automate every test that can meaningfully constrain behaviour at the lowest layer that does — is the deciding question for whether a project owes itself a test suite at all; where it does, the following are the defaults.

- **Composer** for dependency management and PSR-4 autoloading.
- **Pest** for unit and feature tests, when the TDD rule says a test is owed. When you also measure coverage, use **pcov** rather than Xdebug — Xdebug is a full step debugger and roughly 10x slower for coverage collection than pcov, which only instruments line execution. pcov is a PHP runtime extension (installed via PECL, the PHP build, or container config), not a Composer package; no `composer.json` can depend on it the way it depends on Pest.
- **PHPStan** for static analysis, unconditionally — even a project with no test suite gets this check, and its value is highest precisely there, because it is then the only automated check standing between a typo and production. Aim for `--level max` on new code; raise legacy code incrementally. PHPStan catches bugs tests alone do not — typos in property names, wrong argument types, dead branches. Pin PHPStan's `phpVersion` to the project's declared PHP floor (the `Requires PHP` header, or `require.php` in `composer.json`) and keep the two in step — this, not a separate tool, is the mechanism that enforces the floor: with `phpVersion` set, PHPStan reports any syntax or built-in newer than the floor as an ordinary, non-ignorable error that a baseline file cannot bury. **Do not reach for PHPCompatibility's `testVersion` for this** — its last stable release predates PHP 8.0, so it passes 8.x-only syntax in total silence; a gate wired to it looks green while enforcing nothing, worse than no gate at all.
- **DDEV** for any PHP project needing a local server (PHP, database, web server). DDEV's project-local configuration is checked in for a reproducible environment.

WordPress-specific PHP tooling — Brain Monkey, Mockery, the `szepeviktor/phpstan-wordpress` extension, WordPress Playground for integration tests, **phpcs** + **WPCS** for static style checking — is described under the WordPress rules.
