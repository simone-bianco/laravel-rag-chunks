<?php


namespace SimoneBianco\LaravelRagChunks;

use SimoneBianco\LaravelAiAgents\Registries\AgentResponseHookRegistry;
use SimoneBianco\LaravelAiAgents\Registries\ScopeResolverRegistry;
use SimoneBianco\LaravelAiAgents\Registries\ToolRegistry;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\ConnectChunksFactory;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\GetChunksByAliasesFactory;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\SaveResponseDataFactory;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\SearchChunksFactory;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\SearchInAllowedProjectsFactory;
use SimoneBianco\LaravelRagChunks\AiAgents\Factories\SearchInProjectFactory;
use SimoneBianco\LaravelRagChunks\AiAgents\Hooks\ChatAgentResponseNormalizer;
use SimoneBianco\LaravelRagChunks\AiAgents\Resolvers\DocumentScopeResolver;
use SimoneBianco\LaravelRagChunks\AiAgents\Resolvers\ProjectGroupScopeResolver;
use SimoneBianco\LaravelRagChunks\AiAgents\Resolvers\ProjectScopeResolver;
use SimoneBianco\LaravelRagChunks\AiAgents\Types\AgentType;
use SimoneBianco\LaravelRagChunks\AiAgents\Types\AgentTypeRegistry;
use SimoneBianco\LaravelRagChunks\AiAgents\Types\ChatAgentTypeDefinition;
use SimoneBianco\LaravelRagChunks\AiAgents\Types\SearchAgentTypeDefinition;
use SimoneBianco\LaravelRagChunks\Console\Commands\InstallRagChunksCommand;
use SimoneBianco\LaravelRagChunks\Console\Commands\TestDispatchParsingCommand;
use SimoneBianco\LaravelRagChunks\Console\Commands\TestPollParsingCommand;
use SimoneBianco\LaravelRagChunks\Models\Document;
use SimoneBianco\LaravelRagChunks\Services\FileService;
use SimoneBianco\LaravelRagChunks\Services\HashService;
use Spatie\LaravelPackageTools\Package;
use Spatie\LaravelPackageTools\PackageServiceProvider;

class LaravelRagChunksServiceProvider extends PackageServiceProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('laravel-rag-chunks')
            ->hasConfigFile('rag_chunks')
            ->hasCommand(InstallRagChunksCommand::class)
            ->hasCommand(TestDispatchParsingCommand::class)
            ->hasCommand(TestPollParsingCommand::class);
    }

    public function packageRegistered(): void
    {
        // NOTE: legacy class_alias shims for RotableAgent / PageChatStorageDriver /
        // PageChatHistory have been removed. The package still ships local
        // subclasses under SimoneBianco\LaravelRagChunks\AiAgents\* that extend
        // the canonical classes in simone-bianco/laravel-ai-agents, so existing
        // references keep resolving without runtime aliases.

        $this->app->bind('rag-chunks-hash', function () {
            return new HashService();
        });

        $this->app->bind('rag-chunks-file', function () {
            return new FileService();
        });
    }

    public function packageBooted(): void
    {
        Process::resolveRelationUsing('document', function ($process) {
            return $process->belongsTo(Document::class, 'processable_id')
                ->where('processable_type', Document::class);
        });

        // `simone-bianco/laravel-patches` only auto-discovers patches from the
        // host app's `database_path('patches')` directory (no multi-path API).
        // We therefore expose rag-chunks' own patches via a publishable tag so
        // the host app can copy them into its patches directory once with:
        //     php artisan vendor:publish --tag=rag-chunks-patches
        // After publishing, `php artisan patches:apply` will discover them.
        $this->publishes([
            __DIR__.'/../database/patches' => database_path('patches'),
        ], 'rag-chunks-patches');

        $this->registerAiAgentsIntegration();
    }

    /**
     * Wires rag-chunks' tools, scope resolvers, response hooks and agent type
     * definitions into the laravel-ai-agents package registries.
     */
    protected function registerAiAgentsIntegration(): void
    {
        if (! class_exists(ToolRegistry::class)) {
            return;
        }

        /** @var ToolRegistry $tools */
        $tools = $this->app->make(ToolRegistry::class);
        $tools->register('search_in_project', new SearchInProjectFactory());
        $tools->register('search_in_allowed_projects', new SearchInAllowedProjectsFactory());
        $tools->register('search_chunks', new SearchChunksFactory());
        $tools->register('get_chunks_by_aliases', new GetChunksByAliasesFactory());
        $tools->register('connect_chunks', new ConnectChunksFactory());
        $tools->register('save_response_data', new SaveResponseDataFactory());

        /** @var ScopeResolverRegistry $scopes */
        $scopes = $this->app->make(ScopeResolverRegistry::class);
        $scopes->register('project', new ProjectScopeResolver());
        $scopes->register('project_group', new ProjectGroupScopeResolver());
        $scopes->register('document', new DocumentScopeResolver());

        /** @var AgentResponseHookRegistry $hooks */
        $hooks = $this->app->make(AgentResponseHookRegistry::class);
        $hooks->register('chat', ChatAgentResponseNormalizer::class);

        AgentTypeRegistry::register(AgentType::Chat, ChatAgentTypeDefinition::class);
        AgentTypeRegistry::register(AgentType::Search, SearchAgentTypeDefinition::class);
    }
}
