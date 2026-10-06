# Guide Claude Code — DiamantUtils v2 : Transformation de stock par lots

> À placer à la racine du dépôt `Patochiz/diamantutils`, puis dans Claude Code :
> « Lis GUIDE_TRANSFORMATION.md et implémente-le sur une nouvelle branche. »

## 0. Cadre

- **Dépôt** : `Patochiz/diamantutils`, module dans `diamantutils/`.
- **Branche** : créer `feature/transformation-stock` depuis `main`. Ne jamais pousser sur `main`, Patrice fusionne après essais.
- **Cible** : Dolibarr **20.0**, PHP 8.2, hébergement OVH mutualisé.
  - Déploiement par FTP uniquement : pas de SSH, pas de Composer, aucune dépendance externe.
  - Tout le SQL passe par les fichiers `sql/` du module, chargés à l'activation.
- **Source Dolibarr de référence** : branche `20.0` de `Dolibarr/dolibarr` sur GitHub. Vérifier chaque signature de méthode dans ce code, ne rien supposer.
- **Langue** : code et commentaires en français, cohérent avec l'existant. Chaînes dans `langs/fr_FR` et `langs/en_US`.

### Conventions obligatoires (projet Diamant)

- Inclusions : `dol_include_once('/diamantutils/...')`. Jamais `require_once DOL_DOCUMENT_ROOT.'/custom/...'`.
- URLs d'assets : `dol_buildpath('/diamantutils/x.js', 1)`. Chemins fichiers : `dol_buildpath('/diamantutils/dir', 0)`.
- Droits : `$user->hasRight('diamantutils', 'transformation', 'read')`. Jamais `$user->rights->...`.
- Classes de hook : pas de propriétés typées (`public $resprints = '';`).
- Hooks dans le descripteur : format plat `'hooks' => array('stockproductcard')`. Jamais le format imbriqué `data/entity`.
- Endpoints AJAX POST : définir `NOTOKENRENEWAL`, `NOREQUIREMENU`, `NOREQUIREHTML`, `NOREQUIREAJAX`, `NOCSRFCHECK` avant `main.inc.php`.
- SQL : toujours `MAIN_DB_PREFIX`, cast `(int)` / `$db->escape()`, `$db->begin()/commit()/rollback()` pour toute écriture multiple.

## 1. Nettoyage : suppression du contrôle de facturation

Cette fonction est abandonnée (le contrôle est fait ailleurs). Supprimer entièrement :

