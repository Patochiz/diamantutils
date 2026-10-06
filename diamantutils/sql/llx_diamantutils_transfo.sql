-- ============================================================================
-- DiamantUtils — En-tête des transformations de stock par lots
-- ============================================================================

CREATE TABLE llx_diamantutils_transfo (
  rowid            integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity           integer DEFAULT 1 NOT NULL,
  ref              varchar(30) NOT NULL,           -- TRANSFO-AAMM-NNNN
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
