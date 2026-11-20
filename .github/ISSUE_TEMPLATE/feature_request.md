---
name: Feature request
about: Propose a change or a new capability
title: "[feature] "
labels: enhancement
---

> Do not paste device tokens, bearer tokens, receipt markers or keys in this issue. Use placeholders.

## Problem

What you are trying to do, and why the current package does not let you.

## Proposed behaviour

What the package should do, from the caller's point of view.

## Contract impact

Changes to a contract change the documents in `docs/contracts/` and the `src/Core` interfaces. Please say:

- which document and which section of `docs/PLAN.md` it touches, if any;
- which public status, reason code or message field changes, if any;
- whether it changes behaviour for an unknown outcome (`OutcomeUnknown`), a token generation, or a tenant (scope).

## Alternatives considered

Other approaches and why you did not pick them.

## Additional context

Links to the provider documentation you rely on. Quote only what you need.
