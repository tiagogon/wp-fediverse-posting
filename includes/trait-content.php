<?php
/**
 * Content trait: turns a queue row into what is sent.
 *
 * - Placeholders are filled in at SEND time (and for the live preview), never
 *   when queued, so a title or slug edited after queuing is not lost.
 * - Post placeholders: [post-title] [post-url] [post-excerpt] [site-name].
 * - Hashtag placeholders, one per taxonomy: [tags] (post_tag), [categories]
 *   (category), [tax-<slug>] for any other taxonomy. Each renders that post's
 *   terms as hashtags: multi-word names in CamelCase (#BerlinWall, readable
 *   by screen readers), single words as typed, accents kept, numbers-only
 *   terms skipped (Mastodon does not link them). Filter per tag:
 *   fediverse_posting_hashtag( $tag, $term, $taxonomy ) — return '' to skip.
 * - Modifiers: [tax-medium:deepest] (most specific terms only),
 *   [tax-medium:top] (root terms only), [tax-places:max=3],
 *   [tax-medium:only=iPhone] / [tax-medium:not=iPhone,Screenshot] (names or
 *   slugs, applied first); combinable in any order.
 * - One "seen" list per render: no hashtag appears twice in a text, counting
 *   hashtags typed literally. Settings "Never use as hashtags" drops terms.
 * - Standalone rows (post_id 0) only get [site-name]; post placeholders go blank.
 *
 * Content types: text | link | link_image (default) | gallery.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Fediverse_Content_Trait {

    public static function content_types() {
        return array(
            'link_image' => __( 'Text + link + featured image', 'wp-fediverse-posting' ),
            'link'       => __( 'Text + link', 'wp-fediverse-posting' ),
            'gallery'    => __( 'Text + link + gallery', 'wp-fediverse-posting' ),
            'text'       => __( 'Text only', 'wp-fediverse-posting' ),
        );
    }

    public static function content_type_short( $type ) {
        $short = array(
            'link_image' => __( 'Link + image', 'wp-fediverse-posting' ),
            'link'       => __( 'Link', 'wp-fediverse-posting' ),
            'gallery'    => __( 'Gallery', 'wp-fediverse-posting' ),
            'text'       => __( 'Text', 'wp-fediverse-posting' ),
        );
        return isset( $short[ $type ] ) ? $short[ $type ] : $type;
    }

    public static function visibilities() {
        return array(
            'public'   => __( 'Public', 'wp-fediverse-posting' ),
            'unlisted' => __( 'Unlisted (quiet public)', 'wp-fediverse-posting' ),
            'private'  => __( 'Followers only', 'wp-fediverse-posting' ),
            'direct'   => __( 'Direct (mentioned people only, for testing)', 'wp-fediverse-posting' ),
        );
    }

    /** Language choices: '' = automatic (the filter, then the site language). */
    public static function languages() {
        $list = array( 'en', 'pt', 'es', 'fr', 'de', 'it', 'nl', 'ca', 'gl', 'eu', 'pl', 'sv', 'da', 'no', 'fi', 'cs', 'el', 'tr', 'ru', 'uk', 'ja', 'ko', 'zh', 'ar', 'he', 'hi' );
        return apply_filters( 'fediverse_posting_languages', $list );
    }

    /** Site language as an ISO 639-1 code ("pt_PT" → "pt"). */
    public static function site_language() {
        return strtolower( substr( get_locale(), 0, 2 ) );
    }

    /**
     * Language sent with a row: row value, else the default setting, else the
     * filter (themes can map per-post languages), else the site language.
     */
    public function resolve_language( $row_language, $post ) {
        $lang = (string) $row_language;
        if ( '' === $lang ) {
            $lang = (string) $this->get_setting( 'default_language' );
        }
        if ( '' === $lang ) {
            $lang = (string) apply_filters( 'fediverse_posting_language', '', $post );
        }
        if ( '' === $lang ) {
            $lang = self::site_language();
        }
        return preg_match( '/^[a-z]{2,3}$/', $lang ) ? $lang : '';
    }


    // =====================================================================
    //  PLACEHOLDERS
    // =====================================================================

    /** Placeholder token for a taxonomy: [tags], [categories], [tax-<slug>]. */
    public static function taxonomy_placeholder( $taxonomy ) {
        if ( 'post_tag' === $taxonomy ) {
            return 'tags';
        }
        if ( 'category' === $taxonomy ) {
            return 'categories';
        }
        return 'tax-' . $taxonomy;
    }

    /**
     * Hashtag placeholders available for the given post types, as
     * token => taxonomy label. Built from the registered taxonomies, so new
     * custom taxonomies show up without code changes.
     */
    public function hashtag_placeholders( $post_types = null ) {
        $post_types = null === $post_types ? $this->post_types() : (array) $post_types;
        $out        = array();
        foreach ( $post_types as $pt ) {
            foreach ( get_object_taxonomies( $pt, 'objects' ) as $tax ) {
                if ( 'post_format' === $tax->name || ! $tax->show_ui ) {
                    continue;
                }
                $token         = self::taxonomy_placeholder( $tax->name );
                $out[ $token ] = $tax->labels->name;
                if ( $tax->hierarchical ) {
                    /* translators: %s: taxonomy name */
                    $out[ $token . ':deepest' ] = sprintf( __( '%s: most specific level only', 'wp-fediverse-posting' ), $tax->labels->name );
                    /* translators: %s: taxonomy name */
                    $out[ $token . ':top' ] = sprintf( __( '%s: top level only', 'wp-fediverse-posting' ), $tax->labels->name );
                }
            }
        }
        if ( ! isset( $out['tags'] ) ) {
            $out = array_merge( array( 'tags' => __( 'Tags', 'wp-fediverse-posting' ) ), $out );
        }
        return $out;
    }

    public static function post_placeholders() {
        return array(
            'post-title'   => __( 'Post title', 'wp-fediverse-posting' ),
            'post-url'     => __( 'Post link', 'wp-fediverse-posting' ),
            'post-excerpt' => __( 'Excerpt', 'wp-fediverse-posting' ),
            'site-name'    => __( 'Site name', 'wp-fediverse-posting' ),
        );
    }

    /** "Berlin Wall" → "BerlinWall"; "iPhone" stays; "2016" → '' (not a hashtag). */
    public static function hashtagify( $name ) {
        $name  = wp_specialchars_decode( (string) $name, ENT_QUOTES );
        $words = preg_split( '/[^\p{L}\p{N}_]+/u', $name, -1, PREG_SPLIT_NO_EMPTY );
        if ( empty( $words ) ) {
            return '';
        }
        if ( count( $words ) > 1 ) {
            $words = array_map( function ( $w ) {
                return mb_strtoupper( mb_substr( $w, 0, 1 ) ) . mb_substr( $w, 1 );
            }, $words );
        }
        $tag = implode( '', $words );
        return preg_match( '/\p{L}/u', $tag ) ? $tag : '';
    }

    /** Hashtag exclusions from Settings, lowercased, with and without spaces. */
    public function hashtag_exclusions() {
        $out = array();
        foreach ( (array) $this->get_setting( 'hashtag_exclude' ) as $name ) {
            $name = mb_strtolower( trim( (string) $name ) );
            if ( '' !== $name ) {
                $out[ $name ]                                    = true;
                $out[ mb_strtolower( self::hashtagify( $name ) ) ] = true;
            }
        }
        return $out;
    }

    /**
     * Hashtags for one taxonomy on one post, e.g. "#Berlin #Favacal".
     *
     * @param WP_Post $post
     * @param string  $taxonomy
     * @param array   $mods     deepest (bool), top (bool), max (int): from
     *                          placeholder modifiers like [tax-medium:deepest:max=1].
     * @param array   $seen     Lowercase tags already in the text; updated.
     *                          Shared across one render so no tag appears twice.
     */
    public function hashtags_for( $post, $taxonomy, $mods = array(), &$seen = array() ) {
        if ( ! taxonomy_exists( $taxonomy ) ) {
            return '';
        }
        $terms = get_the_terms( $post, $taxonomy );
        if ( empty( $terms ) || is_wp_error( $terms ) ) {
            return '';
        }

        // only= / not=: pick terms by name or slug, before levels are worked out.
        foreach ( array( 'only', 'not' ) as $mode ) {
            if ( empty( $mods[ $mode ] ) ) {
                continue;
            }
            $list  = array_flip( $mods[ $mode ] );
            $terms = array_values( array_filter( $terms, function ( $t ) use ( $list, $mode ) {
                $hit = isset( $list[ mb_strtolower( $t->name ) ] ) || isset( $list[ mb_strtolower( $t->slug ) ] );
                return 'only' === $mode ? $hit : ! $hit;
            } ) );
        }
        if ( empty( $terms ) ) {
            return '';
        }

        if ( is_taxonomy_hierarchical( $taxonomy ) && ( ! empty( $mods['deepest'] ) || ! empty( $mods['top'] ) ) ) {
            if ( ! empty( $mods['deepest'] ) ) {
                $depths = array();
                foreach ( $terms as $term ) {
                    $depths[ $term->term_id ] = count( get_ancestors( $term->term_id, $taxonomy, 'taxonomy' ) );
                }
                $max   = max( $depths );
                $terms = array_values( array_filter( $terms, function ( $t ) use ( $depths, $max ) {
                    return $depths[ $t->term_id ] === $max;
                } ) );
            } else {
                $roots = array();
                foreach ( $terms as $term ) {
                    $ancestors = get_ancestors( $term->term_id, $taxonomy, 'taxonomy' );
                    $root_id   = $ancestors ? (int) end( $ancestors ) : $term->term_id;
                    $root      = $root_id === $term->term_id ? $term : get_term( $root_id, $taxonomy );
                    if ( $root && ! is_wp_error( $root ) ) {
                        $roots[ $root->term_id ] = $root;
                    }
                }
                $terms = array_values( $roots );
            }
        }

        $exclude = $this->hashtag_exclusions();
        $limit   = isset( $mods['max'] ) ? (int) $mods['max'] : 0;
        $tags    = array();
        foreach ( $terms as $term ) {
            $tag = self::hashtagify( $term->name );
            if ( isset( $exclude[ mb_strtolower( $term->name ) ] ) || isset( $exclude[ mb_strtolower( $tag ) ] ) ) {
                continue;
            }
            $tag = ltrim( (string) apply_filters( 'fediverse_posting_hashtag', $tag, $term, $taxonomy ), '#' );
            $key = mb_strtolower( $tag );
            if ( '' === $tag || isset( $seen[ $key ] ) ) {
                continue;
            }
            $seen[ $key ] = true;
            $tags[]       = '#' . $tag;
            if ( $limit && count( $tags ) >= $limit ) {
                break;
            }
        }
        return implode( ' ', $tags );
    }

    /**
     * ":deepest:not=iPhone,Screenshot:max=2" → array( 'deepest' => true,
     * 'not' => array( 'iphone', 'screenshot' ), 'max' => 2 ). Term lists are
     * names or slugs, lowercased. Unrecognised parts land in 'unknown'.
     */
    public static function parse_modifiers( $raw ) {
        $mods = array();
        foreach ( array_filter( array_map( 'trim', explode( ':', (string) $raw ) ), 'strlen' ) as $part ) {
            $lower = mb_strtolower( $part );
            if ( 'deepest' === $lower || 'top' === $lower ) {
                $mods[ $lower ] = true;
            } elseif ( preg_match( '/^max=(\d+)$/', $lower, $m ) ) {
                $mods['max'] = (int) $m[1];
            } elseif ( preg_match( '/^(only|not)=(.+)$/su', $lower, $m ) ) {
                $mods[ $m[1] ] = array_values( array_filter( array_map( 'trim', explode( ',', $m[2] ) ), 'strlen' ) );
            } else {
                $mods['unknown'][] = $part;
            }
        }
        return $mods;
    }

    /** Placeholder token → taxonomy slug, or '' when not a hashtag token. */
    public static function taxonomy_from_placeholder( $token ) {
        if ( 'tags' === $token ) {
            return 'post_tag';
        }
        if ( 'categories' === $token ) {
            return 'category';
        }
        return 0 === strpos( $token, 'tax-' ) ? substr( $token, 4 ) : '';
    }

    /**
     * Fill in a template.
     *
     * @param string       $template
     * @param WP_Post|null $post     null for standalone rows.
     * @param array        $unknown  Receives placeholders that matched nothing.
     */
    public function render_template( $template, $post, &$unknown = array() ) {
        $unknown = array();
        $self    = $this;

        // Hashtags typed literally (e.g. "#Film") count as seen, so a
        // placeholder never repeats them.
        $seen = array();
        if ( preg_match_all( '/(?:^|\s)#([\p{L}\p{N}_]+)/u', preg_replace( '/\[[^\]]*\]/', ' ', (string) $template ), $lit ) ) {
            foreach ( $lit[1] as $tag ) {
                $seen[ mb_strtolower( $tag ) ] = true;
            }
        }

        $text = preg_replace_callback( '/\[([a-z0-9_-]+)((?::[^\]:]+)*)\]/iu', function ( $m ) use ( $post, $self, &$unknown, &$seen ) {
            $token = strtolower( $m[1] );
            switch ( $token ) {
                case 'site-name':
                    return wp_specialchars_decode( get_bloginfo( 'name' ), ENT_QUOTES );
                case 'post-title':
                    return $post ? wp_specialchars_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES ) : '';
                case 'post-url':
                    return $post ? (string) get_permalink( $post ) : '';
                case 'post-excerpt':
                    if ( ! $post ) {
                        return '';
                    }
                    $excerpt = has_excerpt( $post ) ? $post->post_excerpt : wp_trim_words( strip_shortcodes( $post->post_content ), 40, '…' );
                    return trim( wp_specialchars_decode( wp_strip_all_tags( $excerpt ), ENT_QUOTES ) );
            }
            $taxonomy = Fediverse_Posting::taxonomy_from_placeholder( $token );
            if ( '' !== $taxonomy && taxonomy_exists( $taxonomy ) ) {
                $mods = Fediverse_Posting::parse_modifiers( $m[2] );
                if ( ! empty( $mods['unknown'] ) ) {
                    // Flag the typo in the preview, still render the tags.
                    $unknown[] = $m[0];
                }
                return $post ? $self->hashtags_for( $post, $taxonomy, $mods, $seen ) : '';
            }
            $unknown[] = $m[0];
            return $m[0];
        }, (string) $template );

        // Tidy the gaps empty placeholders leave, keep the author's line breaks.
        $lines = array_map( function ( $line ) {
            return trim( preg_replace( '/[ \t]{2,}/', ' ', $line ) );
        }, preg_split( "/\r\n|\n|\r/", $text ) );
        $text  = trim( implode( "\n", $lines ) );
        return preg_replace( "/\n{3,}/", "\n\n", $text );
    }

    /** Mastodon's counting: every URL is worth url_length characters. */
    public function count_characters( $text ) {
        $limits = $this->instance_limits();
        $text   = preg_replace( '#https?://\S+#u', str_repeat( 'x', $limits['url_length'] ), (string) $text );
        return mb_strlen( $text );
    }


    // =====================================================================
    //  IMAGES
    // =====================================================================

    /**
     * First image attached to a post, used when the featured image is missing.
     * Themes with their own gallery order hook fediverse_posting_fallback_image_id.
     */
    public function fallback_image_id( $post ) {
        $images = get_children( array(
            'post_parent'    => $post->ID,
            'post_type'      => 'attachment',
            'post_mime_type' => 'image',
            'orderby'        => 'menu_order ID',
            'order'          => 'ASC',
            'numberposts'    => 1,
            'fields'         => 'ids',
        ) );
        $id = $images ? (int) reset( $images ) : 0;
        return (int) apply_filters( 'fediverse_posting_fallback_image_id', $id, $post );
    }

    /**
     * Attachment ids a row will send, in order, capped at the server limit.
     *
     * - link_image: the row's chosen image, else the featured image, else the
     *   first attached image, else none (sends as text + link).
     * - gallery / standalone: the row's picked images.
     */
    public function resolve_media_ids( $row, $post ) {
        $limits = $this->instance_limits();
        $picked = array_values( array_filter( array_map( 'intval', (array) $row['gallery_ids'] ) ) );

        switch ( $row['content_type'] ) {
            case 'link_image':
                if ( ! empty( $picked ) ) {
                    return array( $picked[0] );
                }
                if ( ! $post ) {
                    return array();
                }
                $id = (int) get_post_thumbnail_id( $post );
                if ( ! $id ) {
                    $id = $this->fallback_image_id( $post );
                }
                return $id ? array( $id ) : array();
            case 'gallery':
                return array_slice( $picked, 0, $limits['max_media'] );
            default:
                return array();
        }
    }

    /** Alt text: the row's override, else the attachment's alt, max 1500 chars. */
    public function alt_text_for( $attachment_id, $row ) {
        $overrides = (array) $row['alt_overrides'];
        $alt       = isset( $overrides[ $attachment_id ] ) ? (string) $overrides[ $attachment_id ] : '';
        if ( '' === trim( $alt ) ) {
            $alt = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
        }
        return mb_substr( trim( $alt ), 0, 1500 );
    }

    /**
     * Pick the file to upload: the original when the server accepts its size
     * and type, else the largest generated size that fits (JPEG copies of
     * HEIC/TIFF originals included). Offloaded media is downloaded to a temp file.
     *
     * @return array|WP_Error { path, mime, temp (bool) }
     */
    public function prepare_media_file( $attachment_id ) {
        $limits = $this->instance_limits();
        $types  = $limits['image_types'];
        $max    = $limits['image_size_limit'];

        $candidates = array();
        $original   = function_exists( 'wp_get_original_image_path' ) ? wp_get_original_image_path( $attachment_id ) : '';
        $attached   = get_attached_file( $attachment_id );
        foreach ( array_unique( array_filter( array( $original, $attached ) ) ) as $path ) {
            $candidates[] = array( 'path' => $path, 'mime' => (string) get_post_mime_type( $attachment_id ) );
        }

        $meta = wp_get_attachment_metadata( $attachment_id );
        if ( $attached && ! empty( $meta['sizes'] ) ) {
            $sizes = $meta['sizes'];
            uasort( $sizes, function ( $a, $b ) {
                return (int) $b['width'] - (int) $a['width'];
            } );
            foreach ( $sizes as $size ) {
                $candidates[] = array(
                    'path' => trailingslashit( dirname( $attached ) ) . $size['file'],
                    'mime' => isset( $size['mime-type'] ) ? $size['mime-type'] : (string) get_post_mime_type( $attachment_id ),
                );
            }
        }

        foreach ( $candidates as $c ) {
            if ( is_readable( $c['path'] ) && in_array( $c['mime'], $types, true ) && filesize( $c['path'] ) <= $max ) {
                return array( 'path' => $c['path'], 'mime' => $c['mime'], 'temp' => false );
            }
        }

        // Not on local disk (offloaded): fetch the largest web copy.
        $src = wp_get_attachment_image_src( $attachment_id, 'large' );
        if ( $src && ! empty( $src[0] ) ) {
            if ( ! function_exists( 'download_url' ) ) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $tmp = download_url( $src[0], 60 );
            if ( ! is_wp_error( $tmp ) ) {
                $check = wp_check_filetype_and_ext( $tmp, basename( wp_parse_url( $src[0], PHP_URL_PATH ) ) );
                $mime  = $check['type'] ? $check['type'] : 'image/jpeg';
                if ( in_array( $mime, $types, true ) && filesize( $tmp ) <= $max ) {
                    return array( 'path' => $tmp, 'mime' => $mime, 'temp' => true );
                }
                wp_delete_file( $tmp );
            }
        }

        return new WP_Error(
            'fediverse_media',
            /* translators: %d: attachment ID */
            sprintf( __( 'Image #%d has no copy the server accepts (type or size).', 'wp-fediverse-posting' ), $attachment_id ),
            array( 'status' => 0, 'retryable' => false )
        );
    }
}
