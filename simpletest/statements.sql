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


-- name: create_accounts
CREATE TABLE accounts (
  id {{AUTOKEY}},
  profile_id {{INT}} NOT NULL DEFAULT 0,
  username VARCHAR(255) NOT NULL DEFAULT '',
  password VARCHAR(255) NULL ,
  age {{INT}} NOT NULL DEFAULT '10',
  height {{DOUBLE}} NOT NULL DEFAULT '10.0',
  favorite_word VARCHAR(255) NULL DEFAULT 'hi',
  birthday TIMESTAMP NOT NULL DEFAULT '1970-01-03 00:00:00'
)

-- name: create_persons
CREATE TABLE persons (
  id {{AUTOKEY}},
  employer_id {{INT}} NOT NULL DEFAULT 0,
  name VARCHAR(255) NOT NULL DEFAULT '',
  age {{INT}} NOT NULL DEFAULT 0,
  height {{DOUBLE}} NOT NULL DEFAULT 0,
  favorite_color VARCHAR(255) NULL,
  favorite_animaniacs VARCHAR(255) NOT NULL DEFAULT '',
  last_happy_moment TIMESTAMP NOT NULL DEFAULT '1970-01-03 00:00:00',
  is_male {{INT}} NOT NULL DEFAULT 0,
  is_alive {{INT}} NULL,
  data VARCHAR(255) NOT NULL DEFAULT ''
)

-- name: create_houses
CREATE TABLE houses (
  id {{AUTOKEY}},
  owner_id {{INT}} NOT NULL DEFAULT 0,
  address VARCHAR(255) NOT NULL DEFAULT '',
  sqft {{INT}} NOT NULL DEFAULT 0,
  price {{INT}} NOT NULL DEFAULT 0
)

-- name: create_souls
CREATE TABLE souls (
  id {{AUTOKEY}},
  person_id {{INT}} NOT NULL DEFAULT 0,
  heaven_bound {{INT}} NOT NULL DEFAULT 0
)

-- name: create_companies
CREATE TABLE companies (
  id {{AUTOKEY}},
  name VARCHAR(255) NOT NULL DEFAULT '',
  shares {{INT}} NOT NULL DEFAULT 0
)

-- name: create_assignments
CREATE TABLE assignments (
  person_id {{INT}} NOT NULL DEFAULT 0,
  company_id {{INT}} NOT NULL DEFAULT 0,
  name VARCHAR(255) NOT NULL DEFAULT '',
  PRIMARY KEY (person_id, company_id)
)

-- name: create_profile
CREATE TABLE profile (
  id {{AUTOKEY}},
  signature VARCHAR(255) NULL DEFAULT 'donewriting'
)

-- name: create_store ; db: mysql
CREATE TABLE `store data` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `picture` BLOB
)

-- name: create_store ; db: sqlite
CREATE TABLE `store data` (
  `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  `picture` BLOB
)

-- name: create_store ; db: pgsql
CREATE TABLE "store data" (
  id SERIAL PRIMARY KEY,
  picture BYTEA
)

-- name: mini_table ; db: mysql
CREATE TABLE `accounts` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `myname` varchar(255) not null
)

-- name: create_faketable ; db: mysql
CREATE TABLE `fake%s:s_``"table` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `my.data` VARCHAR( 1024 ) NULL DEFAULT ''
)

-- name: create_faketable ; db: sqlite
CREATE TABLE `fake%s:s_``"table` (
  `id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT,
  `my.data` VARCHAR( 1024 ) NULL DEFAULT ''
)

-- name: create_faketable ; db: pgsql
CREATE TABLE "fake%s:s_`""table" (
  "id" SERIAL PRIMARY KEY,
  "my.data" VARCHAR( 1024 ) NULL DEFAULT ''
)