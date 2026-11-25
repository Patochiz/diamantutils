# DiamantUtils

Module Dolibarr interne de Diamant Industrie (Dolibarr 20, PHP 8.2, déploiement FTP).

- **Ordres de transformation** de stock par lots (menu GPAO) : voir `GUIDE_TRANSFORMATION_V2.md`.
- **Commandes sous-traitées** (livraison directe client) : section ci-dessous.
- **Listes personnalisées** (menu Outils) : listes générées par des requêtes SQL, section ci-dessous.
- **Description pour devis** : description produit propre aux propositions commerciales, section ci-dessous.
- **Lots de produits** (menu Commerce) : ensembles de produits ajoutés en une fois sur un devis ou une commande client, section ci-dessous.
- **Prix de revient à la réception** : le prix de revient du produit prend le prix rendu saisi à chaque réception fournisseur, section ci-dessous.
- **Liaison des commandes fournisseurs** : lier une commande fournisseur à une commande d'un autre fournisseur (transport ↔ marchandise), section ci-dessous.
- **Tableau de stock par lots** (menu Produits → Stock) : pour les produits des tags choisis, stock physique, commandes en cours et stock virtuel, et lots en stock avec colisage, pièces par colis et qté par pièce, données Dolibarr seules, section ci-dessous.
- **Colisage sur l'onglet Stock** de la fiche produit : pour un produit à lots, tableau du stock sans dates ni valorisations, avec le colisage du lot modifiable directement ou **calculé et tenu à jour** à chaque mouvement de stock, section ci-dessous.

Chaque fonctionnalité a son onglet dans **Configuration → Modules → DiamantUtils**, avec une option pour l'activer ou la désactiver et ses réglages propres. Une fonctionnalité désactivée masque ses menus et pages ; ses données sont conservées.

Historique des versions : `CHANGELOG.md`.

---

## Commandes sous-traitées (livraison directe client)

### Principe

Le sous-traitant livre directement notre client et a besoin de notre BL dès la commande fournisseur. Dolibarr n'autorise un BL sur un produit à lots que si le lot a du stock. Le stock transite donc par un entrepôt dédié :

1. Commande fournisseur (CF) avec **« Livraison directe client » = OUI**.
2. Réception anticipée de la CF sur l'entrepôt **Sous-traitance**, avec **lot = ref de la CF** (ex. `CF2610-0012`).
3. BL client créé depuis ce lot et laissé en **brouillon**. Son PDF est envoyé au sous-traitant.
4. Quand le sous-traitant a livré, on **valide le BL** : le lot repasse à 0.

En régime normal, l'entrepôt Sous-traitance est à 0. Un lot non nul = CF réceptionnée dont le BL n'est pas validé.

### Mise en service

1. Envoyer les fichiers par FTP dans `custom/diamantutils/`.
2. Désactiver puis réactiver le module : cela crée l'extrafield et l'entrepôt, et enregistre hooks, triggers, droit, menu et widget.
3. Configuration du module → section « Commandes sous-traitées » : activer, vérifier l'entrepôt et l'état de l'installation.
4. Accorder le droit « Consulter les commandes sous-traitées en cours » aux utilisateurs non administrateurs.

### Ce que fait le module

| Étape | Contrôle bloquant | Aide à la saisie |
|---|---|---|
| Réception d'une CF à OUI | entrepôt = Sous-traitance, lot = ref CF | entrepôt et lot pré-remplis, bandeau |
| Réception d'une CF à NON | entrepôt Sous-traitance interdit | — |
| BL prélevé sur Sous-traitance | lot = ref d'une CF à OUI **liée** à la commande client | entrepôt présélectionné, lots CF disponibles, lots non liés signalés |
| Suivi | — | page « Sous-traitance en cours », widget d'accueil |

Une CF à OUI doit être liée à la commande client : c'est automatique avec « Créer → Commande fournisseur » depuis la commande client. Sinon, la lier depuis l'onglet « Objets liés » de la CF.

Les contrôles tournent dans la transaction Dolibarr : un refus annule l'opération et ses mouvements de stock.

### Limites connues

- Dans un BL brouillon, un lot ajouté sur une **nouvelle** ligne d'entrepôt n'est contrôlé qu'à la validation : le core n'appelle pas de trigger après l'insertion du lot.
- La création d'un BL ne modifie aucune quantité. Si Dolibarr propose une quantité sur un lot non lié, il faut la corriger, sinon le contrôle bloque.

### Fichiers

| Fichier | Rôle |
|---|---|
| `core/modules/modDiamantutils.class.php` | installation, hooks, triggers, droit, menu, widget |
| `lib/soustraitance.lib.php` | état de la fonctionnalité, installation idempotente |
| `class/soustraitance.class.php` | règles de contrôle et requêtes de suivi |
| `core/triggers/interface_99_modDiamantutils_Diamantutilstriggers.class.php` | garde-fous |
| `class/actions_diamantutils.class.php`, `js/soustraitance.js` | pré-remplissage et bandeaux |
| `soustraitance_list.php`, `core/boxes/box_diamantutils_soustraitance.php` | suivi |
| `admin/setup.php` | configuration |

---

## Listes personnalisées (menu Outils)

### Principe
Un administrateur enregistre des requêtes SQL `SELECT` ; chaque requête devient une liste consultable depuis **Outils → Listes personnalisées**, avec recherche globale, tri par colonne, pagination et export CSV (séparateur `;`, compatible Excel, 50 000 lignes maximum par défaut, réglable).

