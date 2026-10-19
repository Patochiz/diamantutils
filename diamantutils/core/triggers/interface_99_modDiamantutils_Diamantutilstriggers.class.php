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
 *  - LINEPROPAL_INSERT : la ligne d'un produit reprend son extrafield desc_propal.
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
		$this->version = '2.5';
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
}
