<?php

namespace App\Events;

use App\Models\DeadlineExtensionRequest;
use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class DeadlineExtensionApproved
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public DeadlineExtensionRequest $deadlineExtensionRequest,
        public User $actor
    ) {}
}
