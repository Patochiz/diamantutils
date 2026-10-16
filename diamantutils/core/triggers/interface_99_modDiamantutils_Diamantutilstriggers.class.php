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
		$this->description = 'Triggers DiamantUtils : contrôles des commandes sous-traitées';
		$this->version = '2.2';
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
		if (!in_array($action, array('RECEPTION_CREATE', 'RECEPTION_VALIDATE', 'LINERECEPTION_CREATE', 'LINERECEPTION_MODIFY', 'LINEORDER_SUPPLIER_DISPATCH'))) {
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
		}

		if (!empty($errors)) {
			dol_syslog(__METHOD__.' '.$action.' id='.$object->id.' : '.implode(' / ', $errors), LOG_WARNING);
			$this->errors = $errors;
			$this->error = implode(' ', $errors);
			return -1;
		}
		return 1;
	}
}
