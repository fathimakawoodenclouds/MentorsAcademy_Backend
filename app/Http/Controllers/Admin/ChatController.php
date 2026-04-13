<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Message;
use App\Models\User;
use App\Traits\ApiResponseTrait;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    use ApiResponseTrait;

    /**
     * List all conversations the authenticated user has.
     * Returns contacts with last message info and unread counts.
     */
    public function conversations(Request $request)
    {
        $userId = $request->user()->id;

        // Get all unique user IDs from conversations
        $conversationUserIds = Message::where('sender_id', $userId)
            ->orWhere('receiver_id', $userId)
            ->get()
            ->map(fn($msg) => $msg->sender_id === $userId ? $msg->receiver_id : $msg->sender_id)
            ->unique()
            ->values();

        $contacts = User::whereIn('id', $conversationUserIds)
            ->with(['role', 'staffProfile'])
            ->get()
            ->map(function ($user) use ($userId) {
                // Get last message between the two users
                $lastMessage = Message::where(function ($q) use ($userId, $user) {
                    $q->where('sender_id', $userId)->where('receiver_id', $user->id);
                })->orWhere(function ($q) use ($userId, $user) {
                    $q->where('sender_id', $user->id)->where('receiver_id', $userId);
                })->with('media')->latest()->first();

                // Count unread messages from this user
                $unreadCount = Message::where('sender_id', $user->id)
                    ->where('receiver_id', $userId)
                    ->where('is_read', false)
                    ->count();

                // Build last message preview
                $lastMsgPreview = null;
                if ($lastMessage) {
                    if ($lastMessage->type === 'text') {
                        $lastMsgPreview = $lastMessage->message;
                    } elseif ($lastMessage->type === 'image') {
                        $lastMsgPreview = '📷 Image';
                    } elseif ($lastMessage->type === 'file') {
                        $lastMsgPreview = '📎 File';
                    } elseif ($lastMessage->type === 'audio') {
                        $lastMsgPreview = '🎵 Audio';
                    }
                }

                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role->name ?? 'unknown',
                    'role_label' => ucwords(str_replace('_', ' ', $user->role->name ?? 'Unknown')),
                    'avatar' => 'https://ui-avatars.com/api/?name=' . urlencode($user->name) . '&background=random',
                    'last_message' => $lastMsgPreview,
                    'last_message_time' => $lastMessage?->created_at?->diffForHumans(null, true, true),
                    'last_message_at' => $lastMessage?->created_at,
                    'unread' => $unreadCount,
                    'online' => false,
                ];
            })
            ->sortByDesc('last_message_at')
            ->values();

        return response()->json(['status' => 'success', 'data' => $contacts]);
    }

    /**
     * Get paginated messages between authenticated user and a specific user.
     * Joined with media_uploads, returns file_url dynamically.
     */
    public function messages(Request $request, $userId)
    {
        $authId = $request->user()->id;
        $limit = $request->input('limit', 50);
        $page = $request->input('page', 1);

        $messages = Message::where(function ($q) use ($authId, $userId) {
            $q->where('sender_id', $authId)->where('receiver_id', $userId);
        })->orWhere(function ($q) use ($authId, $userId) {
            $q->where('sender_id', $userId)->where('receiver_id', $authId);
        })
        ->with(['sender:id,name,email', 'receiver:id,name,email', 'media'])
        ->orderBy('created_at', 'desc')
        ->paginate($limit);

        // Reverse so oldest is first in the array (for chat display)
        $formatted = collect($messages->items())->reverse()->values()->map(function ($msg) use ($authId) {
            $data = [
                'id' => $msg->id,
                'sender' => $msg->sender_id === $authId ? 'me' : 'them',
                'sender_name' => $msg->sender->name,
                'text' => $msg->message,
                'type' => $msg->type,
                'is_read' => $msg->is_read,
                'time' => $msg->created_at->format('h:i A'),
                'date' => $msg->created_at->format('Y-m-d'),
                'avatar' => 'https://ui-avatars.com/api/?name=' . urlencode($msg->sender->name) . '&background=random',
            ];

            // Attach media info if exists
            if ($msg->media) {
                $data['media'] = [
                    'id' => $msg->media->id,
                    'file_name' => $msg->media->file_name,
                    'file_type' => $msg->media->file_type,
                    'file_url' => $msg->media->file_url,
                    'mime_type' => $msg->media->mime_type,
                    'file_size' => $msg->media->file_size,
                ];
            }

            return $data;
        });

        // Mark all received messages as read
        Message::where('sender_id', $userId)
            ->where('receiver_id', $authId)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json([
            'status' => 'success',
            'data' => $formatted,
            'meta' => [
                'current_page' => $messages->currentPage(),
                'last_page' => $messages->lastPage(),
                'total' => $messages->total(),
            ]
        ]);
    }

    /**
     * Send a message (text or media).
     */
    public function send(Request $request)
    {
        $validated = $request->validate([
            'receiver_id' => 'required|exists:users,id',
            'message' => 'nullable|string|max:5000',
            'type' => 'nullable|in:text,image,file,audio',
            'media_id' => 'nullable|exists:media_files,id',
        ]);

        $type = $validated['type'] ?? 'text';

        // Require either message text or media_id
        if (empty($validated['message']) && empty($validated['media_id'])) {
            return $this->errorResponse('Message text or media is required', 422);
        }

        $msg = Message::create([
            'sender_id' => $request->user()->id,
            'receiver_id' => $validated['receiver_id'],
            'message' => $validated['message'] ?? null,
            'type' => $type,
            'media_id' => $validated['media_id'] ?? null,
            'is_read' => false,
        ]);

        $msg->load(['sender:id,name,email', 'receiver:id,name,email', 'media']);

        $data = [
            'id' => $msg->id,
            'sender' => 'me',
            'sender_name' => $msg->sender->name,
            'text' => $msg->message,
            'type' => $msg->type,
            'is_read' => $msg->is_read,
            'time' => $msg->created_at->format('h:i A'),
            'date' => $msg->created_at->format('Y-m-d'),
        ];

        if ($msg->media) {
            $data['media'] = [
                'id' => $msg->media->id,
                'file_name' => $msg->media->file_name,
                'file_type' => $msg->media->file_type,
                'file_url' => $msg->media->file_url,
                'mime_type' => $msg->media->mime_type,
                'file_size' => $msg->media->file_size,
            ];
        }

        return response()->json(['status' => 'success', 'data' => $data], 201);
    }

    /**
     * Mark messages as read.
     */
    public function markAsRead(Request $request)
    {
        $validated = $request->validate([
            'sender_id' => 'required|exists:users,id',
        ]);

        $updated = Message::where('sender_id', $validated['sender_id'])
            ->where('receiver_id', $request->user()->id)
            ->where('is_read', false)
            ->update(['is_read' => true]);

        return response()->json(['status' => 'success', 'updated' => $updated]);
    }

    /**
     * List available users for new conversations.
     */
    public function availableUsers(Request $request)
    {
        $authId = $request->user()->id;
        $authRole = $request->user()->role->name ?? '';

        $query = User::where('id', '!=', $authId)->with(['role', 'staffProfile']);

        // Security: Sales executives can only chat with admin/super_admin
        if ($authRole === 'sales_executive') {
            $query->whereHas('role', function ($q) {
                $q->whereIn('name', ['super_admin', 'admin']);
            });
        }

        if ($request->has('role') && $request->role !== 'all') {
            $query->whereHas('role', function ($q) use ($request) {
                $q->where('name', $request->role);
            });
        }

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->get()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role->name ?? 'unknown',
                'role_label' => ucwords(str_replace('_', ' ', $user->role->name ?? 'Unknown')),
                'avatar' => 'https://ui-avatars.com/api/?name=' . urlencode($user->name) . '&background=random',
            ];
        });

        return response()->json(['status' => 'success', 'data' => $users]);
    }

    /**
     * Get list of Sales Executive users (for admin sidebar).
     */
    public function salesUsers(Request $request)
    {
        $search = $request->input('search');

        $query = User::whereHas('role', function ($q) {
            $q->where('name', 'sales_executive');
        })->with(['role', 'staffProfile']);

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        $users = $query->orderBy('name')->get()->map(function ($user) {
            return [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role_label' => 'Sales Executive',
                'avatar' => 'https://ui-avatars.com/api/?name=' . urlencode($user->name) . '&background=random',
                'phone' => $user->staffProfile->phone ?? null,
            ];
        });

        return response()->json(['status' => 'success', 'data' => $users]);
    }
}
