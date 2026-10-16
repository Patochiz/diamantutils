# DiamantUtils

Module Dolibarr interne de Diamant Industrie (Dolibarr 20, PHP 8.2, déploiement FTP).

- **Ordres de transformation** de stock par lots (menu GPAO) : voir `GUIDE_TRANSFORMATION_V2.md`.
- **Commandes sous-traitées** (livraison directe client) : section ci-dessous.

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
