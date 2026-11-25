# Changelog — DiamantUtils

## 2.14 — Tableau de stock par lots : stock physique, commandes, stock virtuel

- La colonne Quantité est remplacée par **Stock physique**, **Cdes client**, **Cdes fournisseur** et **Stock virtuel**.
- Par produit : stock physique tous entrepôts ; commandes client validées ou en cours moins les expéditions validées ; commandes fournisseur approuvées ou commandées moins les réceptions ; stock virtuel = physique − client + fournisseur. Calculs de la requête de suivi existante, avec filtre d'entité ; liens vers les commandes du produit.
- Par lot : stock physique hors entrepôts ignorés du colisage ; stock des entrepôts ignorés sur une ligne à part, pour que les lignes totalisent le stock physique du produit.
- Produits sans stock mais avec des commandes en cours désormais affichés.
- Produits parents de variantes exclus du tableau (et du décompte de l'onglet de configuration) ; leurs variantes restent affichées.
- Mise à jour : déposer les fichiers (aucune réactivation nécessaire).

## 2.13 — Tableau de stock par lots : données Dolibarr seules

- Colonnes **Lot, Colisage, Pièces par colis, Qté par pièce, Quantité** : attributs du lot et stock réel, à la place des lignes saisies (pièces, pièces par colis, largeur, longueur, fournisseur, note) et du comparatif (écart, états, tolérance, filtre « écarts seulement »).
- Quantité d'un lot : stock réel hors entrepôts ignorés du colisage (`DIAMANTUTILS_STOCKCOLISAGE_EXCLUDE_WAREHOUSES`), la même que celle du colisage enregistré. Stock non rattaché à un lot affiché sur une ligne à part. Produits sans stock non affichés.
- Pièces par colis, qté par pièce et colisage manuel modifiables dans le tableau, avec le même mécanisme et les mêmes droits que l'onglet Stock (`js/stockcolisage.js` partagé, `ajax/lotcolisage.php`).
- Conservés : tags et sous-catégories, sections, recherche (produit, lot, colisage), filtre par tag, repli mémorisé.
- Supprimés : `ajax/stocktable.php`, `class/stockline.class.php`, `test/stocktable_calc_*.php`, droits `stocktable` *write* et *delete*, réglages tolérance et entrepôts exclus du tableau. **La table `llx_diamantutils_stockline` et ses lignes sont conservées** (plus affichées ; nombre rappelé dans l'onglet de configuration).
- Correction (onglet Stock, v2.12) : le message « colisage saisi à la main remplacé » affichait un texte vide au lieu de l'ancien colisage.
- Mise à jour : déposer les fichiers, **supprimer sur le serveur** `ajax/stocktable.php`, `class/stockline.class.php`, `test/stocktable_calc_cases.php` et `test/stocktable_calc_test.php` (un dépôt FTP ne les retire pas), puis **désactiver et réactiver le module** (droits).

## 2.12 — Colisage calculé et tenu à jour

- Nouveaux attributs du lot **Pièces par colis** (`pieces_par_colis`, entier) et **Qté par pièce** (`qte_par_piece`, décimal 24,8), créés à l'activation du module (idempotent).
- Les deux renseignés : `colisage` calculé à partir du stock réel total du lot (entrepôts ignorés exclus, par défaut Sous-traitance) et enregistré dans l'attribut (`6 colis de 28px`, `1 colis de 28px + 1 colis de 2px`, `1 colis de 6px`). Quantité non multiple de la pièce ou négative : texte vide et avertissement, jamais de texte faux. Fonction unique `diamantutils_colisage_compute()`.
- Trigger `STOCK_MOVEMENT` : recalcul du lot à chaque mouvement, dans la transaction du mouvement ; erreurs journalisées, jamais bloquantes ; traitement du prix de revient inchangé et indépendant. Écriture directe de l'attribut, seulement si la valeur change.
- Onglet Stock : colonnes Pièces par colis et Qté par pièce modifiables en ligne, recalcul immédiat sans rechargement, colisage calculé en lecture seule (texte par ligne avec la quantité de la ligne), avertissements ; auto-réparation de la valeur enregistrée à l'affichage (`LOG_WARNING`).
- `ajax/lotcolisage.php` : paramètre `field` (`colisage`, `pieces_par_colis`, `qte_par_piece`), validation serveur, colisage manuel refusé sur un lot calculé.
- Onglet **Stock produit** : `DIAMANTUTILS_STOCKCOLISAGE_AUTO` (oui), `DIAMANTUTILS_STOCKCOLISAGE_EXCLUDE_WAREHOUSES`, état des attributs, bouton **Recalculer tous les colisages** avec rapport.
- Test `test/stockcolisage_compute_test.php` (navigateur, administrateur).
- Mise à jour : déposer les fichiers, **désactiver puis réactiver le module** (attributs), renseigner les lots, lancer **Recalculer tous les colisages**.

## 2.11 — Colisage sur l'onglet Stock de la fiche produit

- Nouvelle fonctionnalité `DIAMANTUTILS_STOCKCOLISAGE_ENABLED` (désactivée par défaut), onglet **Stock produit** de la configuration, avec le code de l'attribut du lot (`DIAMANTUTILS_STOCKCOLISAGE_EXTRAFIELD`, `colisage`) et l'état de l'attribut, du module Lot/série et du hook.
- Fiche produit, onglet Stock, produit géré par lots : tableau du module à la place du tableau standard (Entrepôt / Lot, Colisage, Quantité, Transférer stock, Corriger le stock), sans les colonnes DLC, DMD/DLUO, PMP, valorisation, prix et valeur à la vente. Quantités, total, liens d'action, repli des lots et avertissements identiques au cœur. Produits sans lots inchangés.
- Colisage modifiable en ligne (droit produit / créer) : Entrée ou sortie du champ = enregistrer, Échap = annuler, sans rechargement. Valeur enregistrée échappée ; valeur HTML saisie sur la fiche lot affichée aplatie avec avertissement. Fiche lot créée à la première saisie pour un lot en stock qui n'en a pas.
- `ajax/lotcolisage.php` : POST, jeton CSRF vérifié, droits et fonctionnalité contrôlés (403), lot retrouvé côté serveur par (produit, lot, entité), refus motivés.
- Repli de sécurité : le tableau standard n'est masqué qu'après l'affichage du tableau du module ; toute erreur laisse le tableau standard visible (log `LOG_ERR`). Constante de contrôle `DIAMANTUTILS_STOCKCOLISAGE_FORCE_ERROR`.
- Nouveau contexte de hook `stockproductcard` (méthode `addMoreActionsButtons`). Cœur de Dolibarr non modifié ; liens compatibles Dolibarr 23 (action `transfer`).
- Mise à jour : déposer les fichiers, **désactiver puis réactiver le module**, puis activer la fonctionnalité dans l'onglet.

## 2.10 — Tableau de stock par lots

- Nouvelle fonctionnalité `DIAMANTUTILS_STOCKTABLE_ENABLED` (désactivée par défaut), onglet **Tableau de stock** de la configuration : remplace la base Airtable « Stock Bobine ».
- Nouvelle table `llx_diamantutils_stockline` (produit, lot, fournisseur facultatif, pièces, pièces par colis, largeur, longueur, note), sans contrainte d'unicité (un lot peut occuper plusieurs lignes). Classe `DiamantutilsStockLine` : toute écriture est validée côté serveur (produit des tags configurés, valeurs > 0, entiers sauf produit au mètre, dimensions exigées par l'unité, lot connu de Dolibarr sauf « autre lot » explicite).
- `lib/stocktable.lib.php` : colisage (« 5 colis de 28u », « 1 colis de 28u + 1 colis de 2u »), quantité dans l'unité du produit (surface, longueur, pièce ; unités impériales refusées), états de comparaison. Les quantités calculées ne sont jamais stockées.
- Périmètre : produits des tags (catégories produit) choisis, sous-catégories comprises en option (`DIAMANTUTILS_STOCKTABLE_CATEGORIES`, `DIAMANTUTILS_STOCKTABLE_SUBCATEGORIES`). Aucun tag = aucun produit.
- Comparatif : écart toléré `DIAMANTUTILS_STOCKTABLE_TOLERANCE` (0,01) ; entrepôts ignorés `DIAMANTUTILS_STOCKTABLE_EXCLUDE_WAREHOUSES` (par défaut l'entrepôt Sous-traitance).
- Nouveaux droits `stocktable` : *read*, *write*, *delete*. Menu **Produits → Stock → Tableau de stock par lots**.
- Écran `stocktable_list.php` : groupes repliables par produit, totaux Tableau / Dolibarr / Écart, état par lot (OK, Écart, Dolibarr seul, Tableau seul, Non calculable), part du stock Dolibarr sans lot, produits sortis des tags affichés « hors périmètre » sans modification possible. Recherche instantanée, filtre « écarts seulement », filtre par tag, tout replier / déplier (mémorisé dans le navigateur), en-tête figé. Une section repliable par tag choisi (produits des sous-catégories dans la section de leur tag, produit de plusieurs tags affiché une seule fois), avec nombre de produits et d'écarts ; section « Hors périmètre » à la fin. Données en 4 requêtes pour tout l'écran.
- Saisie en ligne (`ajax/stocktable.php`, `js/stocktable.js`) : « + » par produit (produit fixé), « + Produit » limité aux tags, lot choisi dans la liste des lots Dolibarr avec leur stock (« Autre lot » explicite avec avertissement), pré-remplissage depuis la dernière ligne du produit, colisage et quantité calculés pendant la frappe, modification, suppression, « Ajouter au tableau » d'un lot Dolibarr seul. POST, jeton CSRF vérifié, droits contrôlés côté serveur.
- Aucun mouvement de stock, aucun hook, trigger ni extrafield.
- Test `test/stocktable_calc_test.php` (navigateur, administrateur, aucune donnée lue ni écrite).
- Mise à jour : déposer les fichiers, **désactiver puis réactiver le module** (table, droits, menu), accorder les droits `stocktable`, choisir les tags dans l'onglet, puis activer la fonctionnalité.

## 2.9 — Liaison des commandes fournisseurs entre elles

- Nouvelle option `DIAMANTUTILS_LINK_SUPPLIER_ORDERS` (désactivée par défaut), onglet **Liaison commandes fournisseurs** : « Permettre de lier une commande fournisseur à une autre commande fournisseur (tous fournisseurs) ».
- Fiche commande fournisseur, bloc Objets liés : entrée **Lier à une commande fournisseur** dans le menu « Lier à… », sans filtre sur le tiers (commande de transport ↔ commandes enlevées). Hook `showLinkToObjectBlock`, clé `supplierorder` (la fiche exclut `order_supplier` du menu), lien enregistré en `order_supplier`.
- Liste limitée aux N derniers mois (`DIAMANTUTILS_LINK_SUPPLIER_ORDERS_MONTHS`, 24 par défaut, 0 = sans limite), hors commande ouverte, annulées, refusées et déjà liées.
- Compatible avec `MAIN_LINK_BY_REF_IN_LINKTO` : saisie par référence traitée par le hook `doActions` (la saisie native ne connaît pas la clé). Doublon refusé dans les deux sens.
- Nouveau contexte de hook `ordersuppliercard`.
- Mise à jour : déposer les fichiers, puis **désactiver et réactiver le module**, puis activer l'option dans l'onglet.

## 2.8 — Prix de revient à la réception fournisseur

- Nouvelle option `DIAMANTUTILS_COSTPRICE_FROM_RECEIPT` (désactivée par défaut), onglet **Prix de revient** de la configuration : « Mettre à jour le prix de revient avec le prix de réception fournisseur ».
- Trigger `STOCK_MOVEMENT` : à chaque entrée en stock issue d'une réception fournisseur (type 3, quantité > 0, origine `reception` ou `order_supplier`, prix > 0), `llx_product.cost_price` prend le prix unitaire du mouvement, c'est-à-dire le prix rendu saisi à la réception. Ancien et nouveau prix tracés dans le log (`LOG_DEBUG`).
- Inchangés : prix fournisseurs, lignes de commande et de facture fournisseur, PMP (déjà calculé par Dolibarr avec ce prix).
- Prérequis Dolibarr : `SUPPLIER_ORDER_EDIT_BUYINGPRICE_DURING_RECEIPT = 1` (contrôlé dans l'onglet), case « mettre à jour le prix fournisseur » décochée à la réception.
- Mise à jour : déposer les fichiers ; la constante vaut 0 tant qu'elle n'est pas activée dans l'onglet (désactiver / réactiver le module n'est pas nécessaire).

## 2.7 — Description pour devis : calculs

- Balises de calcul `{=EXPRESSION}` et `{=EXPRESSION ;N}` dans la description pour devis : `+ - * /`, parenthèses, moins unaire, nombres à virgule ou point, attributs de variante (valeur ou `:ref`) numériques. Exemple : `{=MODULE:ref-5}` donne `595` pour un module de réf. 600.
- Résultat : au plus 2 décimales sans zéros inutiles, ou exactement N décimales arrondies avec `;N` (`{=MODULE:ref/2 ;2}` donne `300,00`). Virgule décimale.
- Parseur dédié, sans `eval()`. Calcul impossible (valeur non numérique, attribut absent, division par zéro, syntaxe) : balise laissée telle quelle, avertissement dans le log.
- Vérification à l'enregistrement du produit : syntaxe des calculs et attributs utilisés.
- Nouveaux cas dans `test/descpropal_render_cases.php`.

## 2.6 — Lots de produits (devis et commandes client)

- Nouvelles tables `llx_diamantutils_lot` (libellé, actif) et `llx_diamantutils_lot_det` (rang, produit, quantité ; clé étrangère avec suppression en cascade). Classe `DiamantLot` (CommonObject) et ses lignes.
- Nouveaux droits `lot` : *read* (voir et utiliser), *write* (créer / modifier), *delete* (supprimer). Lots partagés par l'entité, gérés sans accès à la configuration du module.
- Menu **Commerce → Lots de produits** (Nouveau lot, Liste) : liste paginée avec recherche sur le libellé ; fiche avec lignes produit / quantité, modification de la quantité, ordre, suppression, ajout en bas du tableau.
- Devis et commandes client en brouillon (hooks `propalcard`, `ordercard`) : ligne **Ajouter un lot** au-dessus du formulaire standard (lot actif + multiplicateur). Insertion par `addline()` triggers actifs, prix et TVA par `getSellPrice()` (multiprix, prix client ; 0 sans prix), remise par défaut du tiers, description standard (langue du tiers comme en natif), dans une transaction : tout ou rien. Jeton CSRF vérifié. PDF régénéré comme après un ajout manuel.
- Compatible avec la description pour devis : les lignes d'un lot reçoivent `desc_propal` via `LINEPROPAL_INSERT`.
- Bouton **Enregistrer comme lot** sur devis et commandes (tout statut, droit *write*) : lot créé avec les lignes produit de la pièce, lignes libres ignorées.
- Coexistence avec productselector : `formAddObjectLine` affiche par `print` et retourne 0. Les méthodes du hook vident `resprints` en entrée (instance partagée par tous les hooks de la page).
- Onglet de configuration **Lots de produits** (`DIAMANTUTILS_LOT_ENABLED`, active par défaut).
- Test d'intégration `test/lot_insert_test.php` (navigateur, administrateur, aucune donnée conservée).
- Mise à jour : déposer les fichiers, puis désactiver et réactiver le module (tables, droits, menu, hooks), et accorder les nouveaux droits.

## 2.5 — Description pour devis (propositions commerciales)

- Nouvel attribut produit `desc_propal` « Description pour devis » (texte HTML), sur la fiche produit et service, hors listes. Créé à l'activation du module (idempotent), conservé à la désactivation.
- Fonction activable dans un nouvel onglet **Description devis** de la configuration (`DIAMANTUTILS_DESC_PROPAL`, désactivée par défaut), avec deux modes (`DIAMANTUTILS_DESC_PROPAL_MODE`) : `REPLACE` (défaut) remplace la description de la ligne, `PREPEND` place la description pour devis avant.
- Trigger `LINEPROPAL_INSERT` : à l'ajout d'un produit à une proposition commerciale, la ligne reprend `desc_propal` si elle est renseignée et si la description de la ligne est vide ou identique à la description standard du produit (traductions et mention code douane / pays d'origine comprises).
- Lignes conservées telles quelles : description modifiée à la main, clonage de propale, création depuis un autre objet, import de lignes d'un objet lié, lignes libres.
- Variantes : une variante sans `desc_propal` propre prend celle de son produit parent. Placeholders remplacés par les valeurs de la variante : `{REF_ATTRIBUT}` (valeur), `{REF_ATTRIBUT:ref}` (réf. de la valeur), `{REF_ATTRIBUT:label}` (libellé de l'attribut), `{PRODUCT_REF}`, `{PRODUCT_LABEL}`. Insensibles à la casse ; valeurs échappées dans un modèle HTML ; placeholder inconnu laissé tel quel avec un avertissement dans le log.
- Fiche d'un produit parent : liste des placeholders disponibles sous l'attribut (hook `productcard`).
- Blocs conditionnels `{IF …}{ELSEIF …}{ELSE}{ENDIF}` (imbrication autorisée) évalués sur les attributs de la variante : `= != > >= < <=`, `IN`, présence, `AND` / `OR` (`AND` prioritaire), suffixe `:ref`, comparaison numérique avec virgule décimale. Parseur dédié, sans `eval()`. Attribut inconnu : condition fausse ; syntaxe invalide : modèle laissé brut ; avertissement dans le log dans les deux cas.
- Enregistrement d'un produit (`PRODUCT_CREATE`, `PRODUCT_MODIFY`) : syntaxe et attributs de `desc_propal` vérifiés, avertissement non bloquant.
- Tests du rendu : `test/descpropal_render_test.php`, à ouvrir dans le navigateur en administrateur.
- Mise à jour : déposer les fichiers, puis désactiver et réactiver le module.

## 2.4 — Configuration par onglets

- Page de configuration du module : un onglet par fonctionnalité (Ordres de transformation, Sous-traitance, Listes personnalisées), avec pastille d'état dans le titre de l'onglet.
- Chaque onglet a une option d'activation et ses réglages propres ; chaque onglet n'enregistre que ses réglages.
- Nouvelles constantes `DIAMANTUTILS_TRANSFO_ENABLED` et `DIAMANTUTILS_CUSTOMLIST_ENABLED` (actives par défaut, y compris sur une installation existante). Désactivée, une fonctionnalité masque ses menus et refuse ses pages ; les données sont conservées.
- Listes personnalisées : plafond de l'export CSV réglable (`DIAMANTUTILS_CUSTOMLIST_EXPORT_MAX`, défaut 50 000).
- Mise à jour : désactiver puis réactiver le module (conditions d'affichage des menus).

## 2.3.1 — Listes personnalisées : variables `{{entity}}` / `{{prefix}}`

- Les requêtes acceptent `{{entity}}` et `{{prefix}}` (casse et espaces libres) en plus de `__ENTITY__` / `__PREFIX__`. Auparavant `{{entity}}` était envoyé tel quel à MySQL (erreur de syntaxe).
- Une variable `{{…}}` inconnue est refusée avec un message listant les variables disponibles.

## 2.3 — Listes personnalisées (menu Outils)

- Nouvelle entrée **Outils → Listes personnalisées** : listes générées par des requêtes SQL enregistrées.
- Table `llx_diamantutils_customlist` (libellé, description, requête, position, active).
- Gestion (création, modification, suppression avec confirmation, test de la requête sur 10 lignes) réservée aux administrateurs ayant le droit *Gérer les listes personnalisées*.
- Consultation avec le droit *Consulter les listes personnalisées* : recherche globale, tri par colonne, pagination, export CSV (`;`, BOM UTF-8, décimales à la virgule, 50 000 lignes maximum).
- Sécurité : une seule requête `SELECT`/`WITH`, mots-clés d'écriture et fonctions dangereuses refusés, exécution dans une transaction en lecture seule annulée ensuite.
- Variables `__PREFIX__` (préfixe des tables) et `__ENTITY__` (entité courante).
- Mise à jour : désactiver puis réactiver le module.

## 2.2 — Commandes sous-traitées (livraison directe client)

Fonctionnalité activable dans la configuration du module (`DIAMANTUTILS_SOUSTRAITANCE_ENABLED`, désactivée par défaut). Désactivée, aucun hook ni trigger n'agit.

### Installation (activation du module, idempotente)
- Extrafield `diam_livraison_directe` « Livraison directe client » sur les commandes fournisseur : OUI/NON, obligatoire, NON par défaut, modifiable après validation. Les commandes existantes passent à NON.
- Entrepôt `SOUS-TRAITANCE` créé ou réutilisé, id enregistré dans `DIAMANTUTILS_SOUSTRAITANCE_WAREHOUSE_ID`.
- La désactivation du module conserve l'extrafield et l'entrepôt.
- Page de configuration : activation, choix de l'entrepôt, état de l'installation.

### Réceptions (garde-fou bloquant)
- CF à OUI : réception sur l'entrepôt Sous-traitance, lot = ref de la CF (produits à lots).
- CF à NON : réception sur l'entrepôt Sous-traitance interdite.
- Triggers : `RECEPTION_CREATE`, `RECEPTION_VALIDATE`, `LINERECEPTION_MODIFY`, `LINERECEPTION_CREATE` (hors création de réception), `LINEORDER_SUPPLIER_DISPATCH` (ventilation directe, lignes au-dessus du `MAX(rowid)` mémorisé dans `doActions`).

### Réceptions (pré-remplissage)
- `dispatch.php` et `reception/card.php` : entrepôt Sous-traitance présélectionné, y compris « Forcer vers », lot = ref CF, bandeau d'information, lignes éclatées comprises.

### Expéditions (garde-fou bloquant)
- Ligne prélevée sur l'entrepôt Sous-traitance : le lot doit être la ref d'une CF à OUI liée à la commande client.
- Lot d'une CF à OUI non liée : message « liez-la depuis la commande fournisseur (onglet Objets liés) puis recommencez ».
- Triggers : `SHIPPING_CREATE`, `SHIPPING_VALIDATE`, `LINESHIPPING_MODIFY`.

### Expéditions (pré-remplissage)
- Onglet Expéditions de la commande : entrepôt Sous-traitance présélectionné, bandeau des lots CF disponibles.
- Création du BL : bandeau, lots non liés signalés.

### Suivi
- Page « Sous-traitance en cours » (Produits/Stocks), droit `soustraitance` / `read` (51012504).
- Anomalies : quantité négative, lot sans CF, CF à NON, CF sans commande client liée.
- Widget d'accueil « Sous-traitance en cours ».

### Corrections pendant la recette
- Erreur HTTP 500 sur refus de réception : le constructeur du trigger n'appelait pas celui de `DolibarrTriggers`, qui déclare `$errors` sans valeur en v20.

## 2.1 — Ordres de transformation
- Ordres de transformation de stock par lots dans le menu GPAO : workflow brouillon → validé → consommé → annulé.
- Option produit « Lot = longueur » (saisie pièces × longueur).
- Création d'un ordre pré-rempli depuis une ligne de commande.
- Suppression de l'ancien contrôle de facturation.

## 2.0
- Première version des transformations de stock par lots.
