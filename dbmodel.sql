-- ------
-- BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
-- IronAndWhisper implementation : © Scot Free Kennedy
--
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-- -----

-- Schema for Iron and Whisper. Mirrors the state carried by sim/engine.py's
-- GameState, minus everything that is static configuration: town ids, labels,
-- coordinates and adjacency all come from maps/*.json at runtime, never from
-- the database.
--
-- Tables are prefixed `iaw_` because BGA's database template already contains a
-- `card` table. CREATE TABLE IF NOT EXISTS against that name does nothing and
-- says nothing; the first INSERT then fails on a column that does not exist.
-- Do not drop the prefix.


-- One row per town on the map, created at setup from the map JSON.
--
-- `troops` is Empire strength-in-place; resolution spends it (Decision 3), so a
-- resolved town always ends with 0. The three `resolved_*` columns record the
-- fight after the fact so the board stays a readable history.
CREATE TABLE IF NOT EXISTS `iaw_town` (
  `town_id` VARCHAR(32) NOT NULL,
  `troops` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `resolved` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `winner` VARCHAR(16) NULL DEFAULT NULL,           -- 'empire' | 'insurgency' | NULL
  `resolved_influence` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `resolved_strength` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`town_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- The Insurgency deck. Deliberately NOT BGA's Deck component: that models a
-- deck plus hands, and what this game needs is a dozen ordered piles that
-- rotate under peeking while card identity stays stable.
--
-- `card_location` is 'deck', 'hand', or 'town:<town_id>'.
-- `location_order` means:
--   deck  — draw order, lowest first.
--   pile  — position in the pile, 0 = TOP. New cards go on top; a peek takes
--           the top card and returns it to the bottom (Decision 8).
--   hand  — unused.
-- `empire_seen` is the sim's `empire_known_uids`: once the Empire has looked at
-- a card it knows it forever, however far the pile rotates afterwards.
CREATE TABLE IF NOT EXISTS `iaw_card` (
  `card_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_type` VARCHAR(16) NOT NULL,                 -- key into data/cards.json
  `card_location` VARCHAR(40) NOT NULL,
  `location_order` INT NOT NULL DEFAULT 0,
  `empire_seen` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_id`),
  KEY `card_location_idx` (`card_location`, `location_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;


-- Which side each player commands. Set once at setup from game option 100.
-- The sides are asymmetric in every respect, so nearly all game logic and the
-- whole hidden-information boundary keys off this column.
ALTER TABLE `player` ADD `player_side` VARCHAR(16) NOT NULL DEFAULT '';
