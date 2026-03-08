<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\History;

use LarAgent\Context\Storages\ChatHistoryStorage;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;

/**
 * LarAgent ChatHistory implementation backed by the PageChat `chat_messages` table.
 *
 * Seeding history from the DB on each agent turn gives the LLM full conversation
 * context without relying on file or cache drivers that may be unavailable in
 * queue workers.  Writing back is intentionally skipped (see PageChatStorageDriver)
 * because the job layer already persists messages via ChatMessage::update().
 */
class PageChatHistory extends ChatHistoryStorage implements ChatHistoryInterface
{
    protected array $defaultDrivers = [PageChatStorageDriver::class];
}
