<?php
/**
 * Commandes sous-traitées (livraison directe client) — fonctions communes
 *
 * - état de la fonctionnalité (constante DIAMANTUTILS_SOUSTRAITANCE_ENABLED) ;
 * - entrepôt « Sous-traitance » (constante DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID) ;
 * - installation idempotente de l'extrafield et de l'entrepôt.
 */

/** Code de l'extrafield « Livraison directe client » sur commande fournisseur */
if (!defined('DIAMANTUTILS_ST_EXTRAFIELD')) {
	define('DIAMANTUTILS_ST_EXTRAFIELD', 'diam_livraison_directe');
}

/** Ref de l'entrepôt créé à l'installation */
if (!defined('DIAMANTUTILS_ST_WAREHOUSE_REF')) {
	define('DIAMANTUTILS_ST_WAREHOUSE_REF', 'SOUS-TRAITANCE');
}

/**
 * Indique si la gestion des commandes sous-traitées est active
 *
 * @return	bool
 */
function diamantutils_st_enabled()
{
	return isModEnabled('diamantutils') && getDolGlobalInt('DIAMANTUTILS_SOUSTRAITANCE_ENABLED') && diamantutils_st_warehouse_id() > 0;
}

/**
 * Id de l'entrepôt Sous-traitance configuré
 *
 * @return	int		Id entrepôt, 0 si non configuré
 */
function diamantutils_st_warehouse_id()
{
	return getDolGlobalInt('DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID');
}

/**
 * Indique si l'extrafield « Livraison directe client » existe sur les commandes fournisseur
 *
 * @param	DoliDB	$db		Base de données
 * @return	bool
 */
function diamantutils_st_extrafield_exists($db)
{
	require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
	$extrafields = new ExtraFields($db);
	$extrafields->fetch_name_optionals_label('commande_fournisseur', true);
	return !empty($extrafields->attributes['commande_fournisseur']['label'][DIAMANTUTILS_ST_EXTRAFIELD]);
}

/**
 * Crée l'extrafield « Livraison directe client » s'il n'existe pas (idempotent)
 *
 * select OUI/NON, obligatoire, défaut NON, visible en liste et fiche, modifiable après validation.
 * Colonne créée en NOT NULL DEFAULT 'NON' : les commandes existantes passent à NON.
 *
 * @param	DoliDB	$db		Base de données
 * @param	string	$error	Message d'erreur en retour
 * @return	int				0 si déjà présent, 1 si créé, <0 si erreur
 */
function diamantutils_st_install_extrafield($db, &$error)
{
	if (diamantutils_st_extrafield_exists($db)) {
		return 0;
	}

	$extrafields = new ExtraFields($db);
	$param = array('options' => array('OUI' => 'Oui', 'NON' => 'Non'));
	$result = $extrafields->addExtraField(
		DIAMANTUTILS_ST_EXTRAFIELD,	// code
		'DiamantutilsLivraisonDirecte',	// libellé (clé de traduction)
		'select',
		100,						// position
		'',							// taille
		'commande_fournisseur',
		0,							// unique
		1,							// obligatoire
		'NON',						// valeur par défaut
		$param,
		1,							// alwayseditable
		'',							// perms
		'1',						// visible en liste et fiche
		'DiamantutilsLivraisonDirecteHelp',
		'',							// computed
		'',							// entity
		'diamantutils@diamantutils',
		'isModEnabled("diamantutils")'
	);
	if ($result < 0) {
		$error = $extrafields->error;
		return -1;
	}
	return 1;
}

/**
 * Trouve ou crée l'entrepôt Sous-traitance et enregistre son id en constante (idempotent)
 *
 * 1. Constante DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID pointant vers un entrepôt existant → rien à faire ;
 * 2. sinon entrepôt de ref SOUS-TRAITANCE existant → réutilisé ;
 * 3. sinon création.
 *
 * @param	DoliDB	$db		Base de données
 * @param	User	$user	Utilisateur
 * @param	string	$error	Message d'erreur en retour
 * @return	int				Id de l'entrepôt, <0 si erreur
 */
function diamantutils_st_install_warehouse($db, $user, &$error)
{
	global $conf, $langs;

	require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
	require_once DOL_DOCUMENT_ROOT.'/product/stock/class/entrepot.class.php';

	$fk_warehouse = diamantutils_st_warehouse_id();
	if ($fk_warehouse > 0) {
		$entrepot = new Entrepot($db);
		if ($entrepot->fetch($fk_warehouse) > 0) {
			return $fk_warehouse;
		}
		$fk_warehouse = 0;
	}

	$sql = "SELECT rowid FROM ".MAIN_DB_PREFIX."entrepot";
	$sql .= " WHERE ref = '".$db->escape(DIAMANTUTILS_ST_WAREHOUSE_REF)."'";
	$sql .= " AND entity IN (".getEntity('stock').")";
	$sql .= " ORDER BY rowid";
	$resql = $db->query($sql);
	if (!$resql) {
		$error = $db->lasterror();
		return -1;
	}
	$obj = $db->fetch_object($resql);
	$db->free($resql);
	if ($obj) {
		$fk_warehouse = (int) $obj->rowid;
	}

	if (!$fk_warehouse) {
		$langs->load('diamantutils@diamantutils');
		$entrepot = new Entrepot($db);
		$entrepot->label = DIAMANTUTILS_ST_WAREHOUSE_REF;	// colonne ref
		$entrepot->lieu = $langs->transnoentitiesnoconv('DiamantutilsSoustraitanceWarehouseLabel');
		$entrepot->description = $langs->transnoentitiesnoconv('DiamantutilsSoustraitanceWarehouseDesc');
		$entrepot->statut = Entrepot::STATUS_OPEN_ALL;
		$fk_warehouse = $entrepot->create($user);
		if ($fk_warehouse <= 0) {
			$error = $entrepot->error.' '.implode(', ', (array) $entrepot->errors);
			return -1;
		}
	}

	if (dolibarr_set_const($db, 'DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID', $fk_warehouse, 'chaine', 0, '', $conf->entity) < 0) {
		$error = $db->lasterror();
		return -1;
	}
	$conf->global->DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID = $fk_warehouse;

	return $fk_warehouse;
}
