<?php
/**
 * Triggers du module DiamantUtils
 *
 * Commandes sous-traitées (livraison directe client), contrôles bloquants des réceptions :
 *  - RECEPTION_CREATE, RECEPTION_VALIDATE : toutes les lignes de la réception ;
 *  - LINERECEPTION_MODIFY : ligne modifiée (fiche réception ou dispatch.php) ;
 *  - LINERECEPTION_CREATE : ligne créée hors Reception::create() (dans create(), les erreurs
 *    d'une ligne ne remontent pas à l'écran : le contrôle est fait par RECEPTION_CREATE) ;
 *  - LINEORDER_SUPPLIER_DISPATCH : ventilation directe sans module Réceptions.
 * Contrôles bloquants des expéditions (lignes prélevées sur l'entrepôt Sous-traitance) :
 *  - SHIPPING_CREATE (après insertion des lignes et des lots), SHIPPING_VALIDATE (après le
 *    mouvement de stock) : toutes les lignes ;
 *  - LINESHIPPING_MODIFY : ligne modifiée dans le brouillon. L'ajout d'une ligne de lot par
 *    create_line_batch() n'a pas de trigger après l'insertion du lot : contrôlé à la validation.
 * Description pour devis (lib/descpropal.lib.php) :
 *  - LINEPROPAL_INSERT : la ligne d'un produit reprend son extrafield desc_propal ;
 *  - PRODUCT_CREATE, PRODUCT_MODIFY : syntaxe de desc_propal vérifiée, avertissement non bloquant.
 * Prix de revient (DIAMANTUTILS_COSTPRICE_FROM_RECEIPT) :
 *  - STOCK_MOVEMENT : entrée issue d'une réception fournisseur, cost_price = prix du mouvement.
 * Tous ces triggers sont appelés dans la transaction : un retour < 0 annule l'opération.
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceDiamantutilstriggers extends DolibarrTriggers
{
	/**
	 * @param	DoliDB	$db		Base de données
	 */
	public function __construct($db)
	{
		// Le constructeur parent initialise error et errors (déclarés sans valeur en v20)
		parent::__construct($db);
		$this->family = 'other';
		$this->description = 'Triggers DiamantUtils : contrôles des commandes sous-traitées, description pour devis';
		$this->version = '2.8';
		$this->picto = 'generic';
	}

	/**
	 * @param	string		$action		Code de l'événement
	 * @param	Object		$object		Objet concerné
	 * @param	User		$user		Utilisateur
	 * @param	Translate	$langs		Langues
	 * @param	Conf		$conf		Configuration
	 * @return	int						0 = rien fait, >0 = OK, <0 = erreur (annule l'opération)
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if ($action == 'LINEPROPAL_INSERT') {
			return $this->descPropal($object);
		}
		if ($action == 'PRODUCT_CREATE' || $action == 'PRODUCT_MODIFY') {
			return $this->checkDescPropal($object, $langs);
		}
		if ($action == 'STOCK_MOVEMENT') {
			return $this->costPriceFromReceipt($object);
		}

		if (!in_array($action, array('RECEPTION_CREATE', 'RECEPTION_VALIDATE', 'LINERECEPTION_CREATE', 'LINERECEPTION_MODIFY', 'LINEORDER_SUPPLIER_DISPATCH', 'SHIPPING_CREATE', 'SHIPPING_VALIDATE', 'LINESHIPPING_MODIFY'))) {
			return 0;
		}

		dol_include_once('/diamantutils/lib/soustraitance.lib.php');
		if (!diamantutils_st_enabled()) {
			return 0;
		}
		dol_include_once('/diamantutils/class/soustraitance.class.php');
		$st = new DiamantutilsSoustraitance($this->db);

		$errors = array();
		switch ($action) {
			case 'RECEPTION_CREATE':
			case 'RECEPTION_VALIDATE':
				$errors = $st->checkReception($object->id);
				break;

			case 'LINERECEPTION_CREATE':
				if (!empty($object->fk_reception)) {
					return 0;	// contrôlé par RECEPTION_CREATE
				}
				$errors = $st->checkReceptionDetLine($object->id);
				break;

			case 'LINERECEPTION_MODIFY':
				$errors = $st->checkReceptionDetLine($object->id);
				break;

			case 'LINEORDER_SUPPLIER_DISPATCH':
				$errors = $st->checkDispatch($object->id);
				break;

			case 'SHIPPING_CREATE':
			case 'SHIPPING_VALIDATE':
				$errors = $st->checkShipment($object->id);
				break;

			case 'LINESHIPPING_MODIFY':
				$errors = $st->checkShipmentLine($object->id);
				break;
		}

		if (!empty($errors)) {
			dol_syslog(__METHOD__.' '.$action.' id='.$object->id.' : '.implode(' / ', $errors), LOG_WARNING);
			$this->errors = $errors;
			$this->error = implode(' ', $errors);
			return -1;
		}
		return 1;
	}

	/**
	 * LINEPROPAL_INSERT : description pour devis du produit reprise sur la ligne
	 *
	 * @param	PropaleLigne	$line	Ligne insérée
	 * @return	int						1 = ligne modifiée, 0 = rien fait, -1 = erreur
	 */
	private function descPropal($line)
	{
		dol_include_once('/diamantutils/lib/descpropal.lib.php');
		$error = '';
		$result = diamantutils_descpropal_apply($this->db, $line, $error);
		if ($result < 0) {
			dol_syslog(__METHOD__.' line id='.((int) $line->id).' : '.$error, LOG_ERR);
			$this->error = $error;
			$this->errors = array($error);
			return -1;
		}
		return $result;
	}

	/**
	 * PRODUCT_CREATE / PRODUCT_MODIFY : vérifie le modèle desc_propal (syntaxe, attributs connus)
	 * et affiche un avertissement. Jamais bloquant : le produit est enregistré dans tous les cas.
	 *
	 * @param	Product		$product	Produit enregistré
	 * @param	Translate	$langs		Langues
	 * @return	int						0
	 */
	private function checkDescPropal($product, $langs)
	{
		$template = (string) ($product->array_options['options_desc_propal'] ?? '');
		if (strpos($template, '{') === false) {
			return 0;
		}

		dol_include_once('/diamantutils/lib/diamantutils_descpropal.lib.php');
		// Attributs connus : ceux des variantes (produit parent) ou de la variante elle-même
		$known = null;
		if (isModEnabled('variants') && !empty($product->id)) {
			$known = diamantutils_descpropal_parent_attributes($this->db, $product->id);
			if (empty($known)) {
				$known = diamantutils_descpropal_variant_attributes($this->db, $product->id);
			}
			if (!is_array($known) || empty($known)) {
				$known = null;	// produit simple : syntaxe seulement
			}
		}

		$errors = diamantutils_descpropal_check($template, $known);
		if (count($errors)) {
			$langs->load('diamantutils@diamantutils');
			setEventMessages($langs->trans('DiamantutilsDescPropalCheckWarning', implode(' ; ', $errors)), null, 'warnings');
			dol_syslog(__METHOD__.' produit '.$product->ref.' : '.implode(' ; ', $errors), LOG_WARNING);
		}
		return 0;
	}

	/**
	 * STOCK_MOVEMENT : prix de revient du produit = prix unitaire de la réception fournisseur.
	 * Seul llx_product.cost_price est modifié : le PMP est déjà calculé par MouvementStock::_create()
	 * avec ce même prix, prix fournisseurs et lignes de commande / facture restent inchangés.
	 *
	 * Mouvements retenus : MouvementStock::reception() (type 3), quantité > 0, origine
	 * 'reception' (module Réceptions) ou 'order_supplier' (ventilation sans module Réceptions), prix > 0.
	 *
	 * @param	MouvementStock	$mvt	Mouvement de stock créé
	 * @return	int						1 = prix mis à jour, 0 = rien fait, -1 = erreur SQL
	 */
	private function costPriceFromReceipt($mvt)
	{
		if (!isModEnabled('diamantutils') || !getDolGlobalInt('DIAMANTUTILS_COSTPRICE_FROM_RECEIPT')) {
			return 0;
		}

		$origin = (string) (!empty($mvt->origin_type) ? $mvt->origin_type : ($mvt->origintype ?? ''));
		$productId = (int) (!empty($mvt->product_id) ? $mvt->product_id : ($mvt->fk_product ?? 0));
		$qty = (float) ($mvt->qty ?? 0);
		$price = (float) price2num($mvt->price ?? 0, 'MU');

		if ((int) $mvt->type !== 3 || $qty <= 0 || !in_array($origin, array('order_supplier', 'reception'), true)
			|| $price <= 0 || $productId <= 0) {
			return 0;
		}

		// Ancien prix de revient, pour le log
		$oldprice = '';
		$resql = $this->db->query("SELECT cost_price FROM ".MAIN_DB_PREFIX."product WHERE rowid = ".$productId);
		if ($resql) {
			$obj = $this->db->fetch_object($resql);
			$oldprice = ($obj ? (string) $obj->cost_price : '');
			$this->db->free($resql);
		}

		$sql = "UPDATE ".MAIN_DB_PREFIX."product";
		$sql .= " SET cost_price = ".((float) $price);
		$sql .= " WHERE rowid = ".((int) $productId);

		dol_syslog(__METHOD__.' STOCK_MOVEMENT cost_price product='.$productId.' old='.$oldprice.' new='.$price.' origin='.$origin.' origin_id='.((int) ($mvt->origin_id ?? 0)).' mvt='.((int) $mvt->id), LOG_DEBUG);

		if (!$this->db->query($sql)) {
			$this->error = $this->db->lasterror();
			$this->errors = array($this->error);
			dol_syslog(__METHOD__.' '.$this->error, LOG_ERR);
			return -1;
		}
		return 1;
	}
}
