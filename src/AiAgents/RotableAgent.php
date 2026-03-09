<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use LarAgent\Message;
use LarAgent\Agent;
use SimoneBianco\LaravelRagChunks\AiAgents\Traits\InjectsRotatedOpenAIKey;

class RotableAgent extends Agent
{
    use InjectsRotatedOpenAIKey;

    protected function injectInstructionsForCurrentTurn(): void
    {
        $instructions = trim($this->instructions());

        if ($instructions === '') {
            return;
        }

        $instructionRole = $this->developerRoleForInstructions ? 'developer' : 'system';
        $messages = $this->chatHistory()->getMessages()->all();
        $lastMessage = empty($messages) ? null : $messages[array_key_last($messages)];

        if (
            $lastMessage !== null
            && $lastMessage->getRole() === $instructionRole
            && trim($lastMessage->getContentAsString()) === $instructions
        ) {
            return;
        }

        $instructionMessage = $this->developerRoleForInstructions
            ? Message::developer($instructions)
            : Message::system($instructions);

        $this->chatHistory()->addMessage($instructionMessage);
    }

    public function __construct($key, bool $usesUserId = false, ?string $group = null)
    {
        $this->injectRotatedOpenAIKey();

        parent::__construct($key, $usesUserId, $group);
    }
}
