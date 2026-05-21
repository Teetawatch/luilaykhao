<?php

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
