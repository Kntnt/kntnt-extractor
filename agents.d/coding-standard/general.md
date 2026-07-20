# Coding standard — General

Read before writing or changing any code.

Project coding standard. General rules apply to all code; language- and framework-specific rules live in companion modules.

## Priority order

When two rules conflict, the higher-priority rule wins:

1. **This standard** — this document and its companion modules.
2. **The language's recommended standard** — PSR-12 (PHP), the WordPress Coding Standards (WordPress), the TypeScript handbook, MDN's JavaScript style.
3. **Best practice** — well-reasoned community advice (Airbnb JS, Clean Code, the WordPress Plugin Handbook).
4. **Widely accepted conventions** — what most code in the wild looks like.

## Design philosophy

These principles often conflict. Find the design that best honours all of them — don't apply each mechanically in sequence. When in doubt, start with YAGNI and work down.

- **YAGNI** — implement only what the current requirement demands. No abstraction until more than one concrete implementation exists.
- **KISS** — prefer the simpler solution. Complexity must justify itself through a concrete, present requirement.
- **DRY** — one authoritative source per piece of knowledge. Extract duplication only when two things are the same concept, not merely similar syntax.
- **TDD** — failing test before production code; Red/Green/Refactor; each test Arrange-Act-Assert with a name stating the expected behaviour. The RED step is not ceremony: a test never observed to fail is of unknown value, so demonstrate the failing run as an artifact (seen failing before the satisfying code exists), never inferred after the fact. Automate every test that can meaningfully constrain behaviour at the lowest layer that does; escalate to integration or end-to-end only where a unit test cannot capture the behaviour; reserve human verification for the irreducibly subjective (visual feel, aesthetics, pacing), stating that residual explicitly.
- **Deep modules** — a module's external interface is narrow and simple relative to the complexity it hides. This depth is the primary quality metric for a module boundary and creates a clean seam for mocking. Design the external interface as a commitment, as if it cannot be changed.
- **SOLID inside a module** — governs the internal structure of classes and components, never the module's external interface. Inject dependencies (DIP); keep ISP decomposition internal so the external interface stays deep.

## Universal rules

### Language

- All identifiers are in **English**.
- All comments — file-level, block-level, end-of-line, PHPDoc, JSDoc, TSDoc — are in **English**.
- All technical documentation (`README.md`, `CLAUDE.md`, `AGENTS.md`, files in `docs/`) is in **English**.
- User-facing strings are translatable and may be authored in any language; the source string in `__()` / `gettext()` calls is English.

### Versions and targets

- Use the latest stable major.minor of any chosen language — UNLESS an earlier version is required by the project or a dependency. Pin the constraint explicitly when it applies; don't drift below latest by accident.
- For browser-targeted code, target the most recent ECMAScript edition supported by current stable Safari, Firefox, Chrome, and Edge. Currently **ES2022**.
- No polyfills, no transpiler-emitted runtime helpers for older targets.

### Code is read as prose

Code is read as prose; the reader is always a senior developer fluent in the language and framework. A file is a chapter; a class or function is a section; a *paragraph* (Swedish *stycke*) — consecutive statements that logically belong together — is the basic unit inside a block, with a `//` comment as its topic sentence; a statement is a sentence. The paragraphing rule below is the most central in this standard.

### Paragraphs and comments

**Paragraphing inside blocks.** Inside any block — a function body, a loop body, an `if` / `else` branch, a `try` / `catch` branch — group consecutive statements that logically belong together into a *paragraph*. A paragraph has:

- No blank line between its statements.
- A single-line `//` comment above it naming what the paragraph does — a topic sentence, not an explanation; it lets the reader skim and skip.
- A blank line above the comment and below the last statement — even when the paragraph is first or last in the enclosing block, so it sits flush against the opening `{` or closing `}`.

A *trivial* paragraph — a lone `return $x;`, a single `global $wpdb;`, a one-line assignment whose intent the surrounding code makes obvious — may stand without a `//` comment. **The blank-line rule still applies**: when the other paragraphs in the block are separated by blank lines, the trivial one is too. The first line after `{` must not be jammed against the brace when other paragraphs breathe; a closing `return` must not sit immediately above `}`.

```php
public function dispatch( string $token ): void {

    // Reject malformed tokens — defense-in-depth in case the upstream
    // validator is bypassed.
    if ( ! $this->validator->is_valid( $token ) ) {
        $this->send_error( 400 );
    }

    // Resolve the token to a target record; 404 when missing.
    $record = $this->repository->find_by_token( $token );
    if ( ! $record ) {
        $this->send_error( 404 );
    }

    // Forward incoming query parameters to the redirect target and dispatch.
    $params = array_map( 'sanitize_text_field', $_GET );
    $target = add_query_arg( $params, get_permalink( $record->id ) );
    wp_safe_redirect( $target );
    exit;

}
```

The example is PHP; the rule is identical in TypeScript and plain JavaScript.

**Single-paragraph block.** When a block is one paragraph that needs no explanation of its own, drop both the `//` comment and the surrounding blank lines, and let the comment introducing the **enclosing statement** carry everything. For a function body that is the PHPDoc / JSDoc; for an `if` / `else` / `while` / `for` / `try` body it is the `//` comment above the control statement.

**Doc comments.** Every file, class, interface, enum, trait, function, method, public property, and exported constant carries a doc comment (PHPDoc / JSDoc / TSDoc). Include the why, the contract, and edge cases — not the what. Use `@param`, `@return`, `@throws`, `@since`, `@example` where they add value.

**End-of-line comments.** Use sparingly, only where a reader could plausibly miss a subtle but critical detail (a magic constant chosen for a reason, a non-obvious off-by-one, a workaround for a known platform bug).

