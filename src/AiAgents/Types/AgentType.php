<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents\Types;

enum AgentType: string
{
    case Chat = 'chat';
    case Search = 'search';
}
