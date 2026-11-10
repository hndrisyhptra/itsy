<?php

namespace Tests\Feature;

use App\Http\Controllers\PrototypeController;
use App\Models\PrototypeWorkspace;
use App\Services\PrototypeWorkspaceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Mockery;
use Tests\TestCase;

class WorkspaceUrlTest extends TestCase
{
    public function test_boot_urls_stay_origin_relative_for_root_and_subfolder_even_with_an_http_upstream(): void
    {
        foreach (['', '/its/area3-prototype/public'] as $base) {
            $request = Request::create('http://internal-upstream'.$base.'/dashboard', 'GET', [], [], [], [
                'SCRIPT_NAME' => $base.'/index.php', 'SCRIPT_FILENAME' => '/var/www/index.php', 'PHP_SELF' => $base.'/index.php',
                'HTTP_X_FORWARDED_PROTO' => 'https',
            ]);
            $this->assertSame($base, $request->getBaseUrl());
            app('url')->setRequest($request);
            URL::forceRootUrl('http://internal-upstream'.$base);
            $workspace = new PrototypeWorkspace;
            $service = Mockery::mock(PrototypeWorkspaceService::class);
            $service->shouldReceive('workspace')->once()->with($request)->andReturn($workspace);
            $service->shouldReceive('payload')->once()->with($workspace)->andReturn(['state' => [], 'revision' => 0]);
            $boot = app(PrototypeController::class)->show($request, $service)->getData()['boot'];
            $this->assertSame($base, $boot['basePath']);
            $this->assertSame([
                'state' => $base.'/api/prototype/state', 'commands' => $base.'/api/prototype/commands',
                'csv' => $base.'/api/prototype/exports/csv', 'backup' => $base.'/api/prototype/backup',
            ], $boot['urls']);
            $this->assertSame(['logout' => $base.'/logout', 'manage' => $base.'/akun', 'login' => $base.'/login'], $boot['accountUrls']);
        }
    }
}
