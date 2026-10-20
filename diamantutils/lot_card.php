<?php
/**
 * Lots de produits : fiche d'un lot
 *  - en-tête : libellé, actif ;
 *  - lignes : produit, quantité, modification de la quantité, ordre (monter / descendre),
 *    suppression, ajout en bas du tableau.
 */

// Pages du module : garder le menu Commerce sélectionné quel que soit le lien d'arrivée
if (!isset($_GET['mainmenu']) && !isset($_POST['mainmenu'])) {
	$_GET['mainmenu'] = 'commercial';
	$_GET['leftmenu'] = 'diamantutils_lot';
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

require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
dol_include_once('/diamantutils/lib/lot.lib.php');
dol_include_once('/diamantutils/lib/diamantutils.lib.php');
dol_include_once('/diamantutils/class/diamantlot.class.php');

$langs->loadLangs(array('products', 'diamantutils@diamantutils'));

if (!isModEnabled('diamantutils') || !$user->hasRight('diamantutils', 'lot', 'read')) {
	accessforbidden();
}
if (!diamantutils_lot_enabled()) {
	accessforbidden($langs->trans('DiamantutilsFeatureDisabled'));
}
$canwrite = $user->hasRight('diamantutils', 'lot', 'write');
$candelete = $user->hasRight('diamantutils', 'lot', 'delete');

$action = GETPOST('action', 'aZ09');
$confirm = GETPOST('confirm', 'alpha');
$cancel = GETPOST('cancel', 'alpha');
$id = GETPOSTINT('id');
$lineid = GETPOSTINT('lineid');

$object = new DiamantLot($db);
if ($id > 0) {
	$result = $object->fetch($id);
	if ($result < 0) {
		dol_print_error($db, $object->error);
		exit;
	}
	if ($result == 0) {
		accessforbidden($langs->trans('ErrorRecordNotFound'));
	}
} elseif ($action != 'add') {
	$action = 'create';
}
if (in_array($action, array('create', 'add')) && !$canwrite) {
	accessforbidden();
}

$listurl = dol_buildpath('/diamantutils/lot_list.php', 1);
$cardurl = $_SERVER['PHP_SELF'].'?id='.((int) $object->id);


/*
 * Actions
 */

if ($cancel) {
	if ($object->id > 0) {
		header('Location: '.$cardurl);
	} else {
		header('Location: '.$listurl);
	}
	exit;
}

// Toute modification exige le jeton CSRF de la page
$writeactions = array('add', 'update', 'addline', 'updateline', 'deleteline', 'up', 'down', 'confirm_delete');
if (in_array($action, $writeactions) && GETPOST('token', 'alpha') !== currentToken()) {
	setEventMessages($langs->trans('DiamantutilsLotErrorToken'), null, 'errors');
	$action = ($object->id > 0 ? '' : 'create');
}

if ($action == 'add' && $canwrite) {
	$object->label = GETPOST('label', 'alphanohtml');
	$object->active = GETPOSTINT('active') ? 1 : 0;
	if ($object->create($user) > 0) {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}
	setEventMessages($object->error, $object->errors, 'errors');
	$action = 'create';
}

if ($action == 'update' && $canwrite && $object->id > 0) {
	$object->label = GETPOST('label', 'alphanohtml');
	$object->active = GETPOSTINT('active') ? 1 : 0;
	if ($object->update($user) > 0) {
		setEventMessages($langs->trans('RecordSaved'), null, 'mesgs');
		header('Location: '.$cardurl);
		exit;
	}
	setEventMessages($object->error, $object->errors, 'errors');
	$action = 'edit';
}

if ($action == 'confirm_delete' && $confirm == 'yes' && $candelete && $object->id > 0) {
	if ($object->delete($user) > 0) {
		setEventMessages($langs->trans('DiamantutilsLotDeleted', $object->label), null, 'mesgs');
		header('Location: '.$listurl);
		exit;
	}
	setEventMessages($object->error, $object->errors, 'errors');
	$action = '';
}

if ($action == 'addline' && $canwrite && $object->id > 0) {
	$fk_product = GETPOSTINT('fk_product');
	$qty = GETPOST('qty', 'alpha');
	if ($object->addLotLine($fk_product, $qty) > 0) {
		header('Location: '.$cardurl.'#addline');
		exit;
	}
	setEventMessages($object->error, $object->errors, 'errors');
	$action = '';
}

if ($action == 'updateline' && $canwrite && $object->id > 0 && $lineid > 0) {
	if ($object->updateLotLineQty($lineid, GETPOST('qty', 'alpha')) > 0) {
		header('Location: '.$cardurl.'#row-'.$lineid);
		exit;
	}
	setEventMessages($object->error, $object->errors, 'errors');
	$action = 'editline';
}

if ($action == 'deleteline' && $canwrite && $object->id > 0 && $lineid > 0) {
	if ($object->deleteLotLine($lineid) > 0) {
		header('Location: '.$cardurl);
		exit;
	}
	setEventMessages($object->error, $object->errors, 'errors');
	$action = '';
}

if (($action == 'up' || $action == 'down') && $canwrite && $object->id > 0 && $lineid > 0) {
	if ($object->moveLotLine($lineid, $action) >= 0) {
		header('Location: '.$cardurl.'#row-'.$lineid);
		exit;
	}
	setEventMessages($object->error, $object->errors, 'errors');
	$action = '';
}


/*
 * Vue
 */

$form = new Form($db);

$title = ($object->id > 0 ? $langs->trans('DiamantutilsLot').' - '.$object->label : $langs->trans('DiamantutilsNewLot'));
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-diamantutils page-lot-card');

// Création
if ($action == 'create') {
	print load_fiche_titre($langs->trans('DiamantutilsNewLot'), '', 'list');

	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="add">';

	print dol_get_fiche_head(array(), '');
	print '<table class="border centpercent tableforfieldcreate">';
	print '<tr><td class="titlefieldcreate fieldrequired">'.$langs->trans('Label').'</td>';
	print '<td><input type="text" class="flat minwidth300" name="label" maxlength="255" value="'.dol_escape_htmltag((string) $object->label).'" autofocus></td></tr>';
	print '<tr><td>'.$langs->trans('Enabled').'</td>';
	print '<td><input type="checkbox" name="active" value="1"'.($object->active ? ' checked' : '').'></td></tr>';
	print '</table>';
	print dol_get_fiche_end();

	print '<div class="opacitymedium center marginbottomonly">'.$langs->trans('DiamantutilsLotCreateHelp').'</div>';
	print $form->buttonsSaveCancel('Create');
	print '</form>';

	llxFooter();
	$db->close();
	exit;
}

// Confirmation de suppression
if ($action == 'delete' && $candelete) {
	print $form->formconfirm($cardurl, $langs->trans('DiamantutilsLotDelete'), $langs->trans('DiamantutilsLotConfirmDelete', $object->label), 'confirm_delete', '', 0, 1);
}

$head = array(array($cardurl, $langs->trans('DiamantutilsLot'), 'card'));
print dol_get_fiche_head($head, 'card', $langs->trans('DiamantutilsLot'), -1, 'list');

$linkback = '<a href="'.$listurl.'?restore_lastsearch_values=1">'.$langs->trans('BackToList').'</a>';
print '<div class="arearef heightref valignmiddle centpercent">';
print '<div class="inline-block floatleft valignmiddle refid">'.dol_escape_htmltag((string) $object->label).'</div>';
print '<div class="pagination paginationref"><ul class="right"><li class="noborder litext">'.$linkback.'</li></ul></div>';
print '</div>';
print '<div class="underrefbanner clearboth"></div>';

// En-tête : libellé, actif
if ($action == 'edit' && $canwrite) {
	print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'">';
	print '<input type="hidden" name="token" value="'.newToken().'">';
	print '<input type="hidden" name="action" value="update">';
	print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
}
print '<div class="fichecenter">';
print '<table class="border tableforfield centpercent">';
print '<tr><td class="titlefield'.($action == 'edit' ? ' fieldrequired' : '').'">'.$langs->trans('Label').'</td><td>';
if ($action == 'edit' && $canwrite) {
	print '<input type="text" class="flat minwidth300" name="label" maxlength="255" value="'.dol_escape_htmltag((string) $object->label).'" autofocus>';
} else {
	print dol_escape_htmltag((string) $object->label);
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('Status').'</td><td>';
if ($action == 'edit' && $canwrite) {
	print '<label><input type="checkbox" name="active" value="1"'.($object->active ? ' checked' : '').'> '.$langs->trans('Enabled').'</label>';
	print ' <span class="opacitymedium">'.$langs->trans('DiamantutilsLotActiveHelp').'</span>';
} else {
	print ($object->active ? dolGetStatus($langs->trans('Enabled'), '', '', 'status4', 1) : dolGetStatus($langs->trans('Disabled'), '', '', 'status5', 1));
}
print '</td></tr>';
print '<tr><td>'.$langs->trans('DateCreation').'</td><td>'.dol_print_date($object->datec, 'dayhour');
if ($object->fk_user_creat > 0) {
	$creator = new User($db);
	if ($creator->fetch($object->fk_user_creat) > 0) {
		print ' &nbsp; '.$creator->getNomUrl(-1);
	}
}
print '</td></tr>';
print '</table>';
print '</div>';
if ($action == 'edit' && $canwrite) {
	print $form->buttonsSaveCancel('Save');
	print '</form>';
}

print dol_get_fiche_end();

// Boutons
if ($action != 'edit') {
	print '<div class="tabsAction">';
	print dolGetButtonAction($langs->trans('Modify'), '', 'default', $cardurl.'&action=edit&token='.newToken(), '', $canwrite);
	print dolGetButtonAction($langs->trans('Delete'), '', 'delete', $cardurl.'&action=delete&token='.newToken(), '', $candelete);
	print '</div>';
}

// Lignes du lot
print load_fiche_titre($langs->trans('DiamantutilsLotLines'), '', '');
print '<div class="opacitymedium marginbottomonly">'.$langs->trans('DiamantutilsLotLinesHelp').'</div>';

print '<form method="POST" action="'.$_SERVER['PHP_SELF'].'#addline">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="id" value="'.((int) $object->id).'">';
print '<input type="hidden" name="action" value="'.($action == 'editline' ? 'updateline' : 'addline').'">';
if ($action == 'editline') {
	print '<input type="hidden" name="lineid" value="'.((int) $lineid).'">';
}

print '<div class="div-table-responsive-no-min">';
print '<table class="noborder centpercent" id="tablelines">';
print '<tr class="liste_titre">';
print '<td class="width25 center">#</td>';
print '<td>'.$langs->trans('Product').'</td>';
print '<td class="right width100">'.$langs->trans('Qty').'</td>';
print '<td class="center width100"></td>';
print '</tr>';

$productstatic = new Product($db);
$nblines = count($object->lines);
foreach ($object->lines as $i => $line) {
	print '<tr class="oddeven" id="row-'.((int) $line->id).'">';
	print '<td class="center opacitymedium">'.($i + 1).'</td>';

	// Produit (supprimé depuis la création du lot : signalé, la ligne bloquera l'ajout du lot)
	print '<td class="tdoverflowmax500">';
	if ($line->product_ref === null) {
		print img_warning().' <span class="warning">'.$langs->trans('DiamantutilsLotProductDeleted', (int) $line->fk_product).'</span>';
	} else {
		$productstatic->id = $line->fk_product;
		$productstatic->ref = $line->product_ref;
		$productstatic->label = $line->product_label;
		$productstatic->type = $line->product_type;
		$productstatic->status = $line->product_tosell;
		print $productstatic->getNomUrl(1).' <span class="opacitymedium">'.dol_escape_htmltag((string) $line->product_label).'</span>';
		if (empty($line->product_tosell)) {
			print ' '.img_warning($langs->trans('DiamantutilsLotProductNotForSale')).' <span class="warning small">'.$langs->trans('ProductStatusNotOnSell').'</span>';
		}
	}
	print '</td>';

	// Quantité
	print '<td class="right nowraponall">';
	if ($action == 'editline' && $lineid == $line->id && $canwrite) {
		print '<input type="number" class="flat width75 right" name="qty" value="'.dol_escape_htmltag(price2num($line->qty, 'MS')).'" min="0" step="any" autofocus>';
	} else {
		print diamantutils_qty_format($line->qty);
	}
	print '</td>';

	// Actions
	print '<td class="center nowraponall">';
	if ($canwrite) {
		if ($action == 'editline' && $lineid == $line->id) {
			print '<input type="submit" class="button buttongen button-save small" name="save" value="'.$langs->trans('Save').'">';
			print ' <input type="submit" class="button buttongen button-cancel small" name="cancel" value="'.$langs->trans('Cancel').'">';
		} elseif ($action != 'editline') {
			print '<a class="editfielda reposition paddingright" href="'.$cardurl.'&action=editline&lineid='.((int) $line->id).'#row-'.((int) $line->id).'">'.img_edit().'</a>';
			if ($i > 0) {
				print '<a class="reposition paddingright" href="'.$cardurl.'&action=up&lineid='.((int) $line->id).'&token='.newToken().'">'.img_up().'</a>';
			} else {
				print '<span class="paddingright opacitytransp">'.img_up().'</span>';
			}
			if ($i < $nblines - 1) {
				print '<a class="reposition paddingright" href="'.$cardurl.'&action=down&lineid='.((int) $line->id).'&token='.newToken().'">'.img_down().'</a>';
			} else {
				print '<span class="paddingright opacitytransp">'.img_down().'</span>';
			}
			print '<a class="reposition" href="'.$cardurl.'&action=deleteline&lineid='.((int) $line->id).'&token='.newToken().'">'.img_delete().'</a>';
		}
	}
	print '</td>';
	print '</tr>';
}

if (empty($object->lines)) {
	print '<tr class="oddeven"><td colspan="4"><span class="opacitymedium">'.$langs->trans('DiamantutilsLotNoLine').'</span></td></tr>';
}

// Ajout de ligne en bas du tableau
if ($canwrite && $action != 'editline') {
	print '<tr class="liste_titre nodrag nodrop" id="addline">';
	print '<td></td><td>'.$langs->trans('DiamantutilsLotAddLine').'</td><td class="right">'.$langs->trans('Qty').'</td><td></td>';
	print '</tr>';
	print '<tr class="oddeven nodrag nodrop">';
	print '<td></td>';
	print '<td>';
	// Produits en vente seulement, sans prix dans le libellé
	$form->select_produits(0, 'fk_product', '', getDolGlobalInt('PRODUIT_LIMIT_SIZE'), 0, 1, 2, '', 0, array(), 0, '1', 0, 'minwidth300 maxwidth500 widthcentpercentminusx', 1);
	print '</td>';
	print '<td class="right"><input type="number" class="flat width75 right" name="qty" value="1" min="0" step="any"></td>';
	print '<td class="center"><input type="submit" class="button reposition small" value="'.$langs->trans('Add').'"></td>';
	print '</tr>';
}

print '</table>';
print '</div>';
print '</form>';

llxFooter();
$db->close();
