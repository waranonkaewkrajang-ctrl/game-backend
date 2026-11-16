<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AdminGameController;
use App\Models\ProductImage;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/** "เปลี่ยนรูปค่าย": every upload gets a new URL (browsers cache /uploads for 30 days) — sqlite + temp dir only */
class ProductImageUploadTest extends TestCase
{
    private string $pub;

    protected function setUp(): void
    {
        parent::setUp();
        (include database_path('migrations/2026_07_21_124836_create_product_images_table.php'))->up();
        $this->pub = sys_get_temp_dir().'/pi-test-'.uniqid();
        File::ensureDirectoryExists($this->pub.'/uploads/providers');
        $this->app->usePublicPath($this->pub);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->pub);
        parent::tearDown();
    }

    /** a real 1×1 PNG (no GD needed) */
    private function png(string $name = 'logo.png'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg=='));

        return new UploadedFile($path, $name, 'image/png', null, true);
    }

    private function upload(string $productId, UploadedFile $file): array
    {
        $req = Request::create('/', 'POST', ['product_id' => $productId], [], ['image' => $file]);
        $req->setLaravelSession(app('session.store'));

        $r = app(AdminGameController::class)->updateProductImage($req)->getData(true);
        $r['path'] = parse_url($r['data']['image_url'], PHP_URL_PATH); // the model returns a full URL (APP_URL + path)

        return $r;
    }

    public function test_each_upload_gets_a_new_url_and_the_file_exists(): void
    {
        $a = $this->upload('JILI', $this->png());
        $this->travel(2)->seconds();
        $b = $this->upload('JILI', $this->png());

        $this->assertSame('success', $b['status']);
        $this->assertNotSame($a['path'], $b['path']);
        $this->assertMatchesRegularExpression('#^/uploads/providers/provider_jili_\d{14}_[0-9a-f]{6}\.png$#', $b['path']);
        $this->assertFileExists($this->pub.$b['path']);
        $this->assertFileExists($this->pub.$a['path']); // old one kept for pages still open
        $this->assertSame($b['data']['image_url'], ProductImage::where('product_id', 'JILI')->first()->image_url);
        $this->assertSame(1, ProductImage::count());
    }

    public function test_product_id_cannot_escape_the_upload_folder(): void
    {
        $r = $this->upload('../../evil', $this->png('x.png'));
        $this->assertStringStartsWith('/uploads/providers/provider_evil_', $r['path']);
        $this->assertFileExists($this->pub.$r['path']);
        $this->assertSame([], glob($this->pub.'/*.png'));
    }
}
