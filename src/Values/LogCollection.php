<?php

namespace SimoneBianco\LaravelRagChunks\Models\Traits;

use App\Enums\ProcessStatus;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use SimoneBianco\LaravelRagChunks\Models\Process;

trait HasProcesses
{
    public function processes(): MorphMany
    {
        return $this->morphMany(Process::class, 'processable');
    }

    public function latestProcess(): MorphOne
    {
        return $this->morphOne(Process::class, 'processable')->latestOfMany();
    }

    public function latestPendingProcess(string $type = 'default'): ?Process
    {
        return $this->processes()
            ->where('type', $type)
            ->where('status', ProcessStatus::PENDING)
            ->latest()
            ->first();
    }

    public function latestErrorProcess(string $type = 'default'): ?Process
    {
        return $this->processes()
            ->where('type', $type)
            ->where('status', ProcessStatus::ERROR)
            ->latest()
            ->first();
    }

    public function latestCompleteProcess(string $type = 'default'): ?Process
    {
        return $this->processes()
            ->where('type', $type)
            ->where('status', ProcessStatus::COMPLETE)
            ->latest()
            ->first();
    }

    protected function getCurrentProcess(): Process
    {
        $process = $this->latestProcess;

        if (!$process || in_array($process->status, [ProcessStatus::COMPLETE, ProcessStatus::ERROR])) {
            return $this->startProcess();
        }

        return $process;
    }

    protected function log(string $method, string $content, array $context = []): self
    {
        $process = $this->getCurrentProcess();

        $process->log->{$method}($content, $context);
        $process->save();

        return $this;
    }

    public function error(string $content, array $context = []): self
    {
        return $this->log('error', $content, $context);
    }

    public function warning(string $content, array $context = []): self
    {
        return $this->log('warning', $content, $context);
    }

    public function info(string $content, array $context = []): self
    {
        return $this->log('info', $content, $context);
    }

    public function setStatus(
        string $method,
        ProcessStatus $status,
        ?string $logContext = null,
        array $context = []
    ): self {
        $process = $this->getCurrentProcess();

        $process->status = $status;

        if ($logContext) {
            $process->log->{$method}($logContext, $context);
        }

        $process->save();

        return $this;
    }

    public function setComplete(?string $logContent = null, array $context = []): self
    {
        return $this->setStatus('info', ProcessStatus::COMPLETE, $logContent, $context);
    }

    public function setError(?string $logContent = null, array $context = []): self
    {
        return $this->setStatus('error', ProcessStatus::ERROR, $logContent, $context);
    }

    public function setPending(?string $logContent = null, array $context = []): self
    {
        return $this->setStatus('info', ProcessStatus::PENDING, $logContent, $context);
    }

    public function setProcessing(?string $logContent = null, array $context = []): self
    {
        return $this->setStatus('info', ProcessStatus::PROCESSING, $logContent, $context);
    }

    public function cleanOldProcesses(int $retentionDays = 7): self
    {
        $this->processes()
            ->where('created_at', '<', now()->subDays($retentionDays))
            ->delete();

        return $this;
    }

    public function startProcess(string $type = 'default', array $initialLog = []): Process
    {
        $process = $this->processes()->create([
            'type'   => $type,
            'status' => ProcessStatus::PENDING,
            'log'    => $initialLog,
        ]);

        $this->setRelation('latestProcess', $process);

        return $process;
    }
}
