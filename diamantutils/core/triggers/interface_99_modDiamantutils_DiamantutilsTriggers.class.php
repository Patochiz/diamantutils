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
	 * @param string       $action Code du trigger
	 * @param CommonObject $object Objet concerné (Facture)
	 * @param User         $user   Utilisateur
	 * @param Translate    $langs  Langue
	 * @param Conf         $conf   Configuration
	 * @return int <0 si erreur, 0 si rien fait, >0 si OK
	 */
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
		if (!isModEnabled('diamantutils')) {
			return 0;
		}

		if ($action != 'BILL_CREATE') {
			return 0;
		}

		$mode = getDolGlobalString('DIAMANTUTILS_INVOICE_CHECK_MODE', 'AFFICHER');

		if ($mode == 'DESACTIVE') {
			return 0;
		}

		$object->fetch_lines();

		if (empty($object->lines)) {
			return 0;
		}

		$langs->load('diamantutils@diamantutils');

		$db = $this->db;
		$linesToDelete = array();

		foreach ($object->lines as $line) {
			if (empty($line->fk_origin_line) || $line->id <= 0) {
				continue;
			}

			// Quantité prévue sur la ligne de commande d'origine
			$sql = "SELECT qty FROM ".MAIN_DB_PREFIX."commandedet WHERE rowid = ".((int) $line->fk_origin_line);
			$resql = $db->query($sql);
			if (!$resql || !$db->num_rows($resql)) {
				$db->free($resql);
				continue;
			}
			$objQty = $db->fetch_object($resql);
			$qtyOrigin = (float) $objQty->qty;
			$db->free($resql);

			// Quantité déjà facturée sur cette ligne de commande (hors facture en cours, hors factures annulées)
			$sql = "SELECT SUM(fd.qty) as qty_invoiced";
			$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as fd";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = fd.fk_facture";
			$sql .= " WHERE fd.fk_origin_line = ".((int) $line->fk_origin_line);
			$sql .= " AND fd.fk_facture <> ".((int) $object->id);
			$sql .= " AND f.fk_statut <> 3";
			$resql = $db->query($sql);
			$qtyInvoicedElsewhere = 0.0;
			if ($resql) {
				$objSum = $db->fetch_object($resql);
				$qtyInvoicedElsewhere = (float) $objSum->qty_invoiced;
				$db->free($resql);
			}

			$remaining = $qtyOrigin - $qtyInvoicedElsewhere;

			if ($qtyInvoicedElsewhere <= 0) {
				continue;
			}

			if ($mode == 'MASQUER') {
				if ($remaining <= 0) {
					$linesToDelete[] = $line;
				}
				continue;
			}

			// Modes AFFICHER et AFFICHER_BLOQUER : annotation de la ligne
			$note = $langs->trans('DiamantutilsAlreadyInvoiced', $qtyInvoicedElsewhere, $qtyOrigin);
			if (strpos($line->desc, $note) === false) {
				$line->desc = trim($line->desc)."\n".$note;
				$result = $line->update($user, 1);
				if ($result < 0) {
					$this->errors[] = $langs->trans('DiamantutilsLineUpdateError', $line->id);
					return -1;
				}
			}

			if ($mode == 'AFFICHER_BLOQUER' && $line->qty > $remaining) {
				$this->errors[] = $langs->trans('DiamantutilsLineExceedsRemaining', $line->desc, $line->qty, max($remaining, 0));
			}
		}

		if ($mode == 'AFFICHER_BLOQUER' && !empty($this->errors)) {
			return -1;
		}

		foreach ($linesToDelete as $line) {
			$result = $line->delete($user, 1);
			if ($result < 0) {
				$this->errors[] = $langs->trans('DiamantutilsLineDeleteError', $line->id);
				return -1;
			}
		}

		return 1;
	}
}
