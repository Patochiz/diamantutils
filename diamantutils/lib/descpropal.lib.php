<?php
/**
 * Description spécifique aux propositions commerciales
 *
 * Extrafield produit desc_propal (« Description pour devis »). Quand un produit est ajouté
 * à une proposition commerciale, la ligne reprend cette description si elle est renseignée
 * (trigger LINEPROPAL_INSERT), selon le mode :
 *  - REPLACE : la description de la ligne est remplacée ;
 *  - PREPEND : desc_propal est placée avant la description de la ligne.
 *
 * La ligne n'est modifiée que si sa description est vide ou identique à la description
 * standard du produit : une saisie à la main, une ligne clonée ou reprise d'un autre objet
 * est conservée.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/extrafields.class.php';

define('DIAMANTUTILS_DESCPROPAL_EXTRAFIELD', 'desc_propal');

/**
 * Fonctionnalité « Description pour devis » active ?
 *
 * @return	bool
 */
function diamantutils_descpropal_enabled()
{
	return isModEnabled('diamantutils') && getDolGlobalInt('DIAMANTUTILS_DESC_PROPAL') > 0;
}

/**
 * Mode d'application : REPLACE (défaut) ou PREPEND
 *
 * @return	string
 */
function diamantutils_descpropal_mode()
{
	return (getDolGlobalString('DIAMANTUTILS_DESC_PROPAL_MODE', 'REPLACE') == 'PREPEND' ? 'PREPEND' : 'REPLACE');
}

/**
 * L'extrafield desc_propal existe-t-il sur les produits ?
 *
 * @param	DoliDB	$db		Base de données
 * @return	bool
 */
function diamantutils_descpropal_extrafield_exists($db)
{
	$extrafields = new ExtraFields($db);
	$extrafields->fetch_name_optionals_label('product', true);
	return !empty($extrafields->attributes['product']['label'][DIAMANTUTILS_DESCPROPAL_EXTRAFIELD]);
}

/**
 * Crée l'extrafield produit desc_propal s'il n'existe pas (idempotent)
 *
 * @param	DoliDB	$db		Base de données
 * @param	string	$error	Message d'erreur (sortie)
 * @return	int				0 = déjà présent, 1 = créé, -1 = erreur
 */
function diamantutils_descpropal_install_extrafield($db, &$error)
{
	if (diamantutils_descpropal_extrafield_exists($db)) {
		return 0;
	}

	$extrafields = new ExtraFields($db);
	$result = $extrafields->addExtraField(
		DIAMANTUTILS_DESCPROPAL_EXTRAFIELD,	// code
		'DiamantutilsDescPropal',			// libellé (clé de traduction)
		'html',
		1010,								// position
		'',									// taille
		'product',							// produits et services
		0,									// unique
		0,									// obligatoire
		'',									// valeur par défaut
		'',									// paramètres
		1,									// alwayseditable
		'',									// perms
		'3',								// visible sur la fiche seulement, pas dans les listes
		'DiamantutilsDescPropalHelp',
		'',									// computed
		'',									// entity
		'diamantutils@diamantutils',
		'isModEnabled("diamantutils")',
		0,									// totalizable
		0									// printable : la description est reprise sur la ligne, pas en extrafield
	);
	if ($result < 0) {
		$error = $extrafields->error;
		return -1;
	}
	return 1;
}

/**
 * Normalise une description pour comparaison : retours ligne, HTML basique, espaces
 *
 * @param	string	$text	Description (texte ou HTML)
 * @return	string			Texte brut normalisé
 */
function diamantutils_descpropal_normalize($text)
{
	$text = str_replace(array("\r\n", "\r"), "\n", (string) $text);
	if (dol_textishtml($text)) {
		// Les retours ligne bruts ne comptent pas en HTML : seuls les <br> et fins de blocs
		$text = str_replace("\n", ' ', $text);
		$text = preg_replace('/<br\s*\/?>/i', "\n", $text);
		$text = preg_replace('/<\/(p|div|li|tr|h[1-6])>/i', "\n", $text);
		$text = strip_tags($text);
	}
	$text = dol_html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = str_replace("\xC2\xA0", ' ', $text);	// espace insécable
	$lines = array();
	foreach (explode("\n", $text) as $line) {
		$lines[] = trim(preg_replace('/[ \t]+/', ' ', $line));
	}
	// Lignes vides multiples réduites (paragraphes et <br> successifs)
	return trim(preg_replace('/\n{2,}/', "\n", implode("\n", $lines)));
}

/**
 * La ligne vient-elle d'une copie (clonage, création depuis un autre objet, import de lignes) ?
 *
 * - Propal::create() avec des lignes (clonage, API) et l'import de lignes d'un objet lié
 *   passent origin / origin_id à addline() ;
 * - le clonage pose aussi le contexte createfromclone ;
 * - comm/propal/card.php, création depuis un autre objet (action add + origin) : les lignes
 *   sont ajoutées sans origin, on lit donc la requête.
 *
 * @param	PropaleLigne	$line	Ligne insérée
 * @return	bool
 */
