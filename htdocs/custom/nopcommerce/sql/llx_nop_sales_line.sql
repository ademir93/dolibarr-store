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

CREATE TABLE llx_nop_sales_line(
	rowid                    integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	nop_order_item_id        integer NOT NULL,                 -- order item id on the nopCommerce side, the idempotency key
	fk_product               integer NOT NULL,                 -- Dolibarr product/variant sold, sent as-is by nopCommerce
	qty                      integer NOT NULL,                 -- quantity taken out of the webshop warehouse
	sold_at                  datetime NOT NULL,                -- when nopCommerce sold the line
	fk_mouvement             integer DEFAULT NULL,              -- stock movement written for this line
	date_creation            datetime NOT NULL
) ENGINE=innodb;
