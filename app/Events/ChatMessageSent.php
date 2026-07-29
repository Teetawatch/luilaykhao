<?php

namespace App\Events;

use App\Models\ChatMessage;
use App\Services\ChatService;
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
        // Present with the shared formatter so realtime pushes carry reply/
        // reaction data. currentUserId is null — recipients resolve "is_mine"
        // locally (own sends are added optimistically, never via the socket).
        $this->message->loadMissing(['user', 'replyTo.user', 'reactions', 'poll.options', 'poll.votes']);

        return app(ChatService::class)->presentMessage($this->message);
    }
}
