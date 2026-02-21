<?php

namespace SimoneBianco\LaravelRagChunks\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use SimoneBianco\LaravelProcesses\Models\Process;
use SimoneBianco\LaravelRagChunks\Enums\Process\ProcessType;
use SimoneBianco\LaravelRagChunks\Jobs\Embedding\TagEmbeddingJob;
use SimoneBianco\LaravelRagChunks\Models\Embedding;
use SimoneBianco\LaravelRagChunks\Models\Tag;
use Throwable;

class TagService
{
    protected function getToEmbedText(Tag $tag): string
    {
        return "Tag Name: $tag->name\nDescription: $tag->description";
    }

    public function embedAndSave(Tag $tag): Tag
    {
        $tag->description_embedding = Embedding::embed($this->getToEmbedText($tag));
        $tag->save();

        return $tag;
    }

    /**
     * @param Collection<Tag> $tags
     * @return Collection<Tag>
     */
    public function massEmbedAndSave(Collection $tags): Collection
    {
        $toEmbedTexts = $tags->map(function (Tag $tag) {
            return $this->getToEmbedText($tag);
        })->toArray();

        $embeddedTexts = Embedding::multiEmbed($toEmbedTexts);
        $upsertData = [];

        foreach ($tags as $index => $tag) {
            $tag->description_embedding = $embeddedTexts[$index];
            $upsertData[] = $tag->getAttributes();
        }

        Tag::upsert(
            $upsertData,
            ['id'],
            ['description_embedding']
        );

        return $tags;
    }

    public function dispatchEmbed(Tag $tag): Process
    {
        return DB::transaction(function () use ($tag) {
            $process = $tag->startProcess(ProcessType::EMBEDDING->value);
            TagEmbeddingJob::dispatch($process->id)->afterCommit();
            return $process;
        });
    }

    /**
     * @param Collection<Tag> $tags
     * @return Collection<Process>
     * @throws Throwable
     */
    public function dispatchMassEmbed(Collection $tags): Collection
    {
        return DB::transaction(function () use ($tags) {
            $processes = collect();
            $jobs = [];

            $tags->each(function (Tag $tag) use ($processes, &$jobs) {
                $process = $tag->startProcess(ProcessType::EMBEDDING->value);
                $processes->push($process);
                $jobs[] = new TagEmbeddingJob($process->id);
            });

            Bus::batch($jobs)
                ->name('tag-embeddings-batch')
                ->allowFailures()
                ->dispatch();

            return $processes;
        });
    }
}
