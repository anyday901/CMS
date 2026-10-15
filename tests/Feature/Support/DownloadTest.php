<?php

namespace Tests\Feature\Support;

use App\Enums\ServiceStatus;
use App\Http\Controllers\Admin\DownloadController;
use App\Models\Client;
use App\Models\Download;
use App\Models\DownloadCategory;
use App\Models\Product;
use App\Models\Service;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DownloadTest extends TestCase
{
    use RefreshDatabase;

    private const ADMIN = '/admin/downloads';

    private const AGENT = 'Backup agent';

    private DownloadCategory $category;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->category = DownloadCategory::create(['name' => 'Software']);
    }

    private function download(array $attributes = []): Download
    {
        Storage::disk('local')->put('downloads/agent', 'binary');

        return Download::create($attributes + [
            'download_category_id' => $this->category->id, 'title' => self::AGENT,
            'path' => 'downloads/agent', 'file_name' => 'agent.zip', 'mime' => 'application/zip', 'size' => 6,
        ]);
    }

    public function test_staff_upload_replace_and_delete_downloads(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $product = Product::factory()->create();

        $this->post(self::ADMIN, [
            'download_category_id' => $this->category->id, 'title' => self::AGENT,
            'file' => UploadedFile::fake()->createWithContent('agent.zip', 'v1'), 'product_ids' => [$product->id],
        ])->assertRedirect(self::ADMIN);
        $download = Download::sole();
        $this->assertSame(['agent.zip', 2, [$product->id]], [$download->file_name, $download->size, $download->products->modelKeys()]);
        Storage::disk('local')->assertExists($download->path);
        $this->get(self::ADMIN)->assertOk()->assertSee(self::AGENT)->assertSee("Clients with {$product->name}");

        $old = $download->path;
        $this->put(self::ADMIN."/{$download->id}", [
            'download_category_id' => $this->category->id, 'title' => self::AGENT, 'clients_only' => '1',
            'file' => UploadedFile::fake()->createWithContent('agent-2.zip', 'version 2'),
        ])->assertRedirect();
        $download->refresh();
        $this->assertSame(['agent-2.zip', true, []], [$download->file_name, $download->clients_only, $download->products->modelKeys()]);
        Storage::disk('local')->assertMissing($old);

        // A category with files can't be deleted.
        $this->delete("/admin/download-categories/{$this->category->id}")->assertSessionHasErrors('category');
        $this->delete(self::ADMIN."/{$download->id}")->assertRedirect();
        Storage::disk('local')->assertMissing($download->path);
        $this->assertModelMissing($download);
    }

    public function test_uploads_are_limited_to_7_5_mb(): void
    {
        $this->actingAs(User::factory()->create(), 'web');
        $upload = fn (int $kb) => $this->post(self::ADMIN, [
            'download_category_id' => $this->category->id, 'title' => self::AGENT,
            'file' => UploadedFile::fake()->create('agent.zip', $kb),
        ]);

        $upload(7680 + 1)->assertSessionHasErrors('file');
        $upload(7680)->assertRedirect(self::ADMIN);
        $this->assertSame(7680 * 1024, Download::sole()->size);
        // The rule repeats the limit as a literal for SonarCloud; the form shows the constant.
        $this->assertSame(7680, DownloadController::MAX_KB);
    }

    public function test_who_can_download(): void
    {
        $public = $this->download(['title' => 'Public guide']);
        $clientsOnly = $this->download(['title' => 'Client guide', 'clients_only' => true]);
        $product = Product::factory()->create(['name' => 'Backup Pro']);
        $forProduct = $this->download();
        $forProduct->products()->attach($product);

        $this->get('/downloads')->assertOk()->assertSee('Public guide')->assertSee('Log in to download')->assertSee('Clients with Backup Pro');
        $this->get("/downloads/{$public->id}")->assertOk()->assertDownload('agent.zip');
        $this->get("/downloads/{$clientsOnly->id}")->assertRedirect('/portal/login');

        $client = Client::factory()->create();
        $this->actingAs($client, 'client');
        $this->get("/downloads/{$clientsOnly->id}")->assertOk();
        $this->get("/downloads/{$forProduct->id}")->assertForbidden();

        $service = Service::factory()->for($client)->for($product)->create(['status' => ServiceStatus::Suspended]);
        $this->get("/downloads/{$forProduct->id}")->assertForbidden();
        $service->update(['status' => ServiceStatus::Active]);
        $this->get("/downloads/{$forProduct->id}")->assertOk();

        $this->assertSame([1, 1, 1], [$public->fresh()->downloads, $clientsOnly->fresh()->downloads, $forProduct->fresh()->downloads]);
    }
}
