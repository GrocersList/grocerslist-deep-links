<?php

namespace GrocersList\Jobs;

use GrocersList\Support\Hooks;

class JobState
{
    public function __construct(private string $prefix) {}

    private function option(string $key): string
    {
        return 'grocers_list_' . $this->prefix . '_' . $key;
    }

    public function isRunning(): bool
    {
        return (bool) get_option($this->option('running'), false);
    }

    public function setRunning(bool $running): void
    {
        update_option($this->option('running'), $running);
    }

    public function getLastProcessedId(): int
    {
        return (int) get_option($this->option('last_processed_id'), 0);
    }

    public function setLastProcessedId(int $id): void
    {
        update_option($this->option('last_processed_id'), $id);
    }

    public function getProcessed(): int
    {
        return (int) get_option($this->option('processed'), 0);
    }

    public function setProcessed(int $count): void
    {
        update_option($this->option('processed'), $count);
    }

    public function incrementProcessed(int $delta = 1): void
    {
        $this->setProcessed($this->getProcessed() + $delta);
    }

    public function getTotal(): int
    {
        return (int) get_option($this->option('total'), 0);
    }

    public function setTotal(int $count): void
    {
        update_option($this->option('total'), $count);
    }

    public function reset(): void
    {
        $this->setProcessed(0);
        $this->setTotal(0);
        $this->setLastProcessedId(0);
    }

    public function allBatchesCompleted(): bool
    {
        return $this->getProcessed() >= $this->getTotal();
    }
}