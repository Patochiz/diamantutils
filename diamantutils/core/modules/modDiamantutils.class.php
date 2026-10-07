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

		$this->const = array(
			0 => array(
				'DIAMANTUTILS_TRANSFO_LOT_FORMAT',
				'chaine',
				'Longueur %dmm',
				'Format du nom de lot généré par une transformation (sprintf avec la longueur en mm)',
				0,
				'current',
				0
			),
		);

		// Le menu GPAO n'est affiché que si le module MRP (ou BOM) est actif
		$this->depends = array('modStock', 'modMrp');

		$this->config_page_url = array('setup.php@diamantutils');

		$this->langfiles = array('diamantutils@diamantutils');

		// Droits
		$this->rights = array();
		$r = 0;
		$this->rights[$r][0] = $this->numero.'01';
		$this->rights[$r][1] = 'Lire les transformations de stock';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'transformation';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'02';
		$this->rights[$r][1] = 'Créer et valider les transformations de stock';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'transformation';
		$this->rights[$r][5] = 'write';
		$r++;
		$this->rights[$r][0] = $this->numero.'03';
		$this->rights[$r][1] = 'Annuler une transformation de stock validée';
		$this->rights[$r][2] = 'd';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'transformation';
		$this->rights[$r][5] = 'cancel';
		$r++;

		// Menus gauche dans le menu haut GPAO (eldy : mainmenu=mrp)
		$this->menu = array();
		$r = 0;
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=mrp',
			'type' => 'left',
			'titre' => 'DiamantutilsTransformations',
			'prefix' => img_picto('', 'stock', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'mrp',
			'leftmenu' => 'diamantutils_ot',
			'url' => '/diamantutils/transformation_list.php?mainmenu=mrp&leftmenu=diamantutils_ot',
			'langs' => 'diamantutils@diamantutils',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("diamantutils")',
			'perms' => '$user->hasRight("diamantutils", "transformation", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=mrp,fk_leftmenu=diamantutils_ot',
			'type' => 'left',
			'titre' => 'DiamantutilsNewTransformation',
			'mainmenu' => 'mrp',
			'leftmenu' => 'diamantutils_ot_new',
			'url' => '/diamantutils/transformation_card.php?action=create&mainmenu=mrp&leftmenu=diamantutils_ot',
			'langs' => 'diamantutils@diamantutils',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("diamantutils")',
			'perms' => '$user->hasRight("diamantutils", "transformation", "write")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=mrp,fk_leftmenu=diamantutils_ot',
			'type' => 'left',
			'titre' => 'List',
			'mainmenu' => 'mrp',
			'leftmenu' => 'diamantutils_ot_list',
			'url' => '/diamantutils/transformation_list.php?mainmenu=mrp&leftmenu=diamantutils_ot',
			'langs' => 'diamantutils@diamantutils',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("diamantutils")',
			'perms' => '$user->hasRight("diamantutils", "transformation", "read")',
			'target' => '',
			'user' => 2,
		);
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
