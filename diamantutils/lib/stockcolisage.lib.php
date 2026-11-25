<?php
/**
 * Onglet « Stock » de la fiche produit : tableau de remplacement avec colonne Colisage
 *
 * Page cœur : product/stock/product.php (contexte de hook stockproductcard), jamais modifiée.
 * Le module affiche son propre tableau dans le hook addMoreActionsButtons (appelé juste avant
 * les boutons « Transférer / Corriger le stock » et le tableau natif) ; le script
 * js/stockcolisage.js le place devant le tableau natif puis masque celui-ci. Si le rendu
 * échoue (exception, erreur SQL) rien n'est affiché : le tableau natif reste visible.
 *
 * Références vérifiées dans le cœur (Dolibarr 20.0.0 et 23.0.0, product/stock/product.php) :
 *  - initHooks(array('stockproductcard', 'globalcard')) ; addMoreActionsButtons appelé avec le
 *    produit, $hookmanager->resPrint jamais affiché (le module affiche par print) ;
 *  - entrepôts : entrepot × product_stock, ps.reel != 0, e.entity IN (getEntity('stock')),
 *    ORDER BY e.ref ; quantité affichée price2num(reel, 'MS'), total price2num(total, 'MS') ;
 *  - lots : Productbatch::findAll($db, product_stock_id, 0, fk_product), quantité affichée
 *    telle quelle ; lotid = 0 sans ligne product_lot (nom affiché sans lien) ;
 *  - avertissements : quantité < 0, quantité > 1 pour un numéro de série (status_batch == 2) ;
 *  - liens d'un lot : ?id=&id_entrepot=&action=transfert|correction&pdluoid=&token=, absents
 *    si l'entrepôt est fermé (Entrepot::STATUS_CLOSED) ;
 *  - repli : classes collapse_batch / batch_warehouse<id>, #show_all / #hide_all,
 *    STOCK_SHOW_ALL_BATCH_BY_DEFAULT ;
 *  - tableau affiché si !$variants || VARIANT_ALLOW_STOCK_MOVEMENT_ON_VARIANT_PARENT.
 * Différences v23 : action « transfer » (au lieu de « transfert ») dans les liens, et tableau
 * masqué aussi pour un kit (hasFatherOrChild(1)) sans PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE.
 */

/**
 * Fonctionnalité « Colisage sur l'onglet Stock » active ?
 * Constante absente = désactivée.
 *
 * @return	bool
 */
function diamantutils_stockcolisage_enabled()
{
	return isModEnabled('diamantutils') && !empty(getDolGlobalString('DIAMANTUTILS_STOCKCOLISAGE_ENABLED', '0'));
}

/**
 * Code de l'attribut supplémentaire du lot qui porte le colisage
 *
 * @return	string	Code ('' si la constante est invalide)
 */
function diamantutils_stockcolisage_extrafield_code()
{
	$code = getDolGlobalString('DIAMANTUTILS_STOCKCOLISAGE_EXTRAFIELD', 'colisage');
	return (preg_match('/^[a-z0-9_]{1,64}$/i', $code) ? $code : '');
}

/**
 * L'attribut du lot existe-t-il (élément product_lot) ?
 *
 * @param	DoliDB	$db		Base de données
 * @param	string	$code	Code de l'attribut ('' = code configuré)
 * @return	bool
 */
function diamantutils_stockcolisage_extrafield_exists($db, $code = '')
{
	static $cache = array();

	if ($code === '') {
		$code = diamantutils_stockcolisage_extrafield_code();
	}
	if ($code === '') {
		return false;
	}
	if (!array_key_exists($code, $cache)) {
		$cache[$code] = false;
		$sql = "SELECT COUNT(*) as nb FROM ".MAIN_DB_PREFIX."extrafields";
		$sql .= " WHERE name = '".$db->escape($code)."' AND elementtype = 'product_lot'";
		$sql .= " AND entity IN (0, ".((int) $GLOBALS['conf']->entity).")";
		$resql = $db->query($sql);
		if ($resql) {
			$obj = $db->fetch_object($resql);
			$cache[$code] = ($obj && $obj->nb > 0);
			$db->free($resql);
		}
	}
	return $cache[$code];
}