function diamantutils_descpropal_is_copy($line)
{
	if (!empty($line->origin) && !empty($line->origin_id)) {
		return true;
	}
	if (!empty($line->context['createfromclone'])) {
		return true;
	}
	if (GETPOST('action', 'aZ09') == 'add' && GETPOST('origin', 'alphanohtml') != '' && GETPOSTINT('originid') > 0) {
		return true;
	}
	return false;
}

/**
 * Applique la description pour devis à une ligne de proposition commerciale venant d'être insérée
 *
 * @param	DoliDB			$db		Base de données
 * @param	PropaleLigne	$line	Ligne insérée (trigger LINEPROPAL_INSERT)
 * @param	string			$error	Message d'erreur (sortie)
 * @return	int						1 = ligne modifiée, 0 = rien fait, -1 = erreur
 */
function diamantutils_descpropal_apply($db, $line, &$error)
{
	$error = '';

	if (!diamantutils_descpropal_enabled()) {
		return 0;
	}
	// Ligne libre, ligne spéciale (titre, sous-total) ou ligne non enregistrée
	if (empty($line->fk_product) || (int) $line->fk_product <= 0 || (isset($line->product_type) && $line->product_type == 9)) {
		return 0;
	}
	$lineid = (int) (!empty($line->id) ? $line->id : $line->rowid);
	if ($lineid <= 0) {
		return 0;
	}
	if (diamantutils_descpropal_is_copy($line)) {
		return 0;
	}

	require_once DOL_DOCUMENT_ROOT.'/product/class/product.class.php';
	$product = new Product($db);
	// Prix non chargés (inutiles) ; traductions chargées pour comparer aux descriptions traduites
	if ($product->fetch((int) $line->fk_product, '', '', '', 0, 1) <= 0) {
		$error = 'DiamantUtils desc_propal : produit '.((int) $line->fk_product).' introuvable ('.$product->error.')';
		return -1;
	}
	if ($product->fetch_optionals() < 0) {
		$error = 'DiamantUtils desc_propal : lecture des attributs du produit '.$product->ref.' impossible ('.$product->error.')';
		return -1;
	}

	$descpropal = (string) ($product->array_options['options_'.DIAMANTUTILS_DESCPROPAL_EXTRAFIELD] ?? '');
	if (diamantutils_descpropal_normalize($descpropal) === '') {
		return 0;
	}

	// Descriptions standard du produit : description principale et traductions
	$standards = array((string) $product->description);
	if (!empty($product->multilangs) && is_array($product->multilangs)) {
		foreach ($product->multilangs as $translation) {
			if (!empty($translation['description'])) {
				$standards[] = (string) $translation['description'];
			}
		}
	}

	$linedesc = (string) $line->desc;
	$normline = diamantutils_descpropal_normalize($linedesc);

	// Mention « (Code douane : … - Pays d'origine : …) » ajoutée par la fiche propale : conservée
	$suffix = '';
	$base = $linedesc;	// description conservée derrière desc_propal en mode PREPEND
	$match = ($normline === '');
	foreach ($standards as $standard) {
		if ($match) {
			break;
		}
		$normstd = diamantutils_descpropal_normalize($standard);
		if ($normline === $normstd) {
			$match = true;
		} elseif ($normstd !== '' && $normline === $normstd."\n".$normstd) {
			// Description pré-remplie dans le formulaire, non modifiée mais reformatée par
			// l'éditeur HTML : comm/propal/card.php l'a concaténée à la description standard
			$match = true;
			$base = $standard;
		} elseif ((!empty($product->customcode) || !empty($product->country_code)) && ($normstd === '' || strpos($normline, $normstd."\n") === 0)) {
			$rest = trim($normstd === '' ? $normline : substr($normline, strlen($normstd) + 1));
			if (preg_match('/^\([^\n]*\)$/', $rest)) {
				$match = true;
				$suffix = $rest;
			}
		}
	}
	if (!$match) {
		return 0;	// description saisie ou modifiée à la main : conservée
	}

	if (diamantutils_descpropal_mode() == 'PREPEND' && $normline !== '') {
		$newdesc = dol_concatdesc($descpropal, $base);
	} else {
		$newdesc = ($suffix !== '' ? dol_concatdesc($descpropal, $suffix) : $descpropal);
	}

	// Seule la description est réécrite : PropaleLigne::update() réécrirait toute la ligne
	// (prix, champs obsolètes price/remise, extrafields de ligne) pour un seul champ modifié.
	$sql = "UPDATE ".MAIN_DB_PREFIX."propaldet SET description = '".$db->escape($newdesc)."'";
	$sql .= " WHERE rowid = ".$lineid;
	if (!$db->query($sql)) {
		$error = 'DiamantUtils desc_propal : '.$db->lasterror();
		return -1;
	}

	$line->desc = $newdesc;
	$line->description = $newdesc;
	return 1;
}
