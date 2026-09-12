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

Set `PHPUNIT_DEBUG=1` to see per-test tracing.

## What is deliberately not covered

Real transaction rollback. Dolibarr's nested transactions are a counter, not savepoints,
and the harness owns the outermost transaction, so a rollback inside a test undoes
nothing. The module's contract is to return a negative result so its caller rolls back,
which is what `testCaptureFailurePropagates` asserts. Verify the actual rollback by hand
on the native stock transfer page.