### Mise en service
1. Désactiver puis réactiver le module (création de la table `llx_diamantutils_customlist`, des droits et des menus).
2. Donner les droits dans la fiche utilisateur ou groupe :
   - *Consulter les listes personnalisées* : voir et exporter les listes actives ;
   - *Gérer les listes personnalisées* : créer, modifier, supprimer. Réservé en plus aux **administrateurs** (le SQL donne accès en lecture à toute la base).

### Écrire une requête
- Une seule requête `SELECT` ou `WITH`. Mots-clés d'écriture (`INSERT`, `UPDATE`, `DELETE`, `DROP`, `SET`…) et fonctions dangereuses (`SLEEP`, `LOAD_FILE`, `INTO OUTFILE`…) refusés, y compris dans un nom de colonne non entre guillemets.
- La requête s'exécute dans une transaction **en lecture seule**, annulée ensuite.
- `{{prefix}}` (ou `__PREFIX__`) est remplacé par le préfixe des tables (`llx_`) et `{{entity}}` (ou `__ENTITY__`) par l'entité courante. Une variable inconnue est signalée avant exécution.
- Donner un alias à chaque colonne : il sert d'en-tête. Deux colonnes de même nom empêchent le tri.
- Le bouton **Tester la requête** affiche les 10 premières lignes avant enregistrement.

Exemple :
```sql
SELECT p.ref AS "Référence", p.label AS "Libellé", p.stock AS "Stock"
FROM __PREFIX__product AS p
WHERE p.entity = __ENTITY__ AND p.tosell = 1
ORDER BY p.ref
```

### Fichiers

| Fichier | Rôle |
|---|---|
| `sql/llx_diamantutils_customlist*.sql` | table des listes |
| `class/customlist.class.php` | enregistrement, contrôle et exécution en lecture seule |
| `customlist_index.php` | sommaire et gestion (menu Outils) |
| `customlist_card.php` | création, modification, test de la requête |
| `customlist_view.php` | affichage, recherche, tri, pagination, export CSV |

---

## Description pour devis (propositions commerciales)

### Principe
La fiche produit (et service) porte un attribut **« Description pour devis »** (`desc_propal`, texte HTML), distinct de la description standard, comme la description fournisseur l'est pour les commandes fournisseur. Quand un produit est ajouté à une proposition commerciale, la ligne reprend cette description si elle est renseignée.

### Mise en service
1. Désactiver puis réactiver le module : l'attribut produit est créé (sans doublon s'il existe déjà).
2. Configuration du module → onglet **Description devis** : activer et choisir le mode :
   - **Remplacer** (`REPLACE`, défaut) : la description de la ligne devient la description pour devis ;
   - **Placer avant** (`PREPEND`) : la description pour devis précède la description standard.

### Variantes et placeholders
Saisir la description pour devis sur le **produit parent** seulement : une variante sans description pour devis propre prend celle de son parent. Une variante qui a la sienne l'utilise (exception ponctuelle).

| Placeholder | Remplacé par |
|---|---|
| `{REF_ATTRIBUT}` | valeur de l'attribut sur la variante (ex. `Laqué`) |
| `{REF_ATTRIBUT:ref}` | référence de la valeur (ex. `LAQ`) |
| `{REF_ATTRIBUT:label}` | libellé de l'attribut (ex. `Finition`) |
| `{PRODUCT_REF}`, `{PRODUCT_LABEL}` | réf. et libellé de la variante |

`REF_ATTRIBUT` est la référence de l'attribut (Produits → Attributs de variantes), sans distinction de casse. Exemple : `Profilé {FINITION}, coloris {COULEUR}` donne `Profilé Laqué, coloris RAL 9010`. Un placeholder inconnu reste visible tel quel dans le devis (avertissement dans le log Dolibarr). La fiche du produit parent liste les placeholders disponibles sous l'attribut.

### Conditions
Des blocs conditionnels choisissent le texte selon la variante (mots-clés sans distinction de casse, `ELSEIF` et `ELSE` facultatifs, blocs imbriqués autorisés) :

```
{IF LARGEUR=200}Largeur 200 avec portée 2,13 m
{ELSEIF LARGEUR=300}Largeur 300 avec portée 2,50 m
{ELSE}Largeur {LARGEUR}
{ENDIF}
```

| Condition | Exemple |
|---|---|
| Comparaison | `LARGEUR=200`, `LARGEUR!=200`, `LARGEUR>=300`, `LARGEUR<600` |
| Liste | `LARGEUR IN 200,300,400` |
| Présence | `COULEUR` : attribut présent et non vide |
| Combinaison | `LARGEUR=200 AND FINITION=Laqué`, `A=1 OR B=2` (`AND` prioritaire, pas de parenthèses) |

- `COULEUR:ref=9010` compare la référence de la valeur au lieu de son libellé.
- Deux nombres sont comparés numériquement (`200` = `200,0`) ; sinon comparaison de textes sans casse ni espaces autour. Guillemets facultatifs : `FINITION="Laqué brillant"`.
- Les conditions sont évaluées d'abord, puis les placeholders du texte retenu.
- Attribut inconnu dans une condition : condition fausse. Syntaxe invalide (`ENDIF` manquant, `ELSE` orphelin…) : le modèle est repris **brut** sur le devis, pour que l'erreur se voie à la relecture. Dans les deux cas, avertissement dans le log Dolibarr.
- À l'enregistrement du produit, une erreur de syntaxe ou un attribut inconnu des variantes est signalé par un avertissement (le produit est enregistré quand même).
- Tests : ouvrir `custom/diamantutils/test/descpropal_render_test.php` en administrateur (aucune écriture en base).

