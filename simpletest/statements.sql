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


-- ************ older tables, being phased out


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

-- name: create_companies_old
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

-- name: create_bool_test ; db: pgsql
CREATE TABLE bool_test (
  id SERIAL PRIMARY KEY,
  label VARCHAR(255) NOT NULL DEFAULT '',
  flag BOOLEAN NOT NULL,
  nullable_flag BOOLEAN NULL
)

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

-- name: create_authors
CREATE TABLE authors (
  author_id {{AUTOKEY}},
  name VARCHAR(255) NOT NULL DEFAULT ''
)

-- name: create_books
CREATE TABLE books (
  book_id {{AUTOKEY}},
  writer_id {{INT}} NOT NULL DEFAULT 0,
  title VARCHAR(255) NOT NULL DEFAULT ''
)

-- name: create_bios
CREATE TABLE bios (
  bio_id {{AUTOKEY}},
  author_id {{INT}} NOT NULL DEFAULT 0,
  summary VARCHAR(255) NOT NULL DEFAULT '',
  tags VARCHAR(255) NULL
)
