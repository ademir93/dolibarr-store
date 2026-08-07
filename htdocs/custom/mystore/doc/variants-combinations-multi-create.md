# Create ALL selected variant combinations (multi-create)

## Problem

On the product variants page
`variants/combinations.php?id=<productId>&action=add`, the user builds a list of
attribute/value pairs (each "Select combination" click stores one pair in
`$_SESSION['addvariant_<productId>']`) and then clicks **Create**.

Core only ever created **one** combination — the last one selected.

### Root cause (core)

In `htdocs/variants/combinations.php`, the "Create all defined combinations"
handler sanitized the selected pairs into `$sanit_features` keyed by **attribute
id**:

```php
$sanit_features[(int) $explode[0]] = (int) $explode[1]; // key = attribute id
```

So when several values of the **same attribute** were selected (Color=Red,
Color=Blue, Color=Green), each entry overwrote the previous one at the same key,
leaving a single value. `createProductCombination()` was then called exactly
**once**, producing one combination built from whatever value survived last.

There was **no loop** creating multiple combinations and **no cartesian
expansion** across attributes.

## Chosen behaviour

"Create them all" = **cartesian product**. Selected values are grouped per
attribute and one combination is created for each element of the cross product:

- Color∈{Red, Blue} + Size∈{L} → 2 variants: Red-L, Blue-L
- Color∈{Red, Blue} + Size∈{S, M} → 4 variants
- Already-existing combinations are skipped (not treated as a fatal error).

## Implementation — where the logic lives

The business logic lives in the **MyStore module** (upgrade-safe), not in core.
Because core `combinations.php` had **no hook dispatch at all**, a purely
plugin-side fix was impossible, so a **minimal, generic hook point** was added to
core and the actual create-all logic implemented in the plugin.

### Plugin (primary change — `htdocs/custom/mystore/`)

- `class/actions_mystore.class.php`
  - `doActions()` now short-circuits to `createAllVariantCombinations()` when the
    page context is `combinationcard`.
  - `createAllVariantCombinations()` reads the selected features, groups them per
    attribute, builds the cartesian product, and creates each combination via
    `ProductCombination::createProductCombination()`. It returns a non-zero value
    (so core skips its own single-create path) and, on success, redirects exactly
    like core did. A forced reference is only applied when a single combination is
    created (forcing one reference across several variants would collide them onto
    one product).
- `core/modules/modMyStore.class.php`
  - Registered the new hook context: `module_parts['hooks']` now includes
    `'combinationcard'`.

## Core change (required, documented here)

File: **`htdocs/variants/combinations.php`**

Core has no extension point for this page, so two minimal additions were made:

1. Initialise the hook manager for a new page context near the top:

   ```php
   $hookmanager->initHooks(array('combinationcard'));
   ```

2. In the "Create all defined combinations" handler, dispatch a `doActions` hook
   right after the selected `$features` are read, and only run the original core
   create logic when no module handled it:

   ```php
   $parameters = array('id' => $id, 'features' => $features);
   $reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
   if ($reshook < 0) {
       setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
   }
   if (empty($reshook)) {
       // ... original single-combination core logic, unchanged ...
   }
   ```

The original core code path is preserved verbatim inside `if (empty($reshook))`,
so behaviour is identical when the MyStore module is disabled. The added hook is
generic (standard Dolibarr `doActions` pattern) and does not encode any MyStore
business logic, making it safe and upstream-friendly.

### Why core had to be touched

`combinations.php` never called `initHooks()`/`executeHooks()`, so there was
literally nothing for a plugin to attach to. Adding the generic hook point is the
smallest possible core change and is the only part of this feature that is not
fully contained in the MyStore module.

## Activation / deployment note (important)

Dolibarr snapshots a module's hook contexts into the DB constant
`MAIN_MODULE_MYSTORE_HOOKS` **at module-activation time** (see
`DolibarrModules::insert_module_parts()`); the descriptor file is not re-parsed
on each request. Adding `'combinationcard'` to the descriptor therefore does
**not** take effect until the constant is refreshed.

**After deploying, disable and re-enable the MyStore module** (Home → Setup →
Modules) so the new context is persisted. Verify with:

```sql
SELECT name, value, entity FROM llx_const WHERE name = 'MAIN_MODULE_MYSTORE_HOOKS';
-- value should include "combinationcard", e.g. ["takeposinvoice","login","main","combinationcard"]
```

Until this is done, `initHooks(array('combinationcard'))` finds no module
claiming the context and the fix silently falls back to core (single-create).

## Files changed

| File | Change |
| --- | --- |
| `htdocs/variants/combinations.php` | **CORE** — add `initHooks('combinationcard')`; wrap create logic in a `doActions` hook dispatch |
| `htdocs/custom/mystore/core/modules/modMyStore.class.php` | Register `combinationcard` hook context |
| `htdocs/custom/mystore/class/actions_mystore.class.php` | Add `createAllVariantCombinations()` + `doActions` branch |
