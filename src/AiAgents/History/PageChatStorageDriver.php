<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\History;

use LarAgent\Context\Abstract\StorageDriver;
use LarAgent\Context\Contracts\SessionIdentity;
use SimoneBianco\PageChat\Models\ChatMessage;

/**
 * LarAgent storage driver that reads/writes conversation history from the
 * `chat_messages` table (laravel-page-chat package).
 *
 * The chat session UUID is extracted from the identity's chatName, which is
 * the `$key` passed as the first argument to ProjectChatAgent::__construct().
 *
 * Messages are stored as plain role+content pairs so LarAgent can reconstruct
 * them via MessageArray::fromArray(). Only completed user/assistant turns are
 * included — pending/streaming/failed messages are skipped.
 *
 * On write, LarAgent passes the full re-serialised message array (all turns
 * including the current one). We replace only the rows LarAgent "owns" — i.e.
 * rows whose `metadata->laragent_managed` flag is true — so that assistant
 * messages written by StreamDocumentChatResponse (with status, metadata, etc.)
 * are left untouched.  The simplest safe approach: we do NOT write back at all
 * (read-only driver for chat_messages). LarAgent already persists the assistant
 * reply via the job; we only need the read side so the agent has conversation
 * context on successive turns.
 */
class PageChatStorageDriver extends StorageDriver
{
    /**
     * Read all completed user/assistant messages for the given session,
     * returning them as plain arrays that LarAgent can deserialise.
     *
     * @return array<int, array{role: string, content: string}>|null
     */
    public function readFromMemory(SessionIdentity $identity): ?array
    {
        $sessionId = $identity->getChatName();

        if (! $sessionId) {
            return null;
        }

        $messages = ChatMessage::query()
            ->where('chat_session_id', $sessionId)
            ->whereIn('role', ['user', 'assistant'])
            ->where('status', 'completed')
            ->whereNotNull('content')
            ->where('content', '!=', '')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['role', 'content']);

        if ($messages->isEmpty()) {
            return null;
        }

        return $messages
            ->map(fn (ChatMessage $msg) => ['role' => $msg->role, 'content' => $msg->content])
            ->all();
    }

    /**
     * No-op: chat_messages are written by the job / streaming layer.
     * LarAgent's in-flight history is sufficient for the current turn.
     */
    public function writeToMemory(SessionIdentity $identity, array $data): bool
    {
        return true;
    }

    /**
     * No-op: we do not own the chat_messages rows.
     */
    public function removeFromMemory(SessionIdentity $identity): bool
    {
        return true;
    }
}
