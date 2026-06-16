<?php

use App\Models\GroupPlan;
use App\Models\TripSchedule;
use App\Services\ChatService;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.User.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('schedule.{scheduleId}', function ($user, $scheduleId) {
    return $user !== null;
});

Broadcast::channel('user.{userId}', function ($user, $userId) {
    return (int) $user->id === (int) $userId;
});

Broadcast::channel('chat.schedule.{scheduleId}', function ($user, $scheduleId) {
    $schedule = TripSchedule::find($scheduleId);

    return $schedule
        && app(ChatService::class)->canAccess($user, $schedule);
});

// ประกาศจากผู้จัด — สมาชิกรอบเดียวกัน (เกณฑ์เดียวกับห้องแชท) เท่านั้นที่ฟังได้
Broadcast::channel('announcements.schedule.{scheduleId}', function ($user, $scheduleId) {
    $schedule = TripSchedule::find($scheduleId);

    return $schedule
        && app(ChatService::class)->canAccess($user, $schedule);
});

Broadcast::channel('group.{code}', function ($user, $code) {
    $plan = GroupPlan::where('invite_code', strtoupper($code))->first();
    if (! $plan) {
        return false;
    }

    return (int) $plan->host_user_id === (int) $user->id
        || $plan->members()
            ->where('user_id', $user->id)
            ->where('status', '!=', 'left')
            ->exists();
});
