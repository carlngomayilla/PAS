---
name: exhaustive-task-delivery
description: Analyse and deliver software changes end to end, covering functional and business impacts, architecture, data integrity, authorization, security, UI, tests, performance, observability, deployment, and handover. Always use for any feature creation, correction, evolution, refactor, migration, workflow change, or functionality removal in this project.
---

# Exhaustive Task Delivery

## Objective

Deliver the complete user and business outcome, not only the visible screen or happy path. Keep the implementation tightly scoped, but cover every real downstream impact before declaring completion.

Apply the project's specialized skills alongside this skill. Activate Laravel, Tailwind, broadcasting, document, spreadsheet, PDF, or other domain skills whenever the task enters those domains.

## Define the Outcome

Before editing:

1. Restate the expected business result.
2. Identify affected users, authorized roles, forbidden roles, and organizational scopes.
3. Identify data entered, calculated, displayed, stored, notified, audited, or exported.
4. Map the workflow before and after the change, including rejection and correction loops.
5. Resolve ambiguities from project evidence when safe; ask only when the choice materially changes the result.
6. Define concrete completion criteria.

## Explore the Existing System

Inspect relevant routes, controllers, services, domain rules, models, schema, migrations, Form Requests, Policies, Gates, middleware, scope helpers, views, scripts, notifications, alerts, audits, dashboards, indicators, exports, and tests.

Reuse established architecture and helpers. Do not introduce a second pattern before proving the existing one cannot serve the requirement.

For Laravel ecosystem work, use Laravel Boost `search-docs` before code changes and use Boost schema, log, and URL tools when applicable.

## Build an Impact Matrix

Check every category and mark it applicable or not applicable:

- users and role scopes;
- workflow transitions and status semantics;
- database schema, historical data, and rollback;
- validation and authorization;
- dashboards, KPIs, filters, and personal tasks;
- notifications, alerts, audit trail, and history;
- exports, APIs, files, and attachments;
- UI states, responsive behavior, dark mode, and accessibility;
- query cost, N+1 risk, pagination, caching, and long-running work;
- tests, deployment, build, configuration, and operational rollback.

Do not edit unrelated areas, but do not omit a real impact merely because it is indirect.

## Implement the Complete Vertical Slice

Cover each applicable layer: route, server authorization, request validation, controller entry point, domain service, guarded state transition, model, relationship, migration, constraints, indexes, transaction boundaries, interface states, notifications, audit, dashboards, tasks, reporting, exports, API compatibility, and tests.

Keep controllers thin and business transitions in dedicated services. Use Form Requests for important validation. Reject invalid prior states and duplicate submissions on the server.

## Review Security

Verify authentication, least-privilege authorization, organizational isolation, direct URL access, server validation, CSRF, XSS escaping, SQL binding, mass-assignment protection, file controls, export authorization, secret handling, personal data exposure, and auditability.

Never treat a hidden button as authorization.

## Protect Data Integrity

Verify column types, numeric precision, nullability, foreign keys, indexes, uniqueness, defaults, transactions, concurrency, soft deletion, orphan handling, historical compatibility, and rollback behavior.

For multi-step workflows, use an atomic transaction and reject stale transitions. Do not perform destructive production transformations without an explicit recovery strategy.

## Complete the Interface

Preserve the existing design language. Verify desktop, tablet, mobile, light/dark modes when supported, empty/loading/success/error/correction/frozen states, keyboard use, focus, contrast, labels, tables, buttons, icons, filters, pagination, and actionable wording for each role.

Do not add a framework or redesign unrelated surfaces without approval.

## Test the Workflow

Add or update PHPUnit tests for authorized success, forbidden access, valid and invalid data, missing and boundary values, rejection and correction loops, stale status, double submission, cross-scope denial, file/export failures, downstream propagation, and regressions.

Run the narrowest relevant tests first, then related test files. Run formatting and frontend build checks when PHP or UI files changed. Never claim a command passed unless it was executed successfully.

## Review Performance and Operations

Check eager loading, duplicate queries, pagination, indexes, repeated calculations, large exports, synchronous long work, cache invalidation, queues, and useful error logging.

Prepare migration order, environment requirements, Vite build, caches, queues, scheduler, storage permissions, backups, and rollback instructions as applicable.

## Final Review and Handover

Before concluding:

1. Re-read the request and latest user direction.
2. Inspect the complete diff and preserve unrelated worktree changes.
3. Re-check permissions, transitions, errors, UI, data, and deployment.
4. Confirm required tests and builds passed.
5. Report the result, important files, business rules, permissions, tests, database/UI impacts, deployment step, manual verification, and remaining risks.

Do not declare the task complete while a required layer, failing test, known regression, or required verification remains unresolved.
