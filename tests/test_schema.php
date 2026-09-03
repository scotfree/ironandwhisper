<?php
/**
 * Lint for dbmodel.sql.
 *
 * BGA's schema loader is not a normal MySQL client, and the ways it differs
 * cost a deployed game each. These check the file for the shapes that broke it,
 * because nothing else will: the statements are perfectly valid SQL, and they
 * run fine locally.
 */
declare(strict_types=1);

function schemaStatements(): array
{
    $sql = file_get_contents(dirname(__DIR__) . '/dbmodel.sql');
    return array_filter(array_map('trim', explode(';', $sql)));
}

function test_no_statement_carries_a_trailing_comment(): void
{
    // A column whose line ended in a `--` comment was dropped from the created
    // table without a word, and setup then died inserting into it.
    foreach (schemaStatements() as $statement) {
        foreach (explode("\n", $statement) as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            assertFalse(
                str_contains($line, '--'),
                "dbmodel.sql: statement line carries a trailing comment: {$trimmed}",
            );
        }
    }
}

function test_game_tables_are_prefixed(): void
{
    // BGA's database already contains a table called `card`. CREATE TABLE IF NOT
    // EXISTS against a name it already uses is a silent no-op.
    preg_match_all(
        '/CREATE TABLE(?: IF NOT EXISTS)?\s+`([^`]+)`/i',
        file_get_contents(dirname(__DIR__) . '/dbmodel.sql'),
        $matches,
    );

    assertTrue(count($matches[1]) > 0, 'no CREATE TABLE found at all');
    foreach ($matches[1] as $table) {
        assertTrue(
            str_starts_with($table, 'iaw_'),
            "dbmodel.sql creates `{$table}`, which is not prefixed",
        );
    }
}