/**
 * La valeur enregistrée contient-elle des balises HTML (saisie depuis la fiche lot) ?
 *
 * @param	string|null	$raw	Valeur enregistrée
 * @return	bool
 */
function diamantutils_stockcolisage_is_html($raw)
{
	return (bool) preg_match('/<[a-z\/!][^>]*>/i', (string) $raw);
}

/**
 * Texte affiché et édité pour une valeur enregistrée : sans balises, sur une ligne
 *
 * Valeur saisie ici (échappée, sans balise) : entités décodées, le texte saisi revient tel
 * quel (« <unité> » reste visible). Valeur avec balises (saisie depuis la fiche lot) :
 * aplatie par dol_string_nohtmltag().
 *
 * @param	string|null	$raw	Valeur enregistrée (attribut « Texte HTML »)
 * @return	string				Texte brut (à échapper à l'affichage)
 */
function diamantutils_stockcolisage_text($raw)
{
	if ($raw === null || $raw === '') {
		return '';
	}
	if (diamantutils_stockcolisage_is_html($raw)) {
		$text = dol_string_nohtmltag((string) $raw, 1);
	} else {
		$text = dol_html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
		$text = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', $text);
	}
	return trim(preg_replace('/\s{2,}/u', ' ', $text));
}

/**
 * Valeur enregistrée pour un texte saisi : échappée (attribut « Texte HTML »), aucune balise
 * active ; <b> et <strong> saisis restent du texte (keepb).
 *
 * @param	string	$text	Texte nettoyé
 * @return	string|null		Valeur à enregistrer, null pour vider l'attribut
 */
function diamantutils_stockcolisage_storage_value($text)
{
	$text = (string) $text;
	return ($text === '' ? null : dol_escape_htmltag($text, 1));
}

/**
 * Le tableau de remplacement s'applique-t-il à ce produit ?
 * Mêmes conditions d'affichage que le tableau natif, produits à lots seulement.
 *
 * @param	Product	$object		Produit de la page
 * @return	bool
 */
function diamantutils_stockcolisage_applies($object)
{
	global $conf;

	if (!diamantutils_stockcolisage_enabled() || empty($conf->use_javascript_ajax)) {
		return false;
	}
	if (!is_object($object) || empty($object->id) || $object->element != 'product' || !isModEnabled('productbatch')) {
		return false;
	}
	if (!method_exists($object, 'hasbatch') || !$object->hasbatch()) {
		return false;
	}
	if (method_exists($object, 'hasVariants') && $object->hasVariants() && !getDolGlobalString('VARIANT_ALLOW_STOCK_MOVEMENT_ON_VARIANT_PARENT')) {
		return false;	// pas de tableau natif non plus
	}
	if (diamantutils_stockcolisage_dolversion() >= 23 && method_exists($object, 'hasFatherOrChild')
		&& $object->hasFatherOrChild(1) && !getDolGlobalInt('PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE')) {
		return false;	// v23 : pas de tableau natif pour un kit
	}
	return true;
}

/**
 * Version majeure de Dolibarr
 *
 * @return	int
 */
function diamantutils_stockcolisage_dolversion()
{
	return (int) (defined('DOL_VERSION') ? DOL_VERSION : 0);
}

/**
 * Action « transférer » des liens de la page : transfert (≤ v22) ou transfer (≥ v23)
 *
 * @return	string
 */
function diamantutils_stockcolisage_transfer_action()
{
	return (diamantutils_stockcolisage_dolversion() >= 23 ? 'transfer' : 'transfert');
}

/**
 * Données du tableau : entrepôts (requête du cœur) et lots (Productbatch::findAll)
 *
 * @param	DoliDB	$db		Base de données
 * @param	Product	$object	Produit
 * @return	array			array('warehouses' => [...], 'total' => float)
 * @throws	Exception		Erreur SQL
 */
