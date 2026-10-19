<?php
/**
 * Cas de test de diamantutils_render_desc() (description pour devis)
 *
 * Fichier sans dépendance : inclus par descpropal_render_test.php (navigateur, admin).
 * Chaque cas : nom, modèle, valeurs des attributs, résultat attendu, nombre d'avertissements attendu.
 */

/**
 * Attributs de variante de test : REF => valeur (référence de valeur = 'R-'.valeur sauf précision)
 *
 * @param	array	$values		REF => valeur
 * @param	array	$refs		REF => référence de la valeur
 * @return	array
 */
function diamantutils_test_attrs($values, $refs = array())
{
	$attributes = array();
	foreach ($values as $ref => $value) {
		$attributes[strtoupper($ref)] = array(
			'ref' => $ref,
			'label' => ucfirst(strtolower($ref)),
			'value' => (string) $value,
			'value_ref' => (string) ($refs[$ref] ?? 'R-'.$value),
		);
	}
	return $attributes;
}

/**
 * @return	array	Liste de array('name', 'template', 'attributes', 'expected', 'warnings')
 */
function diamantutils_test_cases()
{
	$largeur = "{IF LARGEUR=200}Largeur 200 avec portée 2,13 m{ELSEIF LARGEUR=300}Largeur 300 avec portée 2,50 m{ELSE}Largeur {LARGEUR}{ENDIF}";
	$prio = '{IF A=1 OR B=2 AND C=3}vrai{ELSE}faux{ENDIF}';
	$nested = '{IF FINITION=Laqué}Laqué{IF COULEUR:ref=9010} blanc{ELSE} {COULEUR}{ENDIF}{ELSE}Brut{IF LARGEUR>=300} large{ENDIF}{ENDIF}';
	$std = diamantutils_test_attrs(array('LARGEUR' => '200', 'FINITION' => 'Laqué', 'COULEUR' => 'RAL 9010'), array('COULEUR' => '9010'));

	return array(
		// 1. IF / ELSEIF / ELSE
		array('1. IF : LARGEUR=200', $largeur, diamantutils_test_attrs(array('LARGEUR' => '200')), 'Largeur 200 avec portée 2,13 m', 0),
		array('1. ELSEIF : LARGEUR=300', $largeur, diamantutils_test_attrs(array('LARGEUR' => '300')), 'Largeur 300 avec portée 2,50 m', 0),
		array('1. ELSE : LARGEUR=600', $largeur, diamantutils_test_attrs(array('LARGEUR' => '600')), 'Largeur 600', 0),
		array('1. Mots-clés en minuscules', '{if largeur=200}A{elseif largeur=300}B{else}C{endif}', diamantutils_test_attrs(array('LARGEUR' => '300')), 'B', 0),
		array('1. Exemple multiligne du guide', "{IF LARGEUR=200}Largeur 200 avec portée 2,13 m\n{ELSEIF LARGEUR=300}Largeur 300 avec portée 2,50 m\n{ELSE}Largeur {LARGEUR}\n{ENDIF}", diamantutils_test_attrs(array('LARGEUR' => '300')), "Largeur 300 avec portée 2,50 m\n", 0),
		// 2. Comparaisons numériques
		array('2. LARGEUR>=300 vrai pour 600', '{IF LARGEUR>=300}oui{ELSE}non{ENDIF}', diamantutils_test_attrs(array('LARGEUR' => '600')), 'oui', 0),
		array('2. LARGEUR>=300 faux pour 200', '{IF LARGEUR>=300}oui{ELSE}non{ENDIF}', diamantutils_test_attrs(array('LARGEUR' => '200')), 'non', 0),
		array('2. 200 = 200,0', '{IF LARGEUR=200,0}oui{ELSE}non{ENDIF}', diamantutils_test_attrs(array('LARGEUR' => '200')), 'oui', 0),
		array('2. Numérique et non lexical : 1000 > 300', '{IF LARGEUR>300}oui{ELSE}non{ENDIF}', diamantutils_test_attrs(array('LARGEUR' => '1000')), 'oui', 0),
		array('2. Décimale à virgule : 2,5 < 2,13 faux', '{IF EP<2,13}oui{ELSE}non{ENDIF}', diamantutils_test_attrs(array('EP' => '2,5')), 'non', 0),
		array('2. != et <', '{IF LARGEUR!=200}A{ENDIF}{IF LARGEUR<600}B{ENDIF}', diamantutils_test_attrs(array('LARGEUR' => '300')), 'AB', 0),
		array('2. Chaînes : casse et espaces ignorés', '{IF FINITION=laqué}oui{ELSE}non{ENDIF}', diamantutils_test_attrs(array('FINITION' => ' Laqué ')), 'oui', 0),
		array('2. Valeur entre guillemets', '{IF FINITION="Laqué brillant"}oui{ELSE}non{ENDIF}', diamantutils_test_attrs(array('FINITION' => 'Laqué brillant')), 'oui', 0),
		array('2. Suffixe :ref', '{IF COULEUR:ref=9010}blanc{ELSE}autre{ENDIF}', $std, 'blanc', 0),
		// 3. IN, AND, OR, priorité
		array('3. IN vrai', '{IF LARGEUR IN 200,300,400}oui{ELSE}non{ENDIF}', diamantutils_test_attrs(array('LARGEUR' => '300')), 'oui', 0),
		array('3. IN faux', '{IF LARGEUR IN 200,300,400}oui{ELSE}non{ENDIF}', diamantutils_test_attrs(array('LARGEUR' => '600')), 'non', 0),
		array('3. AND', '{IF LARGEUR=200 AND FINITION=Laqué}oui{ELSE}non{ENDIF}', $std, 'oui', 0),
		array('3. AND faux', '{IF LARGEUR=200 AND FINITION=Brut}oui{ELSE}non{ENDIF}', $std, 'non', 0),
		array('3. OR', '{IF LARGEUR=999 OR FINITION=Laqué}oui{ELSE}non{ENDIF}', $std, 'oui', 0),
		array('3. Priorité : A=1 seul suffit', $prio, diamantutils_test_attrs(array('A' => '1', 'B' => '0', 'C' => '0')), 'vrai', 0),
		array('3. Priorité : B=2 sans C=3 faux', $prio, diamantutils_test_attrs(array('A' => '0', 'B' => '2', 'C' => '0')), 'faux', 0),
		array('3. Priorité : B=2 et C=3 vrai', $prio, diamantutils_test_attrs(array('A' => '0', 'B' => '2', 'C' => '3')), 'vrai', 0),
		array('3. Présence : attribut non vide', '{IF COULEUR}coloris {COULEUR}{ENDIF}', $std, 'coloris RAL 9010', 0),
		array('3. Présence : attribut absent', '{IF VERNIS}verni{ELSE}sans{ENDIF}', $std, 'sans', 0),
		// 4. Imbrication
		array('4. Imbriqué : Laqué / 9010', $nested, $std, 'Laqué blanc', 0),
		array('4. Imbriqué : Laqué / autre couleur', $nested, diamantutils_test_attrs(array('FINITION' => 'Laqué', 'COULEUR' => 'RAL 7016', 'LARGEUR' => '200'), array('COULEUR' => '7016')), 'Laqué RAL 7016', 0),
		array('4. Imbriqué : Brut / large', $nested, diamantutils_test_attrs(array('FINITION' => 'Brut', 'COULEUR' => 'x', 'LARGEUR' => '600')), 'Brut large', 0),
		// 5. Placeholders dans une branche
		array('5. Placeholders dans la branche retenue', '{IF FINITION=Laqué}Profilé {FINITION} {COULEUR:ref} ({COULEUR:label}){ENDIF}', $std, 'Profilé Laqué 9010 (Couleur)', 0),
		// 6. Attribut inconnu
		array('6. Attribut inconnu : ELSE + warning', '{IF LARGUER=200}A{ELSE}B{ENDIF}', $std, 'B', 1),
		// 7. Syntaxe invalide : brut
		array('7. ENDIF manquant : brut + warning', '{IF LARGEUR=200}A {LARGEUR}', $std, '{IF LARGEUR=200}A {LARGEUR}', 1),
		array('7. ELSE orphelin : brut', 'A{ELSE}B', $std, 'A{ELSE}B', 1),
		array('7. ELSEIF après ELSE : brut', '{IF A}1{ELSE}2{ELSEIF B}3{ENDIF}', $std, '{IF A}1{ELSE}2{ELSEIF B}3{ENDIF}', 1),
		array('7. Condition invalide : brut', '{IF =200}A{ENDIF}', $std, '{IF =200}A{ENDIF}', 1),
		array('7. Guillemet non fermé : brut', '{IF FINITION="Laqué}A{ENDIF}', $std, '{IF FINITION="Laqué}A{ENDIF}', 1),
		// 8. Sans condition
		array('8. Placeholders seuls', 'Profilé {FINITION}, coloris {COULEUR}', $std, 'Profilé Laqué, coloris RAL 9010', 0),
		array('8. Placeholder inconnu laissé + warning', 'Coloris {COLEUR}', $std, 'Coloris {COLEUR}', 1),
		array('8. Texte sans balise', 'Texte simple', $std, 'Texte simple', 0),
		// HTML de l'éditeur
		array('HTML : >= encodé par l\'éditeur', '<p>{IF LARGEUR&gt;=200}large{ENDIF}</p>', $std, '<p>large</p>', 0),
		array('HTML : paragraphes vides retirés', '<p>{IF LARGEUR=200}</p><p>Largeur 200</p><p>{ELSE}</p><p>Autre</p><p>{ENDIF}</p><p>Fin</p>', $std, '<p>Largeur 200</p><p>Fin</p>', 0),
		array('HTML : valeur échappée (& <)', '<p>{IF FINITION}{FINITION}{ENDIF}</p>', diamantutils_test_attrs(array('FINITION' => 'Laqué & <brossé>')), '<p>Laqué &amp; &lt;brossé&gt;</p>', 0),
	);
}

/**
 * Cas de test de diamantutils_descpropal_check() (validation à l'enregistrement du produit)
 *
 * @return	array	Liste de array('name', 'template', 'known' (null = non vérifiés), nombre d'erreurs attendu)
 */
function diamantutils_test_check_cases()
{
	$known = diamantutils_test_attrs(array('LARGEUR' => '', 'COULEUR' => ''));
	return array(
		array('Modèle correct', '{IF LARGEUR>=300}{COULEUR:ref}{ENDIF} {PRODUCT_REF}', $known, 0),
		array('ENDIF manquant', '{IF LARGEUR=200}A', $known, 1),
		array('Attribut inconnu (condition et placeholder)', '{IF LARGUER=200}{COLEUR}{ENDIF}', $known, 1),
		array('Attributs non vérifiés (produit simple)', '{IF LARGUER=200}A{ENDIF}', null, 0),
		array('Texte sans balise', 'Texte', $known, 0),
	);
}
