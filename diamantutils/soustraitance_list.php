<?php
/**
 * Sous-traitance en cours : lots non nuls de l'entrepôt Sous-traitance
 *
 * En régime normal tous les lots sont à 0. Un lot non nul = commande fournisseur
 * réceptionnée dont le BL client n'est pas encore validé. Anomalies mises en évidence :
 * quantité négative, lot sans commande fournisseur, commande fournisseur à NON,
 * commande fournisseur sans commande client liée.
 */

// Pages du module : garder le menu Produits/Stocks sélectionné quel que soit le lien d'arrivée
if (!isset($_GET['mainmenu']) && !isset($_POST['mainmenu'])) {
	$_GET['mainmenu'] = 'products';
	$_GET['leftmenu'] = 'stock';
}

$res = 0;
if (!$res && file_exists("../main.inc.php")) {
	$res = @include "../main.inc.php";
}
if (!$res && file_exists("../../main.inc.php")) {
	$res = @include "../../main.inc.php";
}
if (!$res) {
	die("Include of main fails");
}

require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
dol_include_once('/diamantutils/lib/soustraitance.lib.php');
dol_include_once('/diamantutils/class/soustraitance.class.php');

$langs->loadLangs(array('products', 'stocks', 'orders', 'sendings', 'productbatch', 'suppliers', 'diamantutils@diamantutils'));

if (!isModEnabled('diamantutils') || !$user->hasRight('diamantutils', 'soustraitance', 'read')) {
	accessforbidden();
}

// Filtres
$search_all = trim(GETPOST('search_all', 'alphanohtml'));
$search_anomalies = GETPOSTINT('search_anomalies');
if (GETPOST('button_removefilter', 'alpha') || GETPOST('button_removefilter_x', 'alpha')) {
	$search_all = '';
	$search_anomalies = 0;
}

$title = $langs->trans('DiamantutilsSTListTitle');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-diamantutils page-soustraitance-list');

if (!diamantutils_st_enabled()) {
	print load_fiche_titre($title, '', 'stock');
	print '<div class="warning">'.$langs->trans('DiamantutilsSoustraitanceInactive').'</div>';
	llxFooter();
	$db->close();
	exit;
}

$st = new DiamantutilsSoustraitance($db);
$lines = $st->getInProgressLots();
if (!is_array($lines)) {
	setEventMessages($st->error, null, 'errors');
	$lines = array();
}

// Compteurs avant filtrage
$nbtotal = count($lines);
$nbanomalies = 0;
foreach ($lines as $line) {
	if (!empty($line['anomalies'])) {
		$nbanomalies++;
	}
}

// Filtrage
$filtered = array();
foreach ($lines as $line) {
	if ($search_anomalies && empty($line['anomalies'])) {
		continue;
	}
	if ($search_all !== '') {
		$haystack = $line['batch'].' '.$line['product_ref'].' '.$line['product_label'].' '.$line['soc_name'];
		foreach ($line['orders'] as $order) {
			$haystack .= ' '.$order['ref'];
		}
		if (stripos($haystack, $search_all) === false) {
			continue;
		}
	}
	$filtered[] = $line;
}

$entrepot = new Entrepot($db);
$entrepot->fetch(diamantutils_st_warehouse_id());

print '<form method="POST" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';

$morehtmlright = $entrepot->id > 0 ? $entrepot->getNomUrl(1) : '';
print load_fiche_titre($title.' <span class="opacitymedium">('.count($filtered).($nbtotal != count($filtered) ? ' / '.$nbtotal : '').')</span>', $morehtmlright, 'stock');

print '<span class="opacitymedium">'.$langs->trans('DiamantutilsSTListDesc').'</span><br>';
if ($nbanomalies) {
	print '<div class="warning">'.img_warning('', 'class="pictofixedwidth"').$langs->trans('DiamantutilsSTListNbAnomalies', $nbanomalies).'</div>';
}
print '<br>';

print '<div class="div-table-responsive">';
print '<table class="tagtable liste">';

// Filtres
print '<tr class="liste_titre_filter">';
print '<td class="liste_titre" colspan="3"><input type="text" class="flat minwidth200" name="search_all" placeholder="'.dol_escape_htmltag($langs->trans('DiamantutilsSTListSearch')).'" value="'.dol_escape_htmltag($search_all).'"></td>';
print '<td class="liste_titre" colspan="4"></td>';
print '<td class="liste_titre"><label><input type="checkbox" name="search_anomalies" value="1"'.($search_anomalies ? ' checked' : '').'> '.$langs->trans('DiamantutilsSTListOnlyAnomalies').'</label></td>';
print '<td class="liste_titre right">';
print '<input type="image" class="liste_titre" name="button_search" src="'.img_picto($langs->trans('Search'), 'search.png', '', 0, 1).'" title="'.dol_escape_htmltag($langs->trans('Search')).'">';
print '<input type="image" class="liste_titre" name="button_removefilter" src="'.img_picto($langs->trans('RemoveFilter'), 'searchclear.png', '', 0, 1).'" value="1" title="'.dol_escape_htmltag($langs->trans('RemoveFilter')).'">';
print '</td>';
print '</tr>';

