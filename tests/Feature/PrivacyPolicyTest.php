<?php

namespace Tests\Feature;

use App\Models\Scan;
use App\Models\ScanDetection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The privacy policy the App Store asks for, and the promises in it that the
 * code has to keep.
 */
class PrivacyPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_policy_is_public_and_linked_everywhere_it_matters(): void
    {
        config(['catalog.privacy.contact' => 'privacy@example.test']);

        $this->get(route('privacy'))->assertOk()
            ->assertSee('Privacy policy')
            ->assertSee('privacy@example.test')
            ->assertSee('Help teach the scanner')
            ->assertSee('not sent to any outside AI');

        $this->get(route('home'))->assertSee(route('privacy'), false);
        $this->get(route('register'))->assertSee(route('privacy'), false);
        $this->actingAs(User::factory()->create())->get(route('scan.index'))->assertSee(route('privacy'), false);
    }

    public function test_deleting_an_account_deletes_its_scan_pictures(): void
    {
        Storage::fake('local');
        $user = User::factory()->create(['password' => 'a-good-password-123']);
        $scan = $user->scans()->create(['consent' => true, 'layout' => 'pile', 'photos' => 1]);
        Storage::disk('local')->put('scans/x/1-1-a.jpg', 'CROP');
        $scan->detections()->create(['photo' => 1, 'number' => 1, 'disk' => 'local', 'crop_path' => 'scans/x/1-1-a.jpg',
            'box' => [], 'reading' => [], 'confident' => false, 'confidence' => 0]);

        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'a-good-password-123'])->assertRedirect(route('home'));

        Storage::disk('local')->assertMissing('scans/x/1-1-a.jpg');
        $this->assertSame(0, Scan::count());
        $this->assertSame(0, ScanDetection::count());
    }
}
