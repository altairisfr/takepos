<?php
/* Copyright (C) 2001-2004	Andreu Bisquerra	<jove@bisquerra.com>
 * Copyright (C) 2020		Thibault FOUCART	<support@ptibogxiv.net>
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program. If not, see <https://www.gnu.org/licenses/>.
 */

/**
 *	\file       htdocs/takepos/ajax/ajax.php
 *	\brief      Ajax search component for TakePos. It search products of a category.
 */

if (!defined('NOCSRFCHECK')) {
	define('NOCSRFCHECK', '1');
}
if (!defined('NOTOKENRENEWAL')) {
	define('NOTOKENRENEWAL', '1');
}
if (!defined('NOREQUIREMENU')) {
	define('NOREQUIREMENU', '1');
}
if (!defined('NOREQUIREHTML')) {
	define('NOREQUIREHTML', '1');
}
if (!defined('NOREQUIREAJAX')) {
	define('NOREQUIREAJAX', '1');
}
if (!defined('NOBROWSERNOTIF')) {
	define('NOBROWSERNOTIF', '1');
}

require '../../main.inc.php'; // Load $user and permissions
require_once DOL_DOCUMENT_ROOT.'/categories/class/categorie.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

$category = GETPOST('category', 'alphanohtml');	// Can be id of category or 'supplements'
$action = GETPOST('action', 'aZ09');
$term = GETPOST('term', 'alpha');
$id = GETPOST('id', 'int');
$search_start = GETPOST('search_start', 'int');
$search_limit = GETPOST('search_limit', 'int');

if (empty($user->rights->takepos->run)) {
	accessforbidden();
}

// Initialize technical object to manage hooks. Note that conf->hooks_modules contains array of hooks
$hookmanager->initHooks(array('takeposproductsearch'));
/*
 * View
 */
if ($action == 'closeTerminal') {
	if ($conf->global->{'TAKEPOS_LOCK_TERMINAL_' . $_SESSION["takeposterminal"]}) {
		dolibarr_set_const($db, 'TAKEPOS_TERMINAL_LOCKED_' . $_SESSION["takeposterminal"], '0', 'chaine', 1, '', $conf->entity);
	}
	unset($_SESSION["takeposterminal"]);
	exit;
}

if ($action == 'lockTerminal') {
	dolibarr_set_const($db, 'TAKEPOS_TERMINAL_LOCKED_' . GETPOST('term'), $user->login, 'chaine', 1, '', $conf->entity);
	exit;
}

