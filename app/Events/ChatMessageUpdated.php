<?php

namespace App\Events;

use App\Models\ChatMessage;
use App\Services\ChatService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// An existing message was edited or deleted — recipients replace it in place.
class ChatMessageUpdated implements ShouldBroadcast
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
        return 'chat.message.updated';
    }

    public function broadcastWith(): array
    {
        $this->message->loadMissing(['user', 'replyTo.user', 'reactions']);

        return app(ChatService::class)->presentMessage($this->message);
    }
}
