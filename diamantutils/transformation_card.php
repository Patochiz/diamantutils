<?php
/**
 * Fiche transformation de stock par lots : création, édition (brouillon), vue,
 * validation et annulation.
 */

// Pages du module : garder le menu GPAO sélectionné quel que soit le lien d'arrivée
if (!isset($_GET['mainmenu']) && !isset($_POST['mainmenu'])) {
	$_GET['mainmenu'] = 'mrp';
	$_GET['leftmenu'] = 'diamantutils_ot';
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

require_once DOL_DOCUMENT_ROOT.'/product/class/html.formproduct.class.php';
dol_include_once('/diamantutils/class/transformation.class.php');
dol_include_once('/diamantutils/lib/diamantutils.lib.php');

$langs->loadLangs(array('products', 'stocks', 'orders', 'productbatch', 'diamantutils@diamantutils'));

$id = GETPOSTINT('id');
$ref = GETPOST('ref', 'alpha');
$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$fk_product_default = GETPOSTINT('fk_product');

if (!isModEnabled('diamantutils')) {
	accessforbidden();
}
$permread = $user->hasRight('diamantutils', 'transformation', 'read');
$permwrite = $user->hasRight('diamantutils', 'transformation', 'write');
$permcancel = $user->hasRight('diamantutils', 'transformation', 'cancel');
if (!$permread) {
	accessforbidden();
}

$object = new Transformation($db);
if ($id > 0 || $ref) {
	$result = $object->fetch($id, $ref);
	if ($result <= 0) {
		accessforbidden($langs->trans('ErrorRecordNotFound'));
	}
}

$hookmanager->initHooks(array('diamantutilstransfocard', 'globalcard'));

// Lignes saisies, réaffichées si l'enregistrement échoue
$postedlines = null;

/*
 * Lecture des lignes postées par l'écran de saisie
 */
function diamantutils_read_posted_lines()
{
	$lines = array();
	$raw = GETPOST('lines', 'array');
	if (!is_array($raw)) {
		return $lines;
	}
	foreach ($raw as $l) {
		if (!is_array($l)) {
			continue;
		}
		$direction = (isset($l['direction']) ? (string) $l['direction'] : '');
		if (!in_array($direction, array(Transformation::DIRECTION_IN, Transformation::DIRECTION_OUT, Transformation::DIRECTION_LOSS))) {
			continue;
		}
		$fk_product = (isset($l['fk_product']) ? (int) $l['fk_product'] : 0);
		if ($fk_product <= 0) {
			continue;
		}
		$lines[] = array(
			'direction' => $direction,
			'fk_product' => $fk_product,
			'batch' => (isset($l['batch']) ? dol_trunc(trim(dol_string_nohtmltag((string) $l['batch'])), 128, 'right', 'UTF-8', 1) : ''),
			'nb_pieces' => (isset($l['nb_pieces']) && $l['nb_pieces'] !== '' ? (float) price2num($l['nb_pieces']) : null),
			'length_mm' => (isset($l['length_mm']) && $l['length_mm'] !== '' ? (float) price2num($l['length_mm']) : null),
			'qty' => (isset($l['qty']) && $l['qty'] !== '' ? (float) price2num($l['qty']) : 0),
		);
	}
	return $lines;
}

/*
 * Actions
 */

$parameters = array();
$reshook = $hookmanager->executeHooks('doActions', $parameters, $object, $action);
if ($reshook < 0) {
	setEventMessages($hookmanager->error, $hookmanager->errors, 'errors');
}

if (empty($reshook)) {
	if (GETPOST('cancel', 'alpha')) {
		if ($object->id > 0) {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
		} else {
			header('Location: '.dol_buildpath('/diamantutils/transformation_list.php', 1));
		}
		exit;
	}

	// Enregistrement (création ou mise à jour du brouillon), validation éventuelle
	if ($action == 'save' && $permwrite) {
		$error = 0;
		if ($object->id > 0 && $object->status != Transformation::STATUS_DRAFT) {
			setEventMessages($langs->trans('DiamantutilsErrorNotDraft'), null, 'errors');
			$error++;
		}

		$object->type = GETPOST('type', 'aZ09');
		$object->fk_warehouse = GETPOSTINT('fk_warehouse');
		$object->fk_commande = GETPOSTINT('fk_commande');
		$object->fk_commandedet = GETPOSTINT('fk_commandedet');
		$object->label = GETPOST('label', 'alphanohtml');
		$object->note = GETPOST('note', 'restricthtml');
		$postedlines = diamantutils_read_posted_lines();

		if (!($object->fk_warehouse > 0)) {
			setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('Warehouse')), null, 'errors');
			$error++;
		}

		// La ligne de commande doit appartenir à la commande choisie
		if ($object->fk_commandedet > 0) {
			$sql = "SELECT fk_commande FROM ".MAIN_DB_PREFIX."commandedet WHERE rowid = ".((int) $object->fk_commandedet);
			$resql = $db->query($sql);
			$obj = ($resql ? $db->fetch_object($resql) : null);
			if (!$obj || (int) $obj->fk_commande != (int) $object->fk_commande) {
				$object->fk_commandedet = 0;
			}
		}
		if (!($object->fk_commande > 0)) {
			$object->fk_commandedet = 0;
		}

		if (!$error) {
			$db->begin();
			if ($object->id > 0) {
				$result = $object->update($user);
			} else {
				$result = $object->create($user);
			}
			if ($result > 0) {
				$result = $object->setLines($postedlines);
			}
			if ($result < 0) {
				$error++;
				$db->rollback();
				setEventMessages($object->error, $object->errors, 'errors');
				if (!$id) {
					// Création annulée par le rollback : l'objet n'existe pas en base
					$object->id = 0;
					$object->ref = '';
				}
			} else {
				$db->commit();
			}
		}

		if (!$error && GETPOST('dovalidate', 'alpha')) {
			if ($object->validate($user) > 0) {
				setEventMessages($langs->trans('DiamantutilsOrderValidated', $object->ref), null, 'mesgs');
			} else {
				setEventMessages(null, $object->errors, 'errors');
			}
			if (!empty($object->warnings)) {
				setEventMessages(null, $object->warnings, 'warnings');
			}
		} elseif (!$error) {
			setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
			$balance = $object->checkBalance();
			if (!$balance['sameunit'] && count($object->lines)) {
				setEventMessages($langs->trans('DiamantutilsWarningUnitsDiffer'), null, 'warnings');
			}
		}

		if (!$error) {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		$action = ($object->id > 0 ? '' : 'create');
	}

	// Suppression d'un brouillon
	if ($action == 'confirm_delete' && $confirm == 'yes' && $permwrite && $object->id > 0) {
		if ($object->delete($user) > 0) {
			setEventMessages($langs->trans('RecordDeleted'), null, 'mesgs');
			header('Location: '.dol_buildpath('/diamantutils/transformation_list.php', 1));
			exit;
		}
		setEventMessages($object->error, $object->errors, 'errors');
		$action = '';
	}

	// Consommation : mouvements de stock
	if ($action == 'confirm_consume' && $confirm == 'yes' && $permwrite && $object->id > 0) {
		if ($object->consume($user) > 0) {
			setEventMessages($langs->trans('DiamantutilsOrderConsumed', $object->ref), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		setEventMessages(null, $object->errors, 'errors');
		$action = '';
	}

	// Retour en brouillon d'un ordre validé
	if ($action == 'setdraft' && $permwrite && $object->id > 0) {
		if ($object->setDraft($user) > 0) {
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		setEventMessages(null, $object->errors, 'errors');
		$action = '';
	}

	// Annulation : abandon d'un brouillon / validé (droit write), mouvements inverses d'un consommé (droit cancel)
	$permcancelthis = ($object->status == Transformation::STATUS_CONSUMED ? $permcancel : $permwrite);
	if ($action == 'confirm_cancel' && $confirm == 'yes' && $permcancelthis && $object->id > 0) {
		if ($object->cancel($user) > 0) {
			setEventMessages($langs->trans('DiamantutilsOrderCanceled', $object->ref), null, 'mesgs');
			header('Location: '.$_SERVER['PHP_SELF'].'?id='.$object->id);
			exit;
		}
		setEventMessages(null, $object->errors, 'errors');
		$action = '';
	}
}


/*
 * Vue
 */

$form = new Form($db);
$formproduct = new FormProduct($db);
$types = Transformation::getTypes();

$title = $langs->trans('DiamantutilsTransformation');
if ($object->id > 0) {
	$title = $object->ref.' - '.$title;
}
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-diamantutils page-transformation-card');

$editmode = $permwrite && (($action == 'create' && !($object->id > 0)) || ($object->id > 0 && $object->status == Transformation::STATUS_DRAFT));

if ($action == 'create' && !$permwrite) {
	accessforbidden();
}

// Confirmations
$formconfirm = '';
if ($action == 'delete' && $object->id > 0) {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('DiamantutilsDeleteTransfo'), $langs->trans('DiamantutilsConfirmDeleteTransfo', $object->ref), 'confirm_delete', '', 0, 1);
}
if ($action == 'cancel' && $object->id > 0) {
	$question = ($object->status == Transformation::STATUS_CONSUMED ? 'DiamantutilsConfirmCancelConsumed' : 'DiamantutilsConfirmCancelOrder');
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('DiamantutilsCancelOrder'), $langs->trans($question, $object->ref), 'confirm_cancel', '', 0, 1);
}
if ($action == 'consume' && $object->id > 0) {
	$formconfirm = $form->formconfirm($_SERVER['PHP_SELF'].'?id='.$object->id, $langs->trans('DiamantutilsConsume'), $langs->trans('DiamantutilsConfirmConsume', $object->ref), 'confirm_consume', '', 0, 1);
}
print $formconfirm;

// En-tête de fiche
if ($object->id > 0) {
	$head = diamantutils_transfo_prepare_head($object);
	print dol_get_fiche_head($head, 'card', $langs->trans('DiamantutilsTransformation'), -1, $object->picto);

	$linkback = '<a href="'.dol_buildpath('/diamantutils/transformation_list.php', 1).'?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
	$morehtmlref = '<div class="refidno">'.dol_escape_htmltag($types[$object->type] ?? $object->type);
	if ($object->label) {
		$morehtmlref .= ' - '.dol_escape_htmltag($object->label);
	}
	$morehtmlref .= '</div>';
	dol_banner_tab($object, 'ref', $linkback, 1, 'ref', 'ref', $morehtmlref);
} else {
	print load_fiche_titre($langs->trans('DiamantutilsNewTransformation'), '', 'stock');
}

// Liste des commandes client validées / en cours pour le sélecteur
function diamantutils_order_options($db, $selected)
{
	$options = array();
	$sql = "SELECT c.rowid, c.ref, c.ref_client, s.nom as socname";
	$sql .= " FROM ".MAIN_DB_PREFIX."commande as c";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."societe as s ON s.rowid = c.fk_soc";
	$sql .= " WHERE c.entity IN (".getEntity('commande').")";
	$sql .= " AND (c.fk_statut IN (1, 2)".($selected > 0 ? " OR c.rowid = ".((int) $selected) : "").")";
	$sql .= " ORDER BY c.date_commande DESC, c.rowid DESC";
	$sql .= $db->plimit(1000);
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$options[$obj->rowid] = $obj->ref.($obj->ref_client ? ' ('.$obj->ref_client.')' : '').($obj->socname ? ' - '.$obj->socname : '');
		}
		$db->free($resql);
	}
	return $options;
}

