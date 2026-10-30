<?php
/**
 * Text helpers for Bluesky (and any network that counts graphemes):
 *   graphemes()       length in grapheme clusters (intl), else code points
 *   facets()          link and hashtag facets with UTF-8 BYTE offsets
 *   truncate_words()  shorten at a word boundary with "…", keeping the last URL
 *
 * Facet offsets: preg_match_all() reports byte offsets even with the /u flag,
 * which is exactly what byteStart / byteEnd need. Never use mb_strpos here.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Fediverse_Bluesky_Text {

    const URL_PATTERN = '#https?://[^\s<>"\']+#u';
    const TAG_PATTERN = '/(?:^|(?<=\s))#([\p{L}\p{N}_]+)/u';

    /** Length in graphemes when the intl extension is loaded, else in code points (never under-counts). */
    public static function graphemes( $text ) {
        $text = (string) $text;
        if ( function_exists( 'grapheme_strlen' ) ) {
            $n = grapheme_strlen( $text );
            if ( false !== $n && null !== $n ) {
                return (int) $n;
            }
        }
        return mb_strlen( $text );
    }

    public static function substr( $text, $start, $length ) {
        $text = (string) $text;
        if ( function_exists( 'grapheme_substr' ) ) {
            $out = grapheme_substr( $text, $start, $length );
            if ( false !== $out && null !== $out ) {
                return $out;
            }
        }
        return mb_substr( $text, $start, $length );
    }

    /**
     * URLs in a text with their byte range, trailing punctuation stripped
     * (and a closing parenthesis only when it is unbalanced).
     *
     * @return array[] { url, start, end } ordered by start.
     */
    public static function links( $text ) {
        $out = array();
        if ( ! preg_match_all( self::URL_PATTERN, (string) $text, $m, PREG_OFFSET_CAPTURE ) ) {
            return $out;
        }
        foreach ( $m[0] as $hit ) {
            $url = $hit[0];
            $url = rtrim( $url, '.,;:!?' );
            while ( '' !== $url && ')' === substr( $url, -1 ) && substr_count( $url, ')' ) > substr_count( $url, '(' ) ) {
                $url = rtrim( substr( $url, 0, -1 ), '.,;:!?' );
            }
            if ( '' === $url ) {
                continue;
            }
            $out[] = array( 'url' => $url, 'start' => (int) $hit[1], 'end' => (int) $hit[1] + strlen( $url ) );
        }
        return $out;
    }

    /**
     * Rich-text facets for app.bsky.feed.post: links and hashtags. CamelCase
     * and non-ASCII tags pass through; a tag needs at least one letter.
     */
    public static function facets( $text ) {
        $text   = (string) $text;
        $facets = array();

        foreach ( self::links( $text ) as $link ) {
            $facets[] = array(
                'index'    => array( 'byteStart' => $link['start'], 'byteEnd' => $link['end'] ),
                'features' => array( array( '$type' => 'app.bsky.richtext.facet#link', 'uri' => $link['url'] ) ),
            );
        }

        if ( preg_match_all( self::TAG_PATTERN, $text, $m, PREG_OFFSET_CAPTURE ) ) {
            foreach ( $m[0] as $i => $hit ) {
                $tag = $m[1][ $i ][0];
                if ( ! preg_match( '/\p{L}/u', $tag ) || self::graphemes( $tag ) > 64 ) {
                    continue;
                }
                $facets[] = array(
                    'index'    => array( 'byteStart' => (int) $hit[1], 'byteEnd' => (int) $hit[1] + strlen( $hit[0] ) ),
                    'features' => array( array( '$type' => 'app.bsky.richtext.facet#tag', 'tag' => $tag ) ),
                );
            }
        }

        usort( $facets, function ( $a, $b ) {
            return $a['index']['byteStart'] - $b['index']['byteStart'];
        } );
        return $facets;
    }

    /**
     * Shorten $text to $max units (as counted by $count) ending in "…".
     * Cuts at the last whitespace when that keeps at least half the budget,
     * else hard. With $keep_last_url the final URL in the text survives the
     * cut (a post's own link matters more than its last words). Also keeps
     * the result under $max_bytes.
     *
     * @param callable $count function ( $text ) : int
     */
    public static function truncate_words( $text, $max, $count, $keep_last_url = true, $max_bytes = 3000 ) {
        $text = (string) $text;
        $max  = (int) $max;
        if ( call_user_func( $count, $text ) <= $max && strlen( $text ) <= $max_bytes ) {
            return $text;
        }

        $tail  = '';
        $body  = $text;
        $links = $keep_last_url ? self::links( $text ) : array();
        if ( $links ) {
            $last = end( $links );
            $cost = call_user_func( $count, $last['url'] ) + 1;
            if ( $cost < $max / 2 ) {
                $tail = $last['url'];
                $body = trim( substr( $text, 0, $last['start'] ) . ' ' . substr( $text, $last['end'] ) );
                $body = preg_replace( '/[ \t]{2,}/', ' ', $body );
                $max -= $cost;
            }
        }

        $cut = self::cut( $body, $max - 1, $count );
        $out = '' === $cut ? '' : $cut . '…';
        if ( '' !== $tail ) {
            $out = trim( $out . ' ' . $tail );
        }

        while ( strlen( $out ) > $max_bytes ) {
            $cut = self::cut( $cut, self::graphemes( $cut ) - 1, $count );
            $out = trim( ( '' === $cut ? '' : $cut . '…' ) . ( '' !== $tail ? ' ' . $tail : '' ) );
            if ( '' === $cut ) {
                break;
            }
        }
        return $out;
    }

    /** First $budget units of $text, moved back to a word boundary when that keeps half of them. */
    private static function cut( $text, $budget, $count ) {
        if ( $budget <= 0 ) {
            return '';
        }
        if ( call_user_func( $count, $text ) <= $budget ) {
            return rtrim( $text );
        }
        $hard = self::substr( $text, 0, $budget );
        // $count may charge a URL more than its graphemes: shrink until it fits.
        while ( '' !== $hard && call_user_func( $count, $hard ) > $budget ) {
            $hard = self::substr( $hard, 0, self::graphemes( $hard ) - 1 );
        }
        if ( preg_match( '/^(.*\S)\s+\S*$/su', $hard, $m ) && self::graphemes( $m[1] ) >= $budget / 2 ) {
            $hard = $m[1];
        }
        return rtrim( $hard, " \t\n\r,;:.-–—" );
    }
}
