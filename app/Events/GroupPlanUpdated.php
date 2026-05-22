<?php

namespace App\Events;

use App\Models\GroupPlan;
use App\Support\GroupPlanPresenter;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class GroupPlanUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public GroupPlan $plan,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("group.{$this->plan->invite_code}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'group.updated';
    }

    public function broadcastWith(): array
    {
        return GroupPlanPresenter::present($this->plan);
    }
}
