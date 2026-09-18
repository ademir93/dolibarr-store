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

-- Added when the reversal endpoint was introduced. qty is the quantity that item
-- took out of the webshop warehouse; qty_reversed is how much of it has been put
-- back since. Rows recorded before this migration keep qty = 0, so a reversal
-- request against a pre-migration order correctly finds nothing to reverse
-- instead of guessing a quantity.
ALTER TABLE llx_nop_order_complete_items ADD COLUMN qty integer NOT NULL DEFAULT 0 AFTER nop_external_id;
ALTER TABLE llx_nop_order_complete_items ADD COLUMN qty_reversed integer NOT NULL DEFAULT 0 AFTER qty;
