<?php
/**
 * Classe de hooks du module DiamantUtils
 *
 * - stockproductcard (onglet Stock de la fiche produit) : bouton « Transformer »
 *   pour les produits gérés en lots.
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

	/**
	 * Bouton « Transformer » sur l'onglet Stock de la fiche produit.
	 * product/stock/product.php n'affiche pas $hookmanager->resPrint pour ce hook :
	 * le bouton est donc imprimé directement, dans son propre bloc tabsAction.
	 *
	 * @param	array		$parameters		Paramètres du hook
	 * @param	Product		$object			Produit
	 * @param	string		$action			Action courante
	 * @param	HookManager	$hookmanager	Gestionnaire de hooks
	 * @return	int							0 pour garder les boutons standard
	 */
	public function addMoreActionsButtons($parameters, &$object, &$action, $hookmanager)
	{
		global $langs, $user;

		$contexts = explode(':', $parameters['context']);
		if (!in_array('stockproductcard', $contexts)) {
			return 0;
		}
		if (!empty($action) || empty($object->id) || !isModEnabled('diamantutils') || !isModEnabled('productbatch')) {
			return 0;
		}
		if (!method_exists($object, 'hasbatch') || !$object->hasbatch()) {
			return 0;
		}
		if (!$user->hasRight('diamantutils', 'transformation', 'write')) {
			return 0;
		}

		$langs->load('diamantutils@diamantutils');

		$url = dol_buildpath('/diamantutils/transformation_card.php', 1).'?action=create&fk_product='.((int) $object->id);
		print '<div class="tabsAction">';
		print dolGetButtonAction('', $langs->trans('DiamantutilsTransform'), 'default', $url, 'diamantutils-transform');
		print '</div>';

		return 0;
	}
}
