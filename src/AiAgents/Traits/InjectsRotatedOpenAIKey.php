<?php

namespace SimoneBianco\LaravelRagChunks\AiAgents\Traits;

use App\KeyRotators\Openai\OpenAIKeyRotator;
use Illuminate\Support\Facades\Log;
use Throwable;

trait InjectsRotatedOpenAIKey
{
    protected function injectRotatedOpenAIKey(): void
    {
        if (!class_exists(OpenAIKeyRotator::class)) {
            return;
        }

        try {
            OpenAIKeyRotator::make()
                ->pickKey()
                ->injectKey();
        } catch (Throwable $throwable) {
            Log::channel('search')->warning('[RotableAgent] OpenAI key rotation injection failed, using fallback config', [
                'error' => $throwable->getMessage(),
                'agent' => static::class,
            ]);
        }
    }
}
