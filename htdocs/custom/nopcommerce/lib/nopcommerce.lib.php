<?php
/* Copyright (C) 2026 Demir Agovic
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */

/**
 * \file    htdocs/custom/nopcommerce/lib/nopcommerce.lib.php
 * \ingroup nopcommerce
 * \brief   Common functions for the nopCommerce integration module
 */

/**
 * Prepare admin pages header
 *
 * @return array<array{string,string,string}>
 */
function nopcommerceAdminPrepareHead()
{
	global $langs, $conf;

	$langs->load("nopcommerce@nopcommerce");

	$h = 0;
	$head = array();

	$head[$h][0] = dolBuildUrl(dol_buildpath("/nopcommerce/admin/setup.php", 1));
	$head[$h][1] = $langs->trans("Settings");
	$head[$h][2] = 'settings';
	$h++;

	$head[$h][0] = dolBuildUrl(dol_buildpath("/nopcommerce/admin/about.php", 1));
	$head[$h][1] = $langs->trans("About");
	$head[$h][2] = 'about';
	$h++;

	complete_head_from_modules($conf, $langs, null, $head, $h, 'nopcommerce@nopcommerce');
	complete_head_from_modules($conf, $langs, null, $head, $h, 'nopcommerce@nopcommerce', 'remove');

	return $head;
}

/**
 * Return the base URL of the REST endpoints this module exposes, for display in the setup page.
 *
 * @return string	Absolute URL, without a trailing slash
 */
function nopcommerceApiBaseUrl()
{
	global $dolibarr_main_url_root;

	// DOL_MAIN_URL_ROOT is the Dolibarr root URL as the browser sees it. Fall back to the
	// value in conf.php when it is not defined, for instance in a CLI context.
	$root = defined('DOL_MAIN_URL_ROOT') ? constant('DOL_MAIN_URL_ROOT') : '';
	if (empty($root)) {
		$root = !empty($dolibarr_main_url_root) ? $dolibarr_main_url_root : '';
	}

	return rtrim($root, '/').'/api/index.php/nopcommerce';
}

/**
 * Format a timestamp the way the nopCommerce contract wants every date: ISO-8601, in UTC, with
 * a numeric offset rather than a Z suffix.
 *
 * Built from the UTC clock on purpose: Dolibarr's own RFC 3339 format prints the user's local
 * time and labels it Z.
 *
 * @param	int|string|null	$timestamp	Unix timestamp
 * @return	string|null					Date such as 2026-09-13T10:04:00+00:00, null when there is no timestamp
 */
function nopcommerceIsoDate($timestamp)
{
	if (empty($timestamp)) {
		return null;
	}

	return gmdate('Y-m-d\TH:i:s', (int) $timestamp).'+00:00';
}
