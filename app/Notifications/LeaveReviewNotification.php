<?php

namespace App\Notifications;

use App\Models\LeaveRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class LeaveReviewNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly LeaveRequest $leaveRequest,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $status = strtoupper((string) $this->leaveRequest->status);
        $date = $this->leaveRequest->leave_date?->format('Y-m-d');
        $msg = $status === 'APPROVED'
            ? "Your leave request for {$date} was approved."
            : "Your leave request for {$date} was rejected.";

        return [
            'leave_request_id' => $this->leaveRequest->id,
            'status' => $status,
            'leave_date' => $date,
            'reason' => $this->leaveRequest->reason,
            'message' => $msg,
        ];
    }
}
