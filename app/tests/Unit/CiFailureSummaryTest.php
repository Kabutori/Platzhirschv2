<?php
namespace Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 3) . '/tools/CiFailureSummary.php';

class CiFailureSummaryTest extends TestCase
{
    public function test_diagnostic_excludes_sql_credentials_and_stack_trace(): void
    {
        $input =
            "PDOException: SQLSTATE[42000]: Syntax error or access violation: 1044 Access denied for user 'sensitive-user' to database 'sensitive-db' in C:\\ph-ci\\app\\app\\Jobs\\ProvisionTenant.php:40\nSQL: CREATE USER 'sensitive-user' IDENTIFIED BY 'sensitive-password'";
        $this->assertSame(
            [
                'category' => 'database',
                'sqlstate' => '42000',
                'driver_code' => 1044,
                'provision_line' => 40,
            ],
            \ciFailureSummary($input),
        );
    }

    public function test_unknown_error_text_is_never_returned(): void
    {
        $this->assertSame(
            ['category' => 'unclassified'],
            \ciFailureSummary('Private message with password=example-secret'),
        );
    }
}
