<?php

declare(strict_types=1);

use App\Jobs\Procurement\FetchProcurementDetailJob;
use App\Models\Procurement\Procurement;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

test('явный id ставит одну детальную закупку в очередь', function (): void {
    Queue::fake();
    $procurement = Procurement::factory()->create();

    $this->artisan('crawler:fetch-details', ['procurement' => (string) $procurement->id])
        ->expectsOutputToContain('поставлена в очередь crawl_detail')
        ->assertExitCode(Command::SUCCESS);

    Queue::assertPushed(FetchProcurementDetailJob::class, fn (FetchProcurementDetailJob $job): bool => $job->procurementId === $procurement->id);
    Queue::assertPushed(FetchProcurementDetailJob::class, 1);
});

test('несуществующий id закупки — FAILURE с сообщением об ошибке', function (): void {
    $this->artisan('crawler:fetch-details', ['procurement' => '999999'])
        ->expectsOutputToContain('не найдена')
        ->assertExitCode(Command::FAILURE);
});

test('без аргумента ставит в очередь только ограниченную due-пачку', function (): void {
    Queue::fake();
    config(['crawler.stages.detail.dispatch_batch_limit' => 2]);
    $first = Procurement::factory()->create(['detail_fetched_at' => null]);
    $second = Procurement::factory()->create(['detail_fetched_at' => null]);
    Procurement::factory()->create(['detail_fetched_at' => null]);
    Procurement::factory()->create([
        'detail_fetched_at' => now(),
        'current_revision_detected_at' => now()->subDay(),
    ]);

    $this->artisan('crawler:fetch-details')
        ->expectsOutputToContain('В очередь crawl_detail поставлено 2')
        ->assertExitCode(Command::SUCCESS);

    Queue::assertPushed(FetchProcurementDetailJob::class, 2);
    Queue::assertPushed(FetchProcurementDetailJob::class, fn (FetchProcurementDetailJob $job): bool => in_array($job->procurementId, [$first->id, $second->id], true));
});
