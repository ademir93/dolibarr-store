# Running the nopCommerce module tests

The tests live with the module rather than in Dolibarr's `test/phpunit/` directory, so a
Dolibarr upgrade neither overwrites them nor requires a registration to be re-applied.
They are therefore **not** picked up by Dolibarr's `AllTests.php` and must be run
explicitly:

```bash
cd /var/www/html/dolibarr-store && ./vendor/bin/phpunit --no-configuration htdocs/custom/nopcommerce/test/NopCommerceCaptureTest.php
```

They bootstrap `htdocs/master.inc.php` and run against the real configured database. The
shared `CommonClassTest` harness wraps each test class in a transaction and rolls it back
afterwards, so they do not leave data behind.

To run them all:

```bash
cd /var/www/html/dolibarr-store && for t in htdocs/custom/nopcommerce/test/*Test.php; do ./vendor/bin/phpunit --no-configuration "$t"; done
```

Set `PHPUNIT_DEBUG=1` to see per-test tracing.

## Where tests enter

The contract with the webshop is tested through the module's public REST methods
(`NopCommerceApi*Test`): a test builds the decoded JSON body the webshop sends, calls
`status()`, `orderCompleted()` or `orderReversal()` as the API user, and asserts on the array
that comes back or the `RestException` thrown, plus the stock it left behind. The request and
response bodies are copied from the frozen contract in the webshop repository, so a shape
change on either side fails a test. `NopCommerceApiTestCase` holds the shared setup and the
factories for warehouses, mapped products, size variants and stock.

Two classes do not enter through REST, because their subject has no REST method or cannot be
built in a transaction that rolls back: `NopCommerceSchemaTest` (the health check is given a
requirement list naming things that do not exist, and the repair is run once on the real schema,
where it only adds) and `NopCommerceReadinessTest` (the setup page report).

**The schema must be complete before the order and reversal tests can run.** They write to
`llx_nop_order_*`. On an instance that enabled the module before those tables existed, run
`NopCommerceSchemaTest` first, or press **Repair the schema** on the setup page.

## What is deliberately not covered

Real transaction rollback. Dolibarr's nested transactions are a counter, not savepoints,
and the harness owns the outermost transaction, so a rollback inside a test undoes
nothing. That includes the all-or-nothing behaviour of an order and of a reversal: a
refused order leaves its `llx_nop_order_completed` row behind in a test, and stock moved by an
earlier item stays moved. Tests therefore put the failing item first and assert on the refusal
and on stock that was never touched. The module's contract is to return a negative result so its caller rolls back,
which is what `testCaptureFailurePropagates` asserts. Verify the actual rollback by hand
on the native stock transfer page.
