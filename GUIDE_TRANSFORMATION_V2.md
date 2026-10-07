# Guide Claude Code — DiamantUtils v2.1 : Ordres de transformation (menu GPAO)

> À placer à la racine du dépôt `Patochiz/diamantutils`, puis dans Claude Code :
> « Lis GUIDE_TRANSFORMATION_V2.md et applique-le sur la branche existante. »

## 0. Cadre

- **Branche de départ** : `claude/vigilant-meitner-391o77`. Elle contient la v2.0, qui est une première implémentation de `GUIDE_TRANSFORMATION.md`.
  - Créer `feature/ordres-transformation` à partir de cette branche.
  - Ce guide décrit **les écarts** par rapport à la v2.0. Tout ce qui n'est pas mentionné ici reste valable : modèle de données, calculs, coût, contrôles R1 à R6, conventions.
- Ne pas pousser sur `main`. Ouvrir une PR vers `main` et ne pas la fusionner : Patrice fusionne après essais.
- **Cible** : Dolibarr 20.0, PHP 8.2, OVH mutualisé, déploiement par FTP uniquement. Vérifier chaque signature dans la branche `20.0` de `Dolibarr/dolibarr`.
- Les conventions du §0 du premier guide restent obligatoires : `dol_include_once`, `hasRight`, hooks au format plat, constantes AJAX, transactions.
- La v2.0 n'a jamais été mise en production, donc **aucune migration de données**. Les tables peuvent être recréées. Il suffit de noter dans la PR que les données d'essai v2.0 sont à purger.

## 1. Pourquoi ce changement

Patrice a testé l'OF natif de type *Démontage*. Le fonctionnement est proche du besoin, mais il pose deux problèmes :

- il demande trop de manipulations ;
- les lots ne sont visibles qu'au moment de faire les mouvements de stock.

On garde donc notre propre objet, avec un **workflow façon OF** placé **dans le menu GPAO**.

Le module doit aussi devenir **générique**. Les lots Dolibarr servent à deux choses chez Diamant :

1. **Longueur** de pièce, sur les profilés comme `Horizon_D85a_PRE_BLANC_NP`.
2. **Livraison fournisseur / bain / teinte** : deux livraisons d'un même produit sont dans deux lots différents. Le nom du lot est libre (ex. `BL 2026-118`) et **ne contient pas de longueur**.

## 2. Écarts à appliquer

### 2.1 Nom de l'objet et menus

- Libellé utilisateur : **« Ordre de transformation »** (OT).
  - Référence : passer de `TRANSFO-AAMM-NNNN` à `OT-AAMM-NNNN`.
  - Le nom des tables et des classes ne change pas.
- **Supprimer** le hook `stockproductcard` et le bouton « Transformer » de la fiche produit. Retirer `stockproductcard` des `module_parts` et vider `actions_diamantutils.class.php`. Garder la classe vide, ou supprimer le fichier si aucun hook ne reste.
- **Supprimer** les menus sous Produits > Stocks.
- **Ajouter** les menus dans le menu haut **GPAO** (`mainmenu = 'mrp'`, cf. `core/menus/standard/eldy.lib.php` v20, l.2214 et suivantes) :
  - une entrée titre gauche « Ordres de transformation » : `fk_menu => 'fk_mainmenu=mrp'`, `leftmenu => 'diamantutils_ot'` ;
  - deux enfants, `fk_menu => 'fk_mainmenu=mrp,fk_leftmenu=diamantutils_ot'` :
    - « Nouvel ordre » → `transformation_card.php?action=create&mainmenu=mrp` ;
    - « Liste » → `transformation_list.php?mainmenu=mrp`.
- Le menu GPAO n'apparaît que si le module MRP ou BOM est actif. Ajouter `modMrp` dans `$this->depends`.
- Toutes les pages du module passent `mainmenu=mrp` pour garder le menu GPAO sélectionné.

### 2.2 Statuts et workflow

| Code | Statut | Ce qui est possible |
|---|---|---|
| 0 | **Brouillon** | Saisie et modification libres. Aucun impact sur le stock. |
| 1 | **Validé** | Lignes figées. Affichage du **résumé des mouvements**. Boutons **Consommer** et **Remettre en brouillon**. |
| 2 | **Consommé** | Mouvements de stock effectués. Bouton **Annuler** (droit `cancel`). |
| 9 | **Annulé** | Mouvements inverses effectués (depuis Consommé), ou abandon (depuis Brouillon ou Validé, sans mouvement). |

