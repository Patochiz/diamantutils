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

		$langs->load('diamantutils@diamantutils');

		$db = $this->db;
		$linesToDelete = array();
		$originLineIds = array();

		$object->fetch_lines();

		// --- Contrôle ligne par ligne (uniquement si les lignes existent) ---
		if (!empty($object->lines)) {
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
		}

		// --- Recherche des commandes liées ---
		$orderIds = array();
		$orderTotalHt = 0;

		// 1) Via element_element
		$sql = "SELECT DISTINCT c.rowid, c.total_ht";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element as el";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = el.fk_source";
		$sql .= " WHERE el.fk_target = ".((int) $object->id);
		$sql .= " AND el.sourcetype = 'commande'";
		$sql .= " AND el.targettype = 'facture'";
		$resql = $db->query($sql);
		if ($resql) {
			while ($row = $db->fetch_object($resql)) {
				$orderIds[] = (int) $row->rowid;
				$orderTotalHt += (float) $row->total_ht;
			}
			$db->free($resql);
		}

		// 2) Fallback via fk_origin_line des lignes de facture
		if (empty($orderIds) && !empty($originLineIds)) {
			$sql = "SELECT DISTINCT c.rowid, c.total_ht";
			$sql .= " FROM ".MAIN_DB_PREFIX."commandedet as cd";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = cd.fk_commande";
			$sql .= " WHERE cd.rowid IN (".implode(',', $originLineIds).")";
			$resql = $db->query($sql);
			if ($resql) {
				while ($row = $db->fetch_object($resql)) {
					$orderIds[] = (int) $row->rowid;
					$orderTotalHt += (float) $row->total_ht;
				}
				$db->free($resql);
			}
		}

		// 3) Fallback via linked_objects sur l'objet Facture (cas de la
		//    création groupée depuis la liste des commandes : les liens
		//    element_element et les lignes ne sont pas encore en base, mais
		//    Dolibarr positionne linked_objects avant d'appeler create()).
		if (empty($orderIds)) {
			$linkedOrderIds = array();
			if (!empty($object->linked_objects['commande'])) {
				$val = $object->linked_objects['commande'];
				$linkedOrderIds = is_array($val) ? $val : array($val);
			}
			if (empty($linkedOrderIds) && !empty($object->origin) && $object->origin == 'commande' && !empty($object->origin_id)) {
				$linkedOrderIds = array((int) $object->origin_id);
			}
			if (!empty($linkedOrderIds)) {
				$cleanIds = array();
				foreach ($linkedOrderIds as $oid) {
					$cleanIds[] = (int) $oid;
				}
				$sql = "SELECT DISTINCT c.rowid, c.total_ht";
				$sql .= " FROM ".MAIN_DB_PREFIX."commande as c";
				$sql .= " WHERE c.rowid IN (".implode(',', $cleanIds).")";
				$resql = $db->query($sql);
				if ($resql) {
					while ($row = $db->fetch_object($resql)) {
						$orderIds[] = (int) $row->rowid;
						$orderTotalHt += (float) $row->total_ht;
					}
					$db->free($resql);
				}
			}
		}

		// --- Recherche des factures liées et remplissage de l'extrafield ---
		if (!empty($orderIds)) {
			$sql = "SELECT DISTINCT f.rowid, f.ref, f.datef, f.total_ht";
			$sql .= " FROM ".MAIN_DB_PREFIX."element_element as el";
			$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = el.fk_target";
			$sql .= " WHERE el.fk_source IN (".implode(',', $orderIds).")";
			$sql .= " AND el.sourcetype = 'commande'";
			$sql .= " AND el.targettype = 'facture'";
			$sql .= " AND f.fk_statut <> 3";
			if (!empty($originLineIds)) {
				$sql .= " UNION SELECT DISTINCT f.rowid, f.ref, f.datef, f.total_ht";
				$sql .= " FROM ".MAIN_DB_PREFIX."facturedet as fd";
				$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = fd.fk_facture";
				$sql .= " WHERE fd.fk_origin_line IN (".implode(',', $originLineIds).")";
				$sql .= " AND f.fk_statut <> 3";
			}
			$sql .= " ORDER BY datef, ref";
			$resql = $db->query($sql);

			$allInvoices = array();
			if ($resql) {
				while ($row = $db->fetch_object($resql)) {
					$allInvoices[] = $row;
				}
				$db->free($resql);
			}

			$html = '';
			$totalInvoiced = 0;
			$currency = $langs->getCurrencySymbol($conf->currency);
			foreach ($allInvoices as $inv) {
				$url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $inv->rowid);
				$ref = dol_escape_htmltag($inv->ref);
				$date = dol_print_date($db->jdate($inv->datef), 'day');
				$ht = price($inv->total_ht, 0, '', 1, -1, 2);
				$html .= '<a href="'.$url.'">'.$ref.'</a> ('.$date.' &mdash; '.$ht.' '.$currency.' HT)<br>'."\n";
				$totalInvoiced += (float) $inv->total_ht;

				// Détail par commande (via fk_origin_line puis fallback element_element)
				$sqlBk = "SELECT c.ref, SUM(fd.total_ht) as order_ht";
				$sqlBk .= " FROM ".MAIN_DB_PREFIX."facturedet as fd";
				$sqlBk .= " INNER JOIN ".MAIN_DB_PREFIX."commandedet as cd ON cd.rowid = fd.fk_origin_line";
				$sqlBk .= " INNER JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = cd.fk_commande";
				$sqlBk .= " WHERE fd.fk_facture = ".((int) $inv->rowid);
				$sqlBk .= " AND fd.fk_origin_line > 0";
				$sqlBk .= " GROUP BY c.rowid, c.ref ORDER BY c.ref";
				$resqlBk = $db->query($sqlBk);
				$bkRows = array();
				if ($resqlBk) {
					while ($bkRow = $db->fetch_object($resqlBk)) {
						$bkRows[] = $bkRow;
					}
					$db->free($resqlBk);
				}
				if (empty($bkRows)) {
					$sqlBk = "SELECT c.ref, c.total_ht as order_ht";
					$sqlBk .= " FROM ".MAIN_DB_PREFIX."element_element as el";
					$sqlBk .= " INNER JOIN ".MAIN_DB_PREFIX."commande as c ON c.rowid = el.fk_source";
					$sqlBk .= " WHERE el.fk_target = ".((int) $inv->rowid);
					$sqlBk .= " AND el.sourcetype = 'commande'";
					$sqlBk .= " AND el.targettype = 'facture'";
					$sqlBk .= " ORDER BY c.ref";
					$resqlBk = $db->query($sqlBk);
					if ($resqlBk) {
						while ($bkRow = $db->fetch_object($resqlBk)) {
							$bkRows[] = $bkRow;
						}
						$db->free($resqlBk);
					}
				}
				if (count($bkRows) >= 2) {
					foreach ($bkRows as $bk) {
						$bkRef = dol_escape_htmltag($bk->ref);
						$bkHt = price($bk->order_ht, 0, '', 1, -1, 2);
						$html .= '&nbsp;&nbsp;&nbsp;- Commande '.$bkRef.' &mdash; '.$bkHt.' '.$currency.' HT<br>'."\n";
					}
				}
			}

			$remaining = $orderTotalHt - $totalInvoiced;
			$amountStr = price($totalInvoiced, 0, '', 1, -1, 2).' '.$currency.' HT';
			$orderStr = price($orderTotalHt, 0, '', 1, -1, 2).' '.$currency.' HT';
			$remainStr = price($remaining, 0, '', 1, -1, 2).' '.$currency.' HT';
			$html .= '<strong>=&gt; '.$langs->trans('DiamantutilsTotalInvoiced', $amountStr, $orderStr).'</strong><br>'."\n";
			if (round($remaining, 2) != 0) {
				$html .= '<strong style="color: #cc0000;">'.$langs->trans('DiamantutilsRemainingToInvoice', $remainStr).'</strong>'."\n";
			} else {
				$html .= '<strong>'.$langs->trans('DiamantutilsRemainingToInvoice', $remainStr).'</strong>'."\n";
			}

			$object->fetch_optionals();
			$object->array_options['options_factures'] = $html;
			$object->updateExtraFields();
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
