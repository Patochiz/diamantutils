<?php
/**
 * Cas de test des calculs du tableau de stock par lots (lib/stocktable.lib.php)
 *
 * Fichier sans dépendance Dolibarr : inclus par stocktable_calc_test.php (navigateur, admin).
 * diamantutils_stocktable_test_run() renvoie la liste des contrôles :
 * array('group', 'name', 'expected', 'got', 'ok').
 */

/**
 * Exécute tous les cas
 *
 * @return	array	Résultats
 */
function diamantutils_stocktable_test_run()
{
	$results = array();
	$add = function ($group, $name, $expected, $got) use (&$results) {
		if (is_float($expected) || is_float($got)) {
			$ok = ($expected !== null && $got !== null && abs((float) $expected - (float) $got) < 1e-9);
		} else {
			$ok = ($expected === $got);
		}
		$results[] = array('group' => $group, 'name' => $name, 'expected' => var_export($expected, true), 'got' => var_export($got, true), 'ok' => $ok);
	};

	// 1. Colisage
	$colis = array(
		array(140, 28, '5 colis de 28u', 5, ''),
		array(6, 6, '1 colis de 6u', 1, ''),
		array(30, 28, '1 colis de 28u + 1 colis de 2u', 2, ''),
		array(28, 28, '1 colis de 28u', 1, ''),
		array(20, 28, '1 colis de 20u', 1, 'partial'),
		array(57, 28, '2 colis de 28u + 1 colis de 1u', 3, ''),
		array(0.3, 0.1, '3 colis de 0,1u', 3, ''),
		array(7.5, 2, '3 colis de 2u + 1 colis de 1,5u', 4, ''),
		array(0, 28, '', 0, 'invalid'),
		array(10, 0, '', 0, 'invalid'),
	);
	foreach ($colis as $c) {
		$r = diamantutils_stock_colis($c[0], $c[1]);
		$label = $c[0].' pièces × '.$c[1].'/colis';
		$add('Colisage', $label.' : texte', $c[2], $r['text']);
		$add('Colisage', $label.' : nombre de colis', $c[3], $r['colis']);
		$add('Colisage', $label.' : avertissement', $c[4], $r['warn']);
	}

	// 2. Unités (c_units Dolibarr 20)
	$m2 = diamantutils_stock_unit(1, 'surface', 0, 'M2', 'm2');
	$dm2 = diamantutils_stock_unit(2, 'surface', -2, 'DM2', 'dm2');
	$m = diamantutils_stock_unit(3, 'size', 0, 'M', 'm');
	$mm = diamantutils_stock_unit(4, 'size', -3, 'MM', 'mm');
	$p = diamantutils_stock_unit(5, 'qty', 0, 'P', 'p');
	$none = diamantutils_stock_unit(0, null, null);
	$ft2 = diamantutils_stock_unit(6, 'surface', 98, 'FT2', 'ft2');
	$kg = diamantutils_stock_unit(7, 'weight', 0, 'KG', 'kg');
	$migrated = diamantutils_stock_unit(8, '', null, 'M2', 'm2');

	$add('Unités', 'm² : accepté', true, $m2['ok']);
	$add('Unités', 'sans unité : compté en pièces', 'qty', $none['type']);
	$add('Unités', 'ft² (échelle 98) : refusé', 'DiamantutilsStockErrUnitScale', $ft2['error']);
	$add('Unités', 'kg : refusé', 'DiamantutilsStockErrUnitType', $kg['error']);
	$add('Unités', 'type vide, code M2 : surface', 'surface', $migrated['type']);
	$add('Unités', 'type vide, code M2 : échelle 0', 0, $migrated['scale']);

	// 3. Quantité d'une ligne
	$err = '';
	$q = diamantutils_stock_line_qty($m2, 140, 600, 600, $err);
	$add('Quantité', '140 × 600 × 600 mm en m²', 50.4, $q);
	$add('Quantité', '140 × 600 × 600 mm en m² (affichage 2 déc.)', '50,40', number_format(round($q, 2), 2, ',', ''));
	$add('Quantité', '140 × 600 × 600 mm en dm²', 5040.0, diamantutils_stock_line_qty($dm2, 140, 600, 600));
	$add('Quantité', '10 × 3000 mm en m', 30.0, diamantutils_stock_line_qty($m, 10, null, 3000));
	$add('Quantité', '10 × 3000 mm en mm', 30000.0, diamantutils_stock_line_qty($mm, 10, null, 3000));
	$add('Quantité', '2,5 pièces × 1000 mm en m (produit au mètre)', 2.5, diamantutils_stock_line_qty($m, 2.5, null, 1000));
	$add('Quantité', '140 pièces (unité pièce, dimensions ignorées)', 140.0, diamantutils_stock_line_qty($p, 140, 600, 600));
	$add('Quantité', '140 pièces (sans unité)', 140.0, diamantutils_stock_line_qty($none, 140, null, null));

	$q = diamantutils_stock_line_qty($m2, 140, 600, null, $err);
	$add('Quantité', 'm² sans longueur : null', null, $q);
	$add('Quantité', 'm² sans longueur : message', 'DiamantutilsStockErrDimsSurface', $err);
	$q = diamantutils_stock_line_qty($m2, 140, '', 600, $err);
	$add('Quantité', 'm² sans largeur : null', null, $q);
	$q = diamantutils_stock_line_qty($m, 10, 600, null, $err);
	$add('Quantité', 'mètre sans longueur : null', null, $q);
	$add('Quantité', 'mètre sans longueur : message', 'DiamantutilsStockErrDimsSize', $err);
	$q = diamantutils_stock_line_qty($ft2, 10, 600, 600, $err);
	$add('Quantité', 'ft² : null', null, $q);
	$q = diamantutils_stock_line_qty($m2, 0, 600, 600, $err);
	$add('Quantité', '0 pièce : null', null, $q);

	// 4. États
	$tol = 0.01;
	$add('États', 'écart 0,005 ≤ tolérance : OK', 'ok', diamantutils_stock_state(10.005, 10, $tol, true));
	$add('États', 'écart 0,01 = tolérance : OK', 'ok', diamantutils_stock_state(10.01, 10, $tol, true));
	$add('États', 'écart 0,02 : Écart', 'diff', diamantutils_stock_state(10.02, 10, $tol, true));
	$add('États', 'absent du tableau, stock 5 : Dolibarr seul', 'doli_only', diamantutils_stock_state(0, 5, $tol, false));
	$add('États', 'dans le tableau, stock 0 : Tableau seul', 'table_only', diamantutils_stock_state(5, 0, $tol, true));
	$add('États', 'quantité non calculable', 'unknown', diamantutils_stock_state(null, 5, $tol, true));

	// 5. Comparaison FOCAL (capture Airtable) : Lot 1 = 28 + 6 pièces, Lot 2 = 140 pièces, 600 × 600 mm
	$lines = array(
		array('batch' => 'Lot 1', 'nb_pieces' => 28, 'larg_mm' => 600, 'long_mm' => 600),
		array('batch' => 'Lot 2', 'nb_pieces' => 140, 'larg_mm' => 600, 'long_mm' => 600),
		array('batch' => 'Lot 1', 'nb_pieces' => 6, 'larg_mm' => 600, 'long_mm' => 600),
		array('batch' => 'Lot 4', 'nb_pieces' => 10, 'larg_mm' => 600, 'long_mm' => 600),
	);
	$doli_lots = array('Lot 1' => 12.24, 'Lot 2' => 50, 'Lot 3' => 7.2);
	$cmp = diamantutils_stock_compare($m2, $lines, $doli_lots, 72.44, true, $tol);
	$add('Comparaison FOCAL', 'Lot 1 : 2 lignes additionnées', 2, count($cmp['lots']['Lot 1']['lines']));
	$add('Comparaison FOCAL', 'Lot 1 : tableau 34 × 0,36 = 12,24 m²', 12.24, round($cmp['lots']['Lot 1']['table'], 6));
	$add('Comparaison FOCAL', 'Lot 1 : OK', 'ok', $cmp['lots']['Lot 1']['state']);
	$add('Comparaison FOCAL', 'Lot 2 : 50,40 / 50 → Écart', 'diff', $cmp['lots']['Lot 2']['state']);
	$add('Comparaison FOCAL', 'Lot 2 : écart +0,40', 0.4, round($cmp['lots']['Lot 2']['diff'], 6));
	$add('Comparaison FOCAL', 'Lot 3 : Dolibarr seul', 'doli_only', $cmp['lots']['Lot 3']['state']);
	$add('Comparaison FOCAL', 'Lot 4 : Tableau seul', 'table_only', $cmp['lots']['Lot 4']['state']);
	$add('Comparaison FOCAL', 'Total tableau 12,24 + 50,40 + 3,60', 66.24, round($cmp['table'], 6));
	$add('Comparaison FOCAL', 'Part Dolibarr sans lot 72,44 − 69,44', 3.0, round($cmp['doli_nolot'], 6));
	$add('Comparaison FOCAL', 'Produit : Écart', 'diff', $cmp['state']);
	$add('Comparaison FOCAL', 'Ordre des lots', "'',Lot 1,Lot 2,Lot 3,Lot 4", "'',".implode(',', array_slice(array_keys($cmp['lots']), 1)));

	// 6. Produit sans gestion de lot : une ligne « sans lot » comparée au total
	$cmp = diamantutils_stock_compare($p, array(array('batch' => '', 'nb_pieces' => 140, 'larg_mm' => null, 'long_mm' => null)), array(), 140, false, $tol);
	$add('Sans lot', 'une seule clé « sans lot »', array(''), array_map('strval', array_keys($cmp['lots'])));
	$add('Sans lot', '140 / 140 : OK', 'ok', $cmp['state']);

	// 7. Ligne non calculable : jamais 0 silencieux
	$cmp = diamantutils_stock_compare($m2, array(array('batch' => 'L1', 'nb_pieces' => 10, 'larg_mm' => 600, 'long_mm' => null)), array('L1' => 3.6), 3.6, true, $tol);
	$add('Non calculable', 'lot : Non calculable', 'unknown', $cmp['lots']['L1']['state']);
	$add('Non calculable', 'total tableau : null', null, $cmp['table']);
	$add('Non calculable', 'produit : Non calculable', 'unknown', $cmp['state']);

	return $results;
}
