<?php

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

class BudgetPidStage4GuardTest extends TestCase
{
    public function test_operator_tool_refuses_non_qa_paths_before_loading_laravel_or_connecting(): void
    {
        $repo = dirname(__DIR__, 2);
        foreach ([$repo, '/private/tmp', '/private/tmp/area3-pid-stage4-marker-missing'] as $path) {
            $process = new Process([PHP_BINARY, $repo.'/tools/qa_budget_pid_stage4.php', $path, 'migrate']);
            $process->setTimeout(10);
            $process->run();
            $output = $process->getErrorOutput().$process->getOutput();
            $this->assertFalse($process->isSuccessful());
            $this->assertStringContainsString('Requires an explicit private', $output);
            $this->assertStringNotContainsString('PDOException', $output);
            $this->assertStringNotContainsString('bootstrap/app.php', $output);
        }
    }
}