**Audience.** Comments are for an experienced developer reading the file for the first time. Don't restate what the code shows, write tutorials, address juniors, or narrate the obvious.

**Line wrapping.** Comments wrap at column 80. Code may go wider where it improves readability — see formatter settings per language.

### Whitespace

- **No vertical alignment of `=` or `=>`.** Single-space the operator and move on; realignment churn on every edit costs more than the negligible visual benefit.
- **No padding inside short collections.** Short array literals stay on one line: `[1, 2, 3]`.
- **No gratuitous line breaks** in parameter lists. One line unless it becomes hard to read or exceeds the formatter's max width.
- **Motivated line breaks are fine.** Break an array literal across lines when its elements naturally form a list or matrix — lookup tables, observer thresholds, route definitions, fixture rows. Content-driven, not character-count-driven. Do not split a short call like `create_user( $name, $email, $role )`.

### Modern syntax

Always prefer the modern construction over the legacy one: nullish coalescing, null-safe operator, spread, destructuring, arrow functions, match/switch expressions, pattern matching, template literals. Specific examples are in the language modules.

### Defensive coding

Write a guard only where a real, present condition needs it — an untrusted boundary (user input, a network response, deserialization), a documented platform quirk, a contract a caller can plausibly break. Defensive code against states the surrounding invariants already rule out is forbidden: redundant null checks, `try`/`catch` around calls that cannot throw, re-validation of data already validated upstream, `else` branches for conditions that cannot occur, fallbacks for a dependency the module constructs itself. Such code adds paths no test covers, dilutes the contract, and feigns a doubt the types and invariants have already settled. When a guard is warranted, its `//` topic sentence names the threat it defends against — as the dispatch example above does.

### Identifiers

- Names are self-documenting. Avoid abbreviations except well-established ones (`url`, `id`, `db`, `i` in tight loops).
- No magic strings or numbers in business logic — extract to named constants or enum cases.
- Boolean variables and methods read as predicates: `isReady`, `hasConsent`, `should_retry()`.

### Naming and prefixes

Wherever there is a real risk of name collision in a global registry — WordPress plugins and themes are the canonical case, but the same applies to npm package names, browser globals, custom DOM events, and similar — use a project prefix:

- **`kntnt-`** (hyphens) where the convention requires hyphens: plugin/theme directory names, plugin slugs, text domains, REST namespaces, file paths, CSS class names, npm package names, custom HTML data attributes.
- **`kntnt_`** (underscores) where the convention requires underscores: PHP function names, hook names, option keys, transient keys, post-type slugs, capability slugs, user-meta keys, JavaScript globals.

After the prefix comes the project's own name, then one or more words describing the purpose:

```
kntnt-<project>                        ← plugin slug, repo name, dir name
kntnt_<project>_<purpose>              ← hook, option, post-type slug
kntnt-<project>-<purpose>              ← CSS class, REST endpoint segment
```

The project name itself does **not** start with `kntnt` — the prefix provides that segment exactly once. A project `<project>` gets the slug `kntnt-<project>`, not `kntnt-kntnt-<project>`; its hooks are `kntnt_<project>_<purpose>`, not `kntnt_kntnt_<project>_<purpose>`.

When the project name is long, an abbreviation may be used where length matters (hooks, option keys, post-type slugs); the plugin's `README.md` documents it. Human-facing places — plugin name, repository name, documentation — keep the full name.

PHP namespaces follow the same composition with their own casing. The root is `\Kntnt`, then the project's name (without re-prefixing) in `Pascal_Snake_Case`, then any sub-namespaces:

```
\Kntnt\<Project>                       ← root namespace for the project
\Kntnt\<Project>\<Sub>\<Class_Name>    ← organised further as needed
```

Never `\Kntnt\Kntnt_<Project>\…` — the `\Kntnt` segment already provides the prefix.

**When the prefix is not needed.** It exists to prevent collisions in a global registry. Where there is none — inside a TypeScript package whose public API is named exports, a Laravel app's `App\` namespace, a SvelteKit `$lib`, a standalone script whose identifiers stay in its own scope — the package, namespace, or file boundary already isolates, and an extra `kntnt` prefix is noise. Apply the prefix where collisions can happen (WordPress hooks, npm packages on a public registry, browser globals, custom DOM events, custom HTML data attributes); skip it where they cannot.

## Standalone-script packaging

When a single-file script runs on its own, its packaging shape depends on the directory it lives in, not on its language.

**In a directory named `bin/`** → *command-style*, meant to be invoked as a unix command:

- Filename has no extension.
- First line is an env-based shebang (the exact line is in the language module).
- File is executable (`chmod +x`).

This holds whether or not `bin/` is on `PATH`; making a command globally available is the user's decision, never the script's. Never modify `PATH`.

**Anywhere else** → *internal*, invoked by another script, skill, or tool rather than as a command:

- Filename keeps its extension (`.ts`, `.py`, `.php`, `.sh`).
- No shebang.
- The caller invokes it explicitly (`bun foo.ts`, `php foo.php`, `uv run foo.py`, `bash foo.sh`).

Shebangs are always env-based (`#!/usr/bin/env …`), never a hard-coded interpreter path. Each language module gives its exact shebang and any inline-dependency mechanism that keeps a single-file script self-contained.

## Universal tooling

Applies to every project regardless of language; language-specific tools live in that language's module. Substitutions are allowed for specific project constraints; document them in the project's `README.md`.

- **Git** for local version control.
- **GitHub** for the remote, issues, pull requests, releases, and code review.
- **GitHub Actions** for continuous integration.
