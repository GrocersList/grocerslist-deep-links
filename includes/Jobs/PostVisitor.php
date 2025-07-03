<?php

namespace GrocersList\Jobs;

use GrocersList\Support\Hooks;
use GrocersList\Support\Logger;

abstract class PostVisitor
{
    protected Hooks $hooks;
    protected int $batchSize;

    private int $total = 0;
    private int $processed = 0;
    private bool $running = false;

    public function __construct(
        Hooks $hooks,
        int   $batchSize = 100
    )
    {
        $this->hooks = $hooks;
        $this->batchSize = $batchSize;
    }

    public function start(): array
    {
        if ($this->running) {
            $this->log("Start called but job is already running.");
            return $this->getStatus();
        }

        $this->log("Starting new synchronous job...");
        $this->reset();
        $this->running = true;

        $this->total = $this->getTotalPostCount();
        $this->log("Total posts to process: {$this->total}");

        $this->processPosts();

        $this->running = false;
        $this->log("Synchronous job completed.");
        $this->onJobCompleted();

        return $this->getStatus();
    }

    protected function processPosts(): void
    {
        $lastId = 0;

        do {
            $posts = $this->getPostsForBatch($lastId);

            if (empty($posts)) {
                break;
            }

            foreach ($posts as $post) {
                if ($this->visitPost($post)) {
                    $this->processed++;
                }
                $lastId = max($lastId, $post->ID);
            }

        } while (!empty($posts));
    }

    public function getStatus(): array
    {
        return [
            'total' => $this->total,
            'processed' => $this->processed,
            'remaining' => $this->total - $this->processed,
            'isRunning' => $this->running,
            'isComplete' => !$this->running,
        ];
    }

    protected function reset(): void
    {
        $this->total = 0;
        $this->processed = 0;
        $this->running = false;
    }

    abstract protected function getPostsForBatch(int $lastId): array;

    /**
     * @param \WP_Post $post
     */
    abstract protected function visitPost($post): bool;

    abstract protected function getTotalPostCount(): int;

    protected function onJobCompleted(): void
    {
        $this->log("Job completed.");
    }

    public function getProcessedPosts(): int
    {
        return $this->processed;
    }

    private function log(string $message): void
    {
        Logger::debug("[PostVisitor] [" . static::class . "] $message");
    }

    public function getTotalPosts(): int
    {
        return $this->total;
    }
}
