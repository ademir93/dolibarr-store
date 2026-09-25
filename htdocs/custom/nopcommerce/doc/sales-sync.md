# Sold-stock sync from nopCommerce to Dolibarr: superseded

This note used to describe a `POST /nopcommerce/sales` endpoint, keyed by `nop_order_item_id`,
and the `sync_products` task that would call it. The endpoint was never called by the webshop
and has been removed from this module, along with its tests. Its table, `llx_nop_sales_line`,
is no longer created for new installs; an instance that already has it keeps it and its rows.

The contract that shipped is [`POST /order_completed`](api.md#completed-orders-post-order_completed),
with [`POST /order_reversal`](api.md#reversing-a-completed-order-post-order_reversal) for
cancellations, refunds and returns.
