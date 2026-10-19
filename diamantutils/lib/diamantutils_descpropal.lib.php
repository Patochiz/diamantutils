<?php
/**
 * Rendu de la description pour devis (desc_propal) : modèle → texte de la ligne
 *
 * Fonction publique : diamantutils_render_desc($template, $attributes, $product).
 * Indépendante des propositions commerciales : réutilisable pour les commandes, factures…
 *
 * Placeholders (insensibles à la casse) :
 *  - {REF_ATTRIBUT}        libellé de la valeur de l'attribut (product_attribute_value.value) ;
 *  - {REF_ATTRIBUT:ref}    référence de la valeur (product_attribute_value.ref) ;
 *  - {REF_ATTRIBUT:label}  libellé de l'attribut (product_attribute.label) ;
 *  - {PRODUCT_REF}, {PRODUCT_LABEL}  réf. et libellé du produit (la variante).
 * Placeholder inconnu ou attribut absent : laissé tel quel, avertissement dans le log.
 *
 * Modèle HTML : les valeurs injectées sont échappées (dol_escape_htmltag). Modèle texte : elles
 * sont injectées telles quelles, Dolibarr échappe le texte brut à l'affichage et dans le PDF
 * (l'échapper ici ferait passer le texte pour du HTML et perdre ses retours ligne).
 */

/**
 * Attributs de variante d'un produit (vide si ce n'est pas une variante)
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_product		Id du produit (variante)
 * @return	array|int				Tableau REF_ATTRIBUT (majuscules) => array('ref', 'label', 'value', 'value_ref'), -1 si erreur
 */
function diamantutils_descpropal_variant_attributes($db, $fk_product)
{
	$sql = "SELECT a.ref as attr_ref, a.label as attr_label, v.ref as value_ref, v.value as value";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination as c";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_combination2val as c2v ON c2v.fk_prod_combination = c.rowid";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute as a ON a.rowid = c2v.fk_prod_attr";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_value as v ON v.rowid = c2v.fk_prod_attr_val";
	$sql .= " WHERE c.fk_product_child = ".((int) $fk_product);
	$sql .= " AND c.entity IN (".getEntity('product').")";
	$sql .= " AND a.entity IN (".getEntity('product').")";
	$sql .= " ORDER BY a.position, a.rowid";

	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return -1;
	}
	$attributes = array();
	while ($obj = $db->fetch_object($resql)) {
		$attributes[diamantutils_descpropal_key($obj->attr_ref)] = array(
			'ref' => (string) $obj->attr_ref,
			'label' => (string) $obj->attr_label,
			'value' => (string) $obj->value,
			'value_ref' => (string) $obj->value_ref,
		);
	}
	$db->free($resql);
	return $attributes;
}

/**
 * Attributs utilisés par les variantes d'un produit parent, avec leurs valeurs (aide à la saisie)
 *
 * @param	DoliDB	$db				Base de données
 * @param	int		$fk_parent		Id du produit parent
 * @return	array					REF_ATTRIBUT => array('ref', 'label', 'values' => array(value_ref => value))
 */
function diamantutils_descpropal_parent_attributes($db, $fk_parent)
{
	$sql = "SELECT DISTINCT a.rowid, a.position, a.ref as attr_ref, a.label as attr_label, v.ref as value_ref, v.value as value, v.position as vposition";
	$sql .= " FROM ".MAIN_DB_PREFIX."product_attribute_combination as c";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_combination2val as c2v ON c2v.fk_prod_combination = c.rowid";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute as a ON a.rowid = c2v.fk_prod_attr";
	$sql .= " INNER JOIN ".MAIN_DB_PREFIX."product_attribute_value as v ON v.rowid = c2v.fk_prod_attr_val";
	$sql .= " WHERE c.fk_product_parent = ".((int) $fk_parent);
	$sql .= " AND c.entity IN (".getEntity('product').")";
	$sql .= " AND a.entity IN (".getEntity('product').")";
	$sql .= " ORDER BY a.position, a.rowid, v.position, v.ref";

	$attributes = array();
	$resql = $db->query($sql);
	if (!$resql) {
		dol_syslog(__FUNCTION__.' '.$db->lasterror(), LOG_ERR);
		return $attributes;
	}
	while ($obj = $db->fetch_object($resql)) {
		$key = diamantutils_descpropal_key($obj->attr_ref);
		if (!isset($attributes[$key])) {
			$attributes[$key] = array('ref' => (string) $obj->attr_ref, 'label' => (string) $obj->attr_label, 'values' => array());
		}
		$attributes[$key]['values'][(string) $obj->value_ref] = (string) $obj->value;
	}
	$db->free($resql);
	return $attributes;
}

