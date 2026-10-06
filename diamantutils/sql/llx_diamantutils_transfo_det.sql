-- ============================================================================
-- DiamantUtils — Lignes des transformations de stock par lots
-- ============================================================================

CREATE TABLE llx_diamantutils_transfo_det (
  rowid              integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  fk_transfo         integer NOT NULL,
  direction          varchar(8) NOT NULL,            -- IN (consommé) | OUT (produit) | LOSS (perte)
  fk_product         integer NOT NULL,
  batch              varchar(128),
  nb_pieces          double(24,8) NULL,              -- saisie profilé
  length_mm          double(24,8) NULL,
  qty                double(24,8) NOT NULL,          -- dans l'unité du produit, toujours positive
  unit_cost          double(24,8) NULL,              -- coût unitaire appliqué au mouvement
  fk_stock_mouvement integer NULL,                   -- mouvement créé à la validation
  rang               integer DEFAULT 0
) ENGINE=innodb;
