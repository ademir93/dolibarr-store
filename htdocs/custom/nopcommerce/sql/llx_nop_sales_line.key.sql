ALTER TABLE llx_nop_sales_line ADD UNIQUE INDEX uk_nop_sales_line_nop_order_item_id (nop_order_item_id);
ALTER TABLE llx_nop_sales_line ADD INDEX idx_nop_sales_line_fk_product (fk_product);
