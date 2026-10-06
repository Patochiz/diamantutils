<?php
/**
 * Descripteur du module DiamantUtils
 * Module custom Diamant Industrie — fonctionnalités internes regroupées
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
		$this->description = "Module interne Diamant Industrie : transformation de stock par lots (découpe, peinture, changement d'unité)";
		$this->descriptionlong = "Transformation de stock par lots : consommation de N lots et production de M lots (découpe de profilés, peinture, changement d'unité), avec saisie en pièces × longueur, contrôle d'équilibre, gestion des restes et des pertes, calcul du coût et traçabilité des mouvements de stock.";
		$this->editor_name = 'Diamant Industrie';
		$this->version = '2.0';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'generic';

		$this->module_parts = array(
			'hooks' => array(),
		);

		$this->dirs = array();

		$this->const = array();

		$this->config_page_url = array('setup.php@diamantutils');

		$this->langfiles = array('diamantutils@diamantutils');

		$this->rights = array();

		$this->menu = array();
	}

	public function init($options = '')
	{
		require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';

		// Purge de l'ancienne constante du contrôle de facturation (fonction abandonnée en v2.0)
		$sql = "DELETE FROM ".MAIN_DB_PREFIX."const WHERE name = '".$this->db->escape('DIAMANTUTILS_INVOICE_CHECK_MODE')."'";
		$this->db->query($sql);

		// Création des tables de transformation (fichiers sql/ du module)
		$result = $this->_load_tables('/diamantutils/sql/');
		if ($result < 0) {
			return -1;
		}

		return $this->_init(array(), $options);
	}

	public function remove($options = '')
	{
		return $this->_remove(array(), $options);
	}
}
