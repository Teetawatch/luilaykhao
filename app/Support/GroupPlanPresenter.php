<?php

namespace App\Support;

use App\Models\GroupPlan;
use App\Models\GroupPlanMember;

/**
 * Single source of truth for the JSON shape of a group plan, used by both the
 * REST responses and the realtime GroupPlanUpdated broadcast so the Flutter
 * client can render either identically.
 */
class GroupPlanPresenter
{
    public static function present(GroupPlan $plan, ?int $viewerUserId = null): array
    {
        $plan->loadMissing(['members.user', 'schedule.trip']);

        $schedule = $plan->schedule;
        $trip = $schedule?->trip;
        $claimedSeatIds = $plan->members
            ->whereNotNull('seat_id')
            ->where('status', '!=', 'left')
            ->pluck('seat_id')
            ->values()
            ->all();

        return [
            'id' => $plan->id,
            'invite_code' => $plan->invite_code,
            'name' => $plan->name,
            'status' => $plan->status,
            'is_open' => $plan->isOpen(),
            'seat_count' => $plan->seat_count,
            'host_user_id' => $plan->host_user_id,
            'is_host' => $viewerUserId !== null && $viewerUserId === $plan->host_user_id,
            'booking_ref' => $plan->booking?->booking_ref,
            'expires_at' => $plan->expires_at?->toISOString(),
            'claimed_seat_ids' => $claimedSeatIds,
            'schedule' => $schedule ? [
                'id' => $schedule->id,
                'departure_date' => $schedule->departure_date?->toDateString(),
                'return_date' => $schedule->return_date?->toDateString(),
                'effective_price' => (float) $schedule->effective_price,
                'available_seats' => $schedule->available_seats,
            ] : null,
            'trip' => $trip ? [
                'id' => $trip->id,
                'title' => $trip->title,
                'slug' => $trip->slug,
                'location' => $trip->location,
                'thumbnail_image' => $trip->thumbnail_image,
                'cover_image' => $trip->cover_image,
            ] : null,
            'members' => $plan->members
                ->where('status', '!=', 'left')
                ->map(fn (GroupPlanMember $m) => self::presentMember($m, $viewerUserId))
                ->values()
                ->all(),
        ];
    }

    private static function presentMember(GroupPlanMember $member, ?int $viewerUserId): array
    {
        $user = $member->user;

        return [
            'id' => $member->id,
            'user_id' => $member->user_id,
            'is_host' => $member->is_host,
            'is_me' => $viewerUserId !== null && $viewerUserId === $member->user_id,
            'status' => $member->status,
            'seat_id' => $member->seat_id,
            'passenger_name' => $member->passenger_name,
            'display_name' => $member->passenger_name
                ?: $user?->nickname
                ?: $user?->name
                ?: 'สมาชิก',
            'avatar_url' => $user?->avatar_url,
        ];
    }
}
