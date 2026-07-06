<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Support\MediaDisk;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class ContactController extends Controller
{
    /**
     * Display a listing of the resource for Admin.
     */
    public function index()
    {
        return response()->json(Contact::latest()->get());
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:20',
            'email' => 'nullable|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
            'partner_type' => 'nullable|string',
            'van_description' => 'nullable|string',
            'forests_hiked' => 'nullable|string',
            'images.*' => 'nullable|image|max:8192',
        ]);

        $imagePaths = [];
        if ($request->hasFile('images')) {
            foreach ($request->file('images') as $image) {
                $path = $image->store('contacts', MediaDisk::name());
                $imagePaths[] = MediaDisk::url($path);
            }
        }

        $contact = Contact::create([
            'name' => $validated['name'],
            'phone' => $validated['phone'],
            'email' => $validated['email'],
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'partner_type' => $validated['partner_type'] ?? null,
            'van_description' => $validated['van_description'] ?? null,
            'forests_hiked' => $validated['forests_hiked'] ?? null,
            'images' => $imagePaths,
        ]);

        return response()->json([
            'message' => 'Message sent successfully',
            'contact' => $contact,
        ], 201);
    }

    /**
     * Mark message as read.
     */
    public function markAsRead($id)
    {
        $contact = Contact::findOrFail($id);
        $contact->update(['read_at' => now()]);

        return response()->json(['message' => 'Marked as read']);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $contact = Contact::findOrFail($id);

        // Delete images from storage. Stored values are full URLs, so recover
        // the relative path from the 'contacts/' segment — works for both the
        // local public URL and an R2 public URL.
        if ($contact->images) {
            foreach ($contact->images as $url) {
                $pos = strpos((string) $url, 'contacts/');
                if ($pos !== false) {
                    Storage::disk(MediaDisk::name())->delete(substr($url, $pos));
                }
            }
        }

        $contact->delete();

        return response()->json(['message' => 'Deleted successfully']);
    }
}
