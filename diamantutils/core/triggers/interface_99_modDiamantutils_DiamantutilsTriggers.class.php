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
 *  - MASQUER          : les lignes déjà entièrement facturées sont retirées silencieusement
 *  - AFFICHER         : liste des factures liées affichée dans l'extrafield "factures", sans blocage
 *  - AFFICHER_BLOQUER : idem + la création est refusée si une ligne dépasse le reliquat facturable
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
		$originLineIds = array();

		foreach ($object->lines as $line) {
			if (empty($line->fk_origin_line) || $line->id <= 0) {
				continue;
			}

			$originLineId = (int) $line->fk_origin_line;
			$originLineIds[$originLineId] = $originLineId;

			$sql = "SELECT qty FROM ".MAIN_DB_PREFIX."commandedet WHERE rowid = ".$originLineId;
			$resql = $db->query($sql);
			if (!$resql || !$db->num_rows($resql)) {
				$db->free($resql);
				continue;
			}
			$objQty = $db->fetch_object($resql);
			$qtyOrigin = (float) $objQty->qty;
			$db->free($resql);

			$sql = "SELECT SUM(fd.qty) as qty_invoiced";
			$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as fd";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = fd.fk_facture";
			$sql .= " WHERE fd.fk_origin_line = ".$originLineId;
			$sql .= " AND fd.fk_facture <> ".((int) $object->id);
			$sql .= " AND f.fk_statut <> 3";
			$resql = $db->query($sql);
			$qtyInvoicedElsewhere = 0.0;
			if ($resql) {
				$objSum = $db->fetch_object($resql);
				$qtyInvoicedElsewhere = (float) $objSum->qty_invoiced;
				$db->free($resql);
			}

			if ($qtyInvoicedElsewhere <= 0) {
				continue;
			}

			$remaining = $qtyOrigin - $qtyInvoicedElsewhere;

			if ($mode == 'MASQUER') {
				if ($remaining <= 0) {
					$linesToDelete[] = $line;
				}
				continue;
			}

			if ($mode == 'AFFICHER_BLOQUER' && $line->qty > $remaining) {
				$this->errors[] = $langs->trans('DiamantutilsLineExceedsRemaining', $line->desc, $line->qty, max($remaining, 0));
			}
		}

		// Recherche des factures liées aux mêmes commandes via element_element
		$sql = "SELECT DISTINCT f.rowid, f.ref, f.datef, f.total_ht";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element as el1";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."element_element as el2 ON el2.fk_source = el1.fk_source AND el2.sourcetype = 'commande' AND el2.targettype = 'facture'";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = el2.fk_target";
		$sql .= " WHERE el1.fk_target = ".((int) $object->id);
		$sql .= " AND el1.sourcetype = 'commande'";
		$sql .= " AND el1.targettype = 'facture'";
		$sql .= " AND el2.fk_target <> ".((int) $object->id);
		$sql .= " AND f.fk_statut <> 3";
		$sql .= " ORDER BY f.datef, f.ref";
		$resql = $db->query($sql);
		if ($resql) {
			$relatedInvoices = array();
			while ($row = $db->fetch_object($resql)) {
				$relatedInvoices[] = $row;
			}
			$db->free($resql);

			if (!empty($relatedInvoices)) {
				$html = '';
				foreach ($relatedInvoices as $inv) {
					$url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $inv->rowid);
					$ref = dol_escape_htmltag($inv->ref);
					$date = dol_print_date($db->jdate($inv->datef), 'day');
					$ht = price($inv->total_ht, 0, '', 1, -1, 2);
					$html .= '<a href="'.$url.'">'.$ref.'</a> ('.$date.' — '.$ht.' '.$langs->getCurrencySymbol($conf->currency).' HT)<br>'."\n";
				}
				$object->fetch_optionals();
				$object->array_options['options_factures'] = $html;
				$object->updateExtraFields();
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
