-- name: AUTOKEY ; db: mysql
INT NOT NULL AUTO_INCREMENT PRIMARY KEY

-- name: AUTOKEY ; db: sqlite
INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT

-- name: AUTOKEY ; db: pgsql
SERIAL PRIMARY KEY

-- name: INT ; db: mysql
INT

-- name: INT ; db: sqlite
INTEGER

-- name: INT ; db: pgsql
INT

-- name: DOUBLE ; db: mysql
DOUBLE

-- name: DOUBLE ; db: sqlite
DOUBLE

-- name: DOUBLE ; db: pgsql
DOUBLE PRECISION

-- name: BOOL ; db: mysql
TINYINT(1)

-- name: BOOL ; db: sqlite
INTEGER

-- name: BOOL ; db: pgsql
BOOLEAN


-- ************ standard tables, created by resetDatabase() in test.php

-- name: create_users
CREATE TABLE users (
  user_id {{AUTOKEY}},
  employer_id {{INT}} NULL,
  name VARCHAR(255) NOT NULL DEFAULT '',
  nickname VARCHAR(255) NULL DEFAULT 'buddy',
  age {{INT}} NOT NULL DEFAULT 0,
  height {{DOUBLE}} NOT NULL DEFAULT 0,
  is_active {{BOOL}} NULL,
  settings VARCHAR(1024) NOT NULL DEFAULT '',
  signed_up_at TIMESTAMP NOT NULL DEFAULT '1970-01-03 00:00:00'
)

-- name: create_companies
CREATE TABLE companies (
  company_id {{AUTOKEY}},
  name VARCHAR(255) NOT NULL DEFAULT ''
)

-- name: create_profiles
CREATE TABLE profiles (
  profile_id {{AUTOKEY}},
  user_id {{INT}} NOT NULL DEFAULT 0,
  bio VARCHAR(255) NULL,
  tags VARCHAR(255) NULL
)

-- name: create_memberships
CREATE TABLE memberships (
  user_id {{INT}} NOT NULL,
  company_id {{INT}} NOT NULL,
  role VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (user_id, company_id)
)

-- table and column names full of characters that need quoting, or look like placeholders
-- name: create_weird ; db: mysql
CREATE TABLE `weird %s:s_``"table` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `my.data` VARCHAR(1024) NULL DEFAULT '',
  `picture` BLOB NULL
)

-- name: create_weird ; db: sqlite
CREATE TABLE `weird %s:s_``"table` (
  `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  `my.data` VARCHAR(1024) NULL DEFAULT '',
  `picture` BLOB NULL
)

-- name: create_weird ; db: pgsql
CREATE TABLE "weird %s:s_`""table" (
  "id" SERIAL PRIMARY KEY,
  "my.data" VARCHAR(1024) NULL DEFAULT '',
  "picture" BYTEA NULL
)


-- ************ other tables, created by the tests that use them

-- primary key is (id, region), for BasicOrmTest; sqlite only allows AUTOINCREMENT on a single-column primary key
-- name: create_tickets ; db: mysql
CREATE TABLE tickets (
  id INT NOT NULL AUTO_INCREMENT,
  region INT NOT NULL DEFAULT 1,
  name VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id, region)
)

-- name: create_tickets ; db: pgsql
CREATE TABLE tickets (
  id SERIAL,
  region INT NOT NULL DEFAULT 1,
  name VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (id, region)
)