function diamantutils_stockcolisage_load($db, $object)
{
	require_once DOL_DOCUMENT_ROOT.'/product/class/productbatch.class.php';

	$sql = "SELECT e.rowid, e.ref, e.lieu, e.fk_parent, e.statut as status, ps.reel, ps.rowid as product_stock_id";
	$sql .= " FROM ".MAIN_DB_PREFIX."entrepot as e,";
	$sql .= " ".MAIN_DB_PREFIX."product_stock as ps";
	$sql .= " WHERE ps.reel != 0";
	$sql .= " AND ps.fk_entrepot = e.rowid";
	$sql .= " AND e.entity IN (".getEntity('stock').")";
	$sql .= " AND ps.fk_product = ".((int) $object->id);
	$sql .= " ORDER BY e.ref";

	$resql = $db->query($sql);
	if (!$resql) {
		throw new Exception('SQL '.$db->lasterror());
	}

	$warehouses = array();
	$total = 0;
	while ($obj = $db->fetch_object($resql)) {
		$total += $obj->reel;
		$lots = Productbatch::findAll($db, (int) $obj->product_stock_id, 0, (int) $object->id);
		if (!is_array($lots)) {
			throw new Exception('Productbatch::findAll '.$db->lasterror());
		}
		$warehouses[] = array(
			'id' => (int) $obj->rowid,
			'ref' => $obj->ref,
			'lieu' => $obj->lieu,
			'fk_parent' => $obj->fk_parent,
			'status' => $obj->status,
			'reel' => $obj->reel,
			'lots' => $lots,
		);
	}
	$db->free($resql);

	return array('warehouses' => $warehouses, 'total' => $total);
}

/**
 * Valeurs de l'attribut colisage des lots, par id de lot (llx_product_lot.rowid)
 *
 * @param	DoliDB	$db			Base de données
 * @param	int[]	$lotids		Ids des lots
 * @param	string	$code		Code de l'attribut (vérifié existant)
 * @return	array				array(lotid => valeur brute)
 * @throws	Exception			Erreur SQL
 */
function diamantutils_stockcolisage_values($db, $lotids, $code)
{
	$values = array();
	$lotids = array_values(array_unique(array_filter(array_map('intval', $lotids))));
	if (empty($lotids) || !preg_match('/^[a-z0-9_]{1,64}$/i', $code)) {
		return $values;
	}
	$sql = "SELECT ef.fk_object, ef.".$code." as val FROM ".MAIN_DB_PREFIX."product_lot_extrafields as ef";
	$sql .= " WHERE ef.fk_object IN (".implode(',', $lotids).")";
	$resql = $db->query($sql);
	if (!$resql) {
		throw new Exception('SQL '.$db->lasterror());
	}
	while ($obj = $db->fetch_object($resql)) {
		$values[(int) $obj->fk_object] = $obj->val;
	}
	$db->free($resql);
	return $values;
}

/**
 * HTML de la cellule Colisage
 *
 * @param	string|null	$raw		Valeur enregistrée
 * @param	string		$batch		Numéro de lot
 * @param	bool		$editable	Modifiable par l'utilisateur
 * @return	string
 */
function diamantutils_stockcolisage_cell($raw, $batch, $editable)
{
	$text = diamantutils_stockcolisage_text($raw);
	$ishtml = diamantutils_stockcolisage_is_html($raw);

	$attrs = ' class="dut-sc-colisage'.($editable ? ' dut-sc-editable' : '').($text === '' ? ' dut-sc-empty' : '').'"';
	$attrs .= ' data-batch="'.dol_escape_htmltag($batch).'"';
	if ($editable) {
		$attrs .= ' data-html="'.($ishtml ? '1' : '0').'"';
		$attrs .= ' tabindex="0"';
	}
	if ($text !== '') {
		$attrs .= ' title="'.dol_escape_htmltag($text, 1).'"';
	}

	return '<td'.$attrs.'><span class="dut-sc-text">'.dol_escape_htmltag($text, 1).'</span></td>';
}

/**
 * HTML du tableau de remplacement (bloc #dut-stockcolisage, masqué jusqu'à son placement par JS)
 *
 * @param	DoliDB	$db		Base de données
 * @param	Product	$object	Produit (produit à lots)
 * @return	string
 * @throws	Exception		Erreur de lecture : rien ne doit être affiché
 */
