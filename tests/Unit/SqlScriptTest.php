<?php

namespace Tests\Unit;

use App\Services\SqlScript;
use PHPUnit\Framework\TestCase;

class SqlScriptTest extends TestCase
{
    public function test_comments_and_quoted_semicolons_cannot_hide_migrations(): void
    {
        $source = <<<'SQL'
-- compatibility; keep old rows
ALTER TABLE users ADD fence tinyint DEFAULT 0;
/* comment; */ ALTER TABLE users ALTER COLUMN fence SET DEFAULT 1;
INSERT INTO notes VALUES ('a;b', 'it''s; valid', 'escaped\';quote'); # tail;
SQL;
        $statements = SqlScript::statements($source);
        $this->assertCount(3, $statements);
        $this->assertSame('ALTER TABLE users ADD fence tinyint DEFAULT 0', $statements[0]);
        $this->assertSame('ALTER TABLE users ALTER COLUMN fence SET DEFAULT 1', $statements[1]);
        $this->assertStringContainsString("'a;b'", $statements[2]);
    }

    public function test_unfinished_sql_is_rejected_before_execution(): void
    {
        $this->expectException(\RuntimeException::class);
        SqlScript::statements("CREATE TABLE example (value varchar(10) DEFAULT 'unfinished);");
    }
}