// Lignes d'une commande pour le sélecteur
function diamantutils_orderline_options($db, $fk_commande)
{
	$options = array();
	if (!($fk_commande > 0)) {
		return $options;
	}
	$sql = "SELECT cd.rowid, cd.qty, cd.description, p.ref";
	$sql .= " FROM ".MAIN_DB_PREFIX."commandedet as cd";
	$sql .= " LEFT JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = cd.fk_product";
	$sql .= " WHERE cd.fk_commande = ".((int) $fk_commande)." AND cd.product_type = 0";
	$sql .= " ORDER BY cd.rang, cd.rowid";
	$resql = $db->query($sql);
	if ($resql) {
		while ($obj = $db->fetch_object($resql)) {
			$options[$obj->rowid] = ($obj->ref ? $obj->ref : dol_trunc(dol_string_nohtmltag($obj->description), 40)).' × '.price2num($obj->qty, 'MS');
		}
		$db->free($resql);
	}
	return $options;
}

if ($editmode) {
	/*
	 * Écran de saisie (création ou brouillon)
	 */
	if (!($object->id > 0) && empty($object->fk_warehouse)) {
		$object->fk_warehouse = getDolGlobalInt('DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE');
	}
	if (!($object->id > 0) && empty($object->type)) {
		$object->type = 'DECOUPE';
	}

	// Lignes à afficher : saisie postée en erreur, sinon lignes en base
	$jslines = array();
	if (is_array($postedlines)) {
		foreach ($postedlines as $l) {
			$jslines[] = $l;
		}
	} else {
		foreach ($object->lines as $line) {
			$jslines[] = array(
				'direction' => $line->direction,
				'fk_product' => $line->fk_product,
				'batch' => $line->batch,
				'nb_pieces' => $line->nb_pieces,
				'length_mm' => ($line->direction == Transformation::DIRECTION_IN ? null : $line->length_mm),
				'qty' => $line->qty,
			);
		}
	}

	// Infos produits et lots préchargés pour les produits des lignes
	$productids = array();
	foreach ($jslines as $l) {
		$productids[(int) $l['fk_product']] = 1;
	}
	if ($fk_product_default > 0) {
		$productids[$fk_product_default] = 1;
	}
	$jsproducts = array();
	foreach (array_keys($productids) as $pid) {
		$info = diamantutils_product_info($db, $pid);
		if (!empty($info)) {
			$info['lots'] = ($object->fk_warehouse > 0 ? diamantutils_product_lots($db, $pid, $object->fk_warehouse) : array());
			$info['stock'] = ($object->fk_warehouse > 0 ? diamantutils_stock_qty($db, $pid, $object->fk_warehouse) : 0);
			$info['alllots'] = diamantutils_product_all_lots($db, $pid);
			$jsproducts[$pid] = $info;
		}
	}

	$jsconfig = array(
		'ajaxUrl' => dol_buildpath('/diamantutils/ajax/transformation.php', 1),
		'decimals' => getDolGlobalInt('MAIN_MAX_DECIMALS_STOCK', 5),
		'decSep' => ($langs->transnoentitiesnoconv('SeparatorDecimal') != 'SeparatorDecimal' ? $langs->transnoentitiesnoconv('SeparatorDecimal') : ','),
		'lotFormat' => (substr_count(getDolGlobalString('DIAMANTUTILS_TRANSFO_LOT_FORMAT', 'Longueur %dmm'), '%d') == 1 ? getDolGlobalString('DIAMANTUTILS_TRANSFO_LOT_FORMAT', 'Longueur %dmm') : 'Longueur %dmm'),
		'batchEnabled' => isModEnabled('productbatch') ? 1 : 0,
		'defaultProduct' => ($fk_product_default > 0 && isset($jsproducts[$fk_product_default]) ? $fk_product_default : 0),
		'lines' => $jslines,
		'products' => $jsproducts,
		'deleteIcon' => img_delete(),
		'warningIcon' => img_warning(),
		'lang' => array(),
	);
	$jslangkeys = array(
		'Product', 'Batch', 'Qty', 'Unit',
		'DiamantutilsPieces', 'DiamantutilsLengthMm', 'DiamantutilsSearchProduct', 'DiamantutilsNoWidth',
		'DiamantutilsLotNotMultiple', 'DiamantutilsNoLot', 'DiamantutilsNoBatch', 'DiamantutilsStock',
		'DiamantutilsPiecesEq', 'DiamantutilsQtyToConsume', 'DiamantutilsNbToConsume', 'DiamantutilsEatSellBy',
		'DiamantutilsNewLot', 'DiamantutilsNewLotName', 'DiamantutilsRemoveProduct', 'DiamantutilsChooseProductFirst',
		'DiamantutilsToLoss', 'DiamantutilsToStock', 'DiamantutilsBalanceOk', 'DiamantutilsBalanceKo',
		'DiamantutilsUnitsDiffer', 'DiamantutilsRemaining', 'DiamantutilsConsumed', 'DiamantutilsProduced',
		'DiamantutilsLoss', 'DiamantutilsNeedInOut', 'DiamantutilsStockExceeded',
		'DiamantutilsPlanReplace', 'DiamantutilsPlanNotProfile', 'DiamantutilsPlanShortage', 'DiamantutilsPlanDone',
		'DiamantutilsPlanNoProduct', 'DiamantutilsLengthFromLot',
	);
	foreach ($jslangkeys as $key) {
		$jsconfig['lang'][$key] = $langs->transnoentitiesnoconv($key);
	}

	print '<form method="POST" id="transfoform" action="'.$_SERVER['PHP_SELF'].($object->id > 0 ? '?id='.$object->id : '').'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="save">';
	if ($object->id > 0) {
		print '<input type="hidden" name="id" value="'.$object->id.'">';
	}
	if ($fk_product_default > 0) {
		print '<input type="hidden" name="fk_product" value="'.$fk_product_default.'">';
	}

	if ($object->id > 0) {
		print '<div class="fichecenter">';
	}
	print '<table class="border centpercent tableforfieldcreate">';

	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Type').'</td><td>';
	print $form->selectarray('type', $types, $object->type, 0, 0, 0, '', 0, 0, 0, '', 'minwidth150');
	print '</td></tr>';

	print '<tr><td class="fieldrequired">'.$langs->trans('Warehouse').'</td><td>';
	print img_picto('', 'stock', 'class="pictofixedwidth"').$formproduct->selectWarehouses($object->fk_warehouse, 'fk_warehouse', 'warehouseopen', 1, 0, 0, '', 0, 0, array(), 'minwidth200');
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('DiamantutilsCustomerOrder').'</td><td>';
	print img_picto('', 'order', 'class="pictofixedwidth"').$form->selectarray('fk_commande', diamantutils_order_options($db, $object->fk_commande), $object->fk_commande, 1, 0, 0, '', 0, 0, 0, '', 'minwidth300');
	print ' '.$form->selectarray('fk_commandedet', diamantutils_orderline_options($db, $object->fk_commande), $object->fk_commandedet, 1, 0, 0, '', 0, 0, 0, '', 'minwidth200');
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('Label').'</td><td>';
	print '<input type="text" class="flat minwidth300" name="label" maxlength="255" value="'.dol_escape_htmltag((string) $object->label).'">';
	print '</td></tr>';

	print '<tr><td class="tdtop">'.$langs->trans('Note').'</td><td>';
	print '<textarea class="flat quatrevingtpercent" name="note" rows="2">'.dol_escape_htmltag((string) $object->note).'</textarea>';
	print '</td></tr>';

	print '</table>';
	if ($object->id > 0) {
		print '</div>';
	}
	print '<br>';

	// Bloc « À consommer » : produit → tableau de ses lots en stock → cocher
	print load_fiche_titre($langs->trans('DiamantutilsToConsume'), '', '');
	print '<div id="transfo-in-groups"></div>';
	print '<a href="#" id="transfo-add-in">'.img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('DiamantutilsAddProductToConsume').'</a>';

	// Bloc « À produire » + assistant de découpe
	print '<br><br>';
	print load_fiche_titre($langs->trans('DiamantutilsToProduce'), '', '');
	print '<div id="transfo-assistant" class="marginbottomonly" style="display: none">';
	print '<span class="opacitymedium">'.$langs->trans('DiamantutilsCutAssistant').' :</span> ';
	print '<input type="text" class="flat width50 right" id="transfo-plan-nb" placeholder="N"> '.$langs->trans('DiamantutilsPiecesOf').' ';
	print '<input type="text" class="flat width75 right" id="transfo-plan-len" placeholder="mm"> mm ';
	print '<a href="#" class="button smallpaddingimp" id="transfo-plan-go">'.$langs->trans('DiamantutilsProposePlan').'</a>';
	print ' <span id="transfo-plan-msg"></span>';
	print '</div>';
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" id="transfo-table-OUT"><thead></thead><tbody></tbody></table></div>';
	print '<a href="#" id="transfo-add-out">'.img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('DiamantutilsAddLineToProduce').'</a>';

	// Bloc Reste / perte
	print '<br><br>';
	print load_fiche_titre($langs->trans('DiamantutilsRemainingLoss'), '', '');
	print '<div class="div-table-responsive-no-min"><table class="noborder centpercent" id="transfo-table-LOSS"><thead></thead><tbody></tbody></table></div>';
	print '<a href="#" id="transfo-add-loss">'.img_picto('', 'add', 'class="pictofixedwidth"').$langs->trans('DiamantutilsAddLoss').'</a>';

	print '<div class="margintoponly" id="transfo-balance-block">';
	print '<span id="transfo-balance"></span> ';
	print '<a href="#" class="button smallpaddingimp" id="transfo-rest-loss">'.$langs->trans('DiamantutilsRestToLoss').'</a> ';
	print '<a href="#" class="button smallpaddingimp" id="transfo-rest-stock">'.$langs->trans('DiamantutilsRestToStock').'</a>';
	print '</div>';

	// Lignes envoyées : construites par le JS à l'envoi du formulaire
	print '<div id="transfo-posted"></div>';

	// Boutons du brouillon
	print '<div class="center margintoponly">';
	print '<input type="submit" class="button button-save" name="save" value="'.$langs->trans('Save').'">';
	print ' <input type="submit" class="button" name="dovalidate" id="transfo-validate" value="'.$langs->trans('DiamantutilsSaveAndValidate').'">';
	if ($object->id > 0) {
		print ' <a class="button butActionDelete" href="'.$_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete&token='.newToken().'">'.$langs->trans('Delete').'</a>';
	} else {
		print ' <input type="submit" class="button button-cancel" name="cancel" value="'.$langs->trans('Cancel').'" formnovalidate>';
	}
	print '</div>';

	print '</form>';

	print '<script>window.diamantutilsTransfo = '.json_encode($jsconfig, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>';
	print '<script src="'.dol_buildpath('/diamantutils/js/transformation.js', 1).'?v=2.1"></script>';

	if ($object->id > 0) {
		print dol_get_fiche_end();
	}
} elseif ($object->id > 0) {
	/*
	 * Vue d'une transformation (validée, annulée, ou brouillon en lecture seule)
	 */
	print '<div class="fichecenter">';
	print '<div class="underbanner clearboth"></div>';
	print '<table class="border centpercent tableforfield">';

	print '<tr><td class="titlefield">'.$langs->trans('Type').'</td><td>'.dol_escape_htmltag($types[$object->type] ?? $object->type).'</td></tr>';

	print '<tr><td>'.$langs->trans('Warehouse').'</td><td>';
	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
	$warehouse = new Entrepot($db);
	if ($warehouse->fetch($object->fk_warehouse) > 0) {
		print $warehouse->getNomUrl(1);
	}
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('DiamantutilsCustomerOrder').'</td><td>';
	if ($object->fk_commande > 0) {
		require_once DOL_DOCUMENT_ROOT.'/commande/class/commande.class.php';
		$order = new Commande($db);
		if ($order->fetch($object->fk_commande) > 0) {
			print $order->getNomUrl(1);
			if ($object->fk_commandedet > 0) {
				$lineoptions = diamantutils_orderline_options($db, $object->fk_commande);
				if (isset($lineoptions[$object->fk_commandedet])) {
					print ' — '.dol_escape_htmltag($lineoptions[$object->fk_commandedet]);
				}
			}
		}
	}
	print '</td></tr>';

	print '<tr><td>'.$langs->trans('DateCreation').'</td><td>'.dol_print_date($object->date_creation, 'dayhour').'</td></tr>';
	if ($object->date_valid) {
		print '<tr><td>'.$langs->trans('DateValidation').'</td><td>'.dol_print_date($object->date_valid, 'dayhour');
		if ($object->fk_user_valid > 0) {
			$uservalid = new User($db);
			if ($uservalid->fetch($object->fk_user_valid) > 0) {
				print ' — '.$uservalid->getNomUrl(1);
			}
		}
		print '</td></tr>';
	}
	if ($object->note) {
		print '<tr><td class="tdtop">'.$langs->trans('Note').'</td><td>'.dol_nl2br(dol_escape_htmltag($object->note)).'</td></tr>';
	}
	if ($object->status > 0) {
		print '<tr><td>'.$langs->trans('DiamantutilsStockMovements').'</td><td>';
		print '<a href="'.DOL_URL_ROOT.'/product/stock/movement_list.php?search_inventorycode='.urlencode($object->ref).'">'.img_picto('', 'movement', 'class="pictofixedwidth"').$langs->trans('DiamantutilsSeeMovements', $object->ref).'</a>';
		print '</td></tr>';
	}

	print '</table>';
	print '</div>';
	print '<div class="clearboth"></div>';

	// Lignes
	$directions = array(
		Transformation::DIRECTION_IN => $langs->trans('DiamantutilsConsumed'),
		Transformation::DIRECTION_OUT => $langs->trans('DiamantutilsProduced'),
		Transformation::DIRECTION_LOSS => $langs->trans('DiamantutilsLoss'),
	);
	foreach ($directions as $direction => $dirlabel) {
		$dirlines = array();
		foreach ($object->lines as $line) {
			if ($line->direction == $direction) {
				$dirlines[] = $line;
			}
		}
		if (empty($dirlines)) {
			continue;
		}
		print '<br>';
		print load_fiche_titre($dirlabel, '', '');
		print '<div class="div-table-responsive-no-min"><table class="noborder centpercent">';
		print '<tr class="liste_titre">';
		print '<td>'.$langs->trans('Product').'</td>';
		print '<td>'.$langs->trans('Batch').'</td>';
		print '<td class="right">'.$langs->trans('DiamantutilsPieces').'</td>';
		print '<td class="right">'.$langs->trans('DiamantutilsLengthMm').'</td>';
		print '<td class="right">'.$langs->trans('Qty').'</td>';
		print '<td>'.$langs->trans('Unit').'</td>';
		if ($direction != Transformation::DIRECTION_LOSS) {
			print '<td class="right">'.$langs->trans('DiamantutilsUnitCost').'</td>';
			print '<td class="right">'.$langs->trans('DiamantutilsMovement').'</td>';
		}
		print '</tr>';
		foreach ($dirlines as $line) {
			$info = diamantutils_product_info($db, $line->fk_product);
			print '<tr class="oddeven">';
			print '<td>';
			if (!empty($info)) {
				print '<a href="'.DOL_URL_ROOT.'/product/card.php?id='.((int) $line->fk_product).'">'.img_picto('', 'product', 'class="pictofixedwidth"').dol_escape_htmltag($info['ref']).'</a> <span class="opacitymedium">'.dol_escape_htmltag($info['label']).'</span>';
			}
			print '</td>';
			print '<td>';
			if ($line->batch !== '') {
				print '<a href="'.DOL_URL_ROOT.'/product/stock/productlot_list.php?search_batch='.urlencode($line->batch).'&search_fk_product='.((int) $line->fk_product).'">'.dol_escape_htmltag($line->batch).'</a>';
			}
			print '</td>';
			print '<td class="right">'.($line->nb_pieces !== null ? diamantutils_qty_format($line->nb_pieces) : '').'</td>';
			print '<td class="right">'.($line->length_mm !== null ? diamantutils_qty_format($line->length_mm) : '').'</td>';
			print '<td class="right">'.diamantutils_qty_format($line->qty).'</td>';
			print '<td>'.dol_escape_htmltag(empty($info) ? '' : $info['unit_short']).'</td>';
			if ($direction != Transformation::DIRECTION_LOSS) {
				print '<td class="right">'.($line->unit_cost !== null ? price($line->unit_cost, 0, $langs, 1, -1, 'MU') : '').'</td>';
				print '<td class="right">';
				if ($line->fk_stock_mouvement > 0) {
					print '<a href="'.DOL_URL_ROOT.'/product/stock/movement_list.php?search_inventorycode='.urlencode($object->ref).'&search_product_ref='.urlencode(empty($info) ? '' : $info['ref']).'">'.((int) $line->fk_stock_mouvement).'</a>';
				}
				print '</td>';
			}
			print '</tr>';
		}
		print '</table></div>';
	}

	// Équilibre
	$balance = $object->checkBalance();
	print '<br><div>';
	if ($balance['sameunit']) {
		print $langs->trans('DiamantutilsConsumed').' : <b>'.diamantutils_qty_format($balance['in']).'</b> '.dol_escape_htmltag($balance['unit']);
		print ' — '.$langs->trans('DiamantutilsProduced').' : <b>'.diamantutils_qty_format($balance['out']).'</b>';
		print ' — '.$langs->trans('DiamantutilsLoss').' : <b>'.diamantutils_qty_format($balance['loss']).'</b> ';
		print ($balance['ok'] ? '<span class="badge badge-status4">'.$langs->trans('DiamantutilsBalanceOk').'</span>' : '<span class="badge badge-status8">'.$langs->trans('DiamantutilsBalanceKo').'</span>');
	} elseif (count($object->lines)) {
		print img_warning().' '.$langs->trans('DiamantutilsWarningUnitsDiffer');
	}
	print '</div>';

	print dol_get_fiche_end();

	// Boutons d'action
	print '<div class="tabsAction">';
	$parameters = array();
	$reshook = $hookmanager->executeHooks('addMoreActionsButtons', $parameters, $object, $action);
	if (empty($reshook)) {
		$baseurl = $_SERVER['PHP_SELF'].'?id='.$object->id.'&token='.newToken();
		if ($object->status == Transformation::STATUS_VALIDATED) {
			print dolGetButtonAction('', $langs->trans('DiamantutilsConsume'), 'default', $baseurl.'&action=consume', 'diamantutils-consume', $permwrite);
			print dolGetButtonAction('', $langs->trans('SetToDraft'), 'default', $baseurl.'&action=setdraft', '', $permwrite);
			print dolGetButtonAction('', $langs->trans('DiamantutilsCancelOrder'), 'danger', $baseurl.'&action=cancel', '', $permwrite);
		}
		if ($object->status == Transformation::STATUS_CONSUMED) {
			print dolGetButtonAction('', $langs->trans('DiamantutilsCancelOrder'), 'danger', $baseurl.'&action=cancel', '', $permcancel);
		}
		if ($object->status == Transformation::STATUS_DRAFT) {
			print dolGetButtonAction('', $langs->trans('Delete'), 'delete', $_SERVER['PHP_SELF'].'?id='.$object->id.'&action=delete&token='.newToken(), '', $permwrite);
		}
	}
	print '</div>';
}

llxFooter();
$db->close();
