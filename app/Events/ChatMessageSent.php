<?php

namespace App\Events;

use App\Models\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ChatMessageSent implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("chat.schedule.{$this->message->schedule_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.message';
    }

    public function broadcastWith(): array
    {
        $user = $this->message->user;

        return [
            'id' => $this->message->id,
            'schedule_id' => $this->message->schedule_id,
            'body' => $this->message->body,
            'sender_role' => $this->message->sender_role,
            'user' => $user ? [
                'id' => $user->id,
                'name' => $user->name,
                'nickname' => $user->nickname,
                'avatar_url' => $user->avatar_url,
            ] : null,
            'created_at' => $this->message->created_at?->toISOString(),
        ];
    }
}
