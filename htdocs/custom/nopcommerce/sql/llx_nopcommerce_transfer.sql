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

CREATE TABLE llx_nopcommerce_transfer(
	rowid                    integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
	entity                   integer DEFAULT 1 NOT NULL,
	ref                      varchar(128) DEFAULT '(PROV)' NOT NULL,
	label                    varchar(255),
	fk_warehouse_source      integer NOT NULL,                 -- parent warehouse, decremented on ack success
	fk_warehouse_destination integer NOT NULL,                 -- webshop warehouse, incremented on ack success
	status                   smallint NOT NULL DEFAULT 0,      -- 0=draft 1=pending 2=synced 3=failed 9=canceled
	sync_flag                smallint NOT NULL DEFAULT 0,      -- 0=not synced with nopCommerce, 1=synced
	sync_attempts            integer NOT NULL DEFAULT 0,
	sync_last_error          text,
	pull_token               varchar(64) DEFAULT NULL,         -- issued on each pull, required by the ack call
	date_pulled              datetime DEFAULT NULL,
	date_synced              datetime DEFAULT NULL,
	note_public              text,
	note_private             text,
	date_creation            datetime NOT NULL,
	tms                      timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
	fk_user_creat            integer NOT NULL,
	fk_user_modif            integer,
	import_key               varchar(14)
) ENGINE=innodb;
