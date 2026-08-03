<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

// `is_admin` is deliberately not fillable: admin rights are granted by seeder or
// console, never by anything that fills attributes from a request payload.
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Capture sessions this user submitted from the phone.
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(Submission::class);
    }

    /**
     * Variations this user owns. Pivot carries quantity/condition/acquired_at.
     */
    public function collection(): BelongsToMany
    {
        return $this->belongsToMany(Variation::class, 'user_collection_items')
            ->withPivot(['quantity', 'condition', 'acquired_at'])
            ->withTimestamps();
    }

    public function wishlist(): BelongsToMany
    {
        return $this->belongsToMany(Variation::class, 'user_wishlist_items')
            ->withTimestamps();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_admin' => 'boolean',
        ];
    }

    /** @return HasMany<CollectionItem, $this> */
    public function collectionItems(): HasMany
    {
        return $this->hasMany(CollectionItem::class);
    }

    /** @return HasMany<WishlistItem, $this> */
    public function wishlistItems(): HasMany
    {
        return $this->hasMany(WishlistItem::class);
    }

    /**
     * Variations this user owns. Use collectionItems() when the per-entry
     * detail matters — this one is for "does the user have it?" checks.
     *
     * @return BelongsToMany<Variation, $this>
     */
    public function collectedVariations(): BelongsToMany
    {
        return $this->belongsToMany(Variation::class, 'collection_items')
            ->withPivot(['quantity', 'condition', 'acquired_at'])
            ->withTimestamps();
    }

    /** @return BelongsToMany<Variation, $this> */
    public function wishlistedVariations(): BelongsToMany
    {
        return $this->belongsToMany(Variation::class, 'wishlist_items')
            ->withPivot(['priority', 'max_price'])
            ->withTimestamps();
    }

    /** @return HasMany<Photo, $this> */
    public function uploadedPhotos(): HasMany
    {
        return $this->hasMany(Photo::class, 'uploaded_by');
    }
}
