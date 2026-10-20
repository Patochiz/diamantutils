<?php
/**
 * Lots de produits : insertion d'un lot sur un devis ou une commande client
 *
 * Chaque ligne du lot est ajoutée par $object->addline(), triggers actifs, comme une saisie
 * de produit prédéfini sur la fiche (comm/propal/card.php, commande/card.php) :
 *  - prix et TVA : Product::getSellPrice($mysoc, $tiers) (prix unique, multiprix par niveau,
 *    prix client) ; pas de prix → 0 ;
 *  - remise : remise par défaut du tiers (valeur pré-remplie du formulaire natif) ;
 *  - description : description standard du produit, dans la langue du tiers si
 *    MAIN_MULTILANGS et PRODUIT_TEXTS_IN_THIRDPARTY_LANGUAGE sont actifs, suivie de la mention
 *    code douane / pays d'origine ;
 *  - unité et type du produit, prix d'achat laissé vide (calculé par la ligne selon la
 *    configuration des marges).
 * Les triggers LINEPROPAL_INSERT / LINEORDER_INSERT s'appliquent donc aux lignes insérées,
 * dont la description pour devis de DiamantUtils (lib/descpropal.lib.php).
 */

/**
 * Fonctionnalité « Lots de produits » active ?
 * Constante absente (installation antérieure à la v2.6) = active.
 *
 * @return	bool
 */
function diamantutils_lot_enabled()
{
	return isModEnabled('diamantutils') && !empty(getDolGlobalString('DIAMANTUTILS_LOT_ENABLED', '1'));
}

/**
 * Description d'une ligne de produit, comme la saisie native d'un produit prédéfini
 *
 * @param	DoliDB		$db			Base de données
 * @param	Product		$prod		Produit (traductions chargées)
 * @param	Societe		$thirdparty	Tiers de la pièce
 * @return	string
 */
function diamantutils_lot_product_desc($db, $prod, $thirdparty)
{
	global $conf, $langs;

	$outputlangs = $langs;
	$thirdpartylang = (getDolGlobalInt('MAIN_MULTILANGS') && getDolGlobalString('PRODUIT_TEXTS_IN_THIRDPARTY_LANGUAGE'));
	if ($thirdpartylang && is_object($thirdparty) && !empty($thirdparty->default_lang)) {
		$outputlangs = new Translate('', $conf);
		$outputlangs->setDefaultLang($thirdparty->default_lang);
		$outputlangs->load('products');
	}

	if ($thirdpartylang && !empty($prod->multilangs[$outputlangs->defaultlang]['description'])) {
		$desc = $prod->multilangs[$outputlangs->defaultlang]['description'];
	} else {
		$desc = (string) $prod->description;
	}

	// Mention code douane / pays d'origine (même texte que comm/propal/card.php)
	if (!getDolGlobalString('MAIN_PRODUCT_DISABLE_CUSTOMCOUNTRYCODE') && (!empty($prod->customcode) || !empty($prod->country_code))) {
		$langs->load('products');
		$tmptxt = '(';
		if (!empty($prod->customcode)) {
			$tmptxt .= $outputlangs->transnoentitiesnoconv('CustomCode').': '.$prod->customcode;
		}
		if (!empty($prod->customcode) && !empty($prod->country_code)) {
			$tmptxt .= ' - ';
		}
		if (!empty($prod->country_code)) {
			$tmptxt .= $outputlangs->transnoentitiesnoconv('CountryOrigin').': '.getCountry($prod->country_code, 0, $db, $outputlangs, 0);
		}
		$tmptxt .= ')';
		$desc = dol_concatdesc($desc, $tmptxt);
	}

	return $desc;
}

