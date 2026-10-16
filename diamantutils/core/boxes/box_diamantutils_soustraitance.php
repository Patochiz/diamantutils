<?php
/**
 * Widget « Sous-traitance en cours » : nombre de lots non nuls de l'entrepôt Sous-traitance
 */

include_once DOL_DOCUMENT_ROOT.'/core/boxes/modules_boxes.php';

class box_diamantutils_soustraitance extends ModeleBoxes
{
	public $boxcode = 'diamantutilssoustraitance';

	public $boximg = 'object_stock';

	public $boxlabel = 'DiamantutilsSTBoxLabel';

	public $depends = array('diamantutils');

	/**
	 * @param	DoliDB	$db		Base de données
	 * @param	string	$param	Paramètres
	 */
	public function __construct($db, $param = '')
	{
		global $user;

		parent::__construct($db, $param);
		$this->hidden = !$user->hasRight('diamantutils', 'soustraitance', 'read');
	}

	/**
	 * @param	int		$max	Nombre maximum de lignes
	 * @return	void
	 */
	public function loadBox($max = 5)
	{
		global $langs;

		$langs->load('diamantutils@diamantutils');
		dol_include_once('/diamantutils/lib/soustraitance.lib.php');
		dol_include_once('/diamantutils/class/soustraitance.class.php');

		$url = dol_buildpath('/diamantutils/soustraitance_list.php', 1);
		$this->info_box_head = array(
			'text' => $langs->trans('DiamantutilsSTListTitle'),
			'sublink' => $url,
			'subtext' => $langs->trans('DiamantutilsSTListTitle'),
			'subpicto' => 'filter.png',
		);

		if (!diamantutils_st_enabled()) {
			$this->info_box_contents[0][0] = array('td' => 'class="nohover left"', 'text' => '<span class="opacitymedium">'.$langs->trans('DiamantutilsSoustraitanceInactive').'</span>');
			return;
		}

		$st = new DiamantutilsSoustraitance($this->db);
		$lines = $st->getInProgressLots();
		if (!is_array($lines)) {
			$this->info_box_contents[0][0] = array('td' => '', 'maxlength' => 500, 'text' => dol_escape_htmltag($st->error));
			return;
		}

		$lots = array();
		$nbanomalies = 0;
		foreach ($lines as $line) {
			$lots[$line['batch']] = 1;
			if (!empty($line['anomalies'])) {
				$nbanomalies++;
			}
		}

		$this->info_box_contents[0][0] = array(
			'td' => 'class="left"',
			'text' => '<a href="'.$url.'">'.$langs->trans('DiamantutilsSTBoxNbLots', count($lots), count($lines)).'</a>',
			'asis' => 1,
		);
		if ($nbanomalies) {
			$this->info_box_contents[1][0] = array(
				'td' => 'class="left"',
				'text' => '<a href="'.$url.'?search_anomalies=1">'.img_warning('', 'class="pictofixedwidth"').'<span class="warning">'.$langs->trans('DiamantutilsSTListNbAnomalies', $nbanomalies).'</span></a>',
				'asis' => 1,
			);
		}
	}

	/**
	 * @param	array	$head		Entête
	 * @param	array	$contents	Contenu
	 * @param	int		$nooutput	Pas d'affichage
	 * @return	string
	 */
	public function showBox($head = null, $contents = null, $nooutput = 0)
	{
		return parent::showBox($this->info_box_head, $this->info_box_contents, $nooutput);
	}
}
