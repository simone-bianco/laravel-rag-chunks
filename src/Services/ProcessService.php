<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Exception;
use SimoneBianco\LaravelProcesses\Enums\ProcessStatus;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\ParsingPhase;
use SimoneBianco\LaravelRagChunks\Jobs\Parsing\DispatchParsingJob;
use SimoneBianco\LaravelRagChunks\Jobs\Parsing\PostProcessParsingJob;
use SimoneBianco\LaravelRagChunks\Jobs\Parsing\RefineParsingResultsJob;
use SimoneBianco\LaravelRagChunks\Jobs\Parsing\SaveParsingJob;

class ProcessService
{
    public function resumeParsingProcess(Process $process): Process
    {
        if ($process->status === ProcessStatus::COMPLETE) {
            throw new Exception('Process is already complete');
        }

        $phase = $process->getContext('phase');
        if (!$phase) {
            throw new Exception('Process phase not found');
        }

        switch ($phase) {
            case ParsingPhase::POST_PROCESSING->value:
            case ParsingPhase::REFINED->value:
                $process->setProcessing(['phase' => ParsingPhase::POST_PROCESSING]);
                PostProcessParsingJob::dispatch($process->id);

                break;
            case ParsingPhase::POST_PROCESSED->value:
                $process->setProcessing(['phase' => ParsingPhase::POST_PROCESSED]);
                SaveParsingJob::dispatch($process->id);

                break;
            case ParsingPhase::DISPATCHING->value:
            case ParsingPhase::DISPATCHED->value:
            case ParsingPhase::POLLING->value:
                $process->setProcessing(['phase' => ParsingPhase::DISPATCHING]);
                DispatchParsingJob::dispatch($process->id);

                break;
            case ParsingPhase::REFINING->value:
                $process->setProcessing(['phase' => ParsingPhase::REFINING]);
                RefineParsingResultsJob::dispatch($process->id);

                break;
            case ParsingPhase::COMPLETED->value:
                throw new Exception('Process is already completed');
            default:
                throw new Exception("Cannot retry process with phase $phase");
        }

        return $process;
    }
}
