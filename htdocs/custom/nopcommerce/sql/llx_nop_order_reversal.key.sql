ALTER TABLE llx_nop_order_reversal ADD UNIQUE INDEX uk_nop_order_reversal (fk_nop_order_completed, nop_reversal_id);
ALTER TABLE llx_nop_order_reversal ADD CONSTRAINT fk_nop_order_reversal_completed FOREIGN KEY (fk_nop_order_completed) REFERENCES llx_nop_order_completed (rowid);
