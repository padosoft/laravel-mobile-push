## Summary

What this pull request changes and why.

## Contract impact

- [ ] No change to a contract (`docs/contracts/*.md` or the `src/Core` interfaces and DTOs)
- [ ] A contract changed: the matching document in `docs/contracts/` is updated in this pull request, and the linked issue was agreed with a maintainer

Plan section affected, if any:

## Verification

Paste the real output or confirm each command passed.

- [ ] `vendor/bin/pest <your tests>` passes
- [ ] `vendor/bin/pest --testsuite=Arch` passes
- [ ] `vendor/bin/phpstan analyse src/Your/Dir --memory-limit=1G` passes at level max
- [ ] `vendor/bin/pint --test` passes, or `vendor/bin/pint <your paths>` was run
- [ ] `composer check` passes (formatting check, PHPStan, tests)

## Checklist

- [ ] Tests are scenario-first, or are unit tests where the logic is dense and fragile
- [ ] Each new test file runs alone
- [ ] No `Illuminate\*`, `Laravel\*` or `Carbon\*` import under `src/Core`
- [ ] `declare(strict_types=1);` in each new PHP file
- [ ] No secrets, real tokens, real keys or device tokens in code, tests, fixtures, logs or docs
- [ ] No token value is logged or dumped; value objects still redact themselves
- [ ] A single destination's failure is a result, not an exception
- [ ] `CHANGELOG.md` has an entry under `Unreleased` for user-visible changes
