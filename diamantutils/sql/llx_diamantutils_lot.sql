-- ============================================================================
-- DiamantUtils — Lots de produits (ensembles prédéfinis ajoutés en une fois sur devis / commande)
-- ============================================================================

CREATE TABLE llx_diamantutils_lot (
  rowid            integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity           integer DEFAULT 1 NOT NULL,
  label            varchar(255) NOT NULL,
  active           tinyint DEFAULT 1 NOT NULL,
  fk_user_creat    integer NULL,
  datec            datetime NULL,
  tms              timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=innodb;
