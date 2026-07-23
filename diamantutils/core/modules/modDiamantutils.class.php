<?php
/**
 * Descripteur du module DiamantUtils
 * Module custom Diamant Industrie — fonctionnalités internes regroupées et activables individuellement
 */

require_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modDiamantutils extends DolibarrModules
{
	public function __construct($db)
	{
		global $langs, $conf;
		$this->db = $db;

		$this->numero = 510125;
		$this->rights_class = 'diamantutils';
		$this->family = 'custom';
		$this->module_position = '90';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = "Module interne Diamant Industrie : fonctionnalités diverses regroupées et activables individuellement";
		$this->descriptionlong = "Regroupe les développements internes Diamant Industrie (ex. contrôle de facturation multi-commandes) sous un seul module avec options activables.";
		$this->editor_name = 'Diamant Industrie';
		$this->version = '1.1';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'generic';

		$this->module_parts = array(
			'triggers' => 1,
			'hooks' => array('invoicecard'),
		);

		$this->dirs = array();

		$this->const = array(
			0 => array(
				'DIAMANTUTILS_INVOICE_CHECK_MODE',
				'chaine',
				'AFFICHER',
				'Mode de contrôle du déjà-facturé lors de la création de facture depuis commande(s) : MASQUER, AFFICHER, AFFICHER_BLOQUER, DESACTIVE',
				0,
				'current',
				1
			),
		);

		$this->config_page_url = array('setup.php@diamantutils');

		$this->langfiles = array('diamantutils@diamantutils');

		$this->rights = array();

		$this->menu = array();
	}

	public function init($options = '')
	{
		return $this->_init(array(), $options);
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