// Titres
print '<tr class="liste_titre">';
print '<th class="liste_titre">'.$langs->trans('DiamantutilsSTColLot').'</th>';
print '<th class="liste_titre">'.$langs->trans('DiamantutilsSTColSupplier').'</th>';
print '<th class="liste_titre">'.$langs->trans('DiamantutilsSTColOrder').'</th>';
print '<th class="liste_titre">'.$langs->trans('DiamantutilsSTColDraft').'</th>';
print '<th class="liste_titre">'.$langs->trans('Product').'</th>';
print '<th class="liste_titre right">'.$langs->trans('DiamantutilsSTColQty').'</th>';
print '<th class="liste_titre center">'.$langs->trans('DiamantutilsSTColDateReception').'</th>';
print '<th class="liste_titre">'.$langs->trans('DiamantutilsSTColAnomalies').'</th>';
print '<th class="liste_titre"></th>';
print '</tr>';

foreach ($filtered as $line) {
	print '<tr class="oddeven">';

	// Lot / commande fournisseur
	print '<td class="nowraponall">';
	if ($line['cf']) {
		print '<a href="'.DOL_URL_ROOT.'/fourn/commande/card.php?id='.((int) $line['cf']['id']).'">'.img_picto('', 'supplier_order', 'class="pictofixedwidth"').dol_escape_htmltag($line['batch']).'</a>';
	} else {
		print img_picto('', 'lot', 'class="pictofixedwidth"').dol_escape_htmltag($line['batch']);
	}
	print '</td>';

	// Sous-traitant
	print '<td class="tdoverflowmax150">';
	if ($line['socid'] > 0) {
		print '<a href="'.DOL_URL_ROOT.'/societe/card.php?socid='.((int) $line['socid']).'">'.img_picto('', 'company', 'class="pictofixedwidth"').dol_escape_htmltag($line['soc_name']).'</a>';
	}
	print '</td>';

	// Commandes clients liées
	print '<td class="nowraponall">';
	$links = array();
	foreach ($line['orders'] as $order) {
		$links[] = '<a href="'.DOL_URL_ROOT.'/commande/card.php?id='.((int) $order['id']).'">'.img_picto('', 'order', 'class="pictofixedwidth"').dol_escape_htmltag($order['ref']).'</a>';
	}
	print implode('<br>', $links);
	print '</td>';

	// BL brouillons
	print '<td class="nowraponall">';
	$links = array();
	foreach ($line['drafts'] as $draft) {
		$links[] = '<a href="'.DOL_URL_ROOT.'/expedition/card.php?id='.((int) $draft['id']).'">'.img_picto('', 'dolly', 'class="pictofixedwidth"').dol_escape_htmltag($draft['ref']).'</a>';
	}
	print (empty($links) ? '<span class="opacitymedium">'.$langs->trans('DiamantutilsSTNoDraft').'</span>' : implode('<br>', $links));
	print '</td>';

	// Produit
	print '<td class="tdoverflowmax200">';
	print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $line['fk_product']).'">'.img_picto('', 'product', 'class="pictofixedwidth"').dol_escape_htmltag($line['product_ref']).'</a>';
	print ' <span class="opacitymedium">'.dol_escape_htmltag($line['product_label']).'</span>';
	print '</td>';

	// Quantité restante (négative en rouge)
	print '<td class="right nowraponall'.($line['qty'] < 0 ? ' error' : '').'"><strong>'.price(price2num($line['qty'], 'MS'), 0, '', 0, 0).'</strong></td>';

	// Date de réception
	print '<td class="center nowraponall">'.($line['date_reception'] ? dol_print_date($line['date_reception'], 'day') : '').'</td>';

	// Anomalies
	print '<td>';
	$labels = array();
	foreach ($line['anomalies'] as $code) {
		$labels[] = img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans($code).'</span>';
	}
	print implode('<br>', $labels);
	print '</td>';

	// Lien vers le stock du lot
	print '<td class="right nowraponall">';
	print '<a href="'.DOL_URL_ROOT.'/product/stock/movement_list.php?search_product_ref='.urlencode($line['product_ref']).'&search_batch='.urlencode($line['batch']).'" title="'.dol_escape_htmltag($langs->trans('StockMovements')).'">'.img_picto($langs->trans('StockMovements'), 'movement').'</a>';
	print '</td>';

	print '</tr>';
}

if (empty($filtered)) {
	print '<tr class="oddeven"><td colspan="9"><span class="opacitymedium">'.($nbtotal ? $langs->trans('NoRecordFound') : $langs->trans('DiamantutilsSTListEmpty')).'</span></td></tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
