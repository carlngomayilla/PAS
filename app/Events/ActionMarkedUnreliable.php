<?php

namespace App\Events;

use App\Models\Action;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ActionMarkedUnreliable
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Action $action,
        public string $reason,
        public ?User $actor = null
    ) {}
}
