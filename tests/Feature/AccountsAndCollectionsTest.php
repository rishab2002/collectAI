<?php

namespace Tests\Feature;

use App\Models\Casting;
use App\Models\CastingVersion;
use App\Models\PriceHistory;
use App\Models\Release;
use App\Models\User;
use App\Models\UserCollectionItem;
use App\Models\Variation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Collector accounts, and each collector's own collection and wishlist.
 */
class AccountsAndCollectionsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'correct horse battery 42';

    protected function setUp(): void
    {
        parent::setUp();

        RateLimiter::clear('register');
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return [
            'name' => 'Rish',
            'email' => 'Rish@Example.test',
            'password' => self::PASSWORD,
            'password_confirmation' => self::PASSWORD,
            ...$overrides,
        ];
    }

    private function liveVariation(): Variation
    {
        $casting = Casting::factory()->create(['name' => 'Bone Shaker']);
        $release = Release::factory()->for(CastingVersion::factory()->for($casting), 'castingVersion')->create(['year' => 2024]);

        return Variation::factory()->for($release)->create(['color' => 'Black']);
    }

    public function test_anyone_can_create_an_account_and_lands_in_their_collection(): void
    {
        $this->post(route('register.store'), $this->registration())
            ->assertRedirect(route('collection.index'));

        $user = User::sole();
        $this->assertSame('rish@example.test', $user->email);
        $this->assertFalse($user->is_admin);
        $this->assertAuthenticatedAs($user);
    }

    public function test_weak_passwords_duplicate_emails_and_bots_are_turned_away(): void
    {
        $this->post(route('register.store'), $this->registration(['password' => 'short1', 'password_confirmation' => 'short1']))
            ->assertSessionHasErrors('password');
        $this->post(route('register.store'), $this->registration(['password' => 'nodigitsatallhere', 'password_confirmation' => 'nodigitsatallhere']))
            ->assertSessionHasErrors('password');

        User::factory()->create(['email' => 'rish@example.test']);
        $this->post(route('register.store'), $this->registration())->assertSessionHasErrors('email');

        // The honeypot: a generic failure that doesn't name the field.
        $this->post(route('register.store'), $this->registration(['email' => 'bot@example.test', 'website' => 'http://spam']))
            ->assertSessionHasErrors(['email' => 'We could not create that account.']);

        $this->assertSame(1, User::count());
    }

    public function test_registration_is_rate_limited(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('register.store'), $this->registration(['email' => "c{$i}@example.test"]));
            auth()->logout();
        }

        $this->post(route('register.store'), $this->registration(['email' => 'c9@example.test']))->assertStatus(429);
        $this->assertSame(5, User::count());
    }

    public function test_collectors_and_admins_land_in_different_places_after_sign_in(): void
    {
        $collector = User::factory()->create(['password' => self::PASSWORD]);
        $this->post(route('login.store'), ['email' => $collector->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('collection.index'));
        auth()->logout();

        $admin = User::factory()->admin()->create(['password' => self::PASSWORD]);
        $this->post(route('login.store'), ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertRedirect(route('admin.dashboard'));
    }

    public function test_a_collector_adds_a_car_and_it_leaves_the_wishlist(): void
    {
        $user = User::factory()->create();
        $variation = $this->liveVariation();
        $user->wishlist()->attach($variation);

        $this->actingAs($user)
            ->post(route('collection.store'), ['variation_id' => $variation->id, 'quantity' => 2, 'condition' => 'carded'])
            ->assertRedirect();

        $item = UserCollectionItem::sole();
        $this->assertSame(2, $item->quantity);
        $this->assertSame('carded', $item->condition->value);
        $this->assertSame(0, $user->wishlist()->count());

        // Adding again updates the one row rather than duplicating it.
        $this->actingAs($user)->post(route('collection.store'), ['variation_id' => $variation->id, 'quantity' => 3]);
        $this->assertSame(3, UserCollectionItem::sole()->quantity);
    }

    public function test_an_unpublished_car_cannot_be_collected_by_a_collector(): void
    {
        $pending = Variation::factory()->pending()->create();

        $this->actingAs(User::factory()->create())
            ->post(route('collection.store'), ['variation_id' => $pending->id])
            ->assertNotFound();

        $this->assertDatabaseCount('user_collection_items', 0);
    }

    public function test_nobody_can_touch_another_collectors_items(): void
    {
        $owner = User::factory()->create();
        $item = $owner->collectionItems()->create(['variation_id' => $this->liveVariation()->id, 'quantity' => 1]);
        $intruder = User::factory()->create();

        $this->actingAs($intruder)->patch(route('collection.update', $item->id), ['quantity' => 99])->assertNotFound();
        $this->actingAs($intruder)->delete(route('collection.destroy', $item->id))->assertNotFound();

        $this->assertSame(1, $item->fresh()->quantity);
    }

    public function test_a_collector_updates_and_removes_their_own_items(): void
    {
        $user = User::factory()->create();
        $item = $user->collectionItems()->create(['variation_id' => $this->liveVariation()->id, 'quantity' => 1]);

        $this->actingAs($user)->patch(route('collection.update', $item->id), ['quantity' => 4, 'condition' => 'loose'])->assertRedirect();
        $this->assertSame(4, $item->fresh()->quantity);

        // The variation can't be swapped out from under an item.
        $this->actingAs($user)->patch(route('collection.update', $item->id), ['variation_id' => 999])->assertSessionHasErrors('variation_id');

        $this->actingAs($user)->delete(route('collection.destroy', $item->id))->assertRedirect();
        $this->assertModelMissing($item);
    }

    public function test_the_collection_page_shows_cars_prices_and_total_value(): void
    {
        $user = User::factory()->create();
        $variation = $this->liveVariation();
        $user->collectionItems()->create(['variation_id' => $variation->id, 'quantity' => 2]);
        PriceHistory::factory()->for($variation)->create(['price' => 4.50, 'kind' => 'sold', 'sample_size' => 5, 'observed_at' => now()]);

        $this->actingAs($user)->get(route('collection.index'))
            ->assertOk()
            ->assertSee('Bone Shaker')
            ->assertSee('$9.00', false)
            ->assertSee('eBay sold · median of last 5 sales');
    }

    public function test_car_pages_offer_collecting_to_members_and_joining_to_guests(): void
    {
        $variation = $this->liveVariation();
        $casting = $variation->release->castingVersion->casting;

        $this->get(route('catalog.casting', $casting->id))->assertOk()->assertSee('Join to track this car');

        $user = User::factory()->create();
        $this->actingAs($user)->get(route('catalog.casting', $casting->id))->assertSee('Add to my collection')->assertSee('Want it');

        $user->collectionItems()->create(['variation_id' => $variation->id, 'quantity' => 2]);
        $this->actingAs($user)->get(route('catalog.casting', $casting->id))->assertSee('In your collection ×2');
    }

    public function test_the_wishlist_toggles(): void
    {
        $user = User::factory()->create();
        $variation = $this->liveVariation();

        $this->actingAs($user)->post(route('wishlist.store'), ['variation_id' => $variation->id])->assertRedirect();
        $this->actingAs($user)->post(route('wishlist.store'), ['variation_id' => $variation->id]);
        $this->assertSame(1, $user->wishlist()->count());

        $this->actingAs($user)->delete(route('wishlist.destroy', $variation->id))->assertRedirect();
        $this->assertSame(0, $user->wishlist()->count());
    }

    public function test_a_collector_can_change_their_password_and_delete_their_account(): void
    {
        $user = User::factory()->create(['password' => self::PASSWORD]);
        $user->collectionItems()->create(['variation_id' => $this->liveVariation()->id, 'quantity' => 1]);

        $this->actingAs($user)->put(route('account.password'), [
            'current_password' => 'wrong', 'password' => 'another strong pass 7', 'password_confirmation' => 'another strong pass 7',
        ])->assertSessionHasErrorsIn('password', 'current_password');

        $this->actingAs($user)->put(route('account.password'), [
            'current_password' => self::PASSWORD, 'password' => 'another strong pass 7', 'password_confirmation' => 'another strong pass 7',
        ])->assertSessionHasNoErrors();

        $this->actingAs($user)->delete(route('account.destroy'), ['password' => 'another strong pass 7'])
            ->assertRedirect(route('home'));

        $this->assertModelMissing($user);
        $this->assertDatabaseCount('user_collection_items', 0);
        $this->assertGuest();
    }

    public function test_the_admin_account_cannot_be_deleted_from_the_account_page(): void
    {
        $admin = User::factory()->admin()->create(['password' => self::PASSWORD]);

        $this->actingAs($admin)->delete(route('account.destroy'), ['password' => self::PASSWORD])->assertForbidden();
        $this->assertModelExists($admin);
    }

    public function test_guests_are_sent_to_sign_in(): void
    {
        $this->get(route('collection.index'))->assertRedirect(route('login'));
        $this->post(route('collection.store'), ['variation_id' => 1])->assertRedirect(route('login'));
    }
}
