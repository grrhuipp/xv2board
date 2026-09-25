<?php

namespace Tests\Unit;

use App\Jobs\SendMassEmailJob;
use App\Services\MassEmailRateLimiter;
use Illuminate\Contracts\Queue\Job as QueueJob;
use Illuminate\Support\Facades\Mail;
use Mockery;
use Tests\TestCase;

class SendMassEmailJobTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['queue.default' => 'redis']);
    }

    public function test_rate_limited_job_is_released_and_does_not_send_mail()
    {
        $limiter = Mockery::mock(MassEmailRateLimiter::class);
        $limiter->shouldReceive('acquire')->once()->andReturn(17);
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('release')->once()->with(17);

        Mail::shouldReceive('send')->never();
        $job = new SendMassEmailJob(['email' => 'person@example.com']);
        $job->setJob($queueJob);
        $job->handle($limiter);
    }

    public function test_redis_limiter_failure_releases_job_instead_of_dropping_it()
    {
        $limiter = Mockery::mock(MassEmailRateLimiter::class);
        $limiter->shouldReceive('acquire')->once()->andThrow(new \RuntimeException('Redis unavailable'));
        $queueJob = Mockery::mock(QueueJob::class);
        $queueJob->shouldReceive('release')->once()->with(30);

        Mail::shouldReceive('send')->never();
        $job = new SendMassEmailJob(['email' => 'person@example.com']);
        $job->setJob($queueJob);
        $job->handle($limiter);
    }

    public function test_sync_queue_is_rejected_without_sleeping_or_sending()
    {
        config(['queue.default' => 'sync']);
        $limiter = Mockery::mock(MassEmailRateLimiter::class);
        $limiter->shouldNotReceive('acquire');
        Mail::shouldReceive('send')->never();

        $this->expectException(\RuntimeException::class);
        (new SendMassEmailJob(['email' => 'person@example.com']))->handle($limiter);
    }
}
