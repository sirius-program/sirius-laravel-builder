# Sirius Laravel Builder working rules

## Scope and workflow

- Follow `../sirius-laravel-builder-docs/IMPLEMENTATION_PLAN.md`. Implement only the phase(s) the owner requests. Phase 0 establishes the baseline; it does not implement `make:crud`.
- Keep docs in English. Update feature documentation, both relevant READMEs, and the implementation evidence log after every phase. Keep this package's README minimal and point usage readers to the docs.
- Run `composer test` in every changed project after each phase, including docs-only changes. Also run affected browser tests and asset builds when UI changes. Record actual results; never mark blocked or unexecuted checks as passed.
- Read applicable skills and existing conventions. The Sirius UI skill installation and UI dependency integration are Phase 1 tasks.
- Preserve user changes. Do not modify vendor files, unrelated routes, existing consumer models, factories, or policies without explicit scope.

## Architecture

- Use `Sirius\LaravelBuilder` under `src/`. Commands orchestrate; Discovery discovers; Schema reads metadata; Definitions hold typed data; Inference suggests mappings; Generators render and write files.
- Definitions and Inference must be independent of commands, UI, database access, service-location, and filesystem access. Do not couple package source to concrete consumer `App` classes.
- Generated `App\Actions` classes own authorized, validated persistence and expose `handle()` as their only public business entry point (constructors excepted).
- Livewire components may read through scoped Eloquent queries but delegate mutations to actions. Blade views must not access the database.
- Generator execution must not write database records or alter schemas. Database writes in isolated tests and generated actions are allowed.
- Add contracts only at meaningful replaceable boundaries. No speculative repository layer.
- Use strict types, typed parameters and returns, explicit names, and braces. Honor PHP 8.3 syntax. Type inherited framework hooks using their documented signatures/PHPDoc where needed.

## Tests and compatibility

- Run architecture checks before feature work. Update architecture rules intentionally as the design evolves, not to suppress defects.
- `tests/Fixtures/Application` is a handwritten representative app, not output from a working generator. `tests/Fixtures/Package` supplies nonempty pure-layer examples. Extend checks to actual generated output when generation exists.
- Tests must use the isolated Testbench environment and SQLite memory connection, never application credentials or production databases. Outbound HTTP is blocked unless explicitly faked.
- Test observable behavior with real database queries. Use negative witnesses for architectural constraints; do not rely on snapshots alone.
- Static guardrails cannot prove absence of all dynamic side effects. Retain behavioral action/component tests and code review.
- `composer test` runs Pint, Larastan/PHPStan, and all Pest suites. Use `composer test:arch`, `test:unit`, or `test:feature` for focused feedback, followed by the full gate.
- Run `php vendor/bin/pint --dirty --format agent` after PHP edits.
- Composer resolves against PHP 8.3. Pest 3 supports that baseline; the docs app retains its separate Pest 5 stack. Do not raise consumer runtime requirements to match a development tool.
- Keep `composer.lock` local for this library; compatibility runs resolve supported framework combinations independently. Record versions tested and do not claim the entire release matrix from one run.
- Minimum framework bounds track the agreed Sirius UI compatibility. Full UI/database/browser acceptance belongs to later phases.

- Phase 1 installs Sirius UI and its complete skill in both projects. The standalone Sirius installer owns `.agents/skills/sirius-ui-development`; refresh it after UI upgrades without also assigning that directory to Boost. See the docs `SETUP.md`.


## CSS formatting

- Apply these rules to authored CSS; do not hand-format vendor, dependency, or generated/minified assets.
- Put the opening brace after the selector, then start declarations on the next line. Never use one-line rule blocks.
- Put every declaration on its own line and the closing brace on its own line.
- Use four spaces per indentation level, including nested rules and media queries; never use tabs.
- Leave exactly one blank line between adjacent CSS rules, including rules nested inside at-rules.
- Group related rules into clearly named English section comments (for example, `/* Toast */` or `/* Card */`). Leave one blank line after each section comment.
- Keep selector order, specificity, declaration order, values, and responsive behavior intact during formatting-only refactors.
- Parse/check authored CSS after edits and run the frontend build and affected browser checks when available.
