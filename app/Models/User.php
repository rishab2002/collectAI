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

#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

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