- `core/triggers/interface_99_modDiamantutils_DiamantutilsTriggers.class.php` (fichier entier) ;
- `class/actions_diamantutils.class.php` : tout le contenu facture. Le fichier est réécrit pour le nouveau hook, voir §6 ;
- dans `modDiamantutils.class.php` :
  - la constante `DIAMANTUTILS_INVOICE_CHECK_MODE` ;
  - le hook `invoicecard` ;
  - `'triggers' => 1` ;
  - le nettoyage des constantes `*_HOOKS` dans `init()/remove()` (garder un `DELETE` de `DIAMANTUTILS_INVOICE_CHECK_MODE` dans `init()` pour purger l'ancienne valeur) ;
- `admin/setup.php` : l'option de mode facture et tout le bloc « Diagnostic » ;
- les chaînes de langue `DiamantutilsInvoice*`, `DiamantutilsMode*`, `DiamantutilsLine*`, `DiamantutilsTotalInvoiced`, `DiamantutilsRemainingToInvoice`.

Ne **pas** toucher à l'extrafield `factures` des factures : il existe en base et contient des données.

Passer la version du module à `2.0` et mettre à jour `description` / `descriptionlong`.

## 2. Besoin métier

DIAMANT INDUSTRIE achète des profilés et les revend tels quels, découpés, ou peints dans une autre couleur. Le stock de ces produits est suivi **par lot**, et le lot porte la longueur de la pièce.

### Exemple réel : `Horizon_D85a_PRE_BLANC_NP` (id 381)

- Unité du produit : **m²**.
- Largeur utile fixe : **100 mm**.
- Surface d'une pièce = longueur × 0,100 m.

| Lot | Qté (m²) | ≈ pièces |
|---|---|---|
| Longueur 4000mm | 2.4 | 6 |
| Longueur 3000 | 3 | 10 |
| Longueur 2000 | 4 | 20 |
| Longueur 997mm | 5.18 | ≈ 52 |
| Longueur 695mm | 0.7 | ≈ 10 |

### Opérations à gérer

1. **Découpe** : même produit, lot différent.
   - Ex. 4 barres de 4000 mm donnent 10 pièces de 1200 mm pour le client.
   - Les restes sont 3 × 400 mm + 1 × 2800 mm. Pour chaque reste, l'opérateur décide de le remettre en stock (nouveau lot) ou de le déclarer en perte.
2. **Peinture** : produit A → produit B (autre couleur), lot généralement identique.
3. **Changement d'unité** : produit A → produit B, où B a une autre unité. Patrice crée lui-même le produit B.

### Règles

- **R1** — Chaque quantité est toujours exprimée dans l'unité **de son propre produit** (`fk_unit`). Jamais de conversion d'unité implicite d'un produit à l'autre.
- **R2** — Le seuil de chute n'est **pas** paramétré. L'opérateur décide, ligne par ligne, au moment de la transformation (sortie stockée ou perte).
- **R3** — Une transformation = N lignes consommées (au moins 1) + M lignes produites (au moins 1) + une perte éventuelle. Validation atomique : tout ou rien.
- **R4** — **Contrôle d'équilibre** (bloquant) si tous les produits consommés et produits ont la même unité (`fk_unit` identique et non nul) : Σ consommé = Σ produit + perte, à la tolérance d'arrondi près (`price2num(..., 'MS')`). Si les unités diffèrent, pas de contrôle de somme, juste un avertissement affiché.
- **R5** — Le lot est obligatoire sur toute ligne dont le produit a `status_batch > 0`.
- **R6** — Le stock disponible du lot consommé est vérifié avant validation.

## 3. Saisie en longueur (mode « profilé »)

Pour éviter les calculs manuels, une ligne peut être saisie en **nombre de pièces × longueur (mm)**, et la quantité est calculée.

Le mode de saisie d'une ligne dépend de l'unité du produit (`llx_c_units.code`) :

| `unit_type` / code | Saisie | Quantité calculée |
|---|---|---|
| `surface` (M2, …) **et** largeur produit renseignée | nb × longueur mm | nb × L/1000 × largeur (m), convertie selon `scale` de l'unité |
| `size` (M, …) | nb × longueur mm | nb × L/1000, convertie selon `scale` |
| `qty` (P) | nb pièces | nb |
| autre / pas d'unité | quantité directe | — |

- **Largeur** : champs natifs du produit `width` + `width_units` (scale : -3 = mm, -2 = cm, 0 = m). Pas d'extrafield.
- Un produit en m² sans largeur renseignée retombe en saisie directe, avec un message invitant à renseigner la largeur sur la fiche produit.
- Toujours afficher la quantité calculée à côté de la saisie (calcul JS en direct + recalcul serveur à la validation : le serveur fait foi).

### Lots ↔ longueur

- **Format du nom de lot généré** : constante `DIAMANTUTILS_TRANSFO_LOT_FORMAT`, défaut `Longueur %dmm` (`sprintf` avec la longueur en mm).
- **Lecture de la longueur d'un lot existant** : extraire le premier entier du nom (regex `/(\d+)/`), avec ou sans `mm` (les lots existants sont incohérents : « Longueur 3000 » / « Longueur 3000mm »).
- Fonction `diamantutils_lot_length($batch)`, qui retourne `null` si aucun entier n'est trouvé. Dans ce cas, la ligne passe en saisie directe.
- Pour une ligne consommée en mode profilé, la longueur est lue sur le lot. Pour une ligne produite, la longueur est saisie et le nom de lot est proposé à partir du format, mais reste modifiable.
- Afficher pour chaque lot consommable : la quantité et le **nombre de pièces équivalent** (qty / surface unitaire). Si ce nombre n'est pas entier à 0,01 près, l'afficher en orange avec l'info-bulle « stock du lot non multiple de la pièce ».

## 4. Modèle de données

Fichiers dans `diamantutils/sql/` (chargés par `_load_tables('/diamantutils/sql/')` dans `init()`). Une table par fichier `.sql`, les clés dans des fichiers `.key.sql`. Syntaxe MySQL/MariaDB compatible avec l'installeur Dolibarr (pas de `DELIMITER`, pas de `IF NOT EXISTS` sur les index).

```sql
-- llx_diamantutils_transfo.sql
CREATE TABLE llx_diamantutils_transfo (
  rowid            integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity           integer DEFAULT 1 NOT NULL,
  ref              varchar(30) NOT NULL,          -- TRANSFO-AAMM-NNNN
  label            varchar(255),
  type             varchar(16) DEFAULT 'DECOUPE',  -- DECOUPE | PEINTURE | AUTRE
  fk_warehouse     integer NOT NULL,
  fk_commandedet   integer NULL,                   -- ligne de commande client liée (optionnel)
  fk_commande      integer NULL,
  status           smallint DEFAULT 0 NOT NULL,    -- 0 brouillon, 1 validée, 9 annulée
  note             text,
  date_creation    datetime NOT NULL,
  date_valid       datetime NULL,
  tms              timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_creat    integer NOT NULL,
  fk_user_valid    integer NULL
) ENGINE=innodb;

-- llx_diamantutils_transfo_det.sql
CREATE TABLE llx_diamantutils_transfo_det (
  rowid            integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  fk_transfo       integer NOT NULL,
  direction        varchar(8) NOT NULL,            -- IN (consommé) | OUT (produit) | LOSS (perte)
  fk_product       integer NOT NULL,
  batch            varchar(128),
  nb_pieces        double(24,8) NULL,              -- saisie profilé
  length_mm        double(24,8) NULL,
  qty              double(24,8) NOT NULL,          -- dans l'unité du produit, toujours positive
  unit_cost        double(24,8) NULL,              -- coût unitaire appliqué au mouvement
  fk_stock_mouvement integer NULL,                 -- mouvement créé à la validation
  rang             integer DEFAULT 0
) ENGINE=innodb;
```

- Index : `uk_diamantutils_transfo_ref (ref, entity)` unique, plus des index sur `fk_transfo` et `fk_product`.
- `LOSS` : une ligne de perte n'a pas de mouvement de stock. Elle sert seulement à l'équilibre (R4) et à la traçabilité (produit = produit consommé, qty = quantité perdue).
- Numérotation simple dans la classe : `TRANSFO-` + `AAMM` + `-` + compteur sur 4 chiffres, calculé par `MAX()` sur le préfixe du mois. Pas de module de numérotation à masque.

## 5. Classe métier `class/transformation.class.php`

`class Transformation extends CommonObject` (`$element = 'diamantutils_transfo'`, `$table_element = 'diamantutils_transfo'`), avec les lignes dans `TransformationLine`.

### Méthodes

`create`, `fetch` (avec lignes), `update`, `delete` (brouillon uniquement), `addLine`, `deleteLine`, `getNextRef`, `checkBalance()`, `validate($user)`, `cancel($user)`.

### `validate($user)` — dans une transaction unique

1. Recharger les lignes et recalculer côté serveur toutes les quantités en mode profilé (§3).
2. Contrôles :
   - R4 : équilibre si même unité ;
   - R5 : lot obligatoire ;
   - R6 : stock suffisant pour chaque couple (produit, entrepôt, lot) consommé, en cumulant les lignes d'un même lot. Lire `llx_product_batch` jointe à `llx_product_stock`.
3. **Coût** :
   - coût total consommé = Σ (qty IN × PMP du produit IN au moment de la validation) ;
   - coût unitaire des sorties = coût total / Σ qty OUT (en valeur, réparti au prorata des quantités OUT, ce qui suppose des sorties homogènes) ;
   - en cas d'unités différentes, répartir le coût total au prorata des quantités de chaque ligne OUT. Une perte n'absorbe pas de coût : elle est portée par les sorties.
   - Pour une découpe (même produit, même unité, sans perte), le PMP reste inchangé.
4. **Mouvements** : `MouvementStock` (vérifier les signatures dans `product/stock/class/mouvementstock.class.php` v20).
   - Pour chaque ligne IN : `livraison($user, $fk_product, $fk_warehouse, $qty, $pmp, $label, '', '', '', $batch, 0, $inventorycode)`.
   - Pour chaque ligne OUT : `reception($user, $fk_product, $fk_warehouse, $qty, $unit_cost, $label, '', '', $batch, '', 0, $inventorycode)`. `_create()` crée automatiquement l'entrée `llx_product_lot` si le lot n'existe pas.
   - Avant chaque appel, `setOrigin('diamantutils_transfo', $this->id)` pour la traçabilité.
   - `$inventorycode` = la ref de la transformation, ce qui permet de filtrer tous les mouvements dans la liste native des mouvements.
   - `$label` = `ref + type + (ref commande client si liée)`.
   - Stocker l'id du mouvement créé dans `fk_stock_mouvement`.
5. Passer `status = 1`, `date_valid`, `fk_user_valid`. Commit. En cas d'erreur : rollback complet et messages remontés à l'écran.

### `cancel($user)`

- Uniquement pour le statut 1.
- Crée les mouvements inverses (reception des IN, livraison des OUT), avec le même `inventorycode` suffixé `-ANN`.
- Vérifie d'abord que les lots produits ont encore le stock suffisant. Sinon, refus avec un message clair (le lot a déjà été expédié ou retransformé).
- Passe ensuite le statut à 9.

## 6. Écrans

### `transformation_card.php` (création / édition / vue)

- **En-tête** : type, entrepôt (défaut `DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE`), commande client optionnelle (sélecteur de commande validée, puis de ligne de commande), note.
- **Bloc « Consommé »** :
  - sélection du produit, puis liste de ses lots disponibles dans l'entrepôt (qté + nb pièces équivalent) ;
  - en mode profilé, saisie du **nombre de pièces**, la longueur étant lue sur le lot ;
  - sinon, saisie de la quantité.
- **Bloc « Produit »** :
  - produit (pré-rempli avec le produit consommé), longueur (mm) + nb de pièces, ou quantité ;
  - nom de lot proposé et modifiable ;
  - bouton « + ligne ».
- **Bloc « Reste / perte »** : afficher en direct le reliquat Σ IN − Σ OUT (même unité).
  - Bouton « Déclarer le reste en perte », qui crée la ligne LOSS.
  - Bouton « Remettre le reste en stock », qui ajoute une ligne OUT pré-remplie.
  - L'opérateur peut aussi saisir plusieurs restes à la main (ex. 3 × 400 mm + 1 × 2800 mm). En mode profilé, il saisit des pièces de longueurs différentes.
- **Indicateur d'équilibre** : vert si équilibré, rouge sinon. Le bouton « Valider » est désactivé tant que c'est rouge (R4). Le serveur re-contrôle de toute façon.
- **Vue d'une transformation validée** : lignes + lien vers les mouvements de stock (liste native filtrée sur `inventorycode`) + bouton « Annuler » si droit `cancel`.
- **Assistant de découpe** (dans le bloc Produit, mode profilé, même produit) :
  - saisie de « N pièces de L mm » ;
  - proposition du plan : utiliser d'abord les lots **les plus courts qui conviennent** (chutes d'abord), puis calcul des restes par barre ;
  - pré-remplissage des lignes IN / OUT / restes, que l'opérateur ajuste ensuite.
  - Algorithme simple *first-fit decreasing*, sans optimisation poussée.

