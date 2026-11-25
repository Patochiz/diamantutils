<?php
/**
 * Onglet « Stock » de la fiche produit : tableau de remplacement avec colonne Colisage
 *
 * Page cœur : product/stock/product.php (contexte de hook stockproductcard), jamais modifiée.
 * Le module affiche son propre tableau dans le hook addMoreActionsButtons (appelé juste avant
 * les boutons « Transférer / Corriger le stock » et le tableau natif) ; le script
 * js/stockcolisage.js le place devant le tableau natif puis masque celui-ci. Si le rendu
 * échoue (exception, erreur SQL) rien n'est affiché : le tableau natif reste visible.
 *
 * Références vérifiées dans le cœur (Dolibarr 20.0.0 et 23.0.0, product/stock/product.php) :
 *  - initHooks(array('stockproductcard', 'globalcard')) ; addMoreActionsButtons appelé avec le
 *    produit, $hookmanager->resPrint jamais affiché (le module affiche par print) ;
 *  - entrepôts : entrepot × product_stock, ps.reel != 0, e.entity IN (getEntity('stock')),
 *    ORDER BY e.ref ; quantité affichée price2num(reel, 'MS'), total price2num(total, 'MS') ;
 *  - lots : Productbatch::findAll($db, product_stock_id, 0, fk_product), quantité affichée
 *    telle quelle ; lotid = 0 sans ligne product_lot (nom affiché sans lien) ;
 *  - avertissements : quantité < 0, quantité > 1 pour un numéro de série (status_batch == 2) ;
 *  - liens d'un lot : ?id=&id_entrepot=&action=transfert|correction&pdluoid=&token=, absents
 *    si l'entrepôt est fermé (Entrepot::STATUS_CLOSED) ;
 *  - repli : classes collapse_batch / batch_warehouse<id>, #show_all / #hide_all,
 *    STOCK_SHOW_ALL_BATCH_BY_DEFAULT ;
 *  - tableau affiché si !$variants || VARIANT_ALLOW_STOCK_MOVEMENT_ON_VARIANT_PARENT.
 * Différences v23 : action « transfer » (au lieu de « transfert ») dans les liens, et tableau
 * masqué aussi pour un kit (hasFatherOrChild(1)) sans PRODUIT_SOUSPRODUITS_ALSO_ENABLE_PARENT_STOCK_MOVE.
 */

/**
 * Fonctionnalité « Colisage sur l'onglet Stock » active ?
 * Constante absente = désactivée.
 *
 * @return	bool
 */
function diamantutils_stockcolisage_enabled()
{
	return isModEnabled('diamantutils') && !empty(getDolGlobalString('DIAMANTUTILS_STOCKCOLISAGE_ENABLED', '0'));
}

/**
 * Version majeure de Dolibarr
 *
 * @return	int
 */
function diamantutils_stockcolisage_dolversion()
{
	return (int) (defined('DOL_VERSION') ? DOL_VERSION : 0);
}

/**
 * Action « transférer » des liens de la page : transfert (≤ v22) ou transfer (≥ v23)
 *
 * @return	string
 */
function diamantutils_stockcolisage_transfer_action()
{
	return (diamantutils_stockcolisage_dolversion() >= 23 ? 'transfer' : 'transfert');
}
