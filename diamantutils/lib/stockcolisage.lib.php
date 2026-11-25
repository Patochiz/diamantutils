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
 *
 * Colisage calculé (v2.12), références vérifiées dans le cœur 20.0.0 et 23.0.0 :
 *  - attributs du lot : élément « product_lot » (Productlot::$table_element), table
 *    llx_product_lot_extrafields (fk_object = llx_product_lot.rowid) ;
 *  - MouvementStock::_create() : begin(), fiche lot créée si absente, mise à jour de
 *    product_stock puis product_batch (createBatch), puis call_trigger('STOCK_MOVEMENT') avant
 *    commit() : le trigger lit le stock déjà à jour et son écriture suit le rollback éventuel
 *    (y compris le contrôle « numéro de série unique » fait après le trigger) ;
 *  - propriétés du mouvement disponibles : product_id, warehouse_id (entrepot_id), qty, type,
 *    batch ;
 *  - v23 : trigger non appelé pour un produit dont le stock est désactivé (stockable_product).
 * Un transfert appelle _create() deux fois (sortie puis entrée) : deux recalculs, le dernier
 * laisse l'état exact.
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
 * @param	string	$code		Code de l'attribut ('' = code configuré)
 * @param	bool	$refresh	Relire la base (après création)
 * @return	bool
 */
