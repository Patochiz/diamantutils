# DiamantUtils

Module Dolibarr interne de Diamant Industrie (Dolibarr 20, PHP 8.2, déploiement FTP).

- **Ordres de transformation** de stock par lots (menu GPAO) : voir `GUIDE_TRANSFORMATION_V2.md`.
- **Commandes sous-traitées** (livraison directe client) : section ci-dessous.
- **Listes personnalisées** (menu Outils) : listes générées par des requêtes SQL, section ci-dessous.
- **Description pour devis** : description produit propre aux propositions commerciales, section ci-dessous.
- **Lots de produits** (menu Commerce) : ensembles de produits ajoutés en une fois sur un devis ou une commande client, section ci-dessous.

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
