<?php


namespace GrocersList\Jobs;

use GrocersList\Support\Hooks;
use GrocersList\Support\ILinkExtractor;
use GrocersList\Settings\PluginSettings;
use GrocersList\Support\Logger;

class LinkCountVisitor extends PostVisitor
{
    private ILinkExtractor $extractor;
    private PluginSettings $settings;

    private int $postsWithLinks = 0;
    private int $totalLinks = 0;
    private int $lastCountTime = 0;

    // Buffer for batch updates
    private array $metaUpdates = [];

    public function __construct(
        PluginSettings $settings,
        Hooks          $hooks,
        ILinkExtractor $extractor,
        int            $batchSize = 500
    )
    {
        parent::__construct($hooks, $batchSize);
        $this->settings = $settings;
        $this->extractor = $extractor;
    }

    public function startCounting(): array
    {
        Logger::debug("LinkCountVisitor::startCounting()");
        $this->resetCounters();
        return $this->start();
    }

    protected function getPostsForBatch(int $lastId): array
    {
        global $wpdb;

        $cache_key = 'grocers_list_link_count_batch_' . $lastId . '_' . $this->batchSize;
        $posts = wp_cache_get($cache_key);

        if ($posts === false) {
            $posts = $wpdb->get_results($wpdb->prepare(
                "SELECT ID, post_content
                 FROM {$wpdb->posts}
                 WHERE post_status = 'publish'
                   AND post_type IN ('post', 'page')
                   AND post_content IS NOT NULL
                   AND post_content != ''
                   AND ID > %d
                 ORDER BY ID ASC
                 LIMIT %d",
                $lastId,
                $this->batchSize
            ));

            wp_cache_set($cache_key, $posts, '', 60 * 5);
        }

        return $posts;
    }

    protected function visitPost($post): bool
    {
        $content = $post->post_content;
        $linkCount = count($this->extractor->extractUnrewrittenLinks($content));

        if ($linkCount > 0) {
            $this->postsWithLinks++;
            $this->totalLinks += $linkCount;
        }

        return true;
    }

    protected function getTotalPostCount(): int
    {
        global $wpdb;

        $cache_key = 'grocers_list_link_count_total_count';
        $count = wp_cache_get($cache_key);

        if ($count === false) {
            $count = (int)$wpdb->get_var(
                $wpdb->prepare(
                    "SELECT COUNT(ID)
                     FROM {$wpdb->posts}
                     WHERE post_status = %s
                       AND post_type IN (%s, %s)
                       AND post_content IS NOT NULL
                       AND post_content != ''",
                    'publish',
                    'post',
                    'page'
                )
            );

            wp_cache_set($cache_key, $count, '', 60 * 5);
        }

        return $count;
    }

    protected function onJobCompleted(): void
    {
        $this->lastCountTime = time();
        $this->saveResults();

        wp_cache_delete('grocers_list_link_count_total_count');
    }

    private function saveResults(): void
    {
        update_option('grocers_list_link_count_posts_with_links', $this->postsWithLinks);
        update_option('grocers_list_link_count_total_links', $this->totalLinks);
        update_option('grocers_list_link_count_total_posts', $this->getTotalPosts());
        update_option('grocers_list_link_count_processed_posts', $this->getProcessedPosts());
        update_option('grocers_list_link_count_last_time', time());
    }

    public function getCountInfo(): array
    {
        return [
            'postsWithLinks' => (int) get_option('grocers_list_link_count_posts_with_links', 0),
            'totalLinks' => (int) get_option('grocers_list_link_count_total_links', 0),
            'totalPosts' => (int) get_option('grocers_list_link_count_total_posts', 0),
            'processedPosts' => (int) get_option('grocers_list_link_count_processed_posts', 0),
            'isComplete' => true,
            'isRunning' => false,
            'lastCount' => (int) get_option('grocers_list_link_count_last_time', 0),
        ];
    }

    private function resetCounters(): void
    {
        $this->postsWithLinks = 0;
        $this->totalLinks = 0;
        $this->lastCountTime = 0;
        $this->metaUpdates = [];
    }
}
