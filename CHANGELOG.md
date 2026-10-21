# Changelog — DiamantUtils

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