/**
 * Ajoute les lignes d'un lot à un devis ou une commande client en brouillon.
 * Tout ou rien : la première ligne en échec annule toutes les lignes déjà insérées.
 *
 * @param	DoliDB				$db			Base de données
 * @param	Propal|Commande		$object		Pièce (brouillon), tiers chargé ou chargeable
 * @param	DiamantLotLine[]	$lines		Lignes du lot, dans l'ordre d'insertion
 * @param	float				$mult		Multiplicateur des quantités (> 0)
 * @param	User				$user		Utilisateur
 * @param	string				$error		Message d'erreur (sortie)
 * @param	string[]			$errors		Messages détaillés (sortie)
 * @return	int								Nombre de lignes ajoutées, <0 si erreur
 */
function diamantutils_lot_insert($db, $object, $lines, $mult, $user, &$error, &$errors = array())
{
	global $langs, $mysoc;

	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	require_once DOL_DOCUMENT_ROOT.'/core/lib/company.lib.php';
	$langs->load('diamantutils@diamantutils');

	$error = '';
	$errors = array();

	$ispropal = ($object->element == 'propal');
	if (!$ispropal && $object->element != 'commande') {
		$error = 'DiamantUtils : type de pièce non géré ('.$object->element.')';
		return -1;
	}
	if ((int) $object->statut !== 0) {
		$error = $langs->trans('DiamantutilsLotErrorNotDraft');
		return -1;
	}
	$mult = (float) $mult;
	if (!($mult > 0)) {
		$error = $langs->trans('DiamantutilsLotErrorMult');
		return -1;
	}
	if (empty($lines)) {
		$error = $langs->trans('DiamantutilsLotErrorEmpty');
		return -1;
	}
	if (empty($object->thirdparty) || !is_object($object->thirdparty)) {
		if ($object->fetch_thirdparty() <= 0) {
			$error = $langs->trans('ErrorRecordNotFound').' (thirdparty)';
			return -1;
		}
	}
	$thirdparty = $object->thirdparty;

	// Remise par défaut du tiers : valeur pré-remplie du formulaire natif d'ajout de ligne
	$remise_percent = (float) price2num(empty($thirdparty->remise_percent) ? 0 : $thirdparty->remise_percent);

	$db->begin();

	$nb = 0;
	foreach ($lines as $line) {
		$prod = new Product($db);
		if ((int) $line->fk_product <= 0 || $prod->fetch((int) $line->fk_product) <= 0) {
			$error = $langs->trans('DiamantutilsLotErrorProductNotFound', (int) $line->fk_product);
			break;
		}
		if (empty($prod->status)) {
			$error = $langs->trans('DiamantutilsLotErrorProductNotForSale', $prod->ref);
			break;
		}

		$qty = price2num((float) $line->qty * $mult, 'MS');

		$price = $prod->getSellPrice($mysoc, $thirdparty);
		if (!is_array($price)) {
			$price = array();
		}
		// price2num : les prix client sont renvoyés formatés par price()
		$pu_ht = (isset($price['pu_ht']) && $price['pu_ht'] !== '' ? price2num($price['pu_ht'], 'MU') : 0);
		$pu_ttc = (isset($price['pu_ttc']) && $price['pu_ttc'] !== '' ? price2num($price['pu_ttc'], 'MU') : 0);
		$price_base_type = (!empty($price['price_base_type']) && $price['price_base_type'] == 'TTC' ? 'TTC' : 'HT');
		$tva_tx = (isset($price['tva_tx']) ? (string) $price['tva_tx'] : (string) get_default_tva($mysoc, $thirdparty, $prod->id));
		$tva_npr = (!empty($price['tva_npr']) ? 1 : 0);
		$localtax1_tx = (isset($price['localtax1']) ? $price['localtax1'] : get_localtax($tva_tx, 1, $thirdparty, $mysoc, $tva_npr));
		$localtax2_tx = (isset($price['localtax2']) ? $price['localtax2'] : get_localtax($tva_tx, 2, $thirdparty, $mysoc, $tva_npr));
		$info_bits = ($tva_npr ? 0x01 : 0);

		$desc = diamantutils_lot_product_desc($db, $prod, $thirdparty);
		$type = (int) $prod->type;
		$fk_unit = $prod->fk_unit;

		// Prix d'achat '' (et non 0) : la ligne le calcule selon la configuration des marges
		if ($ispropal) {
			$result = $object->addline($desc, $pu_ht, $qty, $tva_tx, $localtax1_tx, $localtax2_tx, $prod->id, $remise_percent, $price_base_type, $pu_ttc, $info_bits, $type, -1, 0, 0, 0, '', '', '', '', array(), $fk_unit);
		} else {
			$result = $object->addline($desc, $pu_ht, $qty, $tva_tx, $localtax1_tx, $localtax2_tx, $prod->id, $remise_percent, $info_bits, 0, $price_base_type, $pu_ttc, '', '', $type, -1, 0, 0, null, '', '', array(), $fk_unit);
		}

		if ($result <= 0) {
			$error = $langs->trans('DiamantutilsLotErrorAddLine', $prod->ref).($object->error ? ' : '.$object->error : '');
			$errors = (is_array($object->errors) ? $object->errors : array());
			break;
		}
		$nb++;
	}

	if ($error !== '') {
		$db->rollback();
		// Les lignes annulées restent dans $object->lines : la pièce est rechargée
		$object->fetch($object->id);
		dol_syslog(__FUNCTION__.' '.$object->element.' id='.((int) $object->id).' : '.$error.(count($errors) ? ' / '.implode(' / ', $errors) : ''), LOG_WARNING);
		return -1;
	}

	$db->commit();
	return $nb;
}