function diamantutils_stockcolisage_render($db, $object)
{
	global $langs, $user;

	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';
	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';

	if (getDolGlobalInt('DIAMANTUTILS_STOCKCOLISAGE_FORCE_ERROR')) {
		// Contrôle du repli : le tableau natif doit rester affiché
		throw new Exception('DIAMANTUTILS_STOCKCOLISAGE_FORCE_ERROR');
	}

	$langs->loadLangs(array('stocks', 'productbatch', 'diamantutils@diamantutils'));

	$data = diamantutils_stockcolisage_load($db, $object);

	$code = diamantutils_stockcolisage_extrafield_code();
	$hasfield = diamantutils_stockcolisage_extrafield_exists($db, $code);
	$canedit = ($hasfield && $user->hasRight('produit', 'creer'));

	$lotids = array();
	foreach ($data['warehouses'] as $wh) {
		foreach ($wh['lots'] as $pdluo) {
			$lotids[] = (int) $pdluo->lotid;
		}
	}
	$values = ($hasfield ? diamantutils_stockcolisage_values($db, $lotids, $code) : array());

	$showall = getDolGlobalString('STOCK_SHOW_ALL_BATCH_BY_DEFAULT') ? true : false;
	$transfer = diamantutils_stockcolisage_transfer_action();
	$self = $_SERVER['PHP_SELF'];
	$token = newToken();

	$unit = '';
	if (method_exists($object, 'getLabelOfUnit')) {
		$unit = $object->getLabelOfUnit('short');
		if (!is_string($unit)) {
			$unit = '';
		}
	}

	$entrepotstatic = new Entrepot($db);
	$lotstatic = new Productlot($db);

	$config = array(
		'url' => dol_buildpath('/diamantutils/ajax/lotcolisage.php', 1),
		'token' => $token,
		'product' => (int) $object->id,
		'canEdit' => $canedit,
		'maxLength' => 200,
		'showAll' => $showall,
		'lang' => array(
			'save' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageSaved'),
			'error' => $langs->transnoentitiesnoconv('Error'),
			'network' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrNetwork'),
			'refused' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrRefused'),
			'htmlWarning' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageHtmlWarning'),
			'placeholder' => $langs->transnoentitiesnoconv('DiamantutilsStockColisagePlaceholder'),
			'editTitle' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageEditTitle'),
		),
	);

	$out = '<link rel="stylesheet" href="'.dol_buildpath('/diamantutils/css/stockcolisage.css', 1).'?v=2.11">'."\n";
	$out .= '<div id="dut-stockcolisage" class="div-table-responsive" style="display: none;" data-config="'.htmlspecialchars(json_encode($config), ENT_QUOTES, 'UTF-8').'">'."\n";
	$out .= '<table class="noborder centpercent dut-sc-table">'."\n";

	// En-tête
	$out .= '<tr class="liste_titre">';
	$out .= '<td class="minwidth200">'.$langs->trans('DiamantutilsStockColisageColWarehouse');
	$out .= ' <span class="dut-sc-toggleall">';
	$out .= '<a href="#" class="dut-sc-showall'.($showall ? ' hideobject' : '').'">'.img_picto('', 'folder-open', 'class="paddingright"').$langs->trans('ShowAllLots').'</a>';
	$out .= '<a href="#" class="dut-sc-hideall'.($showall ? '' : ' hideobject').'">'.img_picto('', 'folder', 'class="paddingright"').$langs->trans('HideLots').'</a>';
	$out .= '</span></td>';
	$out .= '<td class="dut-sc-colisage-title">'.$langs->trans('DiamantutilsStockColisageCol');
	if (!$hasfield) {
		$out .= ' '.img_warning($langs->trans('DiamantutilsStockColisageExtrafieldMissing', $code));
	}
	$out .= '</td>';
	$out .= '<td class="right">'.$langs->trans('Quantity').($unit !== '' ? ' ('.dol_escape_htmltag($unit).')' : '').'</td>';
	$out .= '<td></td>';
	$out .= '<td></td>';
	$out .= '</tr>'."\n";

	foreach ($data['warehouses'] as $wh) {
		$entrepotstatic->id = $wh['id'];
		$entrepotstatic->ref = $wh['ref'];
		$entrepotstatic->label = $wh['ref'];
		$entrepotstatic->lieu = $wh['lieu'];
		$entrepotstatic->fk_parent = $wh['fk_parent'];
		$entrepotstatic->statut = $wh['status'];
		$entrepotstatic->status = $wh['status'];
		$closed = ($entrepotstatic->status == Entrepot::STATUS_CLOSED);
		$stock_real = price2num($wh['reel'], 'MS');

		// Ligne entrepôt
		$out .= '<tr class="oddeven dut-sc-warehouse" data-warehouse="'.((int) $wh['id']).'">';
		$out .= '<td>'.$entrepotstatic->getNomUrl(1);
		if (count($wh['lots'])) {
			$out .= '<a href="#" class="dut-sc-toggle marginleftonly" data-warehouse="'.((int) $wh['id']).'">'.($showall ? '(-)' : '(+)').'</a>';
		}
		$out .= '</td>';
		$out .= '<td></td>';
		$out .= '<td class="right nowraponall">'.$stock_real.($stock_real < 0 ? ' '.img_warning() : '').'</td>';
		$out .= '<td></td>';
		$out .= '<td></td>';
		$out .= '</tr>'."\n";

		// Lignes de lot
		foreach ($wh['lots'] as $pdluo) {
			$lotstatic->id = $pdluo->lotid;
			$lotstatic->batch = $pdluo->batch;
			$lotstatic->eatby = $pdluo->eatby;
			$lotstatic->sellby = $pdluo->sellby;

			$out .= '<tr class="oddeven dut-sc-lot dut-sc-wh'.((int) $wh['id']).'"'.($showall ? '' : ' style="display: none;"').'>';
			$out .= '<td class="dut-sc-lotname nowraponall">';
			$out .= ($lotstatic->id > 0 ? $lotstatic->getNomUrl(1) : $lotstatic->getNomUrl(1, 'nolink'));
			$out .= '</td>';
			$raw = ($pdluo->lotid > 0 && isset($values[(int) $pdluo->lotid]) ? $values[(int) $pdluo->lotid] : null);
			$out .= diamantutils_stockcolisage_cell($raw, (string) $pdluo->batch, $canedit);
			$out .= '<td class="right nowraponall">'.$pdluo->qty.($pdluo->qty < 0 ? ' '.img_warning() : (($pdluo->qty > 1 && $object->status_batch == 2) ? ' '.img_warning($langs->trans('IlligalQtyForSerialNumbers')) : '')).'</td>';
			$out .= '<td class="center tdoverflowmax125" title="'.dol_escape_htmltag($langs->trans('TransferStock')).'">';
			if (!$closed) {
				$out .= '<a href="'.$self.'?id='.((int) $object->id).'&id_entrepot='.((int) $wh['id']).'&action='.$transfer.'&pdluoid='.((int) $pdluo->id).'&token='.$token.'">';
				$out .= img_picto($langs->trans('TransferStock'), 'add', 'class="hideonsmartphone paddingright" style="color: #a69944"');
				$out .= $langs->trans('TransferStock');
				$out .= '</a>';
			}
			$out .= '</td>';
			$out .= '<td class="center tdoverflowmax125" title="'.dol_escape_htmltag($langs->trans('CorrectStock')).'">';
			if (!$closed) {
				$out .= '<a href="'.$self.'?id='.((int) $object->id).'&id_entrepot='.((int) $wh['id']).'&action=correction&pdluoid='.((int) $pdluo->id).'&token='.$token.'">';
				$out .= img_picto($langs->trans('CorrectStock'), 'add', 'class="hideonsmartphone paddingright" style="color: #a69944"');
				$out .= $langs->trans('CorrectStock');
				$out .= '</a>';
			}
			$out .= '</td>';
			$out .= '</tr>'."\n";
		}
	}

	// Total
	$out .= '<tr class="liste_total">';
	$out .= '<td class="right liste_total" colspan="2">'.$langs->trans('Total').':</td>';
	$out .= '<td class="liste_total right">'.price2num($data['total'], 'MS').'</td>';
	$out .= '<td class="liste_total"></td>';
	$out .= '<td class="liste_total"></td>';
	$out .= '</tr>'."\n";

	$out .= '</table>'."\n";
	$out .= '</div>'."\n";
	$out .= '<script src="'.dol_buildpath('/diamantutils/js/stockcolisage.js', 1).'?v=2.11"></script>'."\n";

	return $out;
}

