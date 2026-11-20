-- ============================================================================
-- DiamantUtils — Tableau de stock par lots : colisage constaté au dépôt
-- Une ligne = un lot (ou une partie de lot) d'un produit Dolibarr.
-- Les quantités calculées (colis, quantité dans l'unité du produit) ne sont pas stockées.
-- ============================================================================

CREATE TABLE llx_diamantutils_stockline (
  rowid            integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity           integer DEFAULT 1 NOT NULL,
  fk_product       integer NOT NULL,
  batch            varchar(128) DEFAULT '' NOT NULL,   -- '' = produit sans lot
  fk_soc           integer NULL,                       -- fournisseur, facultatif
  nb_pieces        double(24,8) NOT NULL,              -- nombre de pièces
  pieces_per_colis double(24,8) NOT NULL,              -- pièces par colis
  larg_mm          double(24,8) NULL,
  long_mm          double(24,8) NULL,
  note             varchar(255) NULL,
  datec            datetime NULL,
  tms              timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_creat    integer NULL,
  fk_user_modif    integer NULL
) ENGINE=innodb;
