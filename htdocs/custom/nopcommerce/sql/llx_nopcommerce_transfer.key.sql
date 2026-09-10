ALTER TABLE llx_nopcommerce_transfer ADD UNIQUE INDEX uk_nopcommerce_transfer_ref (ref, entity);
ALTER TABLE llx_nopcommerce_transfer ADD INDEX idx_nopcommerce_transfer_status (status);
ALTER TABLE llx_nopcommerce_transfer ADD INDEX idx_nopcommerce_transfer_sync_flag (sync_flag);
ALTER TABLE llx_nopcommerce_transfer ADD INDEX idx_nopcommerce_transfer_wh_dest (fk_warehouse_destination);
