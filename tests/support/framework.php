<?php
/**
 * A minimal stand-in for the parts of the BGA framework this game touches,
 * backed by an in-memory SQLite database.
 *
 * This is NOT an attempt to reimplement BGA. It exists so the real Game, Board
 * and state classes can be run and asserted on locally — there is no PHP on the
 * Studio side of the loop we can test against, and deploying to find out that a
 * turn is wrong is a slow way to work.
 *
 * Where behaviour is guessed rather than documented (getCollectionFromDB's
 * exact shape, mainly) the guess is written down here so a surprise on the
 * Studio has one place to be fixed.
 */
declare(strict_types=1);

namespace Bga\GameFramework {

    enum StateType
    {
        case ACTIVE_PLAYER;
        case MULTIPLE_ACTIVE_PLAYER;
        case GAME;
        case MANAGER;
        case PRIVATE_PLAYER;
    }

    class UserException extends \Exception
    {
    }

    class Globals
    {
        private array $values = [];

        public function get(string $name, mixed $default = null, ?string $class = null): mixed
        {
            return $this->values[$name] ?? $default;
        }

        public function set(string $name, mixed $value): void
        {
            $this->values[$name] = $value;
        }

        public function inc(string $name, int $step): int
        {
            // BGA refuses to increment a global that was never set, with
            // "is not a numeric value". Mirroring that here turns a bug that
            // used to reach the Studio into a failing test.
            if (!array_key_exists($name, $this->values)) {
                throw new \RuntimeException(
                    "Error when incrementing a global variable: {$name} is not a numeric value."
                );
            }
            $this->values[$name] = (int) $this->values[$name] + $step;
            return $this->values[$name];
        }

        public function has(string $name): bool
        {
            return isset($this->values[$name]);
        }
    }

    /** Records notifications instead of sending them, so tests can assert on them. */
    class Notify
    {
        /** @var array<int, array{scope: string, player: ?int, name: string, args: array}> */
        public array $sent = [];

        public function all(string $name, string|object $message = '', array $args = []): void
        {
            $this->sent[] = ['scope' => 'all', 'player' => null, 'name' => $name, 'message' => $message, 'args' => $args];
        }

        public function player(int $playerId, string $name, string|object $message = '', array $args = []): void
        {
            $this->sent[] = ['scope' => 'player', 'player' => $playerId, 'name' => $name, 'message' => $message, 'args' => $args];
        }

        public function addDecorator(callable $fn): void
        {
        }

        /** @return array<int, array> notifications of one name, optionally for one player */
        public function of(string $name, ?int $playerId = null): array
        {
            return array_values(array_filter(
                $this->sent,
                fn(array $n) => $n['name'] === $name && ($playerId === null || $n['player'] === $playerId),
            ));
        }

        public function clear(): void
        {
            $this->sent = [];
        }
    }

    class TableOptions
    {
        public array $values = [];

        public function get(int $optionId): ?int
        {
            return $this->values[$optionId] ?? null;
        }
    }

    /** Scores, stored on the player table exactly as BGA stores them. */
    class PlayerCounter
    {
        public function __construct(private string $column = 'player_score')
        {
        }

        public function get(int $playerId): int
        {
            return (int) Db::value(
                "SELECT `{$this->column}` FROM `player` WHERE `player_id` = {$playerId}"
            );
        }

        public function inc(int $playerId, int $delta, mixed $message = null): int
        {
            Db::query(
                "UPDATE `player` SET `{$this->column}` = `{$this->column}` + ({$delta}) WHERE `player_id` = {$playerId}"
            );
            return $this->get($playerId);
        }

        public function set(int $playerId, int $value, mixed $message = null): int
        {
            Db::query("UPDATE `player` SET `{$this->column}` = {$value} WHERE `player_id` = {$playerId}");
            return $value;
        }

        public function getAll(): array
        {
            return Db::collection("SELECT `player_id`, `{$this->column}` FROM `player`", true);
        }

        public function fillResult(array &$result, ?string $fieldName = null, ?int $playerId = null): void
        {
        }
    }