/**
 * Clé de comparaison d'une référence d'attribut : majuscules, sans espaces autour
 *
 * @param	string	$ref	Référence
 * @return	string
 */
function diamantutils_descpropal_key($ref)
{
	return function_exists('mb_strtoupper') ? mb_strtoupper(trim((string) $ref), 'UTF-8') : strtoupper(trim((string) $ref));
}

/**
 * Texte d'une balise {…} : HTML de l'éditeur retiré, entités décodées, espaces normalisés
 *
 * @param	string	$content	Contenu entre les accolades
 * @return	string
 */
function diamantutils_descpropal_tag_text($content)
{
	$text = dol_html_entity_decode(strip_tags((string) $content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	$text = str_replace("\xC2\xA0", ' ', $text);
	return trim(preg_replace('/\s+/u', ' ', $text));
}

/**
 * Substitue les placeholders d'un texte
 *
 * @param	string		$text			Texte
 * @param	array		$attributes		Attributs (diamantutils_descpropal_variant_attributes())
 * @param	object|null	$product		Produit (ref, label) pour {PRODUCT_REF} / {PRODUCT_LABEL}
 * @param	bool		$html			Texte HTML : valeurs échappées
 * @param	string[]	$warnings		Avertissements (sortie, ajoutés)
 * @return	string
 */
function diamantutils_descpropal_substitute($text, $attributes, $product, $html, &$warnings)
{
	return preg_replace_callback('/\{([^{}]*)\}/u', function ($m) use ($attributes, $product, $html, &$warnings) {
		$tag = diamantutils_descpropal_tag_text($m[1]);
		if (!preg_match('/^([^\s:]+)\s*(?::\s*(ref|label))?$/iu', $tag, $reg)) {
			$warnings[] = 'Placeholder non reconnu : '.$m[0];
			return $m[0];
		}
		$key = diamantutils_descpropal_key($reg[1]);
		$suffix = strtolower($reg[2] ?? '');

		$value = null;
		if ($suffix === '' && $key === 'PRODUCT_REF' && is_object($product)) {
			$value = (string) $product->ref;
		} elseif ($suffix === '' && $key === 'PRODUCT_LABEL' && is_object($product)) {
			$value = (string) $product->label;
		} elseif (isset($attributes[$key])) {
			$value = ($suffix === 'ref' ? $attributes[$key]['value_ref'] : ($suffix === 'label' ? $attributes[$key]['label'] : $attributes[$key]['value']));
		}
		if ($value === null) {
			$warnings[] = 'Placeholder inconnu ou attribut absent de la variante : '.$m[0];
			return $m[0];
		}
		// Seuls & < > " sont échappés (accents et retours ligne de la valeur conservés)
		return ($html ? dol_escape_htmltag($value, 1, 1, '', 1) : $value);
	}, (string) $text);
}

/**
 * Rendu d'un modèle de description pour devis
 *
 * @param	string			$template		Modèle (texte ou HTML)
 * @param	array			$attributes		Attributs de la variante (diamantutils_descpropal_variant_attributes())
 * @param	object|null		$product		Produit de la ligne (ref, label)
 * @param	string[]|null	$warnings		Avertissements (sortie), également écrits dans le log
 * @return	string							Description rendue
 */
function diamantutils_render_desc($template, $attributes, $product, &$warnings = null)
{
	$warnings = array();
	$template = (string) $template;
	if (strpos($template, '{') === false) {
		return $template;
	}
	if (!is_array($attributes)) {
		$attributes = array();
	}

	$result = diamantutils_descpropal_substitute($template, $attributes, $product, dol_textishtml($template), $warnings);

	$ref = (is_object($product) && !empty($product->ref) ? ' produit '.$product->ref : '');
	foreach ($warnings as $warning) {
		dol_syslog('diamantutils_render_desc'.$ref.' : '.$warning, LOG_WARNING);
	}
	return $result;
}
