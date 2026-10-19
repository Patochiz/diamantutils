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
		$this->description = "Module interne Diamant Industrie : ordres de transformation de stock par lots (menu GPAO), commandes sous-traitées livrées directement au client, listes personnalisées SQL (menu Outils) et description pour devis sur la fiche produit";
		$this->descriptionlong = "Transformation de stock par lots : consommation de N lots et production de M lots (découpe de profilés, peinture, changement d'unité), avec saisie en pièces × longueur, contrôle d'équilibre, gestion des restes et des pertes, calcul du coût et traçabilité des mouvements de stock. Commandes sous-traitées : réception sur l'entrepôt Sous-traitance avec lot = ref de la commande fournisseur, BL client prélevé sur ce lot. Listes personnalisées : requêtes SQL en lecture seule enregistrées par un administrateur, consultables avec recherche, tri, pagination et export CSV. Description pour devis : texte saisi sur la fiche produit, repris sur les lignes de propositions commerciales à la place de la description standard.";
		$this->editor_name = 'Diamant Industrie';
		$this->version = '2.5';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'generic';

		// Format plat (le format imbriqué data/entity casse l'enregistrement en v20)
		$this->module_parts = array(
			'hooks' => array('ordersupplierdispatch', 'receptioncard', 'ordershipmentcard', 'expeditioncard', 'productcard'),
			'triggers' => 1,
		);

		$this->dirs = array();

		// Widget d'accueil « Sous-traitance en cours »
		$this->boxes = array(
			0 => array('file' => 'box_diamantutils_soustraitance.php@diamantutils', 'note' => '', 'enabledbydefaulton' => 'Home'),
		);

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
			1 => array(
				'DIAMANTUTILS_SOUSTRAITANCE_ENABLED',
				'chaine',
				'0',
				'Gestion des commandes sous-traitées (livraison directe client) : 1 = active',
				0,
				'current',
				0
			),
			2 => array(
				'DIAMANTUTILS_TRANSFO_ENABLED',
				'chaine',
				'1',
				'Ordres de transformation de stock : 1 = active',
				0,
				'current',
				0
			),
			3 => array(
				'DIAMANTUTILS_CUSTOMLIST_ENABLED',
				'chaine',
				'1',
				'Listes personnalisées (menu Outils) : 1 = active',
				0,
				'current',
				0
			),
			4 => array(
				'DIAMANTUTILS_CUSTOMLIST_EXPORT_MAX',
				'chaine',
				'50000',
				'Listes personnalisées : nombre maximum de lignes d\'un export CSV',
				0,
				'current',
				0
			),
			5 => array(
				'DIAMANTUTILS_DESC_PROPAL',
				'chaine',
				'0',
				'Description pour devis reprise sur les lignes de propositions commerciales : 1 = active',
				0,
				'current',
				0
			),
			6 => array(
				'DIAMANTUTILS_DESC_PROPAL_MODE',
				'chaine',
				'REPLACE',
				'Description pour devis : REPLACE = remplace la description de la ligne, PREPEND = placée avant',
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
		$this->rights[$r][0] = $this->numero.'04';
		$this->rights[$r][1] = 'Consulter les commandes sous-traitées en cours';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'soustraitance';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'05';
		$this->rights[$r][1] = 'Consulter les listes personnalisées';
		$this->rights[$r][2] = 'r';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'customlist';
		$this->rights[$r][5] = 'read';
		$r++;
		$this->rights[$r][0] = $this->numero.'06';
		$this->rights[$r][1] = 'Gérer les listes personnalisées (écriture du SQL, administrateurs seulement)';
		$this->rights[$r][2] = 'w';
		$this->rights[$r][3] = 0;
		$this->rights[$r][4] = 'customlist';
		$this->rights[$r][5] = 'write';
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
			'enabled' => 'isModEnabled("diamantutils") && getDolGlobalString("DIAMANTUTILS_TRANSFO_ENABLED", "1")',
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
			'enabled' => 'isModEnabled("diamantutils") && getDolGlobalString("DIAMANTUTILS_TRANSFO_ENABLED", "1")',
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
			'enabled' => 'isModEnabled("diamantutils") && getDolGlobalString("DIAMANTUTILS_TRANSFO_ENABLED", "1")',
			'perms' => '$user->hasRight("diamantutils", "transformation", "read")',
			'target' => '',
			'user' => 2,
		);
		// Menu gauche Produits/Stocks (eldy : mainmenu=products, leftmenu=stock)
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=products,fk_leftmenu=stock',
			'type' => 'left',
			'titre' => 'DiamantutilsSTListTitle',
			'mainmenu' => 'products',
			'leftmenu' => 'diamantutils_st',
			'url' => '/diamantutils/soustraitance_list.php?mainmenu=products&leftmenu=stock',
			'langs' => 'diamantutils@diamantutils',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("diamantutils") && getDolGlobalInt("DIAMANTUTILS_SOUSTRAITANCE_ENABLED")',
			'perms' => '$user->hasRight("diamantutils", "soustraitance", "read")',
			'target' => '',
			'user' => 2,
		);
		// Menu gauche Outils (eldy : mainmenu=tools) : listes personnalisées
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=tools',
			'type' => 'left',
			'titre' => 'DiamantutilsCustomLists',
			'prefix' => img_picto('', 'list', 'class="paddingright pictofixedwidth"'),
			'mainmenu' => 'tools',
			'leftmenu' => 'diamantutils_cl',
			'url' => '/diamantutils/customlist_index.php?mainmenu=tools&leftmenu=diamantutils_cl',
			'langs' => 'diamantutils@diamantutils',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("diamantutils") && getDolGlobalString("DIAMANTUTILS_CUSTOMLIST_ENABLED", "1")',
			'perms' => '$user->hasRight("diamantutils", "customlist", "read")',
			'target' => '',
			'user' => 2,
		);
		$this->menu[$r++] = array(
			'fk_menu' => 'fk_mainmenu=tools,fk_leftmenu=diamantutils_cl',
			'type' => 'left',
			'titre' => 'DiamantutilsNewCustomList',
			'mainmenu' => 'tools',
			'leftmenu' => 'diamantutils_cl_new',
			'url' => '/diamantutils/customlist_card.php?action=create&mainmenu=tools&leftmenu=diamantutils_cl',
			'langs' => 'diamantutils@diamantutils',
			'position' => 1000 + $r,
			'enabled' => 'isModEnabled("diamantutils") && getDolGlobalString("DIAMANTUTILS_CUSTOMLIST_ENABLED", "1")',
			'perms' => '$user->admin && $user->hasRight("diamantutils", "customlist", "write")',
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

		// Table créée en v2.0 : colonnes ajoutées en v2.1 (erreur « colonne existante » ignorée)
		foreach (array('date_consume datetime NULL', 'fk_user_consume integer NULL') as $column) {
			$this->db->query("ALTER TABLE ".MAIN_DB_PREFIX."diamantutils_transfo ADD COLUMN ".$column);
		}

		// Option produit « Lot = longueur » : active la saisie pièces × longueur
		require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';
		$extrafields = new ExtraFields($this->db);
		$extrafields->fetch_name_optionals_label('product');
		if (empty($extrafields->attributes['product']['label']['diamantutils_lotlongueur'])) {
			$result = $extrafields->addExtraField('diamantutils_lotlongueur', 'DiamantutilsLotLongueur', 'boolean', 1000, '', 'product', 0, 0, '', '', 1, '', '1', 'DiamantutilsLotLongueurHelp', '', '', 'diamantutils@diamantutils', 'isModEnabled("diamantutils")');
			if ($result < 0) {
				$this->error = $extrafields->error;
				return -1;
			}
		}

		// Commandes sous-traitées : extrafield sur commande fournisseur et entrepôt Sous-traitance
		dol_include_once('/diamantutils/lib/soustraitance.lib.php');
		$error = '';
		if (diamantutils_st_install_extrafield($this->db, $error) < 0) {
			$this->error = $error;
			return -1;
		}
		global $user;
		if (diamantutils_st_install_warehouse($this->db, $user, $error) < 0) {
			$this->error = $error;
			return -1;
		}

		// Description pour devis : extrafield produit desc_propal
		dol_include_once('/diamantutils/lib/descpropal.lib.php');
		if (diamantutils_descpropal_install_extrafield($this->db, $error) < 0) {
			$this->error = $error;
			return -1;
		}

		return $this->_init(array(), $options);
	}

	public function remove($options = '')
	{
		// Les extrafields diam_livraison_directe et desc_propal et l'entrepôt Sous-traitance
		// portent des données : conservés
		return $this->_remove(array(), $options);
	}
}