Découper l'actuelle `validate()` en deux méthodes :

- **`validate($user)`** : passe de 0 à 1.
  - Recalcule les quantités côté serveur.
  - Contrôles R3, R4 et R5 **bloquants**.
  - R6 (stock suffisant) **non bloquant** à cette étape : afficher un avertissement par ligne en défaut.
  - Aucun mouvement de stock.
- **`consume($user)`** : passe de 1 à 2. C'est le contenu actuel de `validate()` à partir des contrôles :
  - re-contrôle **R6 bloquant**, car le stock a pu bouger depuis la validation ;
  - calcul du coût ;
  - mouvements de stock ;
  - le tout dans une seule transaction.
  - Enregistrer `date_consume` et `fk_user_consume` : ajouter ces deux colonnes à la table.
- **`setDraft($user)`** : passe de 1 à 0.
- **`cancel($user)`** :
  - depuis 2 : mouvements inverses (comportement actuel), puis statut 9 ;
  - depuis 0 ou 1 : statut 9 seulement, sans mouvement.

Les codes de mouvement (`inventorycode`) restent la ref de l'OT, et la ref suffixée `-ANN` pour une annulation.

### 2.3 Écran de saisie (brouillon)

Le parcours doit coller à celui de Patrice : **produit → lots → cocher**, sans saisie de lot à la main côté consommation.

**Bloc « À consommer »**

1. Champ **Produit** (autocomplétion existante).
2. Dès que le produit est choisi, afficher **le tableau de ses lots en stock** dans l'entrepôt de l'OT. Colonnes :
   - case à cocher ;
   - lot ;
   - DLC/DLUO si renseignées ;
   - stock ;
   - nb de pièces équivalent (seulement en mode longueur, cf. §2.4) ;
   - champ **Quantité à consommer**, ou **Nb de pièces** en mode longueur.
3. Cocher un lot pré-remplit la quantité avec **tout le stock du lot**. On peut la réduire.
4. Lien « + Autre produit à consommer » pour ajouter un second produit, avec son propre tableau de lots.
5. Un produit sans gestion de lots affiche une seule ligne « sans lot » avec son stock.

**Bloc « À produire »**

1. Champ **Produit**, pré-rempli avec le premier produit consommé et modifiable (cas de la peinture A → B).
2. Afficher **les lots existants** de ce produit (en stock ou non : tous les lots de `llx_product_lot` pour ce produit) dans une liste déroulante, plus une option **« + Nouveau lot »** qui ouvre un champ texte.
   - **Mode longueur** : nb de pièces × longueur (mm). Le nom du nouveau lot est proposé selon `DIAMANTUTILS_TRANSFO_LOT_FORMAT` et reste modifiable. Si un lot existant porte la même longueur, il est présélectionné.
   - **Mode libre** (bain/teinte) : quantité directe. Le lot proposé par défaut est **le même nom que le lot consommé** s'il n'y a qu'un lot consommé (cas de la peinture : le bain suit la pièce), sinon « + Nouveau lot ».
3. Lien « + Ligne à produire » pour en ajouter d'autres.

**Bloc « Reste / perte »** : inchangé. Reliquat en direct, boutons « Reste en perte » et « Reste en stock », lignes LOSS. Indicateur d'équilibre vert/rouge, inchangé.

**Boutons du brouillon** : *Enregistrer*, *Enregistrer et valider*, *Supprimer*.

**Assistant de découpe** : à garder, mais seulement en mode longueur. Changer l'algorithme comme suit :

- pour chaque nouvelle barre à ouvrir, choisir parmi les longueurs de lot disponibles celle qui **minimise la chute**, où chute = L − min(⌊L / l⌋, pièces restantes) × l ;
- à chute égale, prendre la plus courte.

Exemple attendu sur le stock actuel, pour 10 × 1200 mm :

- 3 barres de 4000 mm donnent 9 pièces, avec une chute de 400 mm par barre ;
- puis 1 barre de 2000 mm donne la 10e pièce, avec une chute de 800 mm.

**Ne pas** donner 10 barres de 2000 mm.

### 2.4 Mode longueur par produit (remplace la détection automatique)

Aujourd'hui, la longueur est lue sur **tous** les lots (premier entier du nom). C'est faux pour un lot de bain : `BL 2026-118` serait lu comme une pièce de 2026 mm.

