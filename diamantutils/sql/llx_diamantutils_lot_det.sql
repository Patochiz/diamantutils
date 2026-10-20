-- ============================================================================
-- DiamantUtils — Lignes des lots de produits
-- ============================================================================

CREATE TABLE llx_diamantutils_lot_det (
  rowid            integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  fk_lot           integer NOT NULL,
  rang             integer DEFAULT 0 NOT NULL,     -- ordre d'insertion sur la pièce
  fk_product       integer NOT NULL,
  qty              double DEFAULT 1 NOT NULL       -- multipliée par le multiplicateur saisi à l'insertion
) ENGINE=innodb;
