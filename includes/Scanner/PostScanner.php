<?php

namespace GrocersList\Scanner;

use GrocersList\Support\Regex;

class PostScanner {
  
  /**
   * Scan posts for Amazon links
   * 
   * @param array $args Optional arguments for get_posts
   * @return array Results with postsWithLinks and totalLinks counts
   */
  public static function scanForAmazonLinks($args = array()) {
    $defaults = array(
      'numberposts' => -1,
      'post_type' => array('post', 'page'),
      'post_status' => 'publish',
    );
    
    $args = wp_parse_args($args, $defaults);
    $posts = get_posts($args);
    
    $regex = Regex::amazonLink();
    
    $postCount = 0;
    $linkCount = 0;
    $postsWithLinks = array();
    
    foreach ($posts as $post) {
      if (preg_match_all($regex, $post->post_content, $matches)) {
        $postCount++;
        $linkCount += count($matches[0]);
        
        $postsWithLinks[] = array(
          'id' => $post->ID,
          'title' => $post->post_title,
          'link_count' => count($matches[0]),
          'links' => $matches[0]
        );
      }
    }
    
    return array(
      'postsWithLinks' => $postCount,
      'totalLinks' => $linkCount,
      'posts' => $postsWithLinks
    );
  }
  
  /**
   * Scan a single post for Amazon links
   * 
   * @param int|WP_Post $post Post ID or post object
   * @return array Results with link count and found links
   */
  public static function scanPost($post) {
    if (is_numeric($post)) {
      $post = get_post($post);
    }
    
    if (!$post || !is_a($post, 'WP_Post')) {
      return array(
        'error' => 'Invalid post',
        'link_count' => 0,
        'links' => array()
      );
    }
    
    $regex = Regex::amazonLink();
    
    if (preg_match_all($regex, $post->post_content, $matches)) {
      return array(
        'id' => $post->ID,
        'title' => $post->post_title,
        'link_count' => count($matches[0]),
        'links' => $matches[0]
      );
    }
    
    return array(
      'id' => $post->ID,
      'title' => $post->post_title,
      'link_count' => 0,
      'links' => array()
    );
  }
  
  /**
   * Get summary statistics for Amazon links
   * 
   * @param array $args Optional arguments for get_posts
   * @return array Summary with just counts (for AJAX responses)
   */
  public static function getSummary($args = array()) {
    $results = self::scanForAmazonLinks($args);
    
    return array(
      'success' => true,
      'data' => array(
        'postsWithLinks' => $results['postsWithLinks'],
        'totalLinks' => $results['totalLinks']
      )
    );
  }
  
  /**
   * Scan for posts with legacy rewritten links
   * 
   * @param array $args Optional arguments for get_posts
   * @return array Results with posts containing data-grocerslist-rewritten-link attributes
   */
  public static function scanForLegacyLinks($args = array()) {
    $defaults = array(
      'numberposts' => -1,
      'post_type' => array('post', 'page'),
      'post_status' => 'publish',
    );
    
    $args = wp_parse_args($args, $defaults);
    $posts = get_posts($args);
    
    $postCount = 0;
    $linkCount = 0;
    $postsWithLinks = array();
    
    foreach ($posts as $post) {
      if (preg_match_all('/data-grocerslist-rewritten-link=["\']([^"\']+)["\']/i', $post->post_content, $matches)) {
        $postCount++;
        $linkCount += count($matches[0]);
        
        $postsWithLinks[] = array(
          'id' => $post->ID,
          'title' => $post->post_title,
          'link_count' => count($matches[0]),
          'legacy_links' => $matches[1]
        );
      }
    }
    
    return array(
      'postsWithLegacyLinks' => $postCount,
      'totalLegacyLinks' => $linkCount,
      'posts' => $postsWithLinks
    );
  }
}