/**
 * Lot d'un produit retrouvé côté serveur à partir de (produit, numéro de lot, entité)
 *
 * Le lot doit exister dans le stock du produit (llx_product_batch, entrepôts de l'entité) ou
 * dans les lots connus du produit (llx_product_lot de l'entité).
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @param	string	$batch			Numéro de lot
 * @return	array|null|false		array('lotid' => int (0 = pas de ligne product_lot), 'batch' => string),
 *									null si lot inconnu, false si erreur SQL
 */
function diamantutils_stockcolisage_find_lot($db, $fk_product, $batch)
{
	$batch = (string) $batch;
	if ((int) $fk_product <= 0 || $batch === '') {
		return null;
	}

	$sql = "SELECT pl.rowid, pl.batch FROM ".MAIN_DB_PREFIX."product_lot as pl";
	$sql .= " WHERE pl.fk_product = ".((int) $fk_product);
	$sql .= " AND pl.batch = '".$db->escape($batch)."'";
	$sql .= " AND pl.entity IN (".getEntity('productlot').")";
	$sql .= " ORDER BY pl.rowid";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return false;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if ($obj) {
		return array('lotid' => (int) $obj->rowid, 'batch' => (string) $obj->batch);
	}

	$sql = "SELECT pb.batch FROM ".MAIN_DB_PREFIX."product_batch as pb";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = ps.fk_entrepot";
	$sql .= " WHERE ps.fk_product = ".((int) $fk_product);
	$sql .= " AND pb.batch = '".$db->escape($batch)."'";
	$sql .= " AND e.entity IN (".getEntity('stock').")";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return false;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if ($obj) {
		// Numéro exact du stock (la comparaison SQL peut ignorer la casse)
		return array('lotid' => 0, 'batch' => (string) $obj->batch);
	}
	return null;
}