    class Bga
    {
        public Globals $globals;
        public Notify $notify;
        public TableOptions $tableOptions;
        public PlayerCounter $playerScore;
        public PlayerCounter $playerScoreAux;

        public function __construct()
        {
            $this->globals = new Globals();
            $this->notify = new Notify();
            $this->tableOptions = new TableOptions();
            $this->playerScore = new PlayerCounter('player_score');
            $this->playerScoreAux = new PlayerCounter('player_score_aux');
        }
    }

    /** Stands in for BGA's state machine. Only what the game actually calls. */
    class GameStateMachine
    {
        public ?int $activePlayerId = null;

        public function changeActivePlayer(int $playerId): void
        {
            $this->activePlayerId = $playerId;
        }

        public function jumpToState(int|string $state): void
        {
        }
    }

    /** The SQLite connection behind the static DB helpers. */
    final class Db
    {
        private static ?\PDO $pdo = null;

        public static function connect(): \PDO
        {
            if (self::$pdo === null) {
                self::$pdo = new \PDO('sqlite::memory:');
                self::$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            }
            return self::$pdo;
        }

        public static function reset(): void
        {
            self::$pdo = null;
            self::connect();
        }

        public static function query(string $sql): void
        {
            self::connect()->exec($sql);
        }

        /** @return array<int, array<string, mixed>> */
        public static function rows(string $sql): array
        {
            return self::connect()->query($sql)->fetchAll(\PDO::FETCH_ASSOC);
        }

        public static function value(string $sql): mixed
        {
            $row = self::connect()->query($sql)->fetch(\PDO::FETCH_NUM);
            return $row === false ? null : $row[0];
        }

        /**
         * BGA's getCollectionFromDB: keyed by the first selected column. With
         * $singleValue the value is the second column, otherwise the whole row.
         */
        public static function collection(string $sql, bool $singleValue = false): array
        {
            $result = [];
            foreach (self::rows($sql) as $row) {
                $keys = array_keys($row);
                $key = $row[$keys[0]];
                $result[$key] = $singleValue ? $row[$keys[1]] : $row;
            }
            return $result;
        }

        /**
         * Load dbmodel.sql into SQLite. MySQL-isms are rewritten here rather
         * than kept in a second copy of the schema: the file the Studio runs is
         * the file the tests run.
         */
        public static function loadSchema(string $path): void
        {
            self::query(
                'CREATE TABLE `player` (
                    `player_id` INTEGER PRIMARY KEY,
                    `player_no` INTEGER NOT NULL DEFAULT 0,
                    `player_name` VARCHAR(32) NOT NULL DEFAULT \'\',
                    `player_color` VARCHAR(6) NOT NULL DEFAULT \'\',
                    `player_score` INTEGER NOT NULL DEFAULT 0,
                    `player_score_aux` INTEGER NOT NULL DEFAULT 0
                )'
            );

            // BGA's database template already contains a `card` table. Its exact
            // shape is not documented here — what matters is that it exists, so
            // a CREATE TABLE IF NOT EXISTS against a generic name is a silent
            // no-op and the first INSERT fails on an unknown column. That cost a
            // deploy to find; this decoy makes it cost a test run instead.
            self::query('CREATE TABLE `card` (`card_id` INTEGER PRIMARY KEY, `reserved` INTEGER)');

            $sql = file_get_contents($path);
            $sql = preg_replace('/^\s*--.*$/m', '', $sql);

            foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
                $statement = preg_replace('/\bINT UNSIGNED NOT NULL AUTO_INCREMENT\b/i', 'INTEGER', $statement);
                $statement = preg_replace('/\b(SMALLINT|TINYINT|INT) UNSIGNED\b/i', 'INTEGER', $statement);
                $statement = preg_replace('/\)\s*ENGINE=.*$/is', ')', $statement);
                // SQLite declares indexes separately; drop inline KEY clauses.
                $statement = preg_replace('/,\s*KEY\s+`[^`]+`\s*\([^)]*\)/i', '', $statement);
                $statement = preg_replace('/\bALTER TABLE (`?\w+`?) ADD (?!COLUMN)/i', 'ALTER TABLE $1 ADD COLUMN ', $statement);
                self::query($statement);
            }
        }
    }

    /**
     * The base class the game extends. Only the methods Iron and Whisper calls
     * are present; anything else is a deliberate gap, not an oversight.
     */
    abstract class Table
    {
        public Bga $bga;
        public GameStateMachine $gamestate;

        /** @var array<int, string> player id => name, for getPlayerNameById */
        public array $playerNames = [];

        public function __construct()
        {
            $this->bga = new Bga();
            $this->gamestate = new GameStateMachine();
        }

        final public static function DbQuery(string $sql): void
        {
            Db::query($sql);
        }

        final public static function getCollectionFromDB(string $sql, bool $singleValue = false): array
        {
            return Db::collection($sql, $singleValue);
        }

        final public static function getNonEmptyCollectionFromDB(string $sql): array
        {
            $rows = Db::collection($sql);
            if (!$rows) {
                throw new \RuntimeException("empty collection: {$sql}");
            }
            return $rows;
        }

        final public static function getObjectListFromDB(string $sql, bool $uniqueValue = false): array
        {
            $rows = Db::rows($sql);
            if (!$uniqueValue) {
                return $rows;
            }
            return array_map(fn(array $row) => reset($row), $rows);
        }

        final public static function getObjectFromDB(string $sql): array
        {
            $rows = Db::rows($sql);
            return $rows[0] ?? [];
        }

        final public static function getUniqueValueFromDB(string $sql): mixed
        {
            return Db::value($sql);
        }

        public function getGameinfos(): array
        {
            $raw = file_get_contents(__DIR__ . '/../../gameinfos.jsonc');
            $raw = preg_replace('#^\s*//.*$#m', '', $raw);
            $raw = preg_replace('#/\*.*?\*/#s', '', $raw);
            return json_decode($raw, true);
        }

        public function reattributeColorsBasedOnPreferences(array $players, array $colors): void
        {
        }

        public function reloadPlayersBasicInfos(): void
        {
            $this->playerNames = [];
            foreach (Db::rows('SELECT `player_id`, `player_name` FROM `player`') as $row) {
                $this->playerNames[(int) $row['player_id']] = (string) $row['player_name'];
            }
        }

        public function getPlayerNameById(int $playerId): string
        {
            return $this->playerNames[$playerId] ?? "player {$playerId}";
        }

        public function getPlayersNumber(): int
        {
            return (int) Db::value('SELECT COUNT(*) FROM `player`');
        }

        public function giveExtraTime(int $playerId, ?int $seconds = null): void
        {
        }

        public function activeNextPlayer(): int
        {
            return 0;
        }

        public function getActivePlayerId(): string
        {
            return (string) $this->gamestate->activePlayerId;
        }
    }
}

namespace Bga\GameFramework\States {

    #[\Attribute]
    class PossibleAction
    {
    }

    abstract class GameState
    {
        public \Bga\GameFramework\Bga $bga;
        public \Bga\GameFramework\Notify $notify;
        public ?\Bga\GameFramework\GameStateMachine $gamestate = null;

        public function __construct(
            \Bga\GameFramework\Table $game,
            public int $id,
            public \Bga\GameFramework\StateType $type,
            public ?string $name = null,
            public string $description = '',
            public string $descriptionMyTurn = '',
            public array $transitions = [],
            public bool $updateGameProgression = false,
            public int|string|null $initialPrivate = null,
        ) {
            // The real framework injects these; do it here so a state class can
            // simply be constructed in a test.
            $this->bga = $game->bga;
            $this->notify = $game->bga->notify;
            $this->gamestate = $game->gamestate;
        }

        public function getRandomZombieChoice(array $choices): mixed
        {
            return $choices[array_rand($choices)];
        }

        public function getBestZombieChoice(array $choices, bool $reversed = false): mixed
        {
            return array_key_first($choices);
        }
    }
}

namespace Bga\GameFramework\Actions\Types {

    #[\Attribute]
    class JsonParam
    {
        public function __construct(
            ?string $name = null,
            public ?bool $associative = true,
            public ?bool $alphanum = true,
            public ?string $class = null,
        ) {
        }
    }
}

namespace {

    function clienttranslate(string $text): string
    {
        return $text;
    }
}
