<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

/**
 * The account page: change your password, or delete the account and everything
 * attached to it.
 */
class AccountController extends Controller
{
    public function show(): View
    {
        return view('account.show');
    }

    public function password(Request $request): RedirectResponse
    {
        $data = $request->validateWithBag('password', [
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $request->user()->update(['password' => $data['password']]);

        // Other devices signed in with the old password are signed out.
        Auth::logoutOtherDevices($data['password']);

        return back()->with('status', 'Password changed.');
    }

    /**
     * Deleting an account deletes its collection and wishlist with it (the
     * foreign keys cascade). Admins can't delete themselves here — losing the
     * only admin account would lock the catalog.
     */
    public function destroy(Request $request): RedirectResponse
    {
        $request->validateWithBag('delete', ['password' => ['required', 'current_password']]);

        abort_if($request->user()->is_admin, 403, 'Admin accounts are removed by hand, not from this page.');

        $user = $request->user();
        Auth::logout();

        // Scan pictures are files, not rows: the database cascade would leave
        // them on disk. The privacy policy promises they go with the account.
        $user->scans()->get()->each->deleteWithPhotos();
        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('home')->with('status', 'Your account and collection have been deleted.');
    }
}
