<?php

namespace GrocersList\Database;

class UrlMappingTable
{
    private $table_name;

    public function __construct()
    {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'grocerslist_url_mappings';
    }

    public function create_table(): void
    {
        global $wpdb;

        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            original_url text NOT NULL,
            original_url_hash varchar(64) NOT NULL,
            linksta_url varchar(255) NOT NULL,
            link_hash varchar(255) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_original_url (original_url_hash),
            KEY idx_link_hash (link_hash),
            KEY idx_created_at (created_at)
        ) $charset_collate;";

        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        $result = dbDelta($sql);
        
        // Log the result for debugging
        if (defined('WP_DEBUG') && WP_DEBUG && function_exists('error_log')) {
            error_log('GrocersList: dbDelta result for URL mappings table: ' . print_r($result, true));
        }
    }

    public function drop_table(): void
    {
        global $wpdb;
        $wpdb->query("DROP TABLE IF EXISTS {$this->table_name}");
    }

    public function get_table_name(): string
    {
        return $this->table_name;
    }

    public function table_exists(): bool
    {
        global $wpdb;
        
        $table_name = $wpdb->get_var($wpdb->prepare(
            "SELECT table_name FROM information_schema.tables WHERE table_schema = %s AND table_name = %s",
            $wpdb->dbname,
            $this->table_name
        ));
        
        return $table_name === $this->table_name;
    }

    public function insert_mapping(string $original_url, string $linksta_url, string $link_hash): bool
    {
        // Check if table exists before trying to insert
        if (!$this->table_exists()) {
            return false;
        }

        global $wpdb;

        $original_url_hash = hash('sha256', $original_url);

        return $wpdb->insert(
            $this->table_name,
            [
                'original_url' => $original_url,
                'original_url_hash' => $original_url_hash,
                'linksta_url' => $linksta_url,
                'link_hash' => $link_hash,
            ],
            ['%s', '%s', '%s', '%s']
        ) !== false;
    }

    public function get_mapping(string $original_url): ?object
    {
        // Check if table exists before trying to query it
        if (!$this->table_exists()) {
            return null;
        }

        global $wpdb;

        $original_url_hash = hash('sha256', $original_url);

        return $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE original_url_hash = %s",
                $original_url_hash
            )
        );
    }

    public function get_mappings_by_urls(array $original_urls): array
    {
        if (empty($original_urls)) {
            return [];
        }

        // Check if table exists before trying to query it
        if (!$this->table_exists()) {
            return [];
        }

        global $wpdb;

        $url_hashes = array_map(function($url) {
            return hash('sha256', $url);
        }, $original_urls);

        $placeholders = implode(',', array_fill(0, count($url_hashes), '%s'));
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT * FROM {$this->table_name} WHERE original_url_hash IN ($placeholders)",
                ...$url_hashes
            )
        );

        $mappings = [];
        foreach ($results as $result) {
            $mappings[$result->original_url] = (object)[
                'linksta_url' => $result->linksta_url,
                'link_hash' => $result->link_hash,
                'created_at' => $result->created_at
            ];
        }

        return $mappings;
    }

    public function upsert_mappings(array $url_mappings): void
    {
        if (empty($url_mappings)) {
            return;
        }

        // Check if table exists before trying to insert
        if (!$this->table_exists()) {
            return;
        }

        global $wpdb;

        $values = [];
        $placeholders = [];

        foreach ($url_mappings as $original_url => $data) {
            $post_id = isset($data['post_id']) ? $data['post_id'] : 0;
            $values[] = $post_id;
            $values[] = $original_url;
            $values[] = hash('sha256', $original_url);
            $values[] = $data['linksta_url'];
            $values[] = $data['link_hash'];
            $placeholders[] = '(%d, %s, %s, %s, %s)';
        }

        $placeholders_str = implode(',', $placeholders);

        $sql = "INSERT INTO {$this->table_name} 
                (post_id, original_url, original_url_hash, linksta_url, link_hash) 
                VALUES $placeholders_str
                ON DUPLICATE KEY UPDATE 
                post_id = VALUES(post_id),
                linksta_url = VALUES(linksta_url),
                link_hash = VALUES(link_hash),
                updated_at = CURRENT_TIMESTAMP";

        $wpdb->query($wpdb->prepare($sql, ...$values));
    }
}