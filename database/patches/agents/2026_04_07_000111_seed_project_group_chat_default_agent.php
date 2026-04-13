<?php

declare(strict_types=1);

use SimoneBianco\LaravelAiAgents\Models\AiAgent;
use SimoneBianco\LaravelAiAgents\Models\AiAgentTool;
use SimoneBianco\LaravelAiAgents\Models\AiAgentToolBinding;

return new class {
    public bool $transactional = true;

    public function up(): void
    {
        $systemPrompt = <<<'PROMPT'
Sei un assistant specializzato nel supportare utenti del gruppo "{{ project_group.1.name }}" per:
- preparazione di sessioni, avventure, one-shot e campagne
- roleplay, mastering, conduzione del tavolo, pacing, scene, NPC, incontri
- worldbuilding fantasy e medieval-fantasy
- generazione di spunti, idee, varianti, dettagli di ambientazione, lore, citta, fazioni, conflitti, quest, dungeon, PNG

Scopo: aiutare l'utente in modo pratico, rapido e creativo, restando SEMPRE dentro il dominio session prep / mastering / worldbuilding.

Hai accesso a knowledge base progettuali tramite il tool `search_in_allowed_projects`. Devi usarlo per costruire ogni risposta che richiede contenuto specialistico.

REGOLE OPERATIVE
1) Gating: per messaggi sociali/operativi semplici (saluti, ringraziamenti) NON fare retrieval; rispondi breve e naturale. Per richieste creative o di worldbuilding usa retrieval.
2) Tool: chiama `search_in_allowed_projects` con `projectAlias` (uno degli alias consentiti del gruppo), `persistentKey` e 1-5 query brevi e indipendenti. Combina piu progetti su turni successivi se serve.
3) Persistent key: nuova chiave (suffisso casuale alfanumerico di 5 caratteri) per filoni nuovi; riusa la stessa per raffinamenti.
4) Stile: risposte brevi, chiare, orientate all'azione. Niente wall of text. Liste solo quando utili.
5) Vaghezza: se la richiesta e troppo generica, fai prima una ricerca leggera, poi chiarisci con poche domande mirate o proponi 2-4 direzioni.
6) Immagini: mostra solo immagini davvero rilevanti recuperate dai chunk.
7) Metadiscolosure: NON parlare mai di RAG, knowledge base, progetti, alias, retrieval, tool, sorgenti interne, documenti consultati. Se l'utente chiede da dove arrivano le informazioni, defletti con ironia leggera senza dettagli tecnici.
8) Fedelta allo scopo: rifiuta brevemente richieste fuori dominio e riporta il focus sul tuo perimetro.
PROMPT;

        $agent = AiAgent::query()->updateOrCreate(
            ['slug' => 'project-group-chat-default'],
            [
                'name' => 'Project Group Chat (default)',
                'description' => 'Default chat agent scoped to a project group (multi-project).',
                'type' => 'chat',
                'scope' => 'project_group',
                'provider' => 'openai',
                'model' => 'gpt-5.4',
                'system_prompt' => $systemPrompt,
                'max_completion_tokens' => 16384,
                'parallel_tool_calls' => false,
                'response_format' => 'json_schema',
                'streaming_mode' => 'sync',
                'is_system' => true,
                'is_locked' => true,
                'metadata' => [
                    'default_for' => 'project_group_chat',
                    'chat' => [
                        'include_relevant_chunks' => true,
                        'include_relevant_images' => true,
                    ],
                ],
            ],
        );

        $subAgent = AiAgent::query()->where('slug', 'multi-project-search-default')->first();
        if ($subAgent) {
            $tool = AiAgentTool::query()->updateOrCreate(
                ['key' => 'sub_agent:multi-project-search-default'],
                [
                    'kind' => 'sub_agent',
                    'sub_agent_id' => $subAgent->id,
                    'label' => 'Sub-Agent: Multi-Project Search (default)',
                    'description' => 'Delegates retrieval across allowed projects.',
                    'is_enabled' => true,
                ],
            );

            AiAgentToolBinding::query()->updateOrCreate(
                ['agent_id' => $agent->id, 'tool_id' => $tool->id],
                ['position' => 0, 'sub_agent_history_mode' => 'stateless'],
            );
        }
    }

    public function down(): void
    {
        AiAgent::query()->where('slug', 'project-group-chat-default')->forceDelete();
        AiAgentTool::query()->where('key', 'sub_agent:multi-project-search-default')->delete();
    }
};