### `transformation_list.php`

Liste filtrable (ref, date, type, produit, lot, commande, statut), standard Dolibarr (`print_barre_liste`, tri, pagination).

### Hook `stockproductcard` (onglet Stock de la fiche produit)

- `class/actions_diamantutils.class.php` → `addMoreActionsButtons` : bouton « Transformer ». Il pointe vers `transformation_card.php?action=create&fk_product=<id>` et n'est affiché que si le produit est géré en lots et que l'utilisateur a le droit `write`.
- Vérifier le nom de contexte dans `product/stock/product.php` v20 : `initHooks(array('stockproductcard', 'globalcard'))`.

### Menus

- Menu gauche sous **Produits > Stocks** (`fk_menu=fk_mainmenu=products,fk_leftmenu=stock`) :
  - « Transformations » → liste ;
  - « Nouvelle transformation » → card.
- Vérifier les codes `leftmenu` réels dans `core/menus/standard/eldy.lib.php` v20.

### Droits (descripteur)

`transformation` : `read`, `write` (créer / valider), `cancel` (annuler une transformation validée).

### `admin/setup.php`

- Entrepôt par défaut (`DIAMANTUTILS_TRANSFO_DEFAULT_WAREHOUSE`).
- Format du nom de lot (`DIAMANTUTILS_TRANSFO_LOT_FORMAT`).
- Rien d'autre.

