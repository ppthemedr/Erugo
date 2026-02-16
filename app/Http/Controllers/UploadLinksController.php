<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use App\Models\UploadLink;
use App\Models\User;
use App\Services\SettingsService;

class UploadLinksController extends Controller
{
    /**
     * Create a new upload link.
     * POST /api/upload-links
     */
    public function create(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'name' => ['required', 'string', 'max:255'],
            'expires_days' => ['nullable', 'integer', 'min:1', 'max:365'],
            'max_uses' => ['nullable', 'integer', 'min:0'],
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Validation failed',
                'data' => ['errors' => $validator->errors()]
            ], 422);
        }

        $user = Auth::user();

        // Create a guest user for this upload link
        $guestUser = User::create([
            'name' => $request->name,
            'email' => 'upload-link-' . Str::random(16),
            'password' => Hash::make(Str::random(32)),
            'is_guest' => true,
        ]);

        $token = Str::random(64);

        $uploadLink = UploadLink::create([
            'user_id' => $user->id,
            'guest_user_id' => $guestUser->id,
            'name' => $request->name,
            'token' => $token,
            'max_uses' => $request->max_uses ?? 0,
            'expires_at' => now()->addDays($request->expires_days ?? 30),
        ]);

        $settingsService = new SettingsService();
        $applicationUrl = $settingsService->get('application_url');
        $uploadUrl = $applicationUrl . '/?upload_token=' . $token;

        return response()->json([
            'status' => 'success',
            'data' => [
                'upload_link' => $uploadLink,
                'upload_url' => $uploadUrl,
                'token' => $token,
            ]
        ]);
    }

    /**
     * List all upload links for the authenticated user.
     * GET /api/upload-links
     */
    public function index()
    {
        $user = Auth::user();

        $links = UploadLink::where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($link) {
                $link->is_active = $link->isActive();
                return $link;
            });

        return response()->json([
            'status' => 'success',
            'data' => ['upload_links' => $links]
        ]);
    }

    /**
     * Delete an upload link.
     * DELETE /api/upload-links/{id}
     */
    public function delete($id)
    {
        $user = Auth::user();
        $link = UploadLink::where('id', $id)->where('user_id', $user->id)->first();

        if (!$link) {
            return response()->json([
                'status' => 'error',
                'message' => 'Upload link not found'
            ], 404);
        }

        // Delete the guest user if it exists
        if ($link->guest_user_id) {
            User::where('id', $link->guest_user_id)->where('is_guest', true)->delete();
        }

        $link->delete();

        return response()->json([
            'status' => 'success',
            'message' => 'Upload link deleted'
        ]);
    }

    /**
     * Accept an upload link — authenticate as the guest user.
     * GET /api/upload-links/accept?token=xxx
     * Public endpoint (no auth required).
     */
    public function accept(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|size:64',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'message' => 'Invalid token'
            ], 422);
        }

        $link = UploadLink::where('token', $request->token)->first();

        if (!$link) {
            return response()->json([
                'status' => 'error',
                'message' => 'Upload link not found'
            ], 404);
        }

        if (!$link->isActive()) {
            $reason = 'Upload link is no longer active';
            if ($link->isExpired()) $reason = 'Upload link has expired';
            if ($link->hasReachedUseLimit()) $reason = 'Upload link has reached its use limit';
            if (!$link->active) $reason = 'Upload link has been deactivated';

            return response()->json([
                'status' => 'error',
                'message' => $reason
            ], 410);
        }

        $guestUser = User::find($link->guest_user_id);

        if (!$guestUser) {
            return response()->json([
                'status' => 'error',
                'message' => 'Upload link is broken'
            ], 500);
        }

        // Issue a JWT for the guest user
        $token = Auth::login($guestUser);

        if (!$token) {
            return response()->json([
                'status' => 'error',
                'message' => 'Authentication failed'
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'Upload link accepted',
            'data' => [
                'access_token' => $token,
                'token_type' => 'Bearer',
                'expires_in' => Auth::factory()->getTTL() * 60,
                'guest' => true,
                'upload_link_name' => $link->name,
            ]
        ]);
    }
}
