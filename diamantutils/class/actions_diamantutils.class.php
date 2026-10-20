<?php
/**
 * Hooks du module DiamantUtils
 *
 * Commandes sous-traitées (livraison directe client) :
 *  - ordersupplierdispatch (fourn/commande/dispatch.php) :
 *      doActions : mémorise le MAX(rowid) des lignes ventilées avant une ventilation directe ;
 *      printCommonFooter : pré-remplissage entrepôt / lot + bandeau ;
 *  - receptioncard (reception/card.php, action=create) : pré-remplissage entrepôt / lot + bandeau ;
 *  - ordershipmentcard (expedition/shipment.php) : entrepôt Sous-traitance présélectionné + bandeau ;
 *  - expeditioncard (expedition/card.php, action=create) : bandeau + lots non liés signalés.
 *
 * Description pour devis :
 *  - productcard (product/card.php) formObjectOptions : sur un produit parent de variantes,
 *    liste des placeholders disponibles, affichée sous l'attribut desc_propal.
 *
 * Lots de produits (propalcard, ordercard) :
 *  - formAddObjectLine : ligne « Ajouter un lot » au-dessus du formulaire d'ajout standard
 *    (affichée par print, retour 0 : les autres modules, dont productselector, s'affichent aussi) ;
 *  - doActions : action addlot (insertion du lot par addline()), confirm_diamantutils_savelot ;
 *  - addMoreActionsButtons / formConfirm : « Enregistrer comme lot ».
 *
 * L'instance de cette classe est partagée par tous les hooks de la page et HookManager ne
 * vide pas resprints entre deux appels : chaque méthode le vide en entrée.
 */

class ActionsDiamantutils
{
	/** @var DoliDB */
	public $db;

	public $error = '';

	public $errors = array();

	public $results = array();

	public $resprints = '';

	/**
	 * @param	DoliDB	$db		Base de données
	 */
	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * @param	array		$parameters		Paramètres du hook
	 * @param	Object		$object			Objet de la page
	 * @param	string		$action			Action
	 * @param	HookManager	$hookmanager	Gestionnaire de hooks
	 * @return	int							0 = traitement standard poursuivi
	 */
	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';
		$contexts = explode(':', (string) $parameters['context']);

		// Lots de produits sur devis / commande client
		$element = $this->lotPieceElement($contexts, $object);
		if ($element !== '' && $action == 'addlot') {
			return $this->doAddLot($object, $action, $element);
		}
		if ($element !== '' && $action == 'confirm_diamantutils_savelot' && GETPOST('confirm', 'alpha') == 'yes') {
			return $this->doSaveLot($object, $action);
		}

		if (in_array('ordersupplierdispatch', $contexts) && $action == 'dispatch' && !empty($object->id)) {
			dol_include_once('/diamantutils/lib/soustraitance.lib.php');
			if (diamantutils_st_enabled()) {
				dol_include_once('/diamantutils/class/soustraitance.class.php');
				$st = new DiamantutilsSoustraitance($this->db);
				$st->rememberDispatchMaxRowid($object->id);
			}
		}

