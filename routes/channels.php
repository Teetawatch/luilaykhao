<?php

use App\Models\GroupPlan;
use App\Models\SupportConversation;
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

// ห้องช่วยเหลือ — เจ้าของห้อง (ลูกค้า) หรือทีมงานเท่านั้นที่ฟังได้
Broadcast::channel('support.conversation.{conversationId}', function ($user, $conversationId) {
    if ($user->hasAnyRole(['admin', 'operator'])) {
        return true;
    }

    return SupportConversation::where('id', $conversationId)
        ->where('user_id', $user->id)
        ->exists();
});

// กล่องข้อความรวมของทีมงาน — เฉพาะแอดมิน/ผู้ดูแล
Broadcast::channel('support.admins', function ($user) {
    return $user->hasAnyRole(['admin', 'operator']);
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
