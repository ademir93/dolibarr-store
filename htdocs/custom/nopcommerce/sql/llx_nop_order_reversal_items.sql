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

CREATE TABLE llx_nop_order_reversal_items(
	rowid                    integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_nop_order_reversal    integer NOT NULL,                 -- llx_nop_order_reversal.rowid
	fk_product               integer NOT NULL,                 -- variant child product, same one the completed order took out
	nop_external_id          integer DEFAULT NULL,             -- product id on the nopCommerce side
	qty                      integer NOT NULL,                 -- quantity put back into the webshop warehouse
	date_creation            datetime NOT NULL
) ENGINE=innodb;