function diamantutils_stockcolisage_extrafield_exists($db, $code = '', $refresh = false)
{
	static $cache = array();

	if ($code === '') {
		$code = diamantutils_stockcolisage_extrafield_code();
	}
	if ($code === '') {
		return false;
	}
	if ($refresh || !array_key_exists($code, $cache)) {
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
 * HTML de la cellule Colisage
 *
 * @param	string|null	$raw		Valeur enregistrée
 * @param	string		$batch		Numéro de lot
 * @param	bool		$editable	Modifiable par l'utilisateur
 * @param	int			$warehouse	Id de l'entrepôt de la ligne
 * @return	string
 */
function diamantutils_stockcolisage_cell($raw, $batch, $editable, $warehouse = 0)
{
	$text = diamantutils_stockcolisage_text($raw);
	$ishtml = diamantutils_stockcolisage_is_html($raw);

	$attrs = ' class="dut-sc-colisage'.($editable ? ' dut-sc-editable' : '').($text === '' ? ' dut-sc-empty' : '').'"';
	$attrs .= ' data-batch="'.dol_escape_htmltag($batch).'" data-field="colisage" data-warehouse="'.((int) $warehouse).'"';
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
 * HTML de la cellule Colisage en mode automatique (lecture seule, calculée avec la quantité
 * de la ligne affichée)
 *
 * @param	array	$calc		Résultat de diamantutils_colisage_compute()
 * @param	string	$batch		Numéro de lot
 * @param	int		$warehouse	Id de l'entrepôt de la ligne
 * @return	string
 */
function diamantutils_stockcolisage_auto_cell($calc, $batch, $warehouse)
{
	global $langs;

	$title = $langs->transnoentitiesnoconv('DiamantutilsColisageAutoTitle');
	$out = '<td class="dut-sc-colisage dut-sc-auto" data-batch="'.dol_escape_htmltag($batch).'" data-field="colisage" data-warehouse="'.((int) $warehouse).'"';
	$out .= ' title="'.dol_escape_htmltag(($calc['text'] !== '' ? $calc['text'].' — ' : '').$title).'">';
	$out .= '<span class="dut-sc-text">'.dol_escape_htmltag($calc['text']).'</span>';
	if ($calc['warn'] !== '') {
		$out .= '<span class="dut-sc-calcwarn">'.img_warning('', 'class="pictofixedwidth"').dol_escape_htmltag($calc['warn']).'</span>';
	}
	$out .= '</td>';
	return $out;
}

/**
 * HTML d'une cellule numérique (pièces par colis, qté par pièce)
 *
 * @param	string		$field		DIAMANTUTILS_STOCKCOLISAGE_PPC ou DIAMANTUTILS_STOCKCOLISAGE_QPP
 * @param	string|null	$raw		Valeur enregistrée
 * @param	string		$batch		Numéro de lot
 * @param	int			$warehouse	Id de l'entrepôt de la ligne
 * @param	bool		$editable	Modifiable par l'utilisateur
 * @param	string		$title		Infobulle
 * @return	string
 */
function diamantutils_stockcolisage_num_cell($field, $raw, $batch, $warehouse, $editable, $title)
{
	$text = diamantutils_stockcolisage_num_display($field, $raw);
	$out = '<td class="right nowraponall dut-sc-num'.($editable ? ' dut-sc-editable' : '').($text === '' ? ' dut-sc-empty' : '').'"';
	$out .= ' data-batch="'.dol_escape_htmltag($batch).'" data-field="'.dol_escape_htmltag($field).'" data-warehouse="'.((int) $warehouse).'"';
	if ($editable) {
		$out .= ' tabindex="0"';
	}
	if ($title !== '') {
		$out .= ' title="'.dol_escape_htmltag($title).'"';
	}
	$out .= '><span class="dut-sc-text">'.dol_escape_htmltag($text).'</span></td>';
	return $out;
}

/**
 * Valeur affichée d'un attribut numérique du lot
 *
 * @param	string		$field	DIAMANTUTILS_STOCKCOLISAGE_PPC ou DIAMANTUTILS_STOCKCOLISAGE_QPP
 * @param	string|null	$raw	Valeur enregistrée
 * @return	string
 */
function diamantutils_stockcolisage_num_display($field, $raw)
{
	$n = diamantutils_colisage_number($raw);
	if ($n === null) {
		return '';
	}
	return diamantutils_colisage_format_number($n, ($field == DIAMANTUTILS_STOCKCOLISAGE_PPC ? 0 : 8));
}

/**
 * Infobulle des cellules numériques d'un lot en mode manuel : le colisage saisi à la main
 * sera remplacé quand les deux valeurs seront renseignées.
 *
 * @param	string|null	$colisage	Colisage enregistré
 * @return	string
 */
function diamantutils_stockcolisage_num_title($colisage)
{
	global $langs;

	$text = diamantutils_stockcolisage_text($colisage);
	if ($text === '') {
		return $langs->transnoentitiesnoconv('DiamantutilsColisageNumTitle');
	}
	return $langs->transnoentitiesnoconv('DiamantutilsColisageNumTitleReplace', dol_trunc($text, 60));
}

/**
 * Libellés de l'édition en ligne (js/stockcolisage.js), communs à l'onglet Stock et au tableau
 * de stock par lots
 *
 * @return	array
 */
function diamantutils_stockcolisage_js_lang()
{
	global $langs;

	$langs->loadLangs(array('diamantutils@diamantutils'));
	return array(
		'save' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageSaved'),
		'error' => $langs->transnoentitiesnoconv('Error'),
		'network' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrNetwork'),
		'refused' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageErrRefused'),
		'htmlWarning' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageHtmlWarning'),
		'placeholder' => $langs->transnoentitiesnoconv('DiamantutilsStockColisagePlaceholder'),
		'editTitle' => $langs->transnoentitiesnoconv('DiamantutilsStockColisageEditTitle'),
		'autoTitle' => $langs->transnoentitiesnoconv('DiamantutilsColisageAutoTitle'),
		'numTitle' => $langs->transnoentitiesnoconv('DiamantutilsColisageNumTitle'),
		// Format avec %s complété par le script : texte brut (transnoentitiesnoconv() viderait le %s)
		'replaced' => (isset($langs->tab_translate['DiamantutilsColisageReplaced']) ? (string) $langs->tab_translate['DiamantutilsColisageReplaced'] : 'DiamantutilsColisageReplaced'),
	);
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
	$lotbatch = array();
	foreach ($data['warehouses'] as $wh) {
		foreach ($wh['lots'] as $pdluo) {
			if ($pdluo->lotid > 0) {
				$lotids[] = (int) $pdluo->lotid;
				$lotbatch[(int) $pdluo->lotid] = (string) $pdluo->batch;
			}
		}
	}
	$attrs = ($hasfield ? diamantutils_stockcolisage_lot_attributes($db, $lotids) : array());

	// Colisage calculé : deux colonnes numériques ; valeur enregistrée corrigée si elle ne
	// correspond plus à la quantité totale du lot (auto-réparation, valeur dérivée)
	$auto = diamantutils_stockcolisage_auto_enabled($db);
	$caneditnum = ($auto && $user->hasRight('produit', 'creer'));
	if ($auto) {
		foreach ($attrs as $lotid => $attr) {
			if (diamantutils_colisage_compute(0, $attr['qpp'], $attr['ppc'])['mode'] != 'auto') {
				continue;
			}
			$fix = diamantutils_stockcolisage_recompute_lot($db, $object->id, $lotbatch[$lotid]);
			if ($fix['status'] == 'updated') {
				dol_syslog(__FUNCTION__.' colisage corrigé à l\'affichage : produit '.$object->id.' lot "'.$lotbatch[$lotid].'" (id '.$lotid.') « '.$fix['old'].' » → « '.$fix['text'].' » (quantité '.$fix['qty'].')', LOG_WARNING);
				$attrs[$lotid]['colisage'] = diamantutils_stockcolisage_storage_value($fix['text']);
			}
		}
	}
	$ncols = ($auto ? 7 : 5);

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
		'canEditNum' => $caneditnum,
		'maxLength' => 200,
		'showAll' => $showall,
		'lang' => diamantutils_stockcolisage_js_lang(),
	);

	$out = '<link rel="stylesheet" href="'.dol_buildpath('/diamantutils/css/stockcolisage.css', 1).'?v=2.12">'."\n";
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
	if ($auto) {
		$out .= '<td class="right">'.$langs->trans('DiamantutilsColisagePpcCol').'</td>';
		$out .= '<td class="right">'.$langs->trans('DiamantutilsColisageQppCol').($unit !== '' ? ' ('.dol_escape_htmltag($unit).')' : '').'</td>';
	}
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
		$out .= '<td'.($auto ? ' colspan="3"' : '').'></td>';
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
			$attr = ($pdluo->lotid > 0 && isset($attrs[(int) $pdluo->lotid]) ? $attrs[(int) $pdluo->lotid] : array('colisage' => null, 'ppc' => null, 'qpp' => null));
			// Texte de la ligne : quantité de la ligne affichée (la valeur enregistrée suit le total du lot)
			$calc = ($auto ? diamantutils_colisage_compute($pdluo->qty, $attr['qpp'], $attr['ppc']) : array('mode' => 'manual'));
			if ($calc['mode'] == 'auto') {
				$out .= diamantutils_stockcolisage_auto_cell($calc, (string) $pdluo->batch, (int) $wh['id']);
			} else {
				$out .= diamantutils_stockcolisage_cell($attr['colisage'], (string) $pdluo->batch, $canedit, (int) $wh['id']);
			}
			if ($auto) {
				$numtitle = ($calc['mode'] == 'auto' ? '' : diamantutils_stockcolisage_num_title($attr['colisage']));
				$out .= diamantutils_stockcolisage_num_cell(DIAMANTUTILS_STOCKCOLISAGE_PPC, $attr['ppc'], (string) $pdluo->batch, (int) $wh['id'], $caneditnum, $numtitle);
				$out .= diamantutils_stockcolisage_num_cell(DIAMANTUTILS_STOCKCOLISAGE_QPP, $attr['qpp'], (string) $pdluo->batch, (int) $wh['id'], $caneditnum, $numtitle);
			}
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
	$out .= '<td class="right liste_total" colspan="'.($ncols - 3).'">'.$langs->trans('Total').':</td>';
	$out .= '<td class="liste_total right">'.price2num($data['total'], 'MS').'</td>';
	$out .= '<td class="liste_total"></td>';
	$out .= '<td class="liste_total"></td>';
	$out .= '</tr>'."\n";

	$out .= '</table>'."\n";
	$out .= '</div>'."\n";
	$out .= '<script src="'.dol_buildpath('/diamantutils/js/stockcolisage.js', 1).'?v=2.12"></script>'."\n";

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

/*
 * Colisage calculé (v2.12)
 *
 * colisage = f(quantité réelle du lot, qte_par_piece, pieces_par_colis), recalculé à chaque
 * mouvement de stock (trigger STOCK_MOVEMENT), à l'affichage de l'onglet Stock et par le
 * bouton « Recalculer tous les colisages ». Une seule règle : diamantutils_colisage_compute().
 */

/** Code de l'attribut du lot « Pièces par colis » (entier > 0) */
if (!defined('DIAMANTUTILS_STOCKCOLISAGE_PPC')) {
	define('DIAMANTUTILS_STOCKCOLISAGE_PPC', 'pieces_par_colis');
}
/** Code de l'attribut du lot « Qté par pièce » (décimal > 0, unité du produit) */
if (!defined('DIAMANTUTILS_STOCKCOLISAGE_QPP')) {
	define('DIAMANTUTILS_STOCKCOLISAGE_QPP', 'qte_par_piece');
}
/** Écart toléré sur le nombre de pièces (erreurs d'arrondi des flottants) */
if (!defined('DIAMANTUTILS_STOCKCOLISAGE_TOLERANCE')) {
	define('DIAMANTUTILS_STOCKCOLISAGE_TOLERANCE', 0.02);
}

/**
 * Calcul du colisage : fonction pure (aucune lecture en base), utilisée partout.
 *
 * @param	mixed	$qty			Quantité du lot (unité du produit)
 * @param	mixed	$qteParPiece	Quantité d'une pièce (unité du produit)
 * @param	mixed	$piecesParColis	Pièces par colis complet
 * @return	array					array('mode' => 'auto'|'manual', 'text' => string, 'warn' => string)
 */
function diamantutils_colisage_compute($qty, $qteParPiece, $piecesParColis)
{
	global $langs;

	$res = array('mode' => 'manual', 'text' => '', 'warn' => '');

	$qpp = diamantutils_colisage_number($qteParPiece);
	$ppc = diamantutils_colisage_number($piecesParColis);
	if ($qpp === null || $ppc === null || $qpp <= 0 || $ppc <= 0 || abs($ppc - round($ppc)) > 1e-9) {
		return $res;
	}
	$res['mode'] = 'auto';
	$ppc = (int) round($ppc);

	$q = diamantutils_colisage_number($qty);
	$q = ($q === null ? 0.0 : (float) price2num($q, 'MS'));
	if ($q < 0) {
		$res['warn'] = (is_object($langs) ? $langs->transnoentitiesnoconv('DiamantutilsColisageWarnNegative') : 'negative quantity');
		return $res;
	}

	$pieces = $q / $qpp;
	$rounded = round($pieces);
	if (abs($pieces - $rounded) > DIAMANTUTILS_STOCKCOLISAGE_TOLERANCE) {
		$res['warn'] = (is_object($langs) ? $langs->transnoentitiesnoconv('DiamantutilsColisageWarnNotMultiple', diamantutils_colisage_format_number($pieces, 2)) : 'not a multiple');
		return $res;
	}
	$pieces = (int) $rounded;
	if ($pieces <= 0) {
		return $res;	// lot vide : colisage vidé
	}

	$px = (is_object($langs) ? $langs->transnoentitiesnoconv('DiamantutilsColisagePx') : 'px');
	if ($px === 'DiamantutilsColisagePx') {
		$px = 'px';
	}
	$complets = intdiv($pieces, $ppc);
	$reste = $pieces - $complets * $ppc;
	if ($complets > 0) {
		$res['text'] = $complets.' colis de '.$ppc.$px;
		if ($reste > 0) {
			$res['text'] .= ' + 1 colis de '.$reste.$px;
		}
	} else {
		$res['text'] = '1 colis de '.$reste.$px;
	}
	return $res;
}

/**
 * Nombre lu en base ou saisi : float, ou null si vide / non numérique. Virgule acceptée.
 *
 * @param	mixed	$value	Valeur
 * @return	float|null
 */
function diamantutils_colisage_number($value)
{
	if ($value === null || is_bool($value)) {
		return null;
	}
	if (is_int($value) || is_float($value)) {
		return (float) $value;
	}
	$value = str_replace(array(' ', "\xc2\xa0", ','), array('', '', '.'), trim((string) $value));
	if ($value === '' || !is_numeric($value)) {
		return null;
	}
	return (float) $value;
}

/**
 * Nombre affiché : décimales utiles seulement, séparateur décimal de l'utilisateur
 *
 * @param	float	$value		Nombre
 * @param	int		$maxdec		Décimales au plus
 * @return	string
 */
function diamantutils_colisage_format_number($value, $maxdec = 8)
{
	global $langs;

	$txt = number_format((float) $value, $maxdec, '.', '');
	if (strpos($txt, '.') !== false) {
		$txt = rtrim(rtrim($txt, '0'), '.');
	}
	if ($txt === '-0') {
		$txt = '0';
	}
	$sep = (is_object($langs) ? $langs->transnoentitiesnoconv('SeparatorDecimal') : ',');
	if ($sep === 'SeparatorDecimal' || $sep === '') {
		$sep = ',';
	}
	return str_replace('.', $sep, $txt);
}

/**
 * Attributs « Pièces par colis » et « Qté par pièce » présents (élément product_lot) ?
 *
 * @param	DoliDB	$db		Base de données
 * @return	array			array(code => bool)
 */
function diamantutils_colisage_fields_status($db)
{
	return array(
		DIAMANTUTILS_STOCKCOLISAGE_PPC => diamantutils_stockcolisage_extrafield_exists($db, DIAMANTUTILS_STOCKCOLISAGE_PPC),
		DIAMANTUTILS_STOCKCOLISAGE_QPP => diamantutils_stockcolisage_extrafield_exists($db, DIAMANTUTILS_STOCKCOLISAGE_QPP),
	);
}

/**
 * Crée les attributs du lot « Pièces par colis » et « Qté par pièce » s'ils n'existent pas
 * (idempotent, appelé à l'activation du module et à l'enregistrement de l'onglet).
 *
 * @param	DoliDB	$db		Base de données
 * @param	string	$error	Message d'erreur en sortie
 * @return	int				Nombre d'attributs créés, -1 si erreur
 */
function diamantutils_colisage_install_extrafields($db, &$error)
{
	require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

	$defs = array(
		DIAMANTUTILS_STOCKCOLISAGE_PPC => array('Pièces par colis', 'int', '10', 110),
		DIAMANTUTILS_STOCKCOLISAGE_QPP => array('Qté par pièce', 'double', '24,8', 111),
	);
	$nb = 0;
	foreach ($defs as $code => $def) {
		if (diamantutils_stockcolisage_extrafield_exists($db, $code, true)) {
			continue;
		}
		$extrafields = new ExtraFields($db);
		$result = $extrafields->addExtraField(
			$code,
			$def[0],		// libellé (texte : l'attribut reste lisible module désactivé)
			$def[1],
			$def[3],		// position
			$def[2],		// taille
			'product_lot',	// attributs des lots (Productlot::$table_element)
			0,				// unique
			0,				// obligatoire
			'',				// valeur par défaut
			'',				// paramètres
			1,				// alwayseditable
			'',				// perms
			'1',			// visible
			'',				// aide
			'',				// computed
			'',				// entity
			'',				// langfile
			'1'				// enabled
		);
		if ($result < 0) {
			$error = $extrafields->error;
			return -1;
		}
		diamantutils_stockcolisage_extrafield_exists($db, $code, true);
		$nb++;
	}
	return $nb;
}

/**
 * Colisage calculé actif ? Fonctionnalité active, DIAMANTUTILS_STOCKCOLISAGE_AUTO, module
 * Lot/série, attributs colisage, pieces_par_colis et qte_par_piece présents.
 *
 * @param	DoliDB	$db		Base de données
 * @return	bool
 */
function diamantutils_stockcolisage_auto_enabled($db)
{
	if (!diamantutils_stockcolisage_enabled() || !getDolGlobalInt('DIAMANTUTILS_STOCKCOLISAGE_AUTO', 1) || !isModEnabled('productbatch')) {
		return false;
	}
	$code = diamantutils_stockcolisage_extrafield_code();
	if ($code === '' || !diamantutils_stockcolisage_extrafield_exists($db, $code)) {
		return false;
	}
	$fields = diamantutils_colisage_fields_status($db);
	return ($fields[DIAMANTUTILS_STOCKCOLISAGE_PPC] && $fields[DIAMANTUTILS_STOCKCOLISAGE_QPP]);
}

/**
 * Entrepôts ignorés pour la quantité du lot (valeur stockée).
 * Constante absente = entrepôt Sous-traitance ; '0' = aucun.
 *
 * @return	int[]
 */
function diamantutils_stockcolisage_excluded_warehouses()
{
	$raw = getDolGlobalString('DIAMANTUTILS_STOCKCOLISAGE_EXCLUDE_WAREHOUSES', 'default');
	if ($raw === 'default') {
		dol_include_once('/diamantutils/lib/soustraitance.lib.php');
		$st = (function_exists('diamantutils_st_warehouse_id') ? diamantutils_st_warehouse_id() : 0);
		return ($st > 0 ? array($st) : array());
	}
	return diamantutils_stockcolisage_ids($raw);
}

/**
 * Liste d'identifiants « 1,5,7 » → array(1, 5, 7) (positifs, sans doublon)
 *
 * @param	string|array	$raw	Liste
 * @return	int[]
 */
function diamantutils_stockcolisage_ids($raw)
{
	$ids = array();
	foreach ((is_array($raw) ? $raw : explode(',', (string) $raw)) as $id) {
		$id = (int) $id;
		if ($id > 0) {
			$ids[$id] = $id;
		}
	}
	return array_values($ids);
}

/**
 * Quantité réelle totale d'un lot : somme de product_batch.qty, entrepôts de l'entité, hors
 * entrepôts ignorés
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @param	string	$batch			Numéro de lot
 * @return	float
 * @throws	Exception				Erreur SQL
 */
function diamantutils_stockcolisage_lot_total_qty($db, $fk_product, $batch)
{
	$sql = "SELECT SUM(pb.qty) as qty FROM ".MAIN_DB_PREFIX."product_batch as pb";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = ps.fk_entrepot";
	$sql .= " WHERE ps.fk_product = ".((int) $fk_product);
	$sql .= " AND pb.batch = '".$db->escape((string) $batch)."'";
	$sql .= " AND e.entity IN (".getEntity('stock').")";
	$excluded = diamantutils_stockcolisage_excluded_warehouses();
	if (!empty($excluded)) {
		$sql .= " AND ps.fk_entrepot NOT IN (".implode(',', array_map('intval', $excluded)).")";
	}
	$resql = $db->query($sql);
	if (!$resql) {
		throw new Exception('SQL '.$db->lasterror());
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	return ($obj && $obj->qty !== null ? (float) $obj->qty : 0.0);
}

/**
 * Attributs des lots : colisage, pièces par colis, qté par pièce (attributs absents = null)
 *
 * @param	DoliDB	$db			Base de données
 * @param	int[]	$lotids		Ids des lots (llx_product_lot.rowid)
 * @return	array				array(lotid => array('colisage' => ?string, 'ppc' => ?string, 'qpp' => ?string))
 * @throws	Exception			Erreur SQL
 */
function diamantutils_stockcolisage_lot_attributes($db, $lotids)
{
	$out = array();
	$lotids = array_values(array_unique(array_filter(array_map('intval', $lotids))));
	if (empty($lotids)) {
		return $out;
	}
	$cols = array();
	$code = diamantutils_stockcolisage_extrafield_code();
	if ($code !== '' && diamantutils_stockcolisage_extrafield_exists($db, $code)) {
		$cols['colisage'] = $code;
	}
	foreach (array('ppc' => DIAMANTUTILS_STOCKCOLISAGE_PPC, 'qpp' => DIAMANTUTILS_STOCKCOLISAGE_QPP) as $key => $field) {
		if (diamantutils_stockcolisage_extrafield_exists($db, $field)) {
			$cols[$key] = $field;
		}
	}
	foreach ($lotids as $id) {
		$out[$id] = array('colisage' => null, 'ppc' => null, 'qpp' => null);
	}
	if (empty($cols)) {
		return $out;
	}
	$select = array();
	foreach ($cols as $key => $field) {
		$select[] = "ef.".$field." as ".$key;
	}
	$sql = "SELECT ef.fk_object, ".implode(', ', $select)." FROM ".MAIN_DB_PREFIX."product_lot_extrafields as ef";
	$sql .= " WHERE ef.fk_object IN (".implode(',', $lotids).")";
	$resql = $db->query($sql);
	if (!$resql) {
		throw new Exception('SQL '.$db->lasterror());
	}
	while ($obj = $db->fetch_object($resql)) {
		foreach ($cols as $key => $field) {
			$out[(int) $obj->fk_object][$key] = $obj->$key;
		}
	}
	$db->free($resql);
	return $out;
}

/**
 * Écrit le colisage calculé d'un lot, directement dans llx_product_lot_extrafields.
 *
 * Valeur dérivée, écrite depuis le trigger STOCK_MOVEMENT, l'affichage et le recalcul : pas de
 * Productlot::insertExtraFields(), qui réécrirait tous les attributs du lot, contrôlerait les
 * attributs obligatoires (un attribut obligatoire vide bloquerait le mouvement) et déclencherait
 * PRODUCT_LOT_MODIFY à chaque mouvement de stock.
 *
 * @param	DoliDB		$db		Base de données
 * @param	int			$lotid	Id du lot
 * @param	string		$text	Texte calculé ('' = vider)
 * @return	void
 * @throws	Exception			Erreur SQL
 */
function diamantutils_stockcolisage_store_computed($db, $lotid, $text)
{
	$code = diamantutils_stockcolisage_extrafield_code();
	if ($code === '' || (int) $lotid <= 0) {
		throw new Exception('colisage: attribut ou lot invalide');
	}
	$value = diamantutils_stockcolisage_storage_value($text);
	$sqlvalue = ($value === null ? 'NULL' : "'".$db->escape($value)."'");

	$resql = $db->query("SELECT rowid FROM ".MAIN_DB_PREFIX."product_lot_extrafields WHERE fk_object = ".((int) $lotid));
	if (!$resql) {
		throw new Exception('SQL '.$db->lasterror());
	}
	$exists = ($db->num_rows($resql) > 0);
	$db->free($resql);

	if ($exists) {
		$sql = "UPDATE ".MAIN_DB_PREFIX."product_lot_extrafields SET ".$code." = ".$sqlvalue." WHERE fk_object = ".((int) $lotid);
	} else {
		if ($value === null) {
			return;	// pas de ligne d'attributs et rien à écrire
		}
		$sql = "INSERT INTO ".MAIN_DB_PREFIX."product_lot_extrafields (fk_object, ".$code.") VALUES (".((int) $lotid).", ".$sqlvalue.")";
	}
	if (!$db->query($sql)) {
		throw new Exception('SQL '.$db->lasterror());
	}
}

/**
 * Valeur enregistrée et valeur calculée identiques ?
 *
 * @param	string|null	$stored		Valeur enregistrée
 * @param	string		$text		Texte calculé
 * @return	bool
 */
function diamantutils_stockcolisage_same($stored, $text)
{
	$expected = diamantutils_stockcolisage_storage_value($text);
	return ((string) $stored === (string) $expected);
}

/**
 * Recalcule et enregistre le colisage d'un lot (produit, numéro de lot) à partir de sa quantité
 * totale. N'écrit que si la valeur change.
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @param	string	$batch			Numéro de lot
 * @return	array					array('status' => 'updated'|'unchanged'|'manual'|'nolot', 'lotid', 'qty', 'text', 'warn', 'old')
 * @throws	Exception				Erreur SQL (ou DIAMANTUTILS_STOCKCOLISAGE_FORCE_ERROR)
 */
function diamantutils_stockcolisage_recompute_lot($db, $fk_product, $batch)
{
	if (getDolGlobalInt('DIAMANTUTILS_STOCKCOLISAGE_FORCE_ERROR')) {
		throw new Exception('DIAMANTUTILS_STOCKCOLISAGE_FORCE_ERROR');
	}

	$res = array('status' => 'nolot', 'lotid' => 0, 'qty' => 0.0, 'text' => '', 'warn' => '', 'old' => null);

	$sql = "SELECT pl.rowid FROM ".MAIN_DB_PREFIX."product_lot as pl";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = pl.fk_product";
	$sql .= " WHERE pl.fk_product = ".((int) $fk_product);
	$sql .= " AND pl.batch = '".$db->escape((string) $batch)."'";
	$sql .= " AND pl.entity IN (".getEntity('productlot').")";
	$sql .= " AND p.tobatch > 0";
	$sql .= " ORDER BY pl.rowid";
	$resql = $db->query($sql);
	if (!$resql) {
		throw new Exception('SQL '.$db->lasterror());
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if (!$obj) {
		return $res;	// pas de fiche lot (lotid = 0) : rien à écrire
	}
	$res['lotid'] = (int) $obj->rowid;

	$attrs = diamantutils_stockcolisage_lot_attributes($db, array($res['lotid']));
	$attr = $attrs[$res['lotid']];
	$res['old'] = $attr['colisage'];

	$res['qty'] = diamantutils_stockcolisage_lot_total_qty($db, $fk_product, $batch);
	$calc = diamantutils_colisage_compute($res['qty'], $attr['qpp'], $attr['ppc']);
	if ($calc['mode'] != 'auto') {
		$res['status'] = 'manual';
		return $res;
	}
	$res['text'] = $calc['text'];
	$res['warn'] = $calc['warn'];
	if (diamantutils_stockcolisage_same($attr['colisage'], $calc['text'])) {
		$res['status'] = 'unchanged';
		return $res;
	}
	diamantutils_stockcolisage_store_computed($db, $res['lotid'], $calc['text']);
	$res['status'] = 'updated';
	return $res;
}

/**
 * Valeur saisie d'un attribut numérique du lot, validée (jamais de correction muette)
 *
 * @param	string	$field	DIAMANTUTILS_STOCKCOLISAGE_PPC ou DIAMANTUTILS_STOCKCOLISAGE_QPP
 * @param	string	$raw	Valeur saisie (virgule ou point décimal)
 * @param	string	$error	Message d'erreur en sortie
 * @return	string|null|false	Valeur à enregistrer (texte numérique), null pour vider, false si refus
 */
function diamantutils_stockcolisage_parse_num($field, $raw, &$error)
{
	global $langs;

	$error = '';
	$raw = str_replace(array(' ', "\xc2\xa0"), '', trim((string) $raw));
	if ($raw === '') {
		return null;
	}
	$norm = str_replace(',', '.', $raw);
	$label = $langs->transnoentitiesnoconv($field == DIAMANTUTILS_STOCKCOLISAGE_PPC ? 'DiamantutilsColisagePpcCol' : 'DiamantutilsColisageQppCol');
	if (!preg_match('/^-?\d+(\.\d+)?$/', $norm)) {
		$error = $langs->transnoentitiesnoconv('DiamantutilsColisageErrNumber', $label);
		return false;
	}
	if ((float) $norm <= 0 || $norm[0] === '-') {
		$error = $langs->transnoentitiesnoconv('DiamantutilsColisageErrPositive', $label);
		return false;
	}
	if ($field == DIAMANTUTILS_STOCKCOLISAGE_PPC) {
		if (!preg_match('/^\d+(\.0+)?$/', $norm)) {
			$error = $langs->transnoentitiesnoconv('DiamantutilsColisageErrInteger', $label);
			return false;
		}
		$int = (int) $norm;
		if ($int > 1000000) {
			$error = $langs->transnoentitiesnoconv('DiamantutilsColisageErrTooBig', $label);
			return false;
		}
		return (string) $int;
	}
	if (preg_match('/\.(\d+)$/', $norm, $reg) && strlen(rtrim($reg[1], '0')) > 8) {
		$error = $langs->transnoentitiesnoconv('DiamantutilsColisageErrDecimals', $label, 8);
		return false;
	}
	if ((float) $norm >= 1e15) {
		$error = $langs->transnoentitiesnoconv('DiamantutilsColisageErrTooBig', $label);
		return false;
	}
	return $norm;
}

/**
 * Quantités d'un lot par entrepôt (lignes affichées sur l'onglet Stock, entrepôts exclus compris)
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id produit
 * @param	string	$batch			Numéro de lot
 * @return	array					array(fk_entrepot => quantité)
 * @throws	Exception				Erreur SQL
 */
function diamantutils_stockcolisage_lot_rows($db, $fk_product, $batch)
{
	$rows = array();
	$sql = "SELECT ps.fk_entrepot, pb.qty FROM ".MAIN_DB_PREFIX."product_batch as pb";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_stock as ps ON ps.rowid = pb.fk_product_stock";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."entrepot as e ON e.rowid = ps.fk_entrepot";
	$sql .= " WHERE ps.fk_product = ".((int) $fk_product);
	$sql .= " AND pb.batch = '".$db->escape((string) $batch)."'";
	$sql .= " AND e.entity IN (".getEntity('stock').")";
	$resql = $db->query($sql);
	if (!$resql) {
		throw new Exception('SQL '.$db->lasterror());
	}
	while ($obj = $db->fetch_object($resql)) {
		$rows[(int) $obj->fk_entrepot] = (isset($rows[(int) $obj->fk_entrepot]) ? $rows[(int) $obj->fk_entrepot] : 0) + (float) $obj->qty;
	}
	$db->free($resql);
	return $rows;
}

/**
 * Enregistre un attribut numérique du lot (pièces par colis, qté par pièce) et, si les deux
 * sont renseignés, le colisage recalculé avec la quantité totale du lot, en une seule écriture
 * (Productlot::create() ou array_options + insertExtraFields(), comme une saisie sur la fiche lot).
 *
 * @param	DoliDB		$db				Base de données
 * @param	User		$user			Utilisateur
 * @param	int			$fk_product		Id produit
 * @param	array		$lot			Résultat de diamantutils_stockcolisage_find_lot()
 * @param	string		$field			DIAMANTUTILS_STOCKCOLISAGE_PPC ou DIAMANTUTILS_STOCKCOLISAGE_QPP
 * @param	string|null	$value			Valeur validée (diamantutils_stockcolisage_parse_num)
 * @param	string		$error			Message d'erreur en sortie
 * @return	array|false					array('mode', 'ppc', 'qpp', 'colisage', 'replaced', 'warn'), false si erreur
 */
function diamantutils_stockcolisage_save_num($db, $user, $fk_product, $lot, $field, $value, &$error)
{
	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/productlot.class.php';

	$error = '';
	$code = diamantutils_stockcolisage_extrafield_code();
	$productlot = new Productlot($db);
	if ($lot['lotid'] > 0) {
		if ($productlot->fetch($lot['lotid']) <= 0) {
			$error = ($productlot->error ? $productlot->error : implode(', ', $productlot->errors));
			return false;
		}
		if (!is_array($productlot->array_options)) {
			$productlot->array_options = array();
		}
	} else {
		if ($value === null) {
			return array('mode' => 'manual', 'ppc' => null, 'qpp' => null, 'colisage' => null, 'replaced' => '');
		}
		$productlot->fk_product = (int) $fk_product;
		$productlot->batch = $lot['batch'];
		$productlot->fk_user_creat = (int) $user->id;
		$productlot->array_options = array();
	}

	$productlot->array_options['options_'.$field] = $value;
	$ppc = (isset($productlot->array_options['options_'.DIAMANTUTILS_STOCKCOLISAGE_PPC]) ? $productlot->array_options['options_'.DIAMANTUTILS_STOCKCOLISAGE_PPC] : null);
	$qpp = (isset($productlot->array_options['options_'.DIAMANTUTILS_STOCKCOLISAGE_QPP]) ? $productlot->array_options['options_'.DIAMANTUTILS_STOCKCOLISAGE_QPP] : null);
	$old = (isset($productlot->array_options['options_'.$code]) ? $productlot->array_options['options_'.$code] : null);

	$res = array('mode' => 'manual', 'ppc' => $ppc, 'qpp' => $qpp, 'colisage' => $old, 'replaced' => '', 'warn' => '');
	$calc = diamantutils_colisage_compute(diamantutils_stockcolisage_lot_total_qty($db, $fk_product, $lot['batch']), $qpp, $ppc);
	if ($calc['mode'] == 'auto') {
		$res['mode'] = 'auto';
		$res['warn'] = $calc['warn'];
		$new = diamantutils_stockcolisage_storage_value($calc['text']);
		// Lot qui passe en mode automatique : texte saisi à la main remplacé, signalé en retour
		$wasauto = ($lot['lotid'] > 0 && diamantutils_stockcolisage_lot_was_auto($db, $lot['lotid']));
		$oldtext = diamantutils_stockcolisage_text($old);
		if (!$wasauto && $oldtext !== '' && !diamantutils_stockcolisage_same($old, $calc['text'])) {
			$res['replaced'] = $oldtext;
		}
		$productlot->array_options['options_'.$code] = $new;
		$res['colisage'] = $new;
	}

	if ($lot['lotid'] > 0) {
		$result = $productlot->insertExtraFields('PRODUCT_LOT_MODIFY', $user);
	} else {
		$result = $productlot->create($user);
	}
	if ($result <= 0 && !($lot['lotid'] > 0 && $result == 0)) {
		$error = ($productlot->error ? $productlot->error : implode(', ', $productlot->errors));
		return false;
	}
	return $res;
}

/**
 * Le lot était-il en mode automatique avant la saisie (deux attributs valides en base) ?
 *
 * @param	DoliDB	$db		Base de données
 * @param	int		$lotid	Id du lot
 * @return	bool
 */
function diamantutils_stockcolisage_lot_was_auto($db, $lotid)
{
	$attrs = diamantutils_stockcolisage_lot_attributes($db, array((int) $lotid));
	$attr = $attrs[(int) $lotid];
	return (diamantutils_colisage_compute(0, $attr['qpp'], $attr['ppc'])['mode'] == 'auto');
}

/**
 * Recalcule le colisage de tous les lots dont les deux attributs sont renseignés (bouton de la
 * configuration). Les lots sans stock sont compris : leur colisage est vidé s'il ne l'était pas.
 *
 * @param	DoliDB	$db		Base de données
 * @return	array			array('updated' => int, 'unchanged' => int, 'errors' => int, 'warnings' => array(array('lotid', 'batch', 'product', 'qty', 'warn')))
 */
function diamantutils_stockcolisage_recompute_all($db)
{
	$report = array('updated' => 0, 'unchanged' => 0, 'errors' => 0, 'warnings' => array());

	$sql = "SELECT pl.rowid, pl.batch, pl.fk_product, p.ref FROM ".MAIN_DB_PREFIX."product_lot as pl";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_lot_extrafields as ef ON ef.fk_object = pl.rowid";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product as p ON p.rowid = pl.fk_product";
	$sql .= " WHERE pl.entity IN (".getEntity('productlot').")";
	$sql .= " AND p.tobatch > 0";
	$sql .= " AND ef.".DIAMANTUTILS_STOCKCOLISAGE_PPC." > 0 AND ef.".DIAMANTUTILS_STOCKCOLISAGE_QPP." > 0";
	$sql .= " ORDER BY p.ref, pl.batch";
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		$report['errors']++;
		return $report;
	}
	$lots = array();
	while ($obj = $db->fetch_object($resql)) {
		$lots[] = $obj;
	}
	$db->free($resql);

	foreach ($lots as $obj) {
		try {
			$res = diamantutils_stockcolisage_recompute_lot($db, (int) $obj->fk_product, (string) $obj->batch);
		} catch (Throwable $e) {
			dol_syslog(__FUNCTION__.' lot '.$obj->rowid.' : '.$e->getMessage(), LOG_ERR);
			$report['errors']++;
			continue;
		}
		if ($res['status'] == 'updated') {
			$report['updated']++;
		} elseif ($res['status'] == 'unchanged') {
			$report['unchanged']++;
		}
		if ($res['warn'] !== '') {
			$report['warnings'][] = array('lotid' => (int) $obj->rowid, 'batch' => (string) $obj->batch, 'product' => (string) $obj->ref, 'qty' => $res['qty'], 'warn' => $res['warn']);
		}
	}
	return $report;
}
