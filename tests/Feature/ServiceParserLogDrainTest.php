<?php

use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\StandaloneDocker;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

it('adds the log drain logging config to both application and database components', function () {
    Bus::fake();
    $team = Team::factory()->create();
    $server = Server::factory()->create(['team_id' => $team->id]);
    $server->settings->update(['is_logdrain_custom_enabled' => true]);
    $destination = StandaloneDocker::where('server_id', $server->id)->firstOrFail();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $service = Service::factory()->create([
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: 'nginx:alpine'
  postgres:
    image: 'postgres:18-alpine'
    environment:
      - 'POSTGRES_PASSWORD=${SERVICE_PASSWORD_POSTGRES}'
YAML,
        'environment_id' => $environment->id,
        'server_id' => $server->id,
        'destination_id' => $destination->id,
        'destination_type' => $destination->getMorphClass(),
    ]);

    // The first parse creates the application and database components.
    serviceParser($service);

    expect($service->applications()->count())->toBe(1)
        ->and($service->databases()->count())->toBe(1);

    $service->applications()->update(['is_log_drain_enabled' => true]);
    $service->databases()->update(['is_log_drain_enabled' => true]);

    $services = serviceParser($service->fresh())->get('services');

    expect(data_get($services, 'app.logging.driver'))->toBe('fluentd')
        ->and(data_get($services, 'postgres.logging.driver'))->toBe('fluentd');
});
