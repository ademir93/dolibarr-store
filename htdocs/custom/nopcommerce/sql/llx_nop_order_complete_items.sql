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

CREATE TABLE llx_nop_order_complete_items(
	rowid                    integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	fk_nop_order_completed   integer NOT NULL,                 -- llx_nop_order_completed.rowid
	fk_product               integer NOT NULL,                 -- variant child product
	nop_external_id          integer DEFAULT NULL,             -- product id on the nopCommerce side
	date_creation            datetime NOT NULL
) ENGINE=innodb;