/**
 * Texte saisi nettoyé : une ligne, sans caractères de contrôle, espaces superflus retirés
 *
 * @param	string	$text	Texte reçu
 * @return	string
 */
function diamantutils_stockcolisage_clean_input($text)
{
	$text = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $text);
	return trim(preg_replace('/\s{2,}/u', ' ', (string) $text));
}

/**
 * Enregistre le colisage d'un lot. Lot sans ligne product_lot : la ligne est créée
 * (Productlot::create, attribut compris) ; sinon l'attribut du lot existant est mis à jour
 * (array_options + insertExtraFields, comme la fiche lot).
 *
 * La valeur est enregistrée échappée (attribut de type « Texte HTML ») : aucune balise.
 *
 * @param	DoliDB	$db				Base de données
 * @param	User	$user			Utilisateur
 * @param	int		$fk_product		Id produit
 * @param	array	$lot			Résultat de diamantutils_stockcolisage_find_lot()
 * @param	string	$text			Texte nettoyé (diamantutils_stockcolisage_clean_input)
 * @param	string	$code			Code de l'attribut (vérifié existant)
 * @param	string	$error			Message d'erreur en sortie
 * @return	string|false			Texte affiché après enregistrement, false si erreur
 */
function diamantutils_stockcolisage_save($db, $user, $fk_product, $lot, $text, $code, &$error)
{
	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';

	$error = '';
	$value = diamantutils_stockcolisage_storage_value($text);
	$key = 'options_'.$code;

	$productlot = new Productlot($db);
	if ($lot['lotid'] > 0) {
		if ($productlot->fetch($lot['lotid']) <= 0) {
			$error = ($productlot->error ? $productlot->error : implode(', ', $productlot->errors));
			return false;
		}
		if (!is_array($productlot->array_options)) {
			$productlot->array_options = array();
		}
		$productlot->array_options[$key] = $value;
		if ($productlot->insertExtraFields('PRODUCT_LOT_MODIFY', $user) < 0) {
			$error = ($productlot->error ? $productlot->error : implode(', ', $productlot->errors));
			return false;
		}
	} else {
		if ($value === null) {
			return '';	// rien à enregistrer : la ligne product_lot n'est pas créée pour une valeur vide
		}
		$productlot->fk_product = (int) $fk_product;
		$productlot->batch = $lot['batch'];
		$productlot->fk_user_creat = (int) $user->id;
		$productlot->array_options = array($key => $value);
		if ($productlot->create($user) <= 0) {
			$error = ($productlot->error ? $productlot->error : implode(', ', $productlot->errors));
			return false;
		}
	}

	return diamantutils_stockcolisage_text($value);
}