if ($action == 'getProducts') {
	$object = new Categorie($db);
	if ($category == "supplements") {
		$category = getDolGlobalInt('TAKEPOS_SUPPLEMENTS_CATEGORY');
	}
	$result = $object->fetch($category);
	if ($result > 0) {
		$prods = $object->getObjectsInCateg("product", 0, 0, 0, getDolGlobalString('TAKEPOS_SORTPRODUCTFIELD'), 'ASC');
		// Removed properties we don't need
		$res = array();
		if (is_array($prods) && count($prods) > 0) {
			foreach ($prods as $prod) {
				if ($conf->global->TAKEPOS_PRODUCT_IN_STOCK) {
					$prod->load_stock('nobatch,novirtual');
					if ($prod->stock_warehouse[$conf->global->{'CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal']}]->real <= 0) {
						continue;
					}
				}
				unset($prod->fields);
				unset($prod->db);
				$res[] = $prod;
			}
		}
		echo json_encode($res);
	} else {
		echo 'Failed to load category with id='.$category;
	}
} elseif ($action == 'search_category' && $term != '') {
	$sql = 'SELECT rowid, label, description FROM '.MAIN_DB_PREFIX.'categorie as c';
	$sql .= ' WHERE entity IN ('.getEntity('categorie').')';
	$sql .= natural_search(array('label', 'description'), $term);
	$resql = $db->query($sql);
	if ($resql) {
		$rows = array();
		while ($obj = $db->fetch_object($resql)) {
			$rows[] = array(
				'rowid' => $obj->rowid,
				'label' => $obj->label,
				'description' => $obj->description,
			'object' => 'categorie'
				//'price_formated' => price(price2num($obj->price, 'MU'), 1, $langs, 1, -1, -1, $conf->currency)
			);
		}
		echo json_encode($rows);
	} else {
		echo 'Failed to search category : '.$db->lasterror();
	}
} elseif ($action == 'search' && $term != '') {
	// Change thirdparty with barcode
	require_once DOL_DOCUMENT_ROOT.'/societe/class/societe.class.php';

	$thirdparty = new Societe($db);
	$result = $thirdparty->fetch('', '', '', $term);

	if ($result && $thirdparty->id > 0) {
		$rows = array();
			$rows[] = array(
				'rowid' => $thirdparty->id,
				'name' => $thirdparty->name,
				'barcode' => $thirdparty->barcode,
		  'object' => 'thirdparty'
			);
			echo json_encode($rows);
			exit;
	}

	// Define $filteroncategids, the filter on category ID if there is a Root category defined.
	$filteroncategids = '';
	if ($conf->global->TAKEPOS_ROOT_CATEGORY_ID > 0) {	// A root category is defined, we must filter on products inside this category tree
		$object = new Categorie($db);
		//$result = $object->fetch($conf->global->TAKEPOS_ROOT_CATEGORY_ID);
		$arrayofcateg = $object->get_full_arbo('product', $conf->global->TAKEPOS_ROOT_CATEGORY_ID, 1);
		if (is_array($arrayofcateg) && count($arrayofcateg) > 0) {
			foreach ($arrayofcateg as $val) {
				$filteroncategids .= ($filteroncategids ? ', ' : '').$val['id'];
			}
		}
	}
	$sql = 'SELECT p.rowid, p.ref, p.label, p.tosell, p.tobuy, p.barcode, p.price ';
	if ($conf->global->TAKEPOS_PRODUCT_IN_STOCK == 1) {
		if ( ! empty($conf->global->{'CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal']})) {
			$sql .= ', ps.reel';
		} else {
			$sql .= ', SUM(ps.reel) as reel';
		}
	}
	// Add fields from hooks
	$parameters=array();
	$reshook=$hookmanager->executeHooks('printFieldListSelect', $parameters);
	$sql.=$hookmanager->resPrint;

	$sql .= ' FROM '.MAIN_DB_PREFIX.'product as p';
	if ($conf->global->TAKEPOS_PRODUCT_IN_STOCK == 1) {
		$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'product_stock as ps';
		$sql .= ' ON (p.rowid = ps.fk_product';
		if ( ! empty($conf->global->{'CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal']})) {
			$sql .= ' AND ps.fk_entrepot = '.((int) $db->escape($conf->global->{'CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal']}));
		}
		$sql .= ')';
	}
	// Add tables from hooks
	$parameters=array();
	$reshook=$hookmanager->executeHooks('printFieldListTables', $parameters);
	$sql.=$hookmanager->resPrint;

	$sql .= ' WHERE entity IN ('.getEntity('product').')';
	if ($filteroncategids) {
		$sql .= ' AND EXISTS (SELECT cp.fk_product FROM '.MAIN_DB_PREFIX.'categorie_product as cp WHERE cp.fk_product = p.rowid AND cp.fk_categorie IN ('.$db->sanitize($filteroncategids).'))';
	}
	$sql .= ' AND p.tosell = 1';
	if ($conf->global->TAKEPOS_PRODUCT_IN_STOCK == 1 && ! empty($conf->global->{'CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal']})) {
		$sql .= ' AND ps.reel > 0';
	}
	$sql .= natural_search(array('p.ref', 'p.label', 'p.barcode'), $term);

	// Add where from hooks
	$parameters=array();
	$reshook=$hookmanager->executeHooks('printFieldListWhere', $parameters);
	$sql.=$hookmanager->resPrint;

	if ($conf->global->TAKEPOS_PRODUCT_IN_STOCK == 1 && empty($conf->global->{'CASHDESK_ID_WAREHOUSE'.$_SESSION['takeposterminal']})) {
		$sql .= ' GROUP BY p.rowid HAVING SUM(ps.reel) > 0';
	}

	// load only one page of products
	$sql.= $db->plimit($search_limit, $search_start);

	$resql = $db->query($sql);
	if ($resql) {
		$rows = array();
		while ($obj = $db->fetch_object($resql)) {
			$row = array(
				'rowid' => $obj->rowid,
				'ref' => $obj->ref,
				'label' => $obj->label,
				'tosell' => $obj->tosell,
				'tobuy' => $obj->tobuy,
				'barcode' => $obj->barcode,
				'price' => $obj->price,
				'object' => 'product'
				//'price_formated' => price(price2num($obj->price, 'MU'), 1, $langs, 1, -1, -1, $conf->currency)
			);
			// Add entries to row from hooks
			$parameters=array();
			$parameters['row'] = $row;
			$parameters['obj'] = $obj;
			$hookmanager->executeHooks('completeAjaxReturnArray', $parameters);
			if (!empty($hookmanager->resArray)) $row = $hookmanager->resArray;
			$rows[] = $row;
		}
		echo json_encode($rows);
	} else {
		echo 'Failed to search product : '.$db->lasterror();
	}
} elseif ($action == "opendrawer" && $term != '') {
	require_once DOL_DOCUMENT_ROOT.'/core/class/dolreceiptprinter.class.php';
	$printer = new dolReceiptPrinter($db);
	// check printer for terminal
	if ($conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term} > 0) {
		$printer->initPrinter($conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term});
		// open cashdrawer
		$printer->pulse();
		$printer->close();
	}
} elseif ($action == "printinvoiceticket" && $term != '' && $id > 0 && !empty($user->rights->facture->lire)) {
	require_once DOL_DOCUMENT_ROOT.'/core/class/dolreceiptprinter.class.php';
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	$printer = new dolReceiptPrinter($db);
	// check printer for terminal
	if (($conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term} > 0 || $conf->global->TAKEPOS_PRINT_METHOD == "takeposconnector") && $conf->global->{'TAKEPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term} > 0) {
		$object = new Facture($db);
		$object->fetch($id);
		$ret = $printer->sendToPrinter($object, $conf->global->{'TAKEPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term}, $conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term});
	}
} elseif ($action == 'getInvoice') {
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';

	$object = new Facture($db);
	if ($id > 0) {
		$object->fetch($id);
	}

	echo json_encode($object);
} elseif ($action == 'thecheck') {
	$place = GETPOST('place', 'alpha');
	require_once DOL_DOCUMENT_ROOT.'/compta/facture/class/facture.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/class/dolreceiptprinter.class.php';
	$printer = new dolReceiptPrinter($db);
	$printer->sendToPrinter($object, $conf->global->{'TAKEPOS_TEMPLATE_TO_USE_FOR_INVOICES'.$term}, $conf->global->{'TAKEPOS_PRINTER_TO_USE'.$term});
}
