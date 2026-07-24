<?php
/**
 * Hook sur la page facture (invoicecard)
 *
 * Affiche les factures déjà liées aux mêmes lignes de commande
 * sur le formulaire de création de facture.
 */

class ActionsDiamantutils
{
	public $db;
	public $error = '';
	public $errors = array();
	public $resprints = '';
	public $results = array();

	public function __construct($db)
	{
		$this->db = $db;
	}

	private function getRelatedInvoicesHtml($orderId)
	{
		$db = $this->db;

		$sql = "SELECT DISTINCT f.rowid, f.ref, f.datef";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element as el";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = el.fk_target";
		$sql .= " WHERE el.fk_source = ".((int) $orderId);
		$sql .= " AND el.sourcetype = 'commande'";
		$sql .= " AND el.targettype = 'facture'";
		$sql .= " AND f.fk_statut <> 3";

		$sql .= " UNION SELECT DISTINCT f.rowid, f.ref, f.datef";
		$sql .= " FROM ".MAIN_DB_PREFIX."element_element as el";
		$sql .= " INNER JOIN ".MAIN_DB_PREFIX."facture as f ON f.rowid = el.fk_source";
		$sql .= " WHERE el.fk_target = ".((int) $orderId);
		$sql .= " AND el.sourcetype = 'facture'";
		$sql .= " AND el.targettype = 'commande'";
		$sql .= " AND f.fk_statut <> 3";

		$sql .= " ORDER BY datef, ref";

		$resql = $db->query($sql);
		if (!$resql) {
			return '';
		}

		$invoices = array();
		while ($row = $db->fetch_object($resql)) {
			$invoices[] = $row;
		}
		$db->free($resql);

		if (empty($invoices)) {
			return '';
		}

		$html = '';
		foreach ($invoices as $inv) {
			$url = DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $inv->rowid);
			$ref = dol_escape_htmltag($inv->ref);
			$date = dol_print_date($db->jdate($inv->datef), 'day');
			$html .= '<a href="'.$url.'">'.$ref.'</a> ('.$date.')<br>'."\n";
		}

		return $html;
	}

	public function doActions($parameters, &$object, &$action, $hookmanager)
	{
		return 0;
	}

	public function formObjectOptions($parameters, &$object, &$action, $hookmanager)
	{
		global $conf, $langs;

		$origin = GETPOST('origin', 'alpha');
		$originid = GETPOSTINT('originid');

		$debug = 'DiamantUtils hook: action='.$action.', origin='.$origin.', originid='.$originid;
		$debug .= ', modEnabled='.((int) isModEnabled('diamantutils'));
		$debug .= ', mode='.getDolGlobalString('DIAMANTUTILS_INVOICE_CHECK_MODE', 'AFFICHER');

		if (!isModEnabled('diamantutils')) {
			$this->resprints = '<!-- '.$debug.' (skip: mod disabled) -->';
			return 0;
		}

		$mode = getDolGlobalString('DIAMANTUTILS_INVOICE_CHECK_MODE', 'AFFICHER');
		if ($mode == 'DESACTIVE') {
			$this->resprints = '<!-- '.$debug.' (skip: DESACTIVE) -->';
			return 0;
		}

		$html = '';
		if ($origin == 'commande' && $originid > 0) {
			$html = $this->getRelatedInvoicesHtml($originid);
		}

		$langs->load('diamantutils@diamantutils');

		$this->resprints = '<tr class="oddeven"><td colspan="2">';
		$this->resprints .= '<div class="info" style="padding:8px;background:#eef;border:1px solid #99c;margin:4px 0;">';
		$this->resprints .= '<strong>DiamantUtils</strong> — '.$debug.'<br>';
		if (!empty($html)) {
			$this->resprints .= '<strong>'.$langs->trans('DiamantutilsInvoiceCheckLabel').'</strong><br>';
			$this->resprints .= $html;
		} else {
			$this->resprints .= 'Aucune facture liee trouvee pour cette commande.';
		}
		$this->resprints .= '</div>';
		$this->resprints .= '</td></tr>'."\n";

		if (!empty($html)) {
			if (!isset($object->array_options)) {
				$object->array_options = array();
			}
			$object->array_options['options_factures'] = $html;

			$jsHtml = json_encode($html);
			$this->resprints .= '<script type="text/javascript">'."\n";
			$this->resprints .= 'jQuery(document).ready(function() {'."\n";
			$this->resprints .= '  var htmlVal = '.$jsHtml.';'."\n";
			$this->resprints .= '  setTimeout(function() {'."\n";
			$this->resprints .= '    var el = document.getElementById("options_factures");'."\n";
			$this->resprints .= '    if (!el) el = document.querySelector("[name=\'options_factures\']");'."\n";
			$this->resprints .= '    if (el) el.value = htmlVal;'."\n";
			$this->resprints .= '    if (typeof CKEDITOR !== "undefined" && CKEDITOR.instances) {'."\n";
			$this->resprints .= '      var inst = CKEDITOR.instances["options_factures"];'."\n";
			$this->resprints .= '      if (inst) { inst.setData(htmlVal); return; }'."\n";
			$this->resprints .= '      CKEDITOR.on("instanceReady", function(evt) {'."\n";
			$this->resprints .= '        if (evt.editor.name === "options_factures") evt.editor.setData(htmlVal);'."\n";
			$this->resprints .= '      });'."\n";
			$this->resprints .= '    }'."\n";
			$this->resprints .= '  }, 500);'."\n";
			$this->resprints .= '});'."\n";
			$this->resprints .= '</script>'."\n";
		}

		return 0;
	}
}
