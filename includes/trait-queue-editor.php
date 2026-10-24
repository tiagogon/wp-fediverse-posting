<?php
/**
 * Queue editor trait: the "Fediverse" box on the post edit screen.
 *
 * Same list renderer and composer as the Planner (context 'editor'), filtered
 * to the edited post. A classic meta box flagged block-editor compatible: no
 * build step, works in both editors. Its inputs carry no `name` attributes and
 * everything goes over AJAX, so the post form never submits (or wipes) it.
 *
 * Used by Fediverse_Queue. Uses: $this->plugin.
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

trait Fediverse_Queue_Editor_Trait {

    private function register_editor_hooks() {
        add_action( 'add_meta_boxes', array( $this, 'register_editor_meta_box' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_editor_assets' ) );
    }

    public function register_editor_meta_box() {
        if ( ! current_user_can( Fediverse_Posting::compose_capability() ) ) {
            return;
        }
        foreach ( $this->plugin->post_types() as $pt ) {
            add_meta_box(
                'fediverse-posting-box',
                __( 'Fediverse', 'wp-fediverse-posting' ),
                array( $this, 'render_editor_meta_box' ),
                $pt,
                'normal',
                'default',
                array( '__block_editor_compatible_meta_box' => true )
            );
        }
    }

    public function enqueue_editor_assets( $hook ) {
        if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
            return;
        }
        $screen = get_current_screen();
        if ( ! $screen || ! in_array( $screen->post_type, $this->plugin->post_types(), true ) || ! current_user_can( Fediverse_Posting::compose_capability() ) ) {
            return;
        }
        global $post;
        $this->plugin->enqueue_admin_assets( 'editor', $post );
    }

    public function render_editor_meta_box( $post ) {
        fediverse_render_box( $this, 'editor', $post );
    }
}