### Calculs
Une balise qui commence par `=` calcule un nombre à partir des attributs de la variante :

| Modèle | Variante | Résultat |
|---|---|---|
| `{=MODULE:ref-5}` | réf. de MODULE = 600 | `595` |
| `{=(LARGEUR-5)*2}` | LARGEUR = 600 | `1190` |
| `{=LARGEUR*LONGUEUR/1000}` | 600 et 1200 | `720` |
| `{=LARGEUR/4}` | LARGEUR = 595 | `148,75` |
| `{=MODULE:ref/2 ;2}` | réf. de MODULE = 600 | `300,00` |
| `{=LARGEUR/3 ;0}` | LARGEUR = 1000 | `333` |

- Opérateurs `+ - * /` et parenthèses, avec les priorités habituelles (`*` et `/` avant `+` et `-`). Nombres à virgule ou à point (`0,5`, `0.5`).
- `ATTRIBUT` prend la valeur, `ATTRIBUT:ref` la référence de la valeur ; elle doit être un nombre (virgule décimale acceptée). Les noms d'attributs peuvent contenir `%`, `_`, `Ø`…, mais pas `+ - * / ( ) : ;`.
- Résultat : sans `;N`, au plus 2 décimales sans zéros inutiles ; avec `;N`, arrondi à exactement N décimales. Virgule décimale, pas de séparateur de milliers.
- Le texte hors des accolades n'est pas calculé : `{MODULE:ref}-5` donne `600-5`.
- Calcul impossible (valeur non numérique, attribut absent, division par zéro, syntaxe) : la balise reste visible telle quelle sur le devis, avec un avertissement dans le log. À l'enregistrement du produit, une erreur de syntaxe ou un attribut inconnu est signalé.
- Les calculs s'utilisent dans le texte, y compris dans une branche `{IF}`, mais pas encore dans les conditions.

