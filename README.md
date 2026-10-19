# DiamantUtils

Module Dolibarr interne de Diamant Industrie (Dolibarr 20, PHP 8.2, déploiement FTP).

- **Ordres de transformation** de stock par lots (menu GPAO) : voir `GUIDE_TRANSFORMATION_V2.md`.
- **Commandes sous-traitées** (livraison directe client) : section ci-dessous.
- **Listes personnalisées** (menu Outils) : listes générées par des requêtes SQL, section ci-dessous.
- **Description pour devis** : description produit propre aux propositions commerciales, section ci-dessous.

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

### Règles
- La ligne n'est modifiée que si sa description est vide ou identique à la description standard du produit (ou à l'une de ses traductions). La mention « (Code douane … / Pays d'origine …) » ajoutée par Dolibarr est conservée.
- Ne sont jamais modifiées : une description changée à la main dans le formulaire, les lignes d'une propale clonée, créée depuis un autre objet ou importée d'un objet lié, les lignes libres.
- Produit sans description pour devis : description standard conservée.
- La désactivation du module conserve l'attribut et ses données.

### Fichiers

| Fichier | Rôle |
|---|---|
| `lib/descpropal.lib.php` | installation de l'attribut, choix parent / variante, règles de remplacement |
| `lib/diamantutils_descpropal.lib.php` | rendu du modèle (`diamantutils_render_desc()`), attributs de variante |
| `class/actions_diamantutils.class.php` | aide à la saisie sur la fiche produit parent |
| `core/triggers/interface_99_modDiamantutils_Diamantutilstriggers.class.php` | trigger `LINEPROPAL_INSERT` |
| `admin/setup.php`, `lib/diamantutils.lib.php` | onglet de configuration |
| `core/modules/modDiamantutils.class.php` | constantes, création de l'attribut à l'activation |
