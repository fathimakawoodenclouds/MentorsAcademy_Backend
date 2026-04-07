<?php

namespace App\Traits;

use App\Models\Message;
use App\Models\MediaFile;

trait ChatTrait
{
    /**
     * Create text message
     */
    public function sendTextMessage(int $senderId, int $receiverId, string $messageText): Message
    {
        return Message::create([
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'message' => $messageText,
            'type' => 'text'
        ]);
    }

    /**
     * Create media message
     */
    public function sendMediaMessage(int $senderId, int $receiverId, MediaFile $media): Message
    {
        return Message::create([
            'sender_id' => $senderId,
            'receiver_id' => $receiverId,
            'media_id' => $media->id,
            'type' => $media->file_type
        ]);
    }
}