## 7. Arborescence cible

```
diamantutils/
  admin/setup.php
  class/actions_diamantutils.class.php     (hook stockproductcard)
  class/transformation.class.php
  core/modules/modDiamantutils.class.php
  lib/diamantutils.lib.php                 (calcul qty/longueur, lecture lot, onglets)
  js/transformation.js                     (calcul en direct, équilibre, assistant)
  langs/fr_FR/diamantutils.lang
  langs/en_US/diamantutils.lang
  sql/llx_diamantutils_transfo.sql
  sql/llx_diamantutils_transfo.key.sql
  sql/llx_diamantutils_transfo_det.sql
  sql/llx_diamantutils_transfo_det.key.sql
  transformation_card.php
  transformation_list.php
```

Pas de Node, pas de build : JS natif ou jQuery (déjà chargé par Dolibarr).

## 8. Scénarios de test (à décrire dans le PR, Patrice les jouera)

Produit `Horizon_D85a_PRE_BLANC_NP`, unité m², largeur 100 mm, entrepôt « site » :

1. **Découpe simple** : 4 pièces lot `Longueur 4000mm` (1,6 m²) → 10 × 1200 mm (1,2 m²) + 3 × 400 mm (0,12 m², remis en stock lot `Longueur 400mm`) + 1 × 2800 mm (0,28 m², lot `Longueur 2800mm`).
   - Attendu : équilibre OK, lot 4000mm −1,6, nouveaux lots créés, PMP inchangé, mouvements filtrables par la ref.