### Règles
- La ligne n'est modifiée que si sa description est vide ou identique à la description standard du produit (ou à l'une de ses traductions). La mention « (Code douane … / Pays d'origine …) » ajoutée par Dolibarr est conservée.
- Ne sont jamais modifiées : une description changée à la main dans le formulaire, les lignes d'une propale clonée, créée depuis un autre objet ou importée d'un objet lié, les lignes libres.
- Produit sans description pour devis : description standard conservée.
- La désactivation du module conserve l'attribut et ses données.

### Fichiers

| Fichier | Rôle |
|---|---|
| `lib/descpropal.lib.php` | installation de l'attribut, choix parent / variante, règles de remplacement |
| `lib/diamantutils_descpropal.lib.php` | rendu du modèle (`diamantutils_render_desc()` : conditions puis placeholders et calculs), vérification, attributs de variante |
| `class/actions_diamantutils.class.php` | aide à la saisie sur la fiche produit parent |
| `test/descpropal_render_test.php`, `test/descpropal_render_cases.php` | tests du rendu (navigateur, administrateur) |
| `core/triggers/interface_99_modDiamantutils_Diamantutilstriggers.class.php` | triggers `LINEPROPAL_INSERT`, `PRODUCT_CREATE` / `PRODUCT_MODIFY` (vérification) |
| `admin/setup.php`, `lib/diamantutils.lib.php` | onglet de configuration |
| `core/modules/modDiamantutils.class.php` | constantes, création de l'attribut à l'activation |

---

## Lots de produits (devis et commandes client)

### Principe
Un lot est une liste prédéfinie de produits avec leur quantité (ex. « Plafond 10 m² » : profilés, suspentes, dalles). Sur un devis ou une commande client **en brouillon**, une ligne **Ajouter un lot de produits** au-dessus du formulaire d'ajout standard permet d'insérer tout le lot en une fois, avec un **multiplicateur** des quantités (défaut 1).

### Mise en service
1. Envoyer les fichiers par FTP dans `custom/diamantutils/`.
2. Désactiver puis réactiver le module : cela crée les tables `llx_diamantutils_lot` et `llx_diamantutils_lot_det`, et enregistre les droits, le menu et les hooks `propalcard` / `ordercard`.
3. Accorder les droits (Utilisateurs → Permissions → DiamantUtils). Aucun accès à la configuration du module n'est nécessaire :
   - **Voir les lots et les ajouter sur les devis et commandes** (`lot` / `read`) ;
   - **Créer et modifier les lots** (`lot` / `write`) ;
   - **Supprimer les lots** (`lot` / `delete`).
4. Option : la fonction peut être désactivée dans l'onglet **Lots de produits** de la configuration (`DIAMANTUTILS_LOT_ENABLED`, active par défaut).

### Utilisation
- **Commerce → Lots de produits** : liste (recherche sur le libellé, filtre actif / inactif), **Nouveau lot**. Fiche du lot : libellé, actif, lignes produit + quantité, modification de la quantité, ordre (monter / descendre), suppression, ajout en bas du tableau. Les lots sont partagés par tous les utilisateurs de l'entité ; seuls les lots actifs et non vides sont proposés sur les pièces.
- **Sur un devis ou une commande en brouillon** (droit `lot` / `read` + droit de créer/modifier la pièce) : choisir le lot, le multiplicateur, puis **Ajouter le lot**. Message « N ligne(s) ajoutée(s) ».
- **Enregistrer comme lot** (droit `lot` / `write`, tout statut de pièce) : bouton de la fiche devis / commande. Demande un libellé (réf. de la pièce par défaut) et crée un lot avec les lignes produit de la pièce (produit + quantité) ; lignes libres, titres et sous-totaux ignorés.

### Règles d'insertion
Chaque ligne passe par `addline()` de la pièce, **triggers actifs**, comme une saisie manuelle d'un produit :
- **prix et TVA** : `Product::getSellPrice($mysoc, $tiers)` (prix unique, niveau de prix du client, prix client) ; pas de prix → 0 ;
- **remise** : remise par défaut du tiers (la valeur pré-remplie du formulaire standard) ;
- **description** : description standard du produit ; dans la langue du tiers si `MAIN_MULTILANGS` **et** « textes des produits dans la langue du tiers » (`PRODUIT_TEXTS_IN_THIRDPARTY_LANGUAGE`) sont actifs, comme la saisie native ; mention code douane / pays d'origine ajoutée comme en natif ;
- **quantité** = quantité du lot × multiplicateur ; unité et type du produit ; prix d'achat calculé par Dolibarr selon la configuration des marges ;
- ordre des lignes du lot conservé, lignes ajoutées en fin de pièce ;
- **tout ou rien** : si une ligne échoue (produit supprimé ou plus en vente, stock insuffisant si l'option devis l'exige, refus d'un trigger), aucune ligne n'est ajoutée et le message d'erreur est affiché ;
- le PDF de la pièce est régénéré comme après un ajout manuel (sauf `MAIN_DISABLE_PDF_AUTOUPDATE`).

**Description pour devis** : les lignes d'un lot ajoutées sur un devis reçoivent la description pour devis du produit (trigger `LINEPROPAL_INSERT`), exactement comme un ajout manuel.

**Cohabitation** : le hook `formAddObjectLine` affiche la ligne par `print` et retourne 0 : le formulaire standard et les ajouts d'autres modules (ex. productselector) restent affichés. Le bouton d'ajout envoie son propre formulaire (le tableau des lignes est déjà dans le formulaire `addproduct` de la fiche).

### Test
Ouvrir `custom/diamantutils/test/lot_insert_test.php` en administrateur, choisir un produit ayant une description pour devis et un client, lancer : ajout sur un devis puis une commande brouillon, contrôles des quantités, du prix, de la description pour devis et du « tout ou rien ». Tout est annulé à la fin (transaction annulée, devis de contrôle supprimé).

### Fichiers

| Fichier | Rôle |
|---|---|
| `sql/llx_diamantutils_lot*.sql` | tables des lots et de leurs lignes (clé étrangère, suppression en cascade) |
| `class/diamantlot.class.php` | classe `DiamantLot` (CommonObject) et `DiamantLotLine` |
| `lib/lot.lib.php` | insertion d'un lot sur une pièce (`diamantutils_lot_insert()`), lignes d'une pièce pour « Enregistrer comme lot » |
| `lot_list.php`, `lot_card.php` | liste et fiche des lots |
| `class/actions_diamantutils.class.php` | hooks `formAddObjectLine`, `doActions` (`addlot`, `confirm_diamantutils_savelot`), `addMoreActionsButtons`, `formConfirm` |
| `test/lot_insert_test.php` | test d'intégration (navigateur, administrateur) |
| `admin/setup.php`, `lib/diamantutils.lib.php` | onglet de configuration |
| `core/modules/modDiamantutils.class.php` | droits, menu Commerce, hooks, constante |

---

## Prix de revient à la réception fournisseur

### Principe
À chaque réception de commande fournisseur, le prix unitaire saisi à l'écran de réception (prix « rendu Brest », frais d'approche inclus) est recopié dans le **prix de revient** du produit (`llx_product.cost_price`).

Restent inchangés :
- les prix fournisseurs (`llx_product_fournisseur_price`), qui servent aux futures commandes ;
- les lignes de commande et de facture fournisseur ;
- le PMP, déjà calculé par Dolibarr avec le prix du mouvement.

Exemple : produit commandé à 9,40 €/m², réceptionné à 12,23 €/m² → prix de revient 12,23, prix fournisseur toujours 9,40.

### Mise en service
1. Dolibarr : **Configuration → Divers**, constante `SUPPLIER_ORDER_EDIT_BUYINGPRICE_DURING_RECEIPT = 1` (prix modifiable à la réception). L'onglet du module indique si elle est en place.
2. Configuration du module → onglet **Prix de revient** : activer `DIAMANTUTILS_COSTPRICE_FROM_RECEIPT`.
3. À la réception : saisir le prix rendu, laisser **décochée** la case « mettre à jour le prix fournisseur ».

### Règles
Trigger `STOCK_MOVEMENT`, le prix de revient est mis à jour si toutes les conditions sont remplies :
- option active ;
- mouvement d'entrée de réception (type 3) avec une quantité positive ;
- origine `reception` (module Réceptions) ou `order_supplier` (ventilation sans module Réceptions) ;
- prix > 0.

Corrections et transferts de stock, expéditions, annulations de réception et réceptions à prix nul ne changent rien. Réception en plusieurs fois : le dernier prix reçu l'emporte. Le trigger s'exécute dans la transaction du mouvement : une erreur SQL annule la réception.

Cas particulier : avec l'option Dolibarr d'entrée en stock à l'approbation de la commande fournisseur, le mouvement porte l'origine `order_supplier` et le prix de la commande ; le prix de revient prend alors ce prix.

### Fichiers

| Fichier | Rôle |
|---|---|
| `core/triggers/interface_99_modDiamantutils_Diamantutilstriggers.class.php` | `STOCK_MOVEMENT` → `costPriceFromReceipt()` |
| `admin/setup.php`, `lib/diamantutils.lib.php` | onglet de configuration |
| `core/modules/modDiamantutils.class.php` | constante, version |
| `langs/fr_FR/diamantutils.lang`, `langs/en_US/diamantutils.lang` | libellés |

---

## Liaison des commandes fournisseurs

### Principe
Lier une commande fournisseur à une autre, **même chez un autre fournisseur**, depuis le bloc **Objets liés → Lier à…** de la fiche commande fournisseur. Cas type : la commande de transport (GELIN, KUEHNE & NAGEL, DACHSER…) liée aux commandes Metalscreen qu'elle enlève (ex. PO2610-1043 ↔ PO2610-1029).

Dolibarr 20 ne le permet pas : la fiche commande fournisseur retire le type « commande fournisseur » de son menu « Lier à… », et les autres listes sont limitées au même tiers.

### Mise en service
1. Déposer les fichiers par FTP dans `custom/diamantutils/`.
2. **Désactiver puis réactiver le module** : enregistre le nouveau contexte de hook `ordersuppliercard`. L'onglet de configuration indique si c'est fait.
3. Configuration du module → onglet **Liaison commandes fournisseurs** :
   - `DIAMANTUTILS_LINK_SUPPLIER_ORDERS` : « Permettre de lier une commande fournisseur à une autre commande fournisseur (tous fournisseurs) » (désactivée par défaut) ;
   - `DIAMANTUTILS_LINK_SUPPLIER_ORDERS_MONTHS` : période proposée dans la liste, en mois (24 par défaut, 0 = sans limite).

### Fonctionnement
- Menu **Lier à… → Lier à une commande fournisseur** (libellé natif `LinkToSupplierOrder`) : toutes les commandes fournisseurs de l'entité, tous fournisseurs, des N derniers mois (date de commande, sinon de validation ou de création), triées de la plus récente à la plus ancienne.
- Exclues de la liste : la commande ouverte, les commandes annulées (`STATUS_CANCELED`, `STATUS_CANCELED_AFTER_ORDER`), refusées (`STATUS_REFUSED`) et celles déjà liées, dans un sens ou dans l'autre.
- **Saisie par référence** (`MAIN_LINK_BY_REF_IN_LINKTO = 1`) : le champ « Réf. » de la même entrée accepte n'importe quelle commande fournisseur de l'entité, y compris hors période.
- Le lien est enregistré comme un lien natif (`order_supplier` ↔ `order_supplier` dans `llx_element_element`) : visible et supprimable (corbeille) des deux côtés.
- Doublon refusé dans les deux sens, avec un avertissement (l'index unique de Dolibarr ne couvre que le même sens). Lier une commande à elle-même est refusé.
- Droits : l'entrée n'apparaît qu'avec le droit de lecture des commandes fournisseurs ; lier demande en plus le droit de créer / modifier (comme la liaison native de la fiche).
- Seule la fiche commande fournisseur est concernée (contexte `ordersuppliercard`).

### Détail technique
- `fourn/commande/card.php` appelle `showLinkToObjectBlock($object, null, array('supplier_order', 'order_supplier'))` : l'exclusion est appliquée après la fusion des résultats du hook, donc une entrée `order_supplier` ajoutée par un hook serait masquée. Le module utilise la clé `supplierorder`, que `add_object_linked()` convertit en `order_supplier`.
- `getElementProperties()` ne connaît pas `supplierorder` : la saisie par référence native planterait. Le hook `doActions` traite donc `addlink` et `addlinkbyref` pour cette clé (jeton CSRF, droits, doublon), puis vide l'action.
- Les liaisons `addlink=order_supplier` envoyées directement (favori-script) ne passent pas par le module et fonctionnent comme avant.
- Aucune modification du cœur Dolibarr.

### Fichiers

| Fichier | Rôle |
|---|---|
| `class/actions_diamantutils.class.php` | hooks `showLinkToObjectBlock` (entrée du menu) et `doActions` (liaison) |
| `core/modules/modDiamantutils.class.php` | contexte `ordersuppliercard`, constantes, version |
| `admin/setup.php`, `lib/diamantutils.lib.php` | onglet de configuration |
| `langs/fr_FR/diamantutils.lang`, `langs/en_US/diamantutils.lang` | libellés |

---

## Tableau de stock par lots

### Principe
Vue des **données Dolibarr seules**, pour les produits des **tags** (catégories produit) choisis dans la configuration : lots en stock, avec leur colisage, leurs pièces par colis et leur qté par pièce (attributs du lot, voir « Colisage sur l'onglet Stock ») et leur quantité réelle. Aucun mouvement de stock, aucune donnée propre au tableau.

Depuis la v2.13, le tableau ne compare plus un comptage saisi au stock Dolibarr : les lignes saisies auparavant (`llx_diamantutils_stockline`) sont **conservées en base** mais ne sont plus affichées ni modifiables (leur nombre est rappelé dans l'onglet de configuration).

### Mise en service
1. Déposer les fichiers par FTP dans `custom/diamantutils/`. Mise à jour depuis une version antérieure à la v2.13 : supprimer sur le serveur `ajax/stocktable.php`, `class/stockline.class.php` et `test/stocktable_calc_*.php`.
2. **Désactiver puis réactiver le module** : met à jour les droits (seul *Voir le tableau de stock par lots* reste).
3. Configuration du module → onglet **Tableau de stock** : **Tags produit concernés** (`DIAMANTUTILS_STOCKTABLE_CATEGORIES`), **Inclure les sous-catégories** (`DIAMANTUTILS_STOCKTABLE_SUBCATEGORIES`, oui par défaut), **Activer cette fonctionnalité** (`DIAMANTUTILS_STOCKTABLE_ENABLED`). Aucun tag = aucun produit.
4. Pour modifier les attributs du lot depuis le tableau : fonctionnalité **Stock produit** active (et colisage calculé pour pièces par colis et qté par pièce).

### Écran
Menu **Produits → Stock → Tableau de stock par lots**.

| Colonne | Contenu |
|---|---|
| Lot | numéro de lot, lien vers la fiche lot |
| Colisage | attribut `colisage` du lot : calculé (∑, lecture seule, avertissement si la quantité n'est pas un multiple de la pièce) ou saisi à la main |
| Pièces par colis | attribut `pieces_par_colis` du lot |
| Qté par pièce | attribut `qte_par_piece` du lot (unité du produit) |
| Stock physique | produit : stock réel tous entrepôts ; lot : stock réel du lot hors entrepôts ignorés |
| Cdes client | produit : commandes client validées ou en cours, moins les quantités des expéditions validées (lien vers les commandes du produit) |
| Cdes fournisseur | produit : commandes fournisseur approuvées ou commandées, moins les quantités réceptionnées (lien vers les commandes du produit) |
| Stock virtuel | produit : stock physique − cdes client + cdes fournisseur |

- Une section repliable par tag choisi, un groupe repliable par produit : réf., libellé, stock physique (lien vers l'onglet Stock), commandes en cours, stock virtuel. Les commandes ne sont pas réparties par lot : elles figurent sur la ligne du produit seulement. Produits sans stock ni commande en cours non affichés.
- **Stock physique d'un lot** : stock réel total du lot, tous entrepôts sauf les **entrepôts ignorés du colisage** (onglet **Stock produit**, par défaut Sous-traitance). C'est la quantité sur laquelle le colisage enregistré est calculé : colisage et quantité affichés concordent. Le stock des entrepôts ignorés et le stock non rattaché à un lot (produit sans lots, ou part sans lot) sont sur des lignes en italique : les lignes d'un produit totalisent son stock physique.
- Calculs des commandes repris de la requête de suivi de Diamant Industrie (statuts client 1 et 2, expéditions de statut ≥ 1, statuts fournisseur 3 et 4, réceptions `llx_receptiondet_batch`), avec filtre d'entité ; ils peuvent différer du stock virtuel natif de Dolibarr, qui dépend de ses propres options.
- Recherche instantanée (produit, lot, colisage), filtre par tag, tout replier / tout déplier (mémorisé dans le navigateur).
- **Modification en ligne** (droit Produits → Créer/modifier) : pièces par colis et qté par pièce, colisage d'un lot en mode manuel. Même mécanisme que l'onglet Stock (`js/stockcolisage.js`, `ajax/lotcolisage.php`) : Entrée ou sortie du champ = enregistrer, Échap = annuler, colisage recalculé aussitôt. Sans le droit, ou fonctionnalité Stock produit inactive : lecture seule.

### Détail technique
- Données en **7 requêtes**, quel que soit le nombre de produits : produits des tags avec leur stock ; lots en stock avec leur fiche lot ; commandes client, expéditions, commandes fournisseur moins réceptions ; attributs des lots ; tags des produits (sections).
- Aucune écriture propre au tableau : les attributs du lot sont écrits par `ajax/lotcolisage.php` (droits, jeton, validation : voir la section suivante).

### Fichiers

| Fichier | Rôle |
|---|---|
| `stocktable_list.php` | écran (rendu serveur) |
| `lib/stocktable.lib.php` | périmètre (tags), lecture des données, rendu d'un groupe et d'une section |
| `js/stocktable.js`, `css/stocktable.css` | recherche, sections, repli ; mise en forme |
| `js/stockcolisage.js`, `css/stockcolisage.css` | modification en ligne des attributs du lot (partagé avec l'onglet Stock) |
| `sql/llx_diamantutils_stockline.sql`, `.key.sql` | table des lignes saisies avant la v2.13 (conservée, plus utilisée) |
| `core/modules/modDiamantutils.class.php` | droit `stocktable` *read*, menu, version |
| `admin/setup.php`, `lib/diamantutils.lib.php` | onglet de configuration |
| `langs/fr_FR/diamantutils.lang`, `langs/en_US/diamantutils.lang` | libellés |

---

## Colisage sur l'onglet Stock de la fiche produit

### Principe
Sur la fiche produit, onglet **Stock**, pour un produit **géré par lots** : le tableau du stock par entrepôt est remplacé par celui du module, sans les colonnes DLC, DMD/DLUO, Prix moyen pondéré, Valorisation achat, Prix de vente unitaire et Valeur à la vente, avec une colonne **Colisage** modifiable directement dans le tableau.

Colonnes : **Entrepôt / Lot**, **Colisage**, **Quantité** (unité courte du produit), liens **Transférer stock** et **Corriger le stock**. Le reste est inchangé : boutons au-dessus du tableau, entrepôts et lots repliables (« Afficher tous les lots » / « Masquer les lots », état initial selon `STOCK_SHOW_ALL_BATCH_BY_DEFAULT`), quantités et total identiques au tableau standard, avertissements (quantité négative, numéro de série en quantité > 1), pas de lien d'action sur un entrepôt fermé.

Le colisage est l'attribut supplémentaire existant des lots (code `colisage`, type « Texte HTML », Configuration du module Lot/série → Attributs supplémentaires), stocké dans `llx_product_lot_extrafields`. Le module ne le crée pas.

Les produits sans lots, les produits parents de variantes (sans `VARIANT_ALLOW_STOCK_MOVEMENT_ON_VARIANT_PARENT`) et, en v23, les kits gardent le tableau standard. Le cœur de Dolibarr n'est pas modifié.

### Mise en service
1. Déposer les fichiers par FTP dans `custom/diamantutils/`.
2. **Désactiver puis réactiver le module** : enregistre le hook `stockproductcard` de l'onglet Stock.
3. Configuration du module → onglet **Stock produit** : vérifier l'état (attributs `colisage`, `pieces_par_colis` et `qte_par_piece` présents, module Lot/série actif, hook enregistré), puis **Activer cette fonctionnalité** (`DIAMANTUTILS_STOCKCOLISAGE_ENABLED`, désactivée par défaut : le déploiement est inerte, trigger compris).
4. Code de l'attribut (`DIAMANTUTILS_STOCKCOLISAGE_EXTRAFIELD`, `colisage` par défaut) : à changer seulement si l'attribut des lots porte un autre code.
5. **Colisage calculé** (`DIAMANTUTILS_STOCKCOLISAGE_AUTO`, oui par défaut ; non = saisie à la main seulement, le trigger ne fait rien) et **Entrepôts ignorés pour la quantité du lot** (`DIAMANTUTILS_STOCKCOLISAGE_EXCLUDE_WAREHOUSES`, par défaut Sous-traitance).
6. Renseigner pièces par colis et qté par pièce des lots (onglet Stock ou fiche lot), puis lancer **Recalculer tous les colisages** (premier déploiement, et après tout import ou SQL direct sur le stock).

### Modifier le colisage
- Droit **Produits → Créer/modifier** (le même que la fiche lot). Sans ce droit : texte seul.
- Clic sur la cellule (ou Entrée / F2 au clavier) : champ texte, 200 caractères au plus. **Entrée** ou sortie du champ = enregistrer, **Échap** = annuler. ✓ discret après l'enregistrement, message d'erreur dans la cellule sinon ; la page n'est pas rechargée. Cellule vide : « — » et crayon au survol.
- Texte simple : la valeur est enregistrée échappée (aucune balise active). Une valeur mise en forme depuis la fiche du lot est affichée aplatie (texte complet en infobulle) ; un avertissement prévient qu'elle sera enregistrée sans mise en forme. Une saisie contenant une balise de script est refusée par la protection de Dolibarr, avec un message dans la cellule.
- Lot en stock sans fiche lot (`llx_product_lot`) : la fiche lot est créée à la première saisie, avec le colisage.

### Colisage calculé (v2.12)
Deux attributs du lot, créés à l'activation du module : **Pièces par colis** (`pieces_par_colis`, entier) et **Qté par pièce** (`qte_par_piece`, décimal jusqu'à 8 décimales, dans l'unité du produit : 0,36 pour une pièce de 0,36 m²). Quand les deux sont renseignés, le lot passe en **mode automatique** : `colisage` est calculé et enregistré dans l'attribut, où les autres modules, listes et documents le lisent. Sinon, **mode manuel** : saisie libre comme avant.

| Quantité | Qté / pièce | Pièces / colis | Colisage |
|---|---|---|---|
| 60,48 | 0,36 | 28 | `6 colis de 28px` |
| 2,16 | 0,36 | 28 | `1 colis de 6px` |
| 10,80 | 0,36 | 28 | `1 colis de 28px + 1 colis de 2px` |
| 2,17 | 0,36 | 28 | vide, avertissement « quantité non multiple de la pièce » |
| 0 | 0,36 | 28 | vide (lot épuisé) |

Règle (`diamantutils_colisage_compute()`, seule implémentation, utilisée partout) : quantité arrondie au stock (`price2num(…, 'MS')`), pièces = quantité ÷ qté par pièce, tolérance 0,02 pièce pour les arrondis ; jamais de texte faux (quantité non multiple ou négative : texte vide et avertissement).

**Quantité utilisée pour la valeur enregistrée** : stock réel total du lot, tous entrepôts sauf les entrepôts ignorés (réglage de l'onglet, par défaut Sous-traitance). Sur l'onglet Stock, chaque ligne entrepôt × lot affiche le colisage de **sa** quantité : un lot présent dans deux entrepôts montre deux textes, la valeur enregistrée correspond au total.

Maintien à jour, trois niveaux :
1. **Trigger `STOCK_MOVEMENT`** : à chaque mouvement portant un lot (correction, transfert, réception, expédition, inventaire, transformation…), recalcul du lot dans la transaction du mouvement. Un mouvement annulé annule aussi le recalcul. Une erreur de calcul est journalisée (`LOG_ERR`) et **ne bloque jamais le mouvement**. Le prix de revient (même trigger) reste un traitement indépendant.
2. **Auto-réparation à l'affichage** de l'onglet Stock : valeur enregistrée différente du calcul → corrigée, journalisée en `LOG_WARNING`.
3. **Bouton « Recalculer tous les colisages »** (onglet de configuration) : tous les lots dont les deux attributs sont renseignés ; rapport lots mis à jour / inchangés / avertissements (avec lien vers la fiche lot).

Écran : colonnes **Pièces par colis** et **Qté par pièce** modifiables en ligne (droit Produits → Créer/modifier, virgule acceptée, entier > 0 pour les pièces par colis, > 0 et 8 décimales au plus pour la qté par pièce, refus avec message). Enregistrer l'un des deux recalcule aussitôt le colisage de toutes les lignes du lot, sans rechargement. En mode automatique, le colisage est en lecture seule (∑, infobulle « calculé ») ; un texte saisi à la main est remplacé, ce qu'annoncent l'infobulle des deux champs et un message. Lot en stock sans fiche lot : la fiche est créée à la première saisie.

Écriture de la valeur calculée (trigger, auto-réparation, recalcul) : mise à jour directe de `llx_product_lot_extrafields.colisage`, seulement si la valeur change. `insertExtraFields()` n'est pas utilisé ici : il réécrit tous les attributs du lot, refuserait l'écriture si un autre attribut obligatoire était vide (le mouvement serait bloqué) et déclencherait `PRODUCT_LOT_MODIFY` à chaque mouvement. Les saisies de l'utilisateur (onglet Stock) passent, elles, par `Productlot` (`insertExtraFields('PRODUCT_LOT_MODIFY')` ou `create()`), comme la fiche lot.

### Repli de sécurité
- Le tableau du module est affiché masqué, placé par `js/stockcolisage.js` juste avant le tableau standard, qui n'est masqué qu'ensuite. Tableau standard introuvable, erreur JavaScript, JavaScript désactivé : le tableau standard reste affiché.
- Erreur au rendu côté serveur (exception, erreur SQL) : journalisée (`LOG_ERR`), rien n'est affiché, le tableau standard reste visible. Pour le vérifier : constante `DIAMANTUTILS_STOCKCOLISAGE_FORCE_ERROR = 1` (Configuration → Divers), à supprimer ensuite. La même constante fait échouer le recalcul du trigger : le mouvement de stock doit passer quand même.
- Fonctionnalité désactivée : la page est strictement celle de Dolibarr.

### Détail technique
- Hook `addMoreActionsButtons` (contexte `stockproductcard`), appelé juste avant les boutons et le tableau ; la page n'affiche pas `resPrint`, le module affiche par `print` et retourne 0 (boutons standard conservés).
- Données : requête des entrepôts du cœur (`entrepot` × `product_stock`, `reel != 0`, entité, tri par réf.) et `Productbatch::findAll()` par entrepôt, comme le cœur ; colisage des lots en une requête.
- Liens des lots : `?id=…&id_entrepot=…&action=transfert|correction&pdluoid=…` (action `transfer` à partir de Dolibarr 23).
- `ajax/lotcolisage.php` : POST, jeton CSRF vérifié, droit produit / créer et fonctionnalité active (403 sinon). Le lot est retrouvé côté serveur par (produit, numéro de lot, entité), en stock ou parmi les lots du produit : lot inconnu, lot d'un autre produit, produit sans lots, valeur trop longue, attribut absent sont refusés avec un message. Écriture par `Productlot::create()` (fiche lot absente) ou `array_options` + `insertExtraFields('PRODUCT_LOT_MODIFY')`. Aucun mouvement de stock.

### Fichiers

| Fichier | Rôle |
|---|---|
| `lib/stockcolisage.lib.php` | conditions, lecture des données, rendu du tableau, recherche du lot, enregistrement, calcul du colisage, recalcul |
| `core/triggers/interface_99_modDiamantutils_Diamantutilstriggers.class.php` | `STOCK_MOVEMENT` : recalcul du colisage du lot |
| `test/stockcolisage_compute_test.php` | test du calcul (navigateur, administrateur, aucune donnée lue ni écrite) |
| `class/actions_diamantutils.class.php` | hook `addMoreActionsButtons` (contexte `stockproductcard`) |
| `ajax/lotcolisage.php` | enregistrement du colisage (JSON) |
| `js/stockcolisage.js`, `css/stockcolisage.css` | placement, repli, édition en ligne ; mise en forme |
| `core/modules/modDiamantutils.class.php` | hook `stockproductcard`, constantes, attributs `pieces_par_colis` / `qte_par_piece` (activation), version |
| `admin/setup.php`, `lib/diamantutils.lib.php` | onglet de configuration **Stock produit**, bouton de recalcul |
| `langs/fr_FR/diamantutils.lang`, `langs/en_US/diamantutils.lang` | libellés |
