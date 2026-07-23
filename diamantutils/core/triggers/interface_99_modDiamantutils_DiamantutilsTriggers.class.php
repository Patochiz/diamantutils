<?php
/**
 * Trigger DiamantUtils
 *
 * Contrôle du "déjà facturé" ligne par ligne lors de la création d'une facture
 * depuis une ou plusieurs commandes (facture standard, unitaire ou groupée
 * depuis la liste des commandes).
 *
 * Mode configurable via la constante DIAMANTUTILS_INVOICE_CHECK_MODE :
 *  - DESACTIVE        : aucun contrôle
 *  - MASQUER          : les lignes déjà entièrement facturées sont retirées silencieusement de la facture créée
 *  - AFFICHER         : une note "[Déjà facturé : X/Y]" est ajoutée sur la ligne, aucun blocage
 *  - AFFICHER_BLOQUER : note ajoutée + la création de la facture est refusée si une ligne dépasse le reliquat facturable
 */

require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';

class InterfaceDiamantutilsTriggers extends DolibarrTriggers
{
	public $family = 'custom';
	public $description = "Contrôle du déjà-facturé sur les lignes de commande lors de la création de facture";
	public $version = self::VERSION_DOLIBARR;
	public $picto = 'generic';

	/**
	 * Fonction appelée pour chaque trigger géré
	 *
	 * @param string       $action Code du trigger
	 * @param CommonObject $object Objet concerné (Facture)
	 * @param User         $user   Utilisateur
	 * @param Translate    $langs  Langue
	 * @param Conf         $conf   Configuration
	 * @return int Return integer <0 si erreur (annule la transaction), 0 si rien fait, >0 si OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!$this->isModuleActivated('diamantutils')) {
			return 0;
		}

		if ($action != 'BILL_CREATE') {
			return 0;
		}

		$mode = !empty($conf->global->DIAMANTUTILS_INVOICE_CHECK_MODE) ? $conf->global->DIAMANTUTILS_INVOICE_CHECK_MODE : 'AFFICHER';

		if ($mode == 'DESACTIVE' || empty($object->lines)) {
			return 0;
		}

		$db = $this->db;
		$linesToDelete = array();

		foreach ($object->lines as $line) {
			// On ne traite que les lignes issues d'une ligne de commande (fk_origin_line = commandedet.rowid)
			if (empty($line->fk_origin_line) || $line->id <= 0) {
				continue;
			}

			// Quantité prévue sur la ligne de commande d'origine
			$sql = "SELECT qty FROM ".MAIN_DB_PREFIX."commandedet WHERE rowid = ".((int) $line->fk_origin_line);
			$resql = $db->query($sql);
			if (!$resql || !$db->num_rows($resql)) {
				continue;
			}
			$objQty = $db->fetch_object($resql);
			$qtyOrigin = (float) $objQty->qty;

			// Quantité déjà facturée sur cette ligne de commande, toutes AUTRES factures confondues (hors facture en cours de création)
			$sql = "SELECT SUM(fd.qty) as qty_invoiced";
			$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as fd";
			$sql .= " WHERE fd.fk_origin_line = ".((int) $line->fk_origin_line);
			$sql .= " AND fd.fk_facture <> ".((int) $object->id);
			$resql = $db->query($sql);
			$qtyInvoicedElsewhere = 0.0;
			if ($resql) {
				$objSum = $db->fetch_object($resql);
				$qtyInvoicedElsewhere = (float) $objSum->qty_invoiced;
			}

			$remaining = $qtyOrigin - $qtyInvoicedElsewhere;

			if ($qtyInvoicedElsewhere <= 0) {
				// Rien de facturé ailleurs sur cette ligne, pas d'action nécessaire
				continue;
			}

			if ($mode == 'MASQUER') {
				if ($remaining <= 0) {
					$linesToDelete[] = $line;
				}
				continue;
			}

			// Modes AFFICHER et AFFICHER_BLOQUER : on annote la ligne
			$note = "[Déjà facturé : ".$qtyInvoicedElsewhere."/".$qtyOrigin."]";
			if (strpos($line->desc, $note) === false) {
				$line->desc = trim($line->desc)."\n".$note;
				$line->update($user, 1); // 1 = notrigger pour éviter une boucle
			}

			if ($mode == 'AFFICHER_BLOQUER' && $line->qty > $remaining) {
				$this->errors[] = "Ligne '".$line->desc."' : quantité facturée (".$line->qty.") supérieure au reliquat disponible (".max($remaining, 0).") — vérifiez les factures déjà émises pour cette commande avant de valider.";
			}
		}

		if ($mode == 'AFFICHER_BLOQUER' && !empty($this->errors)) {
			return -1; // Annule la création de la facture, erreurs affichées à l'utilisateur
		}

		// Suppression silencieuse des lignes déjà entièrement facturées (mode MASQUER)
		foreach ($linesToDelete as $line) {
			$line->delete($user, 1); // 1 = notrigger
		}

		return 1;
	}

	/**
	 * Vérifie que le module diamantutils est bien activé (sécurité si le trigger reste après désactivation)
	 */
	protected function isModuleActivated($moduleName)
	{
		global $conf;
		return !empty($conf->{$moduleName}->enabled);
	}
}