2. **Découpe avec perte** : 1 × 4000 mm → 3 × 1300 mm + 100 mm déclarés en perte. Attendu : équilibre OK avec ligne LOSS de 0,01 m².
3. **Équilibre faux** : sortie > entrée. Attendu : validation refusée.
4. **Stock insuffisant** : consommer 7 pièces de 4000 mm (6 en stock). Attendu : refus.
5. **Normalisation de lot** : 10 × `Longueur 3000` → 10 × `Longueur 3000mm`. Attendu : simple renommage via transformation, équilibre OK.
6. **Peinture** : produit A → produit B, même unité, même lot. Attendu : stock A −, stock B +, PMP de B calculé à partir du coût de A.
7. **Unités différentes** : A (m²) → B (pièce). Attendu : pas de contrôle de somme, avertissement affiché, validation possible.
8. **Annulation** : annuler la transformation du test 1, puis tenter d'annuler une transformation dont un lot produit a été expédié. Attendu : retour au stock initial, puis refus clair.
9. **Assistant** : demander 10 × 1200 mm. Attendu : les lots les plus courts qui conviennent (2000 mm) sont proposés avant les 4000 mm.

## 9. Livraison

- Commits atomiques par étape (nettoyage, SQL, classe, écrans, hook / menus, langues).
- Messages de commit en français.
- Pousser la branche `feature/transformation-stock` et ouvrir une PR vers `main`.
  - Résumer les choix faits dans la PR, notamment toute signature v20 qui différerait de ce guide.
  - Lister les scénarios de test du §8.
- **Ne pas fusionner** la PR.
- Rappeler dans la PR la procédure de déploiement FTP : copier `diamantutils/` dans `htdocs/custom/`, puis désactiver / réactiver le module pour créer les tables, droits et menus.
