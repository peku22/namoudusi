<?php

declare(strict_types=1);

namespace Namoudusi\Webinars\Product;

final class WebinarProducts
{
    /**
     * @return array<int, string> Product ID => name, sorted by name.
     */
    public static function options(): array
    {
        $ids = get_posts([
            'post_type' => 'product',
            'post_status' => ['publish', 'private', 'draft', 'future'],
            'numberposts' => -1,
            'fields' => 'ids',
            'orderby' => 'title',
            'order' => 'ASC',
            // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only, small catalogue.
            'meta_query' => [['key' => Webinar::META_TYPE, 'value' => '', 'compare' => '!=']],
        ]);
        $options = [];
        foreach ($ids as $id) {
            $options[(int) $id] = get_the_title($id);
        }
        return $options;
    }
}