		return 0;
	}

	/**
	 * Pied de page : injecte le pré-remplissage des écrans de réception
	 *
	 * @param	array		$parameters		Paramètres du hook
	 * @param	Object		$object			Objet (non fourni par printCommonFooter)
	 * @param	string		$action			Action
	 * @param	HookManager	$hookmanager	Gestionnaire de hooks
	 * @return	int							0
	 */
	public function printCommonFooter($parameters, &$object, &$action, $hookmanager)
	{
		static $done = false;

		$this->resprints = '';
		if ($done || empty($GLOBALS['conf']->use_javascript_ajax)) {
			return 0;
		}
		$contexts = explode(':', (string) $parameters['context']);

		// Expédition : choix de l'entrepôt (onglet Expéditions de la commande) et création du BL
		if (in_array('ordershipmentcard', $contexts) || in_array('expeditioncard', $contexts)) {
			return $this->printShipmentPrefill($contexts);
		}

		$fk_cf = 0;
		$mode = '';
		if (in_array('ordersupplierdispatch', $contexts)) {
			$fk_cf = GETPOSTINT('id');
			$mode = 'dispatch';
		} elseif (in_array('receptioncard', $contexts) && $GLOBALS['action'] == 'create' && GETPOST('origin', 'aZ09') == 'supplierorder') {
			$fk_cf = GETPOSTINT('originid');
			$mode = 'reception';
		}
		if ($fk_cf <= 0) {
			return 0;
		}

		dol_include_once('/diamantutils/lib/soustraitance.lib.php');
		if (!diamantutils_st_enabled()) {
			return 0;
		}
		dol_include_once('/diamantutils/class/soustraitance.class.php');
		$st = new DiamantutilsSoustraitance($this->db);
		$cf = $st->getSupplierOrder($fk_cf);
		if (empty($cf) || !$cf['direct']) {
			return 0;
		}

		global $langs;
		$langs->load('diamantutils@diamantutils');
		$done = true;

		$config = array(
			'mode' => $mode,
			'warehouse' => diamantutils_st_warehouse_id(),
			'lot' => $cf['ref'],
			'banner' => $langs->transnoentitiesnoconv('DiamantutilsSTBannerReception', $cf['ref']),
		);
		print '<script>var diamantutilsST = '.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>'."\n";
		print '<script src="'.dol_buildpath('/diamantutils/js/soustraitance.js', 1).'?v=2.2b"></script>'."\n";

		return 0;
	}

	/**
	 * Pré-remplissage expédition : entrepôt Sous-traitance présélectionné (shipment.php),
	 * bandeau des lots CF disponibles, lots non liés signalés (expedition/card.php?action=create)
	 *
	 * @param	string[]	$contexts	Contextes de la page
	 * @return	int						0
	 */
	private function printShipmentPrefill($contexts)
	{
		global $langs;

		$action = (string) $GLOBALS['action'];
		if (in_array('ordershipmentcard', $contexts)) {
			$mode = 'shipment';
			$fk_commande = GETPOSTINT('id');
		} else {
			if ($action != 'create' || (GETPOST('origin', 'aZ09') && GETPOST('origin', 'aZ09') != 'commande')) {
				return 0;
			}
			$mode = 'expedition';
			$fk_commande = GETPOSTINT('origin_id') ? GETPOSTINT('origin_id') : GETPOSTINT('object_id');
		}
		if ($fk_commande <= 0) {
			return 0;
		}

		dol_include_once('/diamantutils/lib/soustraitance.lib.php');
		if (!diamantutils_st_enabled()) {
			return 0;
		}
		dol_include_once('/diamantutils/class/soustraitance.class.php');
		$st = new DiamantutilsSoustraitance($this->db);
		$expected = $st->getExpectedLots($fk_commande);
		if (empty($expected)) {
			return 0;
		}

		$langs->load('diamantutils@diamantutils');

		// Lots CF disponibles dans l'entrepôt Sous-traitance
		$available = array();
		foreach ($st->getWarehouseLots($expected) as $lot) {
			$available[] = $lot['batch'].' ('.$lot['product_ref'].' : '.price2num($lot['qty'], 'MS').')';
		}
		$banner = $langs->transnoentitiesnoconv('DiamantutilsSTBannerShipment', implode(', ', $expected));
		$banner .= ' '.(empty($available) ? $langs->transnoentitiesnoconv('DiamantutilsSTBannerShipmentNoStock') : $langs->transnoentitiesnoconv('DiamantutilsSTBannerShipmentStock', implode(' ; ', $available)));

		// Lots de l'entrepôt Sous-traitance non attendus pour cette commande : rowid product_batch => lot
		$foreign = array();
		if ($mode == 'expedition') {
			foreach ($st->getWarehouseLots() as $lot) {
				if (!in_array($lot['batch'], $expected, true)) {
					$foreign[$lot['fk_product_batch']] = $lot['batch'];
				}
			}
		}

		$config = array(
			'mode' => $mode,
			'warehouse' => diamantutils_st_warehouse_id(),
			'banner' => $banner,
			'foreign' => (object) $foreign,
			'foreignLabel' => $langs->transnoentitiesnoconv('DiamantutilsSTForeignLot'),
		);
		print '<script>var diamantutilsST = '.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';</script>'."\n";
		print '<script src="'.dol_buildpath('/diamantutils/js/soustraitance.js', 1).'?v=2.2b"></script>'."\n";

		return 0;
	}

	/**
	 * Attributs complémentaires : aide à la saisie de desc_propal sur un produit parent de variantes
	 *
	 * @param	array		$parameters		Paramètres du hook
	 * @param	Object		$object			Produit
	 * @param	string		$action			Action
	 * @param	HookManager	$hookmanager	Gestionnaire de hooks
	 * @return	int							0 = attributs standard affichés
	 */
	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';
		$contexts = explode(':', (string) $parameters['context']);
		if (!in_array('productcard', $contexts) || !isModEnabled('variants') || !is_object($object) || $object->element != 'product' || empty($object->id)) {
			return 0;
		}

		dol_include_once('/diamantutils/lib/diamantutils_descpropal.lib.php');
		$attributes = diamantutils_descpropal_parent_attributes($this->db, $object->id);
		if (empty($attributes)) {
			return 0;	// pas un produit parent
		}

		global $langs;
		$langs->load('diamantutils@diamantutils');

		$lines = array();
		foreach ($attributes as $attribute) {
			$code = dol_escape_htmltag($attribute['ref']);
			$values = array_slice($attribute['values'], 0, 6, true);
			$more = (count($attribute['values']) > 6 ? ', …' : '');
			$lines[] = '<code>{'.$code.'}</code> '.dol_escape_htmltag($attribute['label'])
				.' <span class="opacitymedium">('.dol_escape_htmltag(implode(', ', $values)).$more.')</span>'
				.' &nbsp; <code>{'.$code.':ref}</code> <span class="opacitymedium">('.dol_escape_htmltag(implode(', ', array_keys($values))).$more.')</span>'
				.' &nbsp; <code>{'.$code.':label}</code>';
		}
		$lines[] = '<code>{PRODUCT_REF}</code> <code>{PRODUCT_LABEL}</code> <span class="opacitymedium">'.$langs->trans('DiamantutilsDescPropalHelpProduct').'</span>';
		$lines[] = '<span class="opacitymedium">'.$langs->trans('DiamantutilsDescPropalConditions').'</span>';

		$colspan = (!empty($parameters['colspan']) ? $parameters['colspan'] : '');
		$out = '<tr id="diamantutils-descpropal-help"><td class="titlefield">'.$langs->trans('DiamantutilsDescPropalPlaceholders').'</td>';
		$out .= '<td'.$colspan.'><span class="opacitymedium">'.$langs->trans('DiamantutilsDescPropalPlaceholdersDesc').'</span><br>'.implode('<br>', $lines).'</td></tr>'."\n";
		// Ligne déplacée sous l'attribut desc_propal (fiche et formulaire de modification)
		$out .= '<script>jQuery(function () { var row = jQuery(".product_extras_desc_propal").first().closest("tr"); if (row.length) { row.after(jQuery("#diamantutils-descpropal-help")); } });</script>'."\n";

		$this->resprints = $out;
		return 0;
	}

	/*
	 * Lots de produits
	 */

	/**
	 * Pièce gérée par les lots : 'propal' (fiche devis) ou 'commande' (fiche commande client)
	 *
	 * @param	string[]	$contexts	Contextes de la page
	 * @param	Object		$object		Objet de la page
	 * @return	string					'propal', 'commande' ou '' si la page n'est pas concernée
	 */
	private function lotPieceElement($contexts, $object)
	{
		if (!is_object($object) || empty($object->id)) {
			return '';
		}
		if (in_array('propalcard', $contexts) && $object->element == 'propal') {
			return 'propal';
		}
		if (in_array('ordercard', $contexts) && $object->element == 'commande') {
			return 'commande';
		}
		return '';
	}

	/**
	 * Droit de modifier la pièce (ajout de lignes)
	 *
	 * @param	string	$element	'propal' ou 'commande'
	 * @return	bool
	 */
	private function userCanEditPiece($element)
	{
		global $user;
		return (bool) $user->hasRight($element, 'creer');
	}

	/**
	 * Ajout d'un lot possible : fonction active, pièce en brouillon, droits lot et pièce
	 *
	 * @param	Object	$object		Pièce
	 * @param	string	$element	'propal' ou 'commande'
	 * @return	bool
	 */
	private function canAddLot($object, $element)
	{
		global $user;

		dol_include_once('/diamantutils/lib/lot.lib.php');
		return diamantutils_lot_enabled()
			&& (int) $object->statut === 0
			&& $user->hasRight('diamantutils', 'lot', 'read')
			&& $this->userCanEditPiece($element);
	}

	/**
	 * Ligne « Ajouter un lot » au-dessus du formulaire d'ajout de ligne standard.
	 *
	 * Le tableau des lignes est dans le formulaire addproduct de la fiche : un formulaire
	 * imbriqué est impossible. Le bouton construit un formulaire séparé (action=addlot) au
	 * clic, pour ne pas envoyer les champs du formulaire standard.
	 *
	 * @param	array		$parameters		Paramètres du hook
	 * @param	Object		$object			Pièce
	 * @param	string		$action			Action
	 * @param	HookManager	$hookmanager	Gestionnaire de hooks
	 * @return	int							0 = formulaire standard affiché aussi
	 */
	public function formAddObjectLine($parameters, &$object, &$action, $hookmanager)
	{
		global $langs;

		$this->resprints = '';
		$contexts = explode(':', (string) $parameters['context']);
		$element = $this->lotPieceElement($contexts, $object);
		if ($element === '' || !$this->canAddLot($object, $element)) {
			return 0;
		}

		dol_include_once('/diamantutils/class/diamantlot.class.php');
		$static = new DiamantLot($this->db);
		$lots = $static->fetchAll(1);
		if (!is_array($lots)) {
			dol_syslog(__METHOD__.' '.$static->error, LOG_ERR);
			return 0;
		}
		$options = array();
		foreach ($lots as $lot) {
			if ($lot->nb_lines > 0) {
				$options[$lot->id] = $lot->label.' ('.$lot->nb_lines.')';
			}
		}
		if (empty($options)) {
			return 0;	// aucun lot actif utilisable
		}

		$langs->load('diamantutils@diamantutils');

		print '<tr class="liste_titre nodrag nodrop diamantutils-addlot">';
		print '<td class="linecoldescription" colspan="10">'.img_picto('', 'list', 'class="pictofixedwidth"').$langs->trans('DiamantutilsLotAddTitle').'</td>';
		print '</tr>'."\n";
		print '<tr class="oddeven nodrag nodrop diamantutils-addlot" id="diamantutils-addlot-row">';
		print '<td class="nobottom" colspan="10">';
		print Form::selectarray('diamantutils_lotid', $options, '', 1, 0, 0, '', 0, 0, 0, '', 'minwidth300 maxwidth500', 1);
		print ' <span class="paddingleft paddingright">'.$langs->trans('DiamantutilsLotMultiplier').'</span>';
		print '<input type="number" class="flat width50 right" id="diamantutils_lotmult" value="1" min="0" step="any">';
		print ' <input type="button" class="button small reposition" id="diamantutils-addlot-btn" value="'.dol_escape_htmltag($langs->trans('DiamantutilsLotAddButton')).'">';
		print '</td>';
		print '</tr>'."\n";

		$config = array(
			'url' => $_SERVER['PHP_SELF'],
			'id' => (int) $object->id,
			'token' => newToken(),
			'noLot' => $langs->transnoentitiesnoconv('DiamantutilsLotErrorChoose'),
		);
		print '<script>
jQuery(function ($) {
	var cfg = '.json_encode($config, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT).';
	// Largeur : nombre de colonnes du tableau des lignes
	var cols = 0;
	$("#tablelines tr.liste_titre").not(".diamantutils-addlot").first().children("td,th").each(function () {
		cols += parseInt($(this).attr("colspan") || 1, 10);
	});
	if (cols > 0) {
		$("tr.diamantutils-addlot > td").attr("colspan", cols);
	}
	$("#diamantutils-addlot-btn").on("click", function (e) {
		e.preventDefault();
		var lot = parseInt($("#diamantutils_lotid").val(), 10);
		if (!(lot > 0)) {
			alert(cfg.noLot);
			return;
		}
		var form = $("<form>", {method: "POST", action: cfg.url});
		$.each({token: cfg.token, action: "addlot", id: cfg.id, diamantutils_lotid: lot, diamantutils_lotmult: $("#diamantutils_lotmult").val()}, function (name, value) {
			form.append($("<input>", {type: "hidden", name: name, value: value}));
		});
		$("body").append(form);
		$(this).prop("disabled", true);
		form.get(0).submit();
	});
	// Entrée dans le multiplicateur : ajout du lot, pas envoi du formulaire standard
	$("#diamantutils_lotmult").on("keydown", function (e) {
		if (e.key === "Enter") {
			e.preventDefault();
			$("#diamantutils-addlot-btn").trigger("click");
		}
	});
});
</script>'."\n";

		return 0;
	}

	/**
	 * Action addlot : ajoute les lignes du lot choisi à la pièce, dans une transaction
	 *
	 * @param	Propal|Commande	$object		Pièce
	 * @param	string			$action		Action (vidée en cas d'erreur)
	 * @param	string			$element	'propal' ou 'commande'
	 * @return	int							0
	 */
	private function doAddLot($object, &$action, $element)
	{
		global $langs, $user;

		$langs->load('diamantutils@diamantutils');
		dol_include_once('/diamantutils/lib/lot.lib.php');
		dol_include_once('/diamantutils/class/diamantlot.class.php');
		$action = '';

		if (GETPOST('token', 'alpha') !== currentToken()) {
			setEventMessages($langs->trans('DiamantutilsLotErrorToken'), null, 'errors');
			return 0;
		}
		if (!diamantutils_lot_enabled()) {
			setEventMessages($langs->trans('DiamantutilsFeatureDisabled'), null, 'errors');
			return 0;
		}
		if (!$user->hasRight('diamantutils', 'lot', 'read') || !$this->userCanEditPiece($element)) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
			return 0;
		}
		if ((int) $object->statut !== 0) {
			setEventMessages($langs->trans('DiamantutilsLotErrorNotDraft'), null, 'errors');
			return 0;
		}

		$mult = price2num(GETPOST('diamantutils_lotmult', 'alpha'), 'MS');
		if ($mult === '' || !((float) $mult > 0)) {
			setEventMessages($langs->trans('DiamantutilsLotErrorMult'), null, 'errors');
			return 0;
		}

		$lot = new DiamantLot($this->db);
		if ($lot->fetch(GETPOSTINT('diamantutils_lotid')) <= 0 || !$lot->active) {
			setEventMessages($langs->trans('DiamantutilsLotErrorChoose'), null, 'errors');
			return 0;
		}

		$error = '';
		$errors = array();
		$nb = diamantutils_lot_insert($this->db, $object, $lot->lines, (float) $mult, $user, $error, $errors);
		if ($nb < 0) {
			setEventMessages($langs->trans('DiamantutilsLotErrorRollback', $lot->label).' '.$error, $errors, 'errors');
			return 0;
		}

		diamantutils_lot_regenerate_document($object);

		setEventMessages($langs->trans('DiamantutilsLotAdded', $nb, $lot->label), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id).'#addline');
		exit;
	}

	/**
	 * Action confirm_diamantutils_savelot : crée un lot avec les lignes produit de la pièce
	 *
	 * @param	Propal|Commande	$object		Pièce
	 * @param	string			$action		Action (vidée)
	 * @return	int							0
	 */
	private function doSaveLot($object, &$action)
	{
		global $langs, $user;

		$langs->load('diamantutils@diamantutils');
		dol_include_once('/diamantutils/lib/lot.lib.php');
		dol_include_once('/diamantutils/class/diamantlot.class.php');
		$action = '';

		if (GETPOST('token', 'alpha') !== currentToken()) {
			setEventMessages($langs->trans('DiamantutilsLotErrorToken'), null, 'errors');
			return 0;
		}
		if (!diamantutils_lot_enabled() || !$user->hasRight('diamantutils', 'lot', 'write')) {
			setEventMessages($langs->trans('NotEnoughPermissions'), null, 'errors');
			return 0;
		}

		if (empty($object->lines) && method_exists($object, 'fetch_lines')) {
			$object->fetch_lines();
		}
		$lot = new DiamantLot($this->db);
		$lot->label = GETPOST('diamantutils_lot_label', 'alphanohtml');
		$lot->active = 1;
		$lot->lines = diamantutils_lot_lines_from_object($object);
		if (empty($lot->lines)) {
			setEventMessages($langs->trans('DiamantutilsLotErrorNoProductLine'), null, 'errors');
			return 0;
		}
		if ($lot->create($user) <= 0) {
			setEventMessages($lot->error, $lot->errors, 'errors');
			return 0;
		}

		setEventMessages($langs->trans('DiamantutilsLotSaved', $lot->getNomUrl(1), count($lot->lines)), null, 'mesgs');
		header('Location: '.$_SERVER['PHP_SELF'].'?id='.((int) $object->id));
		exit;
	}

	/**
	 * Bouton « Enregistrer comme lot » sur la fiche devis / commande (tout statut)
	 *
	 * @param	array		$parameters		Paramètres du hook
	 * @param	Object		$object			Pièce
	 * @param	string		$action			Action
	 * @param	HookManager	$hookmanager	Gestionnaire de hooks
	 * @return	int							0 = boutons standard affichés aussi
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$this->resprints = '';
		$contexts = explode(':', (string) $parameters['context']);
		if ($this->lotPieceElement($contexts, $object) === '' || in_array($action, array('editline', 'presend'))) {
			return 0;
		}
		dol_include_once('/diamantutils/lib/lot.lib.php');
		if (!diamantutils_lot_enabled() || !$user->hasRight('diamantutils', 'lot', 'write')) {
			return 0;
		}

		$langs->load('diamantutils@diamantutils');
		$url = $_SERVER['PHP_SELF'].'?id='.((int) $object->id).'&action=diamantutils_savelot&token='.newToken();
		print dolGetButtonAction($langs->trans('DiamantutilsLotSaveAs'), '', 'default', $url, 'diamantutils-savelot', 1);

		return 0;
	}

	/**
	 * Demande du libellé avant « Enregistrer comme lot »
	 *
	 * @param	array		$parameters		Paramètres du hook
	 * @param	Object		$object			Pièce
	 * @param	string		$action			Action
	 * @param	HookManager	$hookmanager	Gestionnaire de hooks
	 * @return	int							0 = confirmations standard conservées
	 */
	public function formConfirm($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$this->resprints = '';
		$contexts = explode(':', (string) $parameters['context']);
		if ($action != 'diamantutils_savelot' || $this->lotPieceElement($contexts, $object) === '') {
			return 0;
		}
		dol_include_once('/diamantutils/lib/lot.lib.php');
		if (!diamantutils_lot_enabled() || !$user->hasRight('diamantutils', 'lot', 'write')) {
			return 0;
		}

		$langs->load('diamantutils@diamantutils');
		$nb = count(diamantutils_lot_lines_from_object($object));
		$form = new Form($this->db);
		$formquestion = array(
			array(
				'type' => 'text',
				'name' => 'diamantutils_lot_label',
				'label' => $langs->trans('Label'),
				'value' => (string) $object->ref,
				'morecss' => 'minwidth300',
			),
		);
		$this->resprints = $form->formconfirm(
			$_SERVER['PHP_SELF'].'?id='.((int) $object->id),
			$langs->trans('DiamantutilsLotSaveAs'),
			$langs->trans('DiamantutilsLotSaveAsQuestion', $nb),
			'confirm_diamantutils_savelot',
			$formquestion,
			'yes',
			1,
			0,
			550
		);

		return 0;
	}
}