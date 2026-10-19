-- ============================================================================
-- DiamantUtils — Listes personnalisées (requêtes SQL affichées sous Outils)
-- ============================================================================

CREATE TABLE llx_diamantutils_customlist (
  rowid            integer AUTO_INCREMENT PRIMARY KEY NOT NULL,
  entity           integer DEFAULT 1 NOT NULL,
  label            varchar(255) NOT NULL,
  description      text,
  sqlquery         text NOT NULL,                  -- requête SELECT (__PREFIX__, __ENTITY__ remplacés)
  position         integer DEFAULT 0 NOT NULL,
  active           smallint DEFAULT 1 NOT NULL,
  date_creation    datetime NOT NULL,
  tms              timestamp DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  fk_user_creat    integer NOT NULL,
  fk_user_modif    integer NULL
) ENGINE=innodb;
