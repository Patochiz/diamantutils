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
 * Calculs : {=EXPRESSION} ou {=EXPRESSION ;N}
 *   ex. {=MODULE:ref-5}, {=(LARGEUR-5)*2}, {=MODULE:ref/2 ;2}
 * Opérateurs + - * / et parenthèses (priorités usuelles, moins unaire), nombres à virgule ou
 * point décimal, attributs REF_ATTRIBUT (valeur) ou REF_ATTRIBUT:ref (référence de la valeur),
 * qui doivent contenir un nombre. Les noms d'attributs peuvent contenir % _ Ø… mais pas d'opérateur.
 * Résultat : avec ;N, arrondi à exactement N décimales (300,00) ; sans, au plus 2 décimales sans
 * zéros inutiles (595, 297,5). Virgule décimale, pas de séparateur de milliers.
 * Erreur (valeur non numérique, attribut absent, division par zéro, syntaxe) : balise laissée
 * telle quelle, avertissement dans le log.
 *
 * Blocs conditionnels (mots-clés insensibles à la casse, imbrication autorisée) :
 *   {IF LARGEUR=200}…{ELSEIF LARGEUR IN 300,400}…{ELSE}…{ENDIF}
 * Conditions : ATTR=v, ATTR!=v, ATTR>=v, ATTR<=v, ATTR>v, ATTR<v, ATTR IN v1,v2, ATTR seul
 * (présent et non vide), combinées par AND (prioritaire) et OR, sans parenthèses. Suffixe :ref
 * pour comparer la référence de la valeur. Comparaison numérique si les deux opérandes sont des
 * nombres (virgule décimale acceptée), sinon chaînes sans casse ni espaces autour. Guillemets
 * facultatifs autour de la valeur.
 * Traitement : conditions d'abord (sur les valeurs brutes), puis placeholders du texte retenu.
 * Aucune exécution de code : tokenisation des balises, arbre, évaluation.
 * Erreurs : attribut inconnu dans une condition → condition fausse + avertissement ; syntaxe
 * invalide (ENDIF manquant, ELSE orphelin…) → modèle rendu brut, sans évaluation + avertissement.
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
 * En texte brut, rien n'est retiré : « LARGEUR<300 » n'est pas une balise HTML.
 *
 * @param	string	$content	Contenu entre les accolades
 * @param	bool	$html		Modèle HTML (l'éditeur y encode < et > en &lt; &gt;)
 * @return	string
 */
function diamantutils_descpropal_tag_text($content, $html = true)
{
	$text = (string) $content;
	if ($html) {
		$text = dol_html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
	}
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
		$tag = diamantutils_descpropal_tag_text($m[1], $html);
		if (substr($tag, 0, 1) === '=') {
			$value = diamantutils_descpropal_calc_render(substr($tag, 1), $attributes, $error);
			if ($value === null) {
				$warnings[] = 'Calcul impossible ('.$error.') : '.$m[0];
				return $m[0];
			}
			return $value;
		}
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
 * Analyse un calcul {=…} : expression et nombre de décimales
 *
 * Grammaire : expr = terme (('+'|'-') terme)* ; terme = facteur (('*'|'/') facteur)* ;
 * facteur = ('+'|'-') facteur | '(' expr ')' | nombre | ATTRIBUT[:ref]
 *
 * @param	string		$text		Texte de la balise sans le « = »
 * @param	string		$error		Message d'erreur (sortie)
 * @return	array|null				array('ast' => arbre, 'decimals' => int|null), null si syntaxe invalide
 */
function diamantutils_descpropal_calc_parse($text, &$error)
{
	$error = '';
	$decimals = null;
	$text = (string) $text;
	if (preg_match('/^(.*);\s*(\d+)\s*$/su', $text, $reg)) {
		$text = $reg[1];
		$decimals = min((int) $reg[2], 10);
	}
	if (strpos($text, ';') !== false) {
		$error = 'nombre de décimales invalide après « ; »';
		return null;
	}

	// Jetons : opérateurs, parenthèses, opérandes (nombre ou attribut, suffixe :ref facultatif)
	$tokens = array();
	$pos = 0;
	$len = strlen($text);
	while ($pos < $len) {
		if (!preg_match('/\G\s*(?:([-+*\/()])|([^\s\-+*\/():;]+)(?:\s*:\s*(ref)(?![^\s\-+*\/()]))?|(\S))\s*/iu', $text, $reg, 0, $pos) || $reg[0] === '') {
			break;
		}
		$pos += strlen($reg[0]);
		if (($reg[1] ?? '') !== '') {
			$tokens[] = array('op', $reg[1]);
		} elseif (($reg[2] ?? '') !== '') {
			$number = diamantutils_descpropal_number($reg[2]);
			if ($number !== null && ($reg[3] ?? '') === '' && preg_match('/^\d+(?:[.,]\d+)?$/', $reg[2])) {
				$tokens[] = array('num', $number);
			} else {
				$tokens[] = array('attr', $reg[2], (($reg[3] ?? '') !== ''));
			}
		} else {
			$error = 'caractère inattendu « '.($reg[4] ?? '').' »';
			return null;
		}
	}
	if (!count($tokens)) {
		$error = 'expression vide';
		return null;
	}

	$i = 0;
	$parse_expr = null;
	$parse_factor = null;
	// Fermetures récursives (référencées par elles-mêmes)
	$parse_factor = function () use (&$tokens, &$i, &$parse_expr, &$parse_factor, &$error) {
		$token = $tokens[$i] ?? null;
		if ($token === null) {
			$error = 'expression incomplète';
			return null;
		}
		if ($token[0] == 'op' && ($token[1] == '-' || $token[1] == '+')) {
			$i++;
			$operand = $parse_factor();
			if ($operand === null) {
				return null;
			}
			return ($token[1] == '-' ? array('neg', $operand) : $operand);
		}
		if ($token[0] == 'op' && $token[1] == '(') {
			$i++;
			$node = $parse_expr();
			if ($node === null) {
				return null;
			}
			if (($tokens[$i][0] ?? '') != 'op' || $tokens[$i][1] != ')') {
				$error = 'parenthèse non fermée';
				return null;
			}
			$i++;
			return $node;
		}
		if ($token[0] == 'num' || $token[0] == 'attr') {
			$i++;
			return $token;
		}
		$error = 'opérateur « '.$token[1].' » inattendu';
		return null;
	};
	$parse_term = function () use (&$tokens, &$i, &$parse_factor) {
		$left = $parse_factor();
		while ($left !== null && ($tokens[$i][0] ?? '') == 'op' && in_array($tokens[$i][1], array('*', '/'))) {
			$op = $tokens[$i][1];
			$i++;
			$right = $parse_factor();
			$left = ($right === null ? null : array($op, $left, $right));
		}
		return $left;
	};
	$parse_expr = function () use (&$tokens, &$i, &$parse_term) {
		$left = $parse_term();
		while ($left !== null && ($tokens[$i][0] ?? '') == 'op' && in_array($tokens[$i][1], array('+', '-'))) {
			$op = $tokens[$i][1];
			$i++;
			$right = $parse_term();
			$left = ($right === null ? null : array($op, $left, $right));
		}
		return $left;
	};

	$ast = $parse_expr();
	if ($ast === null) {
		return null;
	}
	if ($i < count($tokens)) {
		$error = ($tokens[$i][1] == ')' ? 'parenthèse fermante en trop' : 'élément inattendu « '.$tokens[$i][1].' »');
		return null;
	}
	return array('ast' => $ast, 'decimals' => $decimals);
}

/**
 * Évalue l'arbre d'un calcul
 *
 * @param	array		$node			Nœud (diamantutils_descpropal_calc_parse())
 * @param	array		$attributes		Attributs de la variante
 * @param	string		$error			Message d'erreur (sortie)
 * @return	float|null					Résultat, null si erreur
 */
function diamantutils_descpropal_calc_eval($node, $attributes, &$error)
{
	switch ($node[0]) {
		case 'num':
			return $node[1];
		case 'attr':
			$key = diamantutils_descpropal_key($node[1]);
			if (!isset($attributes[$key])) {
				$error = 'attribut '.$node[1].' absent de la variante';
				return null;
			}
			$value = ($node[2] ? $attributes[$key]['value_ref'] : $attributes[$key]['value']);
			$number = diamantutils_descpropal_number($value);
			if ($number === null) {
				$error = 'valeur non numérique « '.$value.' » pour '.$node[1].($node[2] ? ':ref' : '');
				return null;
			}
			return $number;
		case 'neg':
			$value = diamantutils_descpropal_calc_eval($node[1], $attributes, $error);
			return ($value === null ? null : -$value);
	}
	$left = diamantutils_descpropal_calc_eval($node[1], $attributes, $error);
	if ($left === null) {
		return null;
	}
	$right = diamantutils_descpropal_calc_eval($node[2], $attributes, $error);
	if ($right === null) {
		return null;
	}
	switch ($node[0]) {
		case '+':
			return $left + $right;
		case '-':
			return $left - $right;
		case '*':
			return $left * $right;
	}
	if (abs($right) < 1e-12) {
		$error = 'division par zéro';
		return null;
	}
	return $left / $right;
}

/**
 * Attributs utilisés par l'arbre d'un calcul
 *
 * @param	array		$node	Nœud
 * @param	string[]	$refs	Références trouvées (sortie, clés en majuscules)
 * @return	void
 */
function diamantutils_descpropal_calc_refs($node, &$refs)
{
	if ($node[0] == 'attr') {
		$refs[diamantutils_descpropal_key($node[1])] = $node[1];
	} elseif ($node[0] == 'neg') {
		diamantutils_descpropal_calc_refs($node[1], $refs);
	} elseif ($node[0] != 'num') {
		diamantutils_descpropal_calc_refs($node[1], $refs);
		diamantutils_descpropal_calc_refs($node[2], $refs);
	}
}

/**
 * Calcul {=…} : texte du résultat (virgule décimale)
 *
 * @param	string		$text			Texte de la balise sans le « = »
 * @param	array		$attributes		Attributs de la variante
 * @param	string		$error			Message d'erreur (sortie)
 * @return	string|null					Résultat formaté, null si erreur
 */
function diamantutils_descpropal_calc_render($text, $attributes, &$error)
{
	$calc = diamantutils_descpropal_calc_parse($text, $error);
	if ($calc === null) {
		return null;
	}
	$value = diamantutils_descpropal_calc_eval($calc['ast'], $attributes, $error);
	if ($value === null) {
		return null;
	}
	$decimals = ($calc['decimals'] === null ? 2 : $calc['decimals']);
	$value = round($value, $decimals);
	if ($value == 0) {
		$value = 0.0;	// pas de « -0 »
	}
	$out = number_format($value, $decimals, ',', '');
	if ($calc['decimals'] === null && strpos($out, ',') !== false) {
		$out = rtrim(rtrim($out, '0'), ',');
	}
	return $out;
}

/**
 * Découpe un texte en segments, les chaînes entre guillemets étant protégées
 *
 * @param	string		$text		Texte
 * @param	string		$pattern	Séparateur (regex, avec groupe capturant pour le garder)
 * @param	string		$error		Message d'erreur (sortie)
 * @return	string[]|null			Segments, null si guillemet non fermé
 */
function diamantutils_descpropal_split($text, $pattern, &$error)
{
	$quoted = array();
	$protected = preg_replace_callback('/"[^"]*"/u', function ($m) use (&$quoted) {
		$quoted[] = $m[0];
		return "\x01".(count($quoted) - 1)."\x02";
	}, $text);
	if (strpos($protected, '"') !== false) {
		$error = 'guillemet non fermé';
		return null;
	}
	$parts = preg_split($pattern, $protected, -1, PREG_SPLIT_DELIM_CAPTURE);
	foreach ($parts as $i => $part) {
		$parts[$i] = preg_replace_callback('/\x01(\d+)\x02/', function ($m) use ($quoted) {
			return $quoted[(int) $m[1]];
		}, $part);
	}
	return $parts;
}

/**
 * Valeur de droite d'une condition : espaces et guillemets autour retirés
 *
 * @param	string	$value	Valeur
 * @return	string
 */
function diamantutils_descpropal_unquote($value)
{
	$value = trim((string) $value);
	if (strlen($value) >= 2 && $value[0] == '"' && substr($value, -1) == '"') {
		$value = substr($value, 1, -1);
	}
	return $value;
}

/**
 * Analyse une condition : groupes OR de conditions élémentaires AND
 *
 * @param	string		$text		Condition (texte de la balise après IF / ELSEIF)
 * @param	string		$error		Message d'erreur (sortie)
 * @return	array|null				array(array(atome, …), …) ; null si syntaxe invalide
 */
function diamantutils_descpropal_parse_condition($text, &$error)
{
	$parts = diamantutils_descpropal_split($text, '/\s+(AND|OR)\s+/i', $error);
	if ($parts === null) {
		return null;
	}

	$groups = array(array());
	foreach ($parts as $i => $part) {
		if ($i % 2 == 1) {	// séparateur
			if (strtoupper($part) == 'OR') {
				$groups[] = array();
			}
			continue;
		}
		$part = trim($part);
		$attr = '([^\s:=!<>"]+)(?:\s*:\s*(ref))?';
		if (preg_match('/^'.$attr.'\s+IN\s+(.+)$/iu', $part, $reg)) {
			$values = diamantutils_descpropal_split($reg[3], '/,/', $error);
			if ($values === null) {
				return null;
			}
			$atom = array('attr' => $reg[1], 'ref' => ($reg[2] !== ''), 'op' => 'IN', 'values' => array_map('diamantutils_descpropal_unquote', $values));
		} elseif (preg_match('/^'.$attr.'\s*(!=|<>|>=|<=|==|=|>|<)\s*(.*)$/isu', $part, $reg)) {
			$op = ($reg[3] == '<>' ? '!=' : ($reg[3] == '==' ? '=' : $reg[3]));
			$atom = array('attr' => $reg[1], 'ref' => ($reg[2] !== ''), 'op' => $op, 'values' => array(diamantutils_descpropal_unquote($reg[4])));
		} elseif (preg_match('/^'.$attr.'$/iu', $part, $reg)) {
			$atom = array('attr' => $reg[1], 'ref' => (($reg[2] ?? '') !== ''), 'op' => 'EXISTS', 'values' => array());
		} else {
			$error = 'condition invalide « '.$part.' »';
			return null;
		}
		$groups[count($groups) - 1][] = $atom;
	}
	return $groups;
}

/**
 * Construit l'arbre d'un modèle : texte (placeholders compris) et blocs conditionnels
 *
 * Nœud texte : array('type' => 'text', 'text' => …)
 * Nœud bloc  : array('type' => 'if', 'branches' => array(array('cond' => …, 'children' => …), …), 'else' => children|null)
 *
 * @param	string		$template	Modèle
 * @param	string		$error		Message d'erreur (sortie)
 * @return	array|null				Nœuds de premier niveau, null si syntaxe invalide
 */
function diamantutils_descpropal_parse($template, &$error)
{
	$error = '';
	$html = dol_textishtml((string) $template);
	$tokens = preg_split('/(\{[^{}]*\})/u', (string) $template, -1, PREG_SPLIT_DELIM_CAPTURE);

	// Pile de conteneurs : chaque entrée est la liste d'enfants en cours de remplissage.
	// Les blocs ouverts sont gardés par référence pour y ajouter ELSEIF / ELSE.
	$root = array();
	$stack = array();		// array('node' => &bloc, 'parent' => &liste du bloc)
	$current = &$root;

	foreach ($tokens as $i => $token) {
		if ($token === '') {
			continue;
		}
		$keyword = '';
		$argument = '';
		if ($i % 2 == 1) {
			$tag = diamantutils_descpropal_tag_text(substr($token, 1, -1), $html);
			if (preg_match('/^(IF|ELSE\s*IF)(?:\s+(.*))?$/isu', $tag, $reg)) {
				$keyword = (strtoupper($reg[1]) == 'IF' ? 'IF' : 'ELSEIF');
				$argument = trim($reg[2] ?? '');
			} elseif (preg_match('/^ELSE$/i', $tag)) {
				$keyword = 'ELSE';
			} elseif (preg_match('/^END\s*IF$/i', $tag)) {
				$keyword = 'ENDIF';
			}
		}

		if ($keyword === '') {
			$current[] = array('type' => 'text', 'text' => $token);
			continue;
		}

		$depth = count($stack);
		if ($keyword == 'IF' || $keyword == 'ELSEIF') {
			if ($argument === '') {
				$error = $keyword.' sans condition';
				return null;
			}
			$cond = diamantutils_descpropal_parse_condition($argument, $suberror);
			if ($cond === null) {
				$error = '{'.$keyword.' '.$argument.'} : '.$suberror;
				return null;
			}
		}

		if ($keyword == 'IF') {
			$current[] = array('type' => 'if', 'branches' => array(array('cond' => $cond, 'children' => array())), 'else' => null);
			$node = &$current[count($current) - 1];
			$stack[] = array('node' => &$node, 'parent' => &$current);
			unset($current);
			$current = &$node['branches'][0]['children'];
			unset($node);
		} elseif ($keyword == 'ELSEIF') {
			if (!$depth) {
				$error = 'ELSEIF sans IF';
				return null;
			}
			$node = &$stack[$depth - 1]['node'];
			if ($node['else'] !== null) {
				$error = 'ELSEIF après ELSE';
				return null;
			}
			$node['branches'][] = array('cond' => $cond, 'children' => array());
			unset($current);
			$current = &$node['branches'][count($node['branches']) - 1]['children'];
			unset($node);
		} elseif ($keyword == 'ELSE') {
			if (!$depth) {
				$error = 'ELSE sans IF';
				return null;
			}
			$node = &$stack[$depth - 1]['node'];
			if ($node['else'] !== null) {
				$error = 'ELSE en double';
				return null;
			}
			$node['else'] = array();
			unset($current);
			$current = &$node['else'];
			unset($node);
		} else {	// ENDIF
			if (!$depth) {
				$error = 'ENDIF sans IF';
				return null;
			}
			unset($current);
			$current = &$stack[$depth - 1]['parent'];
			array_pop($stack);
		}
	}

	if (count($stack)) {
		$error = count($stack).' ENDIF manquant(s)';
		return null;
	}
	unset($current);
	return $root;
}

/**
 * Nombre d'une valeur de condition (virgule décimale acceptée), null si non numérique
 *
 * @param	string	$value	Valeur
 * @return	float|null
 */
function diamantutils_descpropal_number($value)
{
	$value = str_replace(',', '.', trim((string) $value));
	return (is_numeric($value) ? (float) $value : null);
}

/**
 * Compare deux valeurs : numérique si les deux sont des nombres, sinon chaînes sans casse
 *
 * @param	string	$a	Valeur de l'attribut
 * @param	string	$b	Valeur de la condition
 * @return	int			<0, 0, >0
 */
function diamantutils_descpropal_compare($a, $b)
{
	$na = diamantutils_descpropal_number($a);
	$nb = diamantutils_descpropal_number($b);
	if ($na !== null && $nb !== null) {
		return (abs($na - $nb) < 1e-9 ? 0 : ($na < $nb ? -1 : 1));
	}
	$lower = function ($s) {
		$s = trim((string) $s);
		return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
	};
	return strcmp($lower($a), $lower($b));
}

/**
 * Évalue une condition analysée
 *
 * @param	array		$groups			Résultat de diamantutils_descpropal_parse_condition()
 * @param	array		$attributes		Attributs de la variante
 * @param	string[]	$warnings		Avertissements (sortie, ajoutés)
 * @return	bool
 */
function diamantutils_descpropal_eval_condition($groups, $attributes, &$warnings)
{
	foreach ($groups as $atoms) {
		$ok = true;
		foreach ($atoms as $atom) {
			$key = diamantutils_descpropal_key($atom['attr']);
			if (!isset($attributes[$key])) {
				// Présence testée sur un attribut absent : simplement fausse
				if ($atom['op'] != 'EXISTS') {
					$warnings[] = 'Attribut inconnu dans une condition : '.$atom['attr'];
				}
				$ok = false;
				break;
			}
			$value = ($atom['ref'] ? $attributes[$key]['value_ref'] : $attributes[$key]['value']);
			switch ($atom['op']) {
				case 'EXISTS':
					$res = (trim((string) $value) !== '');
					break;
				case 'IN':
					$res = false;
					foreach ($atom['values'] as $candidate) {
						if (diamantutils_descpropal_compare($value, $candidate) == 0) {
							$res = true;
							break;
						}
					}
					break;
				default:
					$cmp = diamantutils_descpropal_compare($value, $atom['values'][0]);
					$res = (($atom['op'] == '=' && $cmp == 0) || ($atom['op'] == '!=' && $cmp != 0)
						|| ($atom['op'] == '>' && $cmp > 0) || ($atom['op'] == '<' && $cmp < 0)
						|| ($atom['op'] == '>=' && $cmp >= 0) || ($atom['op'] == '<=' && $cmp <= 0));
			}
			if (!$res) {
				$ok = false;
				break;
			}
		}
		if ($ok) {
			return true;	// un groupe AND vrai suffit (OR)
		}
	}
	return false;
}

/**
 * Évalue l'arbre : texte des branches retenues, placeholders non substitués
 *
 * @param	array		$nodes			Nœuds
 * @param	array		$attributes		Attributs de la variante
 * @param	string[]	$warnings		Avertissements (sortie, ajoutés)
 * @return	string
 */
function diamantutils_descpropal_eval_nodes($nodes, $attributes, &$warnings)
{
	$out = '';
	foreach ($nodes as $node) {
		if ($node['type'] == 'text') {
			$out .= $node['text'];
			continue;
		}
		$children = $node['else'];
		foreach ($node['branches'] as $branch) {
			if (diamantutils_descpropal_eval_condition($branch['cond'], $attributes, $warnings)) {
				$children = $branch['children'];
				break;
			}
		}
		if ($children !== null) {
			$out .= diamantutils_descpropal_eval_nodes($children, $attributes, $warnings);
		}
	}
	return $out;
}

/**
 * Références d'attributs utilisées par un arbre (conditions et placeholders)
 *
 * @param	array		$nodes	Nœuds
 * @param	string[]	$refs	Références trouvées (sortie, clés en majuscules)
 * @param	bool		$html	Modèle HTML
 * @param	string[]	$errors	Erreurs de syntaxe des calculs {=…} (sortie, ajoutées)
 * @return	void
 */
function diamantutils_descpropal_collect_refs($nodes, &$refs, $html = true, &$errors = null)
{
	foreach ($nodes as $node) {
		if ($node['type'] == 'text') {
			if (preg_match_all('/\{([^{}]*)\}/u', $node['text'], $matches)) {
				foreach ($matches[1] as $content) {
					$tag = diamantutils_descpropal_tag_text($content, $html);
					if (substr($tag, 0, 1) === '=') {
						$calc = diamantutils_descpropal_calc_parse(substr($tag, 1), $error);
						if ($calc === null) {
							$errors[] = 'calcul {'.$tag.'} : '.$error;
						} else {
							diamantutils_descpropal_calc_refs($calc['ast'], $refs);
						}
						continue;
					}
					if (preg_match('/^([^\s:]+)\s*(?::\s*(?:ref|label))?$/iu', diamantutils_descpropal_tag_text($content, $html), $reg)) {
						$key = diamantutils_descpropal_key($reg[1]);
						if (!in_array($key, array('PRODUCT_REF', 'PRODUCT_LABEL'))) {
							$refs[$key] = $reg[1];
						}
					}
				}
			}
			continue;
		}
		foreach ($node['branches'] as $branch) {
			foreach ($branch['cond'] as $atoms) {
				foreach ($atoms as $atom) {
					$refs[diamantutils_descpropal_key($atom['attr'])] = $atom['attr'];
				}
			}
			diamantutils_descpropal_collect_refs($branch['children'], $refs, $html, $errors);
		}
		if ($node['else'] !== null) {
			diamantutils_descpropal_collect_refs($node['else'], $refs, $html, $errors);
		}
	}
}

/**
 * Vérifie un modèle (enregistrement du produit) : syntaxe et, si fournis, attributs connus
 *
 * @param	string		$template		Modèle
 * @param	array|null	$knownattributes	Attributs connus (clés REF_ATTRIBUT), null = non vérifiés
 * @return	string[]					Erreurs (vide si modèle correct)
 */
function diamantutils_descpropal_check($template, $knownattributes = null)
{
	$errors = array();
	if (strpos((string) $template, '{') === false) {
		return $errors;
	}
	$tree = diamantutils_descpropal_parse($template, $error);
	if ($tree === null) {
		$errors[] = $error;
		return $errors;
	}
	$refs = array();
	diamantutils_descpropal_collect_refs($tree, $refs, dol_textishtml((string) $template), $errors);
	if (is_array($knownattributes)) {
		$unknown = array_diff_key($refs, $knownattributes);
		if (count($unknown)) {
			$errors[] = 'attribut(s) inconnu(s) : '.implode(', ', $unknown);
		}
	}
	return $errors;
}

/**
 * Rendu d'un modèle de description pour devis : conditions, puis placeholders
 *
 * Ne lève jamais d'erreur : un modèle invalide est rendu brut, les anomalies vont dans le log.
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
	$html = dol_textishtml($template);
	$ref = (is_object($product) && !empty($product->ref) ? ' produit '.$product->ref : '');

	$tree = diamantutils_descpropal_parse($template, $error);
	if ($tree === null) {
		$warnings[] = 'Syntaxe invalide ('.$error.') : modèle laissé brut';
		$result = $template;
	} else {
		$hasblock = false;
		foreach ($tree as $node) {
			if ($node['type'] == 'if') {
				$hasblock = true;
				break;
			}
		}
		$result = diamantutils_descpropal_eval_nodes($tree, $attributes, $warnings);
		if ($hasblock && $html) {
			// Paragraphes vidés par les branches non retenues (une balise par ligne dans l'éditeur)
			$result = preg_replace('/<p>(?:\s|&nbsp;|<br\s*\/?>)*<\/p>/i', '', $result);
		}
		$result = diamantutils_descpropal_substitute($result, $attributes, $product, $html, $warnings);
	}

	foreach ($warnings as $warning) {
		dol_syslog('diamantutils_render_desc'.$ref.' : '.$warning, LOG_WARNING);
	}
	return $result;
}
