<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreVariationPhotosRequest;
use App\Models\Photo;
use App\Models\Variation;
use App\Services\Catalog\VariationPhotos;
use Illuminate\Http\RedirectResponse;

/**
 * Adding, ordering and removing catalog photos on a variation.
 *
 * Plain form POSTs, like the review screen: nothing here needs JavaScript, so a
 * broken bundle can't stop an admin fixing a photo.
 */
class VariationPhotoController extends Controller
{
    public function store(StoreVariationPhotosRequest $request, Variation $variation, VariationPhotos $photos): RedirectResponse
    {
        $added = $photos->attach($variation, $request->photos());

        return $this->back($variation, sprintf('Added %d photo%s.', count($added), count($added) === 1 ? '' : 's'));
    }

    public function primary(Variation $variation, Photo $photo, VariationPhotos $photos): RedirectResponse
    {
        $photos->makePrimary($photo);

        return $this->back($variation, 'Primary photo changed.');
    }

    public function destroy(Variation $variation, Photo $photo, VariationPhotos $photos): RedirectResponse
    {
        $photos->remove($photo);

        return $this->back($variation, 'Photo removed.');
    }

    private function back(Variation $variation, string $message): RedirectResponse
    {
        return redirect()
            ->to(route('admin.castings.show', $variation->release->castingVersion->casting).'#variation-'.$variation->getKey())
            ->with('status', $message);
    }
}