/**
 * Régénère le document PDF de la pièce après ajout de lignes, comme la fiche native
 * (sauf MAIN_DISABLE_PDF_AUTOUPDATE)
 *
 * @param	Propal|Commande		$object		Pièce
 * @return	void
 */
function diamantutils_lot_regenerate_document($object)
{
	global $conf, $langs;

	if (getDolGlobalString('MAIN_DISABLE_PDF_AUTOUPDATE')) {
		return;
	}
	if ($object->fetch($object->id) <= 0) {
		return;
	}
	$object->fetch_thirdparty();

	$outputlangs = $langs;
	if (getDolGlobalInt('MAIN_MULTILANGS')) {
		$newlang = (GETPOST('lang_id', 'aZ09') ? GETPOST('lang_id', 'aZ09') : (is_object($object->thirdparty) ? $object->thirdparty->default_lang : ''));
		if (!empty($newlang)) {
			$outputlangs = new Translate('', $conf);
			$outputlangs->setDefaultLang($newlang);
		}
	}
	$hidedetails = (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DETAILS') ? 1 : 0);
	$hidedesc = (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_DESC') ? 1 : 0);
	$hideref = (getDolGlobalString('MAIN_GENERATE_DOCUMENTS_HIDE_REF') ? 1 : 0);

	$result = $object->generateDocument($object->model_pdf, $outputlangs, $hidedetails, $hidedesc, $hideref);
	if ($result < 0) {
		setEventMessages($object->error, $object->errors, 'warnings');
	}
}

/**
 * Lignes produit d'une pièce, pour l'enregistrer comme lot : produit + quantité,
 * lignes libres et lignes spéciales (titres, sous-totaux) ignorées
 *
 * @param	CommonObject	$object		Devis ou commande, lignes chargées
 * @return	DiamantLotLine[]
 */
function diamantutils_lot_lines_from_object($object)
{
	dol_include_once('/diamantutils/class/diamantlot.class.php');

	$lines = array();
	if (empty($object->lines) || !is_array($object->lines)) {
		return $lines;
	}
	foreach ($object->lines as $objline) {
		if (empty($objline->fk_product) || (int) $objline->fk_product <= 0) {
			continue;
		}
		if (isset($objline->product_type) && (int) $objline->product_type == 9) {
			continue;
		}
		if (!((float) $objline->qty > 0)) {
			continue;
		}
		$line = new DiamantLotLine();
		$line->fk_product = (int) $objline->fk_product;
		$line->qty = (float) $objline->qty;
		$lines[] = $line;
	}
	return $lines;
}
