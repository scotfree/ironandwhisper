<?php
/**
 *------
 * BGA framework: Gregory Isabelli & Emmanuel Colin & BoardGameArena
 * IronAndWhisper implementation : © Scot Free Kennedy
 *
 * This code has been produced on the BGA studio platform for use on http://boardgamearena.com.
 * See http://en.boardgamearena.com/#!doc/Studio for more information.
 * -----
 */
/**
 * Thrown by Rules when a submitted turn violates the rules.
 *
 * Deliberately not a BGA UserException: Rules must stay runnable outside the
 * framework so it can be tested. The state classes catch this and rethrow.
 */
declare(strict_types=1);

namespace Bga\Games\IronAndWhisper;

class IllegalMove extends \RuntimeException
{
}
