<?php
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
