<?php

declare(strict_types=1);

namespace SimoneBianco\LaravelRagChunks\AiAgents;

use LarAgent\Context\Drivers\InMemoryStorage;
use SimoneBianco\LaravelAiAgents\Agents\RotableAgent;

/**
 * LarAgent responsible for generating a complete system prompt for a
 * user-defined RAG chat agent.
 *
 * The agent receives a fully rendered context (scopes, tools, variables,
 * optional existing prompt and additional user instructions) and returns
 * plain text — no JSON, no preamble — suitable to be stored as the chat
 * agent's `system_prompt` column.
 */
class SystemPromptGeneratorAgent extends RotableAgent
{
    protected $history = InMemoryStorage::class;

    protected $model = 'gpt-4o';

    protected $maxCompletionTokens = 4096;

    protected $parallelToolCalls = false;

    public function __construct($key, ?string $model = null, bool $usesUserId = false, ?string $group = null)
    {
        parent::__construct($key, $usesUserId, $group);

        $configuredModel = (string) config('rag-chunks.system_prompt_generator.model', '');
        if ($model !== null && $model !== '') {
            $this->model = $model;
        } elseif ($configuredModel !== '') {
            $this->model = $configuredModel;
        }
    }

    public function instructions(): string
    {
        return <<<'PROMPT'
You are an expert system prompt engineer for RAG-based (Retrieval-Augmented Generation) chat agents.

You will receive a structured context describing a user-defined chat agent, including:
- The scopes the agent has access to (e.g. projects, documents) with their names, aliases, ids and descriptions.
- The tools the agent has available with their descriptions.
- The variables available for interpolation inside the system prompt, using Twig-like placeholders of the form {{ variable.name }}.
- Optionally, an existing system prompt that should be refined rather than rewritten from scratch.
- Optionally, additional context or constraints provided by the user.

Your job is to produce a complete, production-ready system prompt for that chat agent.

Hard requirements for the output:
1. Return ONLY the final system prompt text. No preamble, no closing remarks, no markdown fences, no JSON.
2. Write the prompt in the second person ("You are ...", "You must ..."). The prompt is addressed to the chat agent, not to the end user.
3. Use the exact variable placeholders provided in the context (keep them verbatim, including braces and dots) wherever they help contextualize the agent — typically in the role definition, scope description, or examples. Never invent placeholders that are not in the provided list.
4. Structure the prompt in clearly labeled sections (ROLE, SCOPE, TOOLS, RULES, STYLE, OUTPUT FORMAT) when there is enough material to justify them. Keep sections concise and information-dense.
5. Reference tools by their exact name when explaining when to use them; do not hallucinate tools that are not in the provided list.
6. For RAG agents, always instruct them to: (a) prefer retrieved knowledge over parametric memory, (b) cite sources when possible, (c) refuse or gracefully degrade when the scope does not contain the answer.
7. If an existing prompt is provided and the user asks for a refinement, preserve its intent and structure but improve clarity, coverage and integration with the new scopes/tools/variables.
8. Keep the prompt self-contained — do not reference "the context above" or "the JSON you received". The chat agent will never see the meta-context; it will only see the final prompt.
9. Length: aim for 150–500 words unless the user explicitly asks for something longer or shorter.
10. Output language: match the language of the additional_context / existing_prompt when provided; otherwise default to English.

Return only the system prompt string.
PROMPT;
    }

    public function prompt($message): array|string
    {
        return $message;
    }
}
