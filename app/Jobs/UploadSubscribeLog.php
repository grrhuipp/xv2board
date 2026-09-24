<?php

namespace App\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class UploadSubscribeLog implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 12;
    public $timeout = 20;

    private $event;

    public function __construct(array $event)
    {
        $this->event = $event;
    }

    public function backoff(): array
    {
        return [5, 15, 30, 60, 120, 300, 600];
    }

    public function handle(): void
    {
        $url = (string) config('services.subscribe_log.url');
        $token = (string) config('services.subscribe_log.token');
        $serverId = (string) config('services.subscribe_log.server_id');
        $apiHost = (string) ($this->event['api_host'] ?? '');
        if ($url === '' || $token === '' || $serverId === '' || $apiHost === '') {
            throw new RuntimeException('subscribe log service is not configured');
        }

        $response = Http::asJson()
            ->withToken($token)
            ->withHeaders([
                'X-Cnode-Event-Id' => $this->event['event_id'],
                'X-Cnode-Server-Id' => $serverId,
            ])
            ->timeout(10)
            ->post($url, [
                'id' => $this->event['id'],
                'api_host' => $apiHost,
                'user_id' => $this->event['user_id'],
                'email' => $this->event['email'],
                'ip' => $this->event['ip'],
                'as' => $this->event['as'],
                'isp' => $this->event['isp'],
                'country' => $this->event['country'],
                'city' => $this->event['city'],
                'user_agent' => $this->event['user_agent'],
                'created_at' => $this->event['created_at'],
            ]);

        if (!$response->successful()
            || $response->json('accepted') !== true
            || $response->json('event_id') !== $this->event['event_id']) {
            throw new RuntimeException(sprintf(
                'subscribe log upload failed event=%s status=%d',
                $this->event['event_id'],
                $response->status()
            ));
        }
    }
}
