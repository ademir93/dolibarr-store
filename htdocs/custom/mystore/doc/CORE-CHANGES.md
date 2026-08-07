# Dolibarr CORE changes — tracking log

**Purpose:** a single, authoritative registry of **every** modification made to
Dolibarr **core** files (anything outside `htdocs/custom/mystore/`) for this
project. Core changes are **overwritten on every Dolibarr upgrade**, so each one
must be listed here and re-applied after upgrading.

> Rule: if you touch a core file, add a row to the summary table **and** a
> detailed entry below in the same commit. One entry per logical change.

## How to re-apply after an upgrade

1. Upgrade Dolibarr as usual (core files get replaced).
2. Re-apply each change listed below (see the per-entry "What / Where").
3. Run `php -l` on each edited core file.
4. Follow any per-entry "Post-deploy" step (e.g. module disable/re-enable).
5. Smoke-test the affected page.

## Summary table

| # | Date | Core file | Change | Feature doc | Post-deploy step |
| - | ---- | --------- | ------ | ----------- | ---------------- |
| 1 | 2026-08-07 | `htdocs/variants/combinations.php` | Add `initHooks('combinationcard')` + a generic `doActions` hook dispatch wrapping the "Create all defined combinations" block | [variants-combinations-multi-create.md](variants-combinations-multi-create.md) | Disable + re-enable MyStore module |

---

## Entry 1 — Variants: hook point to create ALL selected combinations

- **Date:** 2026-08-07
- **Core file:** `htdocs/variants/combinations.php`
- **Reason:** the page had **no hook dispatch at all**, so the "create every
  selected combination" fix could not live in the MyStore plugin. A minimal,
  generic hook point was added to core; all business logic stays in MyStore.
- **Feature documentation:** [variants-combinations-multi-create.md](variants-combinations-multi-create.md)

### What / Where (exact edits)

**a) Register the page hook context** — near the top, just after the `Form`
object is created (currently around **line 64**):

```php
$hookmanager->initHooks(array('combinationcard'));
```

**b) Dispatch a `doActions` hook and gate the original core logic** — inside the
`if (($action == 'add' || $action == 'create') && ...)` block, right after
`$features` is read from the session (currently around **lines 163–173**):

```php
// MYSTORE CUSTOM (see htdocs/custom/mystore/doc/variants-combinations-multi-create.md):
// Give modules a chance to handle the "Create all defined combinations" submit.
$parameters = array('id' => $id, 'features' => $features);
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
    setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
    // ... ORIGINAL core single-combination logic, preserved verbatim ...
}
```

The entire original create block (`if (!$features) { ... } else { ... }` down to
its closing brace) is wrapped inside `if (empty($reshook)) { ... }`. Nothing of
the original code was deleted — when MyStore is disabled, `$reshook` is `0` and
core behaves exactly as before.

### Behaviour when MyStore is enabled

MyStore's `combinationcard` hook (`actions_mystore.class.php::createAllVariantCombinations()`)
handles the submit, creates every selected combination as a **cartesian product**,
returns non-zero (so the core block is skipped), and redirects.

### Post-deploy step (required)

Dolibarr snapshots a module's hook contexts into the DB constant
`MAIN_MODULE_MYSTORE_HOOKS` **at activation time** — the descriptor is not
re-parsed per request. After deploying, **disable and re-enable the MyStore
module** (Home → Setup → Modules). Verify:

```sql
SELECT name, value, entity FROM llx_const WHERE name = 'MAIN_MODULE_MYSTORE_HOOKS';
-- value must include "combinationcard"
```

Until this is done, the hook is not registered and the page silently falls back
to core single-create.

### Verify

```bash
php -l htdocs/variants/combinations.php
grep -n "combinationcard\|executeHooks('doActions'" htdocs/variants/combinations.php
```