- Créer à l'activation un **extrafield produit** `diamantutils_lotlongueur` :
  - type `boolean` ;
  - libellé « Lot = longueur (DiamantUtils) » ;
  - via `ExtraFields::addExtraField()` dans `init()`, en vérifiant d'abord qu'il n'existe pas déjà.
- `diamantutils_product_info()` : `mode` vaut `surface` ou `size` **seulement si** l'extrafield est coché **et** que les conditions actuelles sont remplies (unité, largeur). Sinon :
  - `qty` pour une unité de type pièce ;
  - `direct` dans les autres cas.
- Hors mode longueur, ne jamais appeler `diamantutils_lot_length()`. Pas de nb de pièces équivalent, pas d'alerte orange.
- Page de configuration : afficher la liste des produits où l'option est cochée, avec un lien vers chaque fiche.

### 2.5 Résumé des modifications de stock (statut Validé)

Sur la fiche d'un OT validé, afficher en premier un tableau **« Mouvements prévus »**, une ligne par couple (produit, lot) :

| Produit | Lot | Stock actuel | Mouvement | Stock après | Remarque |
|---|---|---|---|---|---|
| Horizon_D85a | Longueur 4000mm | 2,4 | −1,2 | 1,2 | |
| Horizon_D85a | Longueur 1200mm | 0 | +0,9 | 0,9 | **Nouveau lot** |
| Horizon_D85a | — (perte) | | −0,04 | | Perte |

- Le stock actuel est lu en temps réel à l'affichage.
- Ligne en rouge si « Stock après » < 0, et le bouton *Consommer* est alors désactivé avec une info-bulle explicative.
- Afficher en dessous : le total consommé, le total produit et la perte (avec unité), et le **coût unitaire prévu** des sorties (PMP actuel des entrées réparti selon la règle existante).
- Sur un OT **consommé**, le même tableau devient **« Mouvements effectués »**. Il lit les mouvements réels (`fk_stock_mouvement`), avec un lien vers chacun et vers la liste filtrée sur la ref.

### 2.6 Liste des ordres

`transformation_list.php` :

- filtre par statut, avec les 4 statuts et les badges Dolibarr standard (brouillon, validé, consommé = statut 6, annulé = statut 9) ;
- colonnes : produit(s) consommé(s), produit(s) produit(s), commande client ;
- tri par défaut : date de création décroissante.

## 3. Scénarios de test (à lister dans la PR)

Les scénarios 1 à 9 du premier guide restent valables, avec le nouveau workflow Valider → Consommer. Ajouter :

10. **Workflow complet** : brouillon → valider (résumé affiché, aucun mouvement) → remettre en brouillon → modifier → valider → consommer.
    - Attendu : les mouvements n'apparaissent qu'après *Consommer*.
11. **Stock modifié entre validation et consommation** : valider un OT, consommer le lot ailleurs (correction manuelle), puis cliquer *Consommer*.
    - Attendu : refus avec un message clair, et une ligne rouge dans le résumé.
12. **Lot de bain** : produit en mode libre avec deux lots `BL 2026-118` et `BL 2026-131`.
    - Peinture A → B depuis `BL 2026-118`.
    - Attendu : aucune lecture de longueur, lot de sortie proposé `BL 2026-118`, équilibre contrôlé si même unité.
13. **Sortie vers lot existant** : produire dans un lot existant `Longueur 2000mm`.
    - Attendu : le stock du lot augmente, pas de doublon dans `llx_product_lot`.
14. **Assistant** : 10 × 1200 mm.
    - Attendu : 3 × 4000 + 1 × 2000 (cf. §2.3).
15. **Menu** : l'entrée apparaît dans GPAO et plus dans Produits > Stocks. Plus de bouton sur la fiche produit.

## 4. Nettoyage resté en suspens (v2.0)

- **Supprimer** `core/triggers/interface_99_modDiamantutils_DiamantutilsTriggers.class.php`. C'est l'ancien contrôle de facturation, il est toujours dans le dépôt.
- Version du module : passer à `2.1`.

## 5. Livraison

- Commits atomiques en français : menus, statuts, écran, mode longueur, résumé, liste, nettoyage.
- PR vers `main`, non fusionnée. Elle doit contenir :
  - le résumé des écarts ;
  - la liste des scénarios ;
  - la procédure de déploiement FTP : copier `diamantutils/` dans `htdocs/custom/`, puis désactiver / réactiver le module (tables, extrafield, menus, droits) ;
  - le rappel de **cocher « Lot = longueur »** et de renseigner la **largeur** sur les profilés concernés.
