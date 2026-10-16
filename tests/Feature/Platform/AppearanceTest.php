<?php

namespace Tests\Feature\Platform;

use App\Enums\StaffRole;
use App\Models\User;
use App\Themes\ThemeManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AppearanceTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/admin/settings/appearance';

    private function save(array $overrides = []): array
    {
        return $overrides + ['theme' => 'harbor', 'company_name' => 'Example Hosting', 'company_email' => 'hello@example.com'];
    }

    public function test_staff_switch_the_theme_and_the_portal_picks_up_its_colours_stylesheet_and_views(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(self::URL)->assertOk()->assertSee('Lighthouse')->assertSee('Harbor');
        $this->get(route('kb.index'))->assertOk()->assertDontSee('--color-brand-600')->assertDontSee('theme-assets/lighthouse');

        $this->put(self::URL, $this->save(['theme' => 'lighthouse']))->assertRedirect(self::URL)->assertSessionHas('status', 'Appearance saved.');

        $this->get(route('kb.index'))->assertOk()
            ->assertSee('--color-brand-600: #2563eb;', false)
            ->assertSee('--color-ink-600: #475569;', false)
            ->assertSee('theme-assets/lighthouse/theme.css', false)
            ->assertSee('mailto:hello@example.com', false);

        // Admin pages keep their own views, so a broken theme can't lock staff out.
        $this->get(self::URL)->assertOk()->assertDontSee('theme-assets/lighthouse');
    }

    public function test_a_white_label_colour_wins_over_the_theme_and_the_powered_by_line_can_go(): void
    {
        $this->actingAs(User::factory()->create());

        $this->put(self::URL, $this->save(['use_color' => '1', 'color' => '#ff0000', 'hide_powered_by' => '1', 'admin_name' => 'Example Admin']))->assertRedirect(self::URL);
        $this->get(route('kb.index'))->assertSee('--color-brand-600: #ff0000;', false)->assertDontSee('Powered by');
        $this->get(self::URL)->assertSee('Example Admin');

        $this->put(self::URL, $this->save(['color' => 'red', 'use_color' => '1']))->assertSessionHasErrors('color');
        $this->put(self::URL, $this->save(['theme' => 'missing']))->assertSessionHasErrors('theme');
    }

    public function test_the_overrides_folder_replaces_portal_views(): void
    {
        $path = sys_get_temp_dir().'/cms-themes-'.uniqid();
        File::copyDirectory(base_path('themes/lighthouse'), $path.'/lighthouse');
        File::ensureDirectoryExists($path.'/overrides/views/partials');
        File::put($path.'/overrides/views/partials/portal-footer.blade.php', 'Our own footer');
        File::ensureDirectoryExists($path.'/overrides/views/components');
        File::put($path.'/overrides/views/components/admin-layout.blade.php', 'Hijacked admin');

        try {
            config(['themes.path' => $path]);
            $themes = app(ThemeManager::class);
            $themes->forget();
            $themes->apply();

            $this->get(route('kb.index'))->assertSee('Our own footer');
            $this->actingAs(User::factory()->create())->get(self::URL)->assertOk()->assertDontSee('Hijacked admin')->assertSee('partials/portal-footer.blade.php');
        } finally {
            File::deleteDirectory($path);
        }
    }

    public function test_a_logo_is_uploaded_served_and_removed(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create());

        $this->put(self::URL, $this->save(['logo' => UploadedFile::fake()->image('logo.png', 200, 60)]))->assertRedirect(self::URL);
        $logo = config('branding.logo');
        Storage::disk('local')->assertExists($logo);
        $this->get(route('kb.index'))->assertSee('branding/logo?v=', false);

        $this->get('/branding/logo')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Content-Security-Policy', "default-src 'none'; style-src 'unsafe-inline'; sandbox");

        $this->put(self::URL, $this->save(['logo' => UploadedFile::fake()->create('logo.html', 5, 'text/html')]))->assertSessionHasErrors('logo');

        $this->delete(self::URL.'/logo')->assertRedirect(self::URL);
        Storage::disk('local')->assertMissing($logo);
        $this->get('/branding/logo')->assertNotFound();
    }

    public function test_theme_files_are_only_served_from_the_theme_assets_folder(): void
    {
        $this->get('/theme-assets/lighthouse/theme.css')->assertOk()->assertHeader('Content-Type', 'text/css; charset=utf-8');
        $this->get('/theme-assets/lighthouse/../theme.json')->assertNotFound();
        $this->get('/theme-assets/lighthouse/%2e%2e/theme.json')->assertNotFound();
        $this->get('/theme-assets/lighthouse/../views/partials/portal-footer.blade.php')->assertNotFound();
        $this->get('/theme-assets/harbor/app.css')->assertNotFound();
        $this->get('/theme-assets/missing/theme.css')->assertNotFound();
    }

    public function test_staff_without_settings_access_cant_change_the_appearance(): void
    {
        $this->actingAs(User::factory()->create(['role' => StaffRole::Support]));

        $this->get(self::URL)->assertForbidden();
        $this->put(self::URL, $this->save())->assertForbidden();
    }
}
