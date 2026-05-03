<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class PromotionController extends Controller
{
    public function index()
    {
        $promotions = Promotion::orderBy('id', 'desc')->get();
        return response()->json($promotions);
    }

    public function publicActive()
    {
        $promotions = Promotion::where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('start_date')->orWhere('start_date', '<=', now()->startOfDay());
            })
            ->where(function ($q) {
                $q->whereNull('end_date')->orWhere('end_date', '>=', now()->startOfDay());
            })
            ->where(function ($q) {
                $q->whereNull('max_uses')->orWhereColumn('used_count', '<', 'max_uses');
            })
            ->select('id', 'code', 'name', 'type', 'value', 'start_date', 'end_date', 'max_uses', 'used_count')
            ->orderBy('id', 'desc')
            ->get();

        return response()->json(['data' => $promotions]);
    }

    public function store(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:promotions,code',
            'name' => 'required|string',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'trip_ids' => 'nullable|array',
            'trip_ids.*' => 'integer|exists:trips,id',
            'max_uses' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $promotion = Promotion::create($validator->validated());

        return response()->json($promotion, 201);
    }

    public function show($id)
    {
        $promotion = Promotion::findOrFail($id);
        return response()->json($promotion);
    }

    public function update(Request $request, $id)
    {
        $promotion = Promotion::findOrFail($id);

        $validator = Validator::make($request->all(), [
            'code' => 'required|string|unique:promotions,code,' . $promotion->id,
            'name' => 'required|string',
            'type' => 'required|in:percent,fixed',
            'value' => 'required|numeric|min:0',
            'trip_ids' => 'nullable|array',
            'trip_ids.*' => 'integer|exists:trips,id',
            'max_uses' => 'nullable|integer|min:1',
            'is_active' => 'boolean',
            'start_date' => 'nullable|date',
            'end_date' => 'nullable|date|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $promotion->update($validator->validated());

        return response()->json($promotion);
    }

    public function destroy($id)
    {
        $promotion = Promotion::findOrFail($id);
        
        // Prevent deletion if already used, instead deactivate
        if ($promotion->used_count > 0) {
            $promotion->is_active = false;
            $promotion->save();
            return response()->json(['message' => 'Promotion is already used, so it has been deactivated instead of deleted.']);
        }

        $promotion->delete();
        return response()->json(['message' => 'Promotion deleted successfully']);
    }

    public function validateCode(Request $request)
    {
        $request->validate([
            'code' => 'required|string',
            'trip_id' => 'required|integer|exists:trips,id'
        ]);

        $promotion = Promotion::where('code', $request->code)->where('is_active', true)->first();

        if (!$promotion) {
            return response()->json(['valid' => false, 'message' => 'Promotion code not found or inactive'], 404);
        }

        if ($promotion->start_date && now()->startOfDay()->lt($promotion->start_date)) {
            return response()->json(['valid' => false, 'message' => 'Promotion has not started yet'], 400);
        }

        if ($promotion->end_date && now()->startOfDay()->gt($promotion->end_date)) {
            return response()->json(['valid' => false, 'message' => 'Promotion has expired'], 400);
        }

        if ($promotion->max_uses && $promotion->used_count >= $promotion->max_uses) {
            return response()->json(['valid' => false, 'message' => 'Promotion has reached its usage limit'], 400);
        }

        if ($promotion->trip_ids && is_array($promotion->trip_ids)) {
            if (!in_array($request->trip_id, $promotion->trip_ids)) {
                return response()->json(['valid' => false, 'message' => 'Promotion is not applicable for this trip'], 400);
            }
        }

        return response()->json([
            'valid' => true,
            'promotion' => $promotion
        ]);
    }
}
