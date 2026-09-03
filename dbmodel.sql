-- ------
-- BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
-- IronAndWhisper implementation : Scot Free Kennedy
--
-- This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
-- See http://en.boardgamearena.com/#!doc/Studio for more information.
-- -----

-- Schema for Iron and Whisper. Mirrors the state carried by sim/engine.py
-- GameState, minus everything that is static configuration: town ids, labels,
-- coordinates and adjacency all come from maps/*.json at runtime, never from
-- the database.
--
-- KEEP THIS FILE BORING. Two rules, both learned by breaking a game on the
-- Studio, and neither reproducible against a normal MySQL client:
--
-- 1. No trailing comments inside a statement. A column whose line ended in a
--    comment was silently dropped from the created table, and the first insert
--    then failed on an unknown column.
-- 2. Tables are prefixed iaw_. BGA already has a table called card, and
--    CREATE TABLE IF NOT EXISTS against it does nothing and says nothing.
--
-- tests/test_schema.php enforces both.


-- One row per town on the map, created at setup from the map JSON.
--
-- troops is Empire strength-in-place. Resolution spends it (Decision 3), so a
-- resolved town always ends with 0. The three resolved_ columns record the
-- fight after the fact so the board stays a readable history. winner holds
-- empire, insurgency, or NULL while the town is still open.
CREATE TABLE IF NOT EXISTS `iaw_town` (
  `town_id` VARCHAR(32) NOT NULL,
  `troops` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `resolved` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  `winner` VARCHAR(16) NULL DEFAULT NULL,
  `resolved_influence` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  `resolved_strength` SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`town_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;


-- The Insurgency deck. Deliberately NOT BGA's Deck component: that models a
-- deck plus hands, and what this game needs is a dozen ordered piles that
-- rotate under peeking while card identity stays stable.
--
-- card_type keys into data/cards.json.
--
-- card_location is deck, hand, or town:<town_id>.
--
-- location_order means:
--   deck, draw order, lowest first.
--   pile, position in the pile, 0 is the TOP. New cards go on top. A peek takes
--         the top card and returns it to the bottom (Decision 8).
--   hand, unused.
--
-- empire_seen is the simulator empire_known_uids: once the Empire has looked at
-- a card it knows it forever, however far the pile rotates afterwards.
CREATE TABLE IF NOT EXISTS `iaw_card` (
  `card_id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `card_type` VARCHAR(16) NOT NULL,
  `card_location` VARCHAR(40) NOT NULL,
  `location_order` INT NOT NULL DEFAULT 0,
  `empire_seen` TINYINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`card_id`),
  KEY `iaw_card_location_idx` (`card_location`, `location_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 AUTO_INCREMENT=1;


-- Which side each player commands. Set once at setup from game option 100.
-- The sides are asymmetric in every respect, so nearly all game logic and the
-- whole hidden-information boundary keys off this column.
ALTER TABLE `player` ADD `player_side` VARCHAR(16) NOT NULL DEFAULT '';
