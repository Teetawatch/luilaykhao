<?php

namespace Tests\Feature;

use App\Jobs\DeleteMediaFilesJob;
use Aws\CommandInterface;
use Aws\Exception\AwsException;
use Aws\MockHandler;
use Aws\Result;
use Aws\S3\S3Client;
use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter as FlysystemS3Adapter;
use League\Flysystem\Filesystem;
use Tests\TestCase;

/**
 * Sweeping a round's photos off R2 must be one request per chunk, not one per
 * file — 400 single deletes ran past the queue's retry_after and the job died
 * as "attempted too many times" while it was still working.
 */
class DeleteMediaFilesJobTest extends TestCase
{
    /** @var array<int, array{name: string, args: array}> */
    private array $calls = [];

    private function fakeS3Disk(MockHandler $mock): void
    {
        $client = new S3Client([
            'region' => 'auto',
            'version' => 'latest',
            'endpoint' => 'https://r2.example.test',
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => 'k', 'secret' => 's'],
            'handler' => $mock,
        ]);

        $adapter = new FlysystemS3Adapter($client, 'media-bucket', 'site');

        Storage::set('r2', new AwsS3V3Adapter(
            new Filesystem($adapter),
            $adapter,
            ['bucket' => 'media-bucket', 'root' => 'site'],
            $client,
        ));
    }

    /** ตอบทุกคำขอด้วย $response แล้วจดไว้ว่าเรียกอะไรไปบ้าง */
    private function record(Result|\Closure $response): \Closure
    {
        return function (CommandInterface $command) use ($response) {
            $this->calls[] = ['name' => $command->getName(), 'args' => $command->toArray()];

            return $response instanceof \Closure ? $response($command) : $response;
        };
    }

    public function test_s3_disk_deletes_a_whole_chunk_in_one_request(): void
    {
        $mock = new MockHandler;
        $mock->append($this->record(new Result([])));
        $this->fakeS3Disk($mock);

        $paths = array_map(fn ($i) => "schedules/{$i}.jpg", range(1, 400));
        (new DeleteMediaFilesJob('r2', [...$paths, 'schedules/1.jpg', null]))->handle();

        $this->assertCount(1, $this->calls);
        $this->assertSame('DeleteObjects', $this->calls[0]['name']);
        $this->assertSame('media-bucket', $this->calls[0]['args']['Bucket']);

        $keys = array_column($this->calls[0]['args']['Delete']['Objects'], 'Key');
        $this->assertCount(400, $keys);
        // คีย์ต้องมี root ของ disk นำหน้า ไม่งั้นลบผิดไฟล์ (หรือไม่ลบอะไรเลย)
        $this->assertSame('site/schedules/1.jpg', $keys[0]);
    }

    public function test_falls_back_to_single_deletes_when_batch_delete_is_rejected(): void
    {
        $mock = new MockHandler;
        $mock->append($this->record(fn (CommandInterface $command) => new AwsException('NotImplemented', $command)));
        $mock->append($this->record(new Result([])));
        $mock->append($this->record(new Result([])));
        $this->fakeS3Disk($mock);

        (new DeleteMediaFilesJob('r2', ['a.jpg', 'b.jpg']))->handle();

        $this->assertSame(
            ['DeleteObjects', 'DeleteObject', 'DeleteObject'],
            array_column($this->calls, 'name'),
        );
        $this->assertSame(['site/a.jpg', 'site/b.jpg'], array_column(array_slice(array_column($this->calls, 'args'), 1), 'Key'));
    }

    public function test_local_disk_deletes_the_files(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('schedules/a.jpg', 'x');
        Storage::disk('public')->put('schedules/a_thumb.jpg', 'x');
        Storage::disk('public')->put('schedules/keep.jpg', 'x');

        (new DeleteMediaFilesJob('public', ['schedules/a.jpg', 'schedules/a_thumb.jpg']))->handle();

        Storage::disk('public')->assertMissing(['schedules/a.jpg', 'schedules/a_thumb.jpg']);
        Storage::disk('public')->assertExists('schedules/keep.jpg');
    }
}
