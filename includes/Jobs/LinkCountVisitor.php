<?php


namespace GrocersList\Jobs;

use GrocersList\Database\UrlMappingTable;
use GrocersList\Support\Hooks;
use GrocersList\Support\ILinkExtractor;
use GrocersList\Support\Logger;

class LinkCountVisitor extends PostVisitor
{
    private ILinkExtractor $extractor;
    private ?UrlMappingTable $urlMappingTable;

    private int $postsWithLinks = 0;
    private int $totalLinks = 0;

    // Buffer for batch updates
    private array $metaUpdates = [];

    public function __construct(
        Hooks          $hooks,
        ILinkExtractor $extractor,
        int            $batchSize = 500,
        ?UrlMappingTable $urlMappingTable = null
    )
    {
        parent::__construct($hooks, $batchSize);
        $this->extractor = $extractor;
        $this->urlMappingTable = $urlMappingTable;
    }

    public function startCounting(): array
    {
        Logger::debug("LinkCountVisitor::startCounting()");
        // Return real-time count instead of starting batch process
        return $this->getRealtimeCount();
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
        $amazonLinks = $this->extractor->extractUnrewrittenLinks($content);

        // If we have the mapping table, only count links without mappings
        if ($this->urlMappingTable !== null && !empty($amazonLinks)) {
            $existingMappings = $this->urlMappingTable->get_mappings_by_urls($amazonLinks);
            $unmappedLinks = array_diff($amazonLinks, array_keys($existingMappings));
            $linkCount = count($unmappedLinks);
        } else {
            // Fallback to counting all Amazon links
            $linkCount = count($amazonLinks);
        }

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
        // No longer storing results - counts are calculated in real-time
        wp_cache_delete('grocers_list_link_count_total_count');
    }

    public function getCountInfo(): array
    {
        // Calculate counts in real-time
        return $this->getRealtimeCount();
    }

    public function getRealtimeCount(): array
    {
        global $wpdb;

        $postsWithLinks = 0;
        $totalLinks = 0;
        $linkCount = 0;

        // Get all published posts with content
        $posts = $wpdb->get_results(
            "SELECT ID, post_content
             FROM {$wpdb->posts}
             WHERE post_status = 'publish'
               AND post_type IN ('post', 'page')
               AND post_content IS NOT NULL
               AND post_content != ''"
        );

        foreach ($posts as $post) {
            $amazonLinks = $this->extractor->extractUnrewrittenLinks($post->post_content);

            if (!empty($amazonLinks)) {
                // Check which links don't have mappings
                if ($this->urlMappingTable !== null) {
                    $existingMappings = $this->urlMappingTable->get_mappings_by_urls($amazonLinks);
                    $unmappedLinks = array_diff($amazonLinks, array_keys($existingMappings));
                    $linkCount = count($unmappedLinks);
                } else {
                    $linkCount = count($amazonLinks);
                }

                if ($linkCount > 0) {
                    $postsWithLinks++;
                    $totalLinks += $linkCount;
                }
            }
        }

        return [
            'unmappedLinks' => $linkCount,
            'postsWithLinks' => $postsWithLinks,
            'totalLinks' => $totalLinks,
            'totalPosts' => count($posts),
            'processedPosts' => count($posts),
            'isComplete' => $this->complete,
            'isRunning' => $this->running,
            'lastCount' => time(),
        ];
    }

    private function resetCounters(): void
    {
        $this->postsWithLinks = 0;
        $this->totalLinks = 0;
        $this->metaUpdates = [];
    }
}
