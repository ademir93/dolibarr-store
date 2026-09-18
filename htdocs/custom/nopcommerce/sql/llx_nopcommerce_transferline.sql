-- ============================================================================
-- Copyright (C) 2026 Demir Agovic
--
-- This program is free software; you can redistribute it and/or modify
-- it under the terms of the GNU General Public License as published by
-- the Free Software Foundation; either version 3 of the License, or
-- (at your option) any later version.
--
-- This program is distributed in the hope that it will be useful,
-- but WITHOUT ANY WARRANTY; without even the implied warranty of
-- MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
-- GNU General Public License for more details.
--
-- You should have received a copy of the GNU General Public License
-- along with this program. If not, see <https://www.gnu.org/licenses/>.
-- ============================================================================

CREATE TABLE llx_nopcommerce_transferline(
	rowid                     integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_nopcommercetransfer    integer NOT NULL,
	fk_product                integer NOT NULL,               -- variant child product, or simple product
	fk_product_parent         integer NOT NULL DEFAULT 0,     -- parent product when fk_product is a variant, else 0
	qty                       real NOT NULL DEFAULT 0,
	batch                     varchar(128) DEFAULT NULL,
	position                  integer NOT NULL DEFAULT 0,
	sync_flag                 smallint NOT NULL DEFAULT 0,    -- per product flag: 0=not synced, 1=synced
	sync_error                text,
	nop_product_id            integer DEFAULT NULL,           -- id returned by nopCommerce
	nop_combination_id        integer DEFAULT NULL,           -- attribute combination id returned by nopCommerce
	fk_mouvement_source       integer DEFAULT NULL,           -- llx_stock_mouvement row of the source exit
	fk_mouvement_destination  integer DEFAULT NULL,           -- llx_stock_mouvement row of the destination entry
	tms                       timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
