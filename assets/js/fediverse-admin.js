/**
 * Fediverse Posting — admin UI: composer, rows, Planner order, weekly slots,
 * placeholder chips, settings helpers. Plain JS + jQuery, no build step.
 *
 * Everything that knows about content (placeholders, hashtags, the image a
 * post will send, character counting) runs on the server: the composer asks
 * `fediverse_preview` for the exact text and images and only draws them.
 * Rows are always server-rendered HTML (`html` in every list response).
 *
 * Config: window.fediverseConfig (wp_localize_script in trait-settings-page.php).
 */
( function ( $ ) {
    'use strict';

    var C = window.fediverseConfig;
    if ( ! C ) {
        return;
    }
    var T = C.i18n;

    function esc( s ) {
        return String( s == null ? '' : s ).replace( /[&<>"']/g, function ( c ) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ c ];
        } );
    }

    function request( box, action, data ) {
        return $.post( C.ajaxUrl, $.extend( {
            action: 'fediverse_' + action,
            nonce: C.nonce,
            context: box.context
        }, data ) );
    }

    // ── Placeholder chips (composer and Settings default text) ─────────────

    $( document ).on( 'click', '.fedi-chip', function () {
        var target = document.getElementById( $( this ).closest( '.fedi-chips' ).data( 'target' ) );
        if ( ! target ) {
            return;
        }
        var token = $( this ).data( 'insert' );
        var start = target.selectionStart || 0;
        var end = target.selectionEnd || 0;
        var before = target.value.slice( 0, start );
        var after = target.value.slice( end );
        var pre = before && ! /\s$/.test( before ) ? ' ' : '';
        var post = after && ! /^\s/.test( after ) ? ' ' : '';
        target.value = before + pre + token + post + after;
        var caret = ( before + pre + token ).length;
        target.focus();
        target.setSelectionRange( caret, caret );
        $( target ).trigger( 'input' );
    } );

    // ── Weekly slots (Planner) ─────────────────────────────────────────────

    $( document ).on( 'click', '.fedi-slot__add', function () {
        var $cell = $( this ).closest( 'td' );
        var day = $( this ).closest( 'tr' ).data( 'day' );
        var name = $( this ).closest( '.fedi-schedule' ).data( 'option' ) + '[queue_schedule][' + day + '][]';
        var $slot = $( '<span class="fedi-slot"><input type="time" step="60" value="12:00"><button type="button" class="fedi-slot__remove">×</button></span>' );
        $slot.find( 'input' ).attr( 'name', name );
        $slot.find( 'button' ).attr( 'aria-label', T.remove );
        $( this ).before( $slot );
        $slot.find( 'input' ).trigger( 'focus' );
        $cell.addClass( 'is-dirty' );
    } );

    $( document ).on( 'click', '.fedi-slot__remove', function () {
        $( this ).closest( '.fedi-slot' ).remove();
    } );

    // ── Clear log (Settings) ───────────────────────────────────────────────

    $( document ).on( 'click', '[data-fedi-clear-log]', function () {
        var $btn = $( this );
        $.post( C.ajaxUrl, { action: 'fediverse_clear_log', nonce: $btn.data( 'nonce' ) } ).done( function () {
            $( '.fedi-log' ).val( '' );
        } );
    } );

    // ── Boxes (editor meta box, Planner) ───────────────────────────────────

    function Box( el ) {
        this.$el = $( el );
        this.context = this.$el.data( 'context' );
        this.postId = parseInt( this.$el.data( 'post-id' ), 10 ) || 0;
        this.$composer = this.$el.find( '.fedi-composer' );
        this.standalone = this.context === 'planner';
        this.rowPostId = this.postId;
        this.postPublic = !! C.postPublic;
        this.state = null;
        this.previewXhr = null;
        this.previewTimer = null;
        this.bind();
        this.initSortable();
    }

    Box.prototype.field = function ( name ) {
        return this.$composer.find( '[data-field="' + name + '"]' );
    };

    Box.prototype.radio = function ( name, value ) {
        var $r = this.field( name );
        if ( value === undefined ) {
            return $r.filter( ':checked' ).val() || '';
        }
        $r.prop( 'checked', false ).filter( '[value="' + value + '"]' ).prop( 'checked', true );
    };

    Box.prototype.contentType = function () {
        if ( this.standalone ) {
            return this.state.images.length ? 'gallery' : 'text';
        }
        return this.radio( 'content_type' ) || 'link_image';
    };

    Box.prototype.bind = function () {
        var self = this;

        // Global "+ New post" button sits outside the Planner box.
        if ( this.context === 'planner' ) {
            $( '.page-title-action[data-fedi-action="compose"]' ).on( 'click', function () {
                self.open( null );
            } );
        }

        this.$el.on( 'click', '[data-fedi-action]', function ( e ) {
            e.preventDefault();
            var action = $( this ).data( 'fedi-action' );
            var id = $( this ).closest( 'tr' ).data( 'id' );
            self.rowAction( action, id, $( this ) );
        } );

        this.$composer.on( 'input change', '[data-field]', function () {
            var name = $( this ).data( 'field' );
            if ( name === 'content_type' ) {
                self.state.images = [];
                self.state.imageKey = '';
            }
            self.syncUi();
            self.schedulePreview();
        } );

        this.$composer.on( 'input', '.fedi-alt', function () {
            self.state.alts[ $( this ).data( 'id' ) ] = $( this ).val();
            self.schedulePreview();
        } );

        this.$composer.on( 'click', '[data-fedi-pick]', function () {
            self.pickImages();
        } );

        this.$composer.on( 'click', '[data-fedi-reset-image]', function () {
            self.state.images = [];
            self.syncUi();
            self.schedulePreview();
        } );

        this.$composer.on( 'click', '.fedi-image__remove', function () {
            var id = parseInt( $( this ).data( 'id' ), 10 );
            self.state.images = self.state.images.filter( function ( x ) {
                return x !== id;
            } );
            self.syncUi();
            self.schedulePreview();
        } );

        this.$composer.on( 'click', '[data-fedi-cancel]', function () {
            self.close();
        } );

        this.$composer.on( 'click', '[data-fedi-submit]', function () {
            self.submit();
        } );
    };

    // ── Composer ───────────────────────────────────────────────────────────

    Box.prototype.open = function ( row ) {
        var d = C.defaults;
        var nextSlot = this.$el.attr( 'data-next-slot' );

        // Mode: the editor always works on its post; the Planner is standalone
        // unless the row being edited belongs to a WP post.
        if ( this.context === 'planner' ) {
            this.rowPostId = row ? parseInt( row.post_id, 10 ) || 0 : 0;
            this.postPublic = row ? !! row.post_public : false;
        } else {
            this.rowPostId = this.postId;
            this.postPublic = !! C.postPublic;
        }
        this.standalone = ! this.rowPostId;
        this.$composer.attr( 'data-mode', this.standalone ? 'standalone' : 'post' );

        this.state = {
            id: row ? row.id : 0,
            images: row ? ( row.gallery_ids || [] ).map( Number ) : [],
            alts: row && row.alt_overrides && ! Array.isArray( row.alt_overrides ) ? $.extend( {}, row.alt_overrides ) : {},
            imageKey: ''
        };

        var when;
        if ( row ) {
            when = row.trigger_type === 'now' ? 'now' : row.trigger_type;
        } else if ( this.context === 'editor' && ! this.postPublic ) {
            when = 'on_publish';
        } else if ( this.context === 'editor' ) {
            when = 'now';
        } else {
            when = nextSlot ? 'queue' : 'now';
        }

        if ( ! this.standalone ) {
            this.radio( 'content_type', row ? row.content_type : d.content_type );
        }
        this.field( 'template' ).val( row ? row.template : ( this.standalone ? '' : d.template ) );
        this.field( 'visibility' ).val( row ? row.visibility : d.visibility );
        this.field( 'language' ).val( row ? row.language : d.language );
        this.field( 'spoiler_text' ).val( row ? row.spoiler_text : '' );
        this.field( 'sensitive' ).prop( 'checked', row ? !! parseInt( row.is_sensitive, 10 ) : false );
        this.radio( 'when', when );
        this.field( 'at' ).val( row && row.at_local ? row.at_local : '' );

        this.$composer.find( '.fedi-images' ).empty();
        this.$composer.find( '.fedi-composer__msg' ).text( '' );
        this.$composer.prop( 'hidden', false );
        this.$el.find( '[data-fedi-action="compose"]' ).closest( 'p' ).prop( 'hidden', true );
        this.syncUi();
        this.preview();
        this.field( 'template' ).trigger( 'focus' );
        if ( this.context === 'planner' ) {
            this.$composer[ 0 ].scrollIntoView( { behavior: 'smooth', block: 'start' } );
        }
    };

    Box.prototype.close = function () {
        this.$composer.prop( 'hidden', true );
        this.$el.find( '[data-fedi-action="compose"]' ).closest( 'p' ).prop( 'hidden', false );
        this.state = null;
    };

    /** Show/hide parts that depend on content type and "When". */
    Box.prototype.syncUi = function () {
        if ( ! this.state ) {
            return;
        }
        var type = this.contentType();
        var when = this.radio( 'when' );
        var hasImages = this.standalone || type === 'link_image' || type === 'gallery';
        var multi = this.standalone || type === 'gallery';

        this.$composer.find( '.fedi-images-field' ).prop( 'hidden', ! hasImages );
        this.$composer.find( '[data-fedi-pick]' ).text( multi ? T.pickImages : T.pickImage );
        this.$composer.find( '[data-fedi-reset-image]' ).prop( 'hidden', ! ( type === 'link_image' && this.state.images.length ) );

        // "When published" only before publishing.
        var $pub = this.$composer.find( '.fedi-when--publish' );
        $pub.prop( 'hidden', this.standalone || this.postPublic );
        if ( ( this.standalone || this.postPublic ) && when === 'on_publish' ) {
            this.radio( 'when', 'now' );
            when = 'now';
        }

        this.field( 'at' ).prop( 'hidden', when !== 'at' );
        var hint = '';
        var blocked = false;
        var linkInText = /\[post-url\]/.test( this.field( 'template' ).val() );
        if ( when === 'queue' ) {
            var next = this.$el.attr( 'data-next-slot' );
            hint = next ? T.nextSlot + ' ' + next : T.noSlots;
            blocked = ! next;
        } else if ( when === 'now' && ! this.standalone && ! this.postPublic && linkInText ) {
            hint = T.nowBlocked;
            blocked = true;
        }
        this.$composer.find( '.fedi-when__hint' ).text( hint ).toggleClass( 'fedi-note--warning', blocked );

        var labels = { now: T.postNow, on_publish: T.postOnPublish, queue: T.addToQueue, at: T.schedule };
        this.$composer.find( '[data-fedi-submit]' ).text( labels[ when ] || T.save ).prop( 'disabled', blocked || this.overLimit );
        this.blocked = blocked;

        // Folded extras summary.
        var cw = $.trim( this.field( 'spoiler_text' ).val() );
        var vis = this.field( 'visibility' ).find( 'option:selected' ).text();
        var lang = this.field( 'language' ).val() || this.field( 'language' ).find( 'option:first' ).text();
        this.$composer.find( '.fedi-extras__summary' ).text( ( cw ? 'CW: ' + cw : T.noCw ) + ' · ' + vis.replace( / \(.*\)$/, '' ) + ' · ' + lang );
    };

    Box.prototype.collect = function () {
        return {
            id: this.state.id,
            post_id: this.rowPostId,
            content_type: this.contentType(),
            template: this.field( 'template' ).val(),
            gallery_ids: this.state.images,
            alt: this.state.alts,
            visibility: this.field( 'visibility' ).val(),
            language: this.field( 'language' ).val(),
            spoiler_text: this.field( 'spoiler_text' ).val(),
            sensitive: this.field( 'sensitive' ).is( ':checked' ) ? 1 : 0,
            when: this.radio( 'when' ),
            at: this.field( 'at' ).val()
        };
    };

    Box.prototype.schedulePreview = function () {
        var self = this;
        clearTimeout( this.previewTimer );
        this.previewTimer = setTimeout( function () {
            self.preview();
        }, 350 );
    };

    Box.prototype.preview = function () {
        var self = this;
        if ( ! this.state ) {
            return;
        }
        if ( this.previewXhr ) {
            this.previewXhr.abort();
        }
        this.previewXhr = request( this, 'preview', this.collect() ).done( function ( res ) {
            if ( res && res.success && self.state ) {
                self.drawPreview( res.data );
            }
        } );
    };

    function linkify( text ) {
        return esc( text )
            .replace( /(https?:\/\/[^\s<]+)/g, function ( url ) {
                var shown = url.replace( /^https?:\/\/(www\.)?/, '' );
                if ( shown.length > 30 ) {
                    shown = shown.slice( 0, 30 ) + '…';
                }
                return '<span class="fedi-preview__link">' + shown + '</span>';
            } )
            .replace( /(^|\s)(#[\p{L}\p{N}_]+)/gu, '$1<span class="fedi-preview__link">$2</span>' )
            .replace( /\n/g, '<br>' );
    }

    Box.prototype.drawPreview = function ( p ) {
        var $c = this.$composer;
        var a = C.account;

        // Counter.
        var left = p.max - p.chars;
        this.overLimit = left < 0;
        $c.find( '.fedi-counter' )
            .text( left >= 0 ? left + ' ' + T.left : ( -left ) + ' ' + T.over )
            .toggleClass( 'is-over', left < 0 );

        // Card.
        $c.find( '.fedi-preview__avatar' ).html( a.avatar ? '<img src="' + esc( a.avatar ) + '" alt="">' : '' );
        $c.find( '.fedi-preview__name' ).text( a.name );
        $c.find( '.fedi-preview__acct' ).text( a.acct ? '@' + a.acct : '' );
        var cw = $.trim( this.field( 'spoiler_text' ).val() );
        $c.find( '.fedi-preview__cw' ).prop( 'hidden', ! cw );
        $c.find( '.fedi-preview__cw-text' ).text( cw );
        $c.find( '.fedi-preview__cw-btn' ).text( T.showMore );
        $c.find( '.fedi-preview__text' ).html( linkify( p.text ) ).toggleClass( 'is-behind-cw', !! cw );

        var blur = !! cw || this.field( 'sensitive' ).is( ':checked' );
        var media = p.images.map( function ( img ) {
            return '<img src="' + esc( img.url ) + '" alt="' + esc( img.alt ) + '"' + ( blur ? ' class="is-blurred"' : '' ) + '>';
        } ).join( '' );
        $c.find( '.fedi-preview__media' ).attr( 'data-count', p.images.length ).html( media );

        // Image list with alt text: rebuilt only when the set changes, so typing keeps focus.
        var key = p.images.map( function ( i ) {
            return i.id;
        } ).join( ',' );
        if ( key !== this.state.imageKey ) {
            this.state.imageKey = key;
            this.drawImages( p.images );
        }

        // Warnings.
        $c.find( '.fedi-warnings' ).html( p.warnings.map( function ( w ) {
            return '<li>' + esc( w ) + '</li>';
        } ).join( '' ) );

        this.syncUi();
    };

    Box.prototype.drawImages = function ( images ) {
        var self = this;
        var removable = this.standalone || this.contentType() === 'gallery';
        var html = images.map( function ( img ) {
            var override = self.state.alts[ img.id ];
            var value = override !== undefined ? override : img.alt_library;
            return '<div class="fedi-image">' +
                '<img src="' + esc( img.url ) + '" alt="">' +
                '<div class="fedi-image__meta">' +
                    '<label class="fedi-label">' + esc( T.altLabel ) +
                    '<textarea rows="2" class="fedi-alt' + ( value ? '' : ' is-missing' ) + '" data-id="' + img.id + '" placeholder="' + esc( T.altPlaceholder ) + '">' + esc( value ) + '</textarea></label>' +
                    ( img.alt_library && override === undefined ? '<span class="description">' + esc( T.altFromLibrary ) + '</span>' : '' ) +
                    ( removable ? '<button type="button" class="button-link fedi-danger-link fedi-image__remove" data-id="' + img.id + '">' + esc( T.remove ) + '</button>' : '' ) +
                '</div></div>';
        } ).join( '' );
        this.$composer.find( '.fedi-images' ).html( html );
    };

    Box.prototype.pickImages = function () {
        var self = this;
        var multi = this.standalone || this.contentType() === 'gallery';
        var frame = wp.media( {
            title: multi ? T.pickImages : T.pickImage,
            button: { text: T.use },
            multiple: multi ? 'add' : false,
            library: { type: 'image' }
        } );
        frame.on( 'open', function () {
            var sel = frame.state().get( 'selection' );
            self.state.images.forEach( function ( id ) {
                var att = wp.media.attachment( id );
                att.fetch();
                sel.add( att );
            } );
        } );
        frame.on( 'select', function () {
            var ids = frame.state().get( 'selection' ).map( function ( a ) {
                return a.id;
            } );
            self.state.images = ids.slice( 0, multi ? C.maxMedia : 1 );
            self.syncUi();
            self.preview();
        } );
        frame.open();
    };

    Box.prototype.submit = function () {
        var self = this;
        if ( this.blocked || this.overLimit ) {
            return;
        }
        var when = this.radio( 'when' );
        var $btn = this.$composer.find( '[data-fedi-submit]' ).prop( 'disabled', true );
        var $spin = this.$composer.find( '.spinner' ).addClass( 'is-active' );
        var $msg = this.$composer.find( '.fedi-composer__msg' ).text( when === 'now' ? T.sending : T.saving );

        request( this, 'save', this.collect() ).done( function ( res ) {
            if ( ! res || ! res.success ) {
                $msg.text( res && res.data && res.data.message ? res.data.message : T.failed );
                return;
            }
            self.applyList( res.data );
            self.close();
            self.flash( res.data.message, res.data.url, res.data.error );
        } ).fail( function () {
            $msg.text( T.failed );
            self.refresh();
        } ).always( function () {
            $spin.removeClass( 'is-active' );
            $btn.prop( 'disabled', false );
        } );
    };

    // ── Rows ───────────────────────────────────────────────────────────────

    Box.prototype.rowAction = function ( action, id, $btn ) {
        var self = this;
        if ( action === 'compose' ) {
            this.open( null );
            return;
        }
        if ( action === 'edit' ) {
            request( this, 'get', { id: id } ).done( function ( res ) {
                if ( res && res.success ) {
                    self.open( res.data.row );
                }
            } );
            return;
        }
        if ( action === 'delete' ) {
            var $row = $btn.closest( 'tr' );
            var msg = $row.hasClass( 'fedi-row--published' ) || $row.hasClass( 'fedi-row--removed' ) ? T.confirmForget : T.confirmDelete;
            if ( ! window.confirm( msg ) ) {
                return;
            }
        }
        if ( action === 'delete_remote' && ! window.confirm( T.confirmRemote ) ) {
            return;
        }
        var data = { id: id };
        if ( action === 'skip_auto' ) {
            data = { post_id: this.postId, skip: $btn.data( 'skip' ) };
        }
        $btn.prop( 'disabled', true );
        request( this, action, data ).done( function ( res ) {
            if ( res && res.success ) {
                self.applyList( res.data );
                if ( res.data.message ) {
                    self.flash( res.data.message, res.data.url, res.data.error );
                }
            } else {
                self.flash( res && res.data && res.data.message ? res.data.message : T.failed, null, true );
            }
        } ).fail( function () {
            self.flash( T.failed, null, true );
        } ).always( function () {
            $btn.prop( 'disabled', false );
        } );
    };

    Box.prototype.applyList = function ( data ) {
        if ( data.html !== undefined ) {
            this.$el.find( '.fedi-list' ).html( data.html );
            this.initSortable();
        }
        if ( data.next_slot !== undefined ) {
            this.$el.attr( 'data-next-slot', data.next_slot );
            this.$el.find( '.fedi-next-slot' ).text( data.next_slot );
        }
        if ( data.note_html !== undefined ) {
            this.$el.find( '.fedi-auto-note' ).replaceWith( data.note_html );
        }
    };

    Box.prototype.flash = function ( message, url, isError ) {
        var $f = this.$el.find( '.fedi-flash' );
        $f.toggleClass( 'is-error', !! isError ).html( esc( message ) + ( url ? ' <a href="' + esc( url ) + '" target="_blank" rel="noopener">' + esc( T.view ) + ' ↗</a>' : '' ) );
        clearTimeout( this.flashTimer );
        this.flashTimer = setTimeout( function () {
            $f.empty();
        }, 12000 );
    };

    Box.prototype.refresh = function () {
        var self = this;
        return request( this, 'list', { post_id: this.postId } ).done( function ( res ) {
            if ( res && res.success ) {
                self.applyList( res.data );
                if ( res.data.post_public !== undefined && self.context === 'editor' ) {
                    C.postPublic = self.postPublic = !! res.data.post_public;
                    self.syncUi();
                }
            }
        } );
    };

    Box.prototype.initSortable = function () {
        var self = this;
        if ( this.context !== 'planner' || ! $.fn.sortable ) {
            return;
        }
        this.$el.find( '.fedi-upcoming' ).sortable( {
            items: '> tr.fedi-row--sortable',
            handle: '.fedi-handle',
            axis: 'y',
            helper: function ( e, tr ) {
                var $orig = tr.children();
                var $h = tr.clone();
                $h.children().each( function ( i ) {
                    $( this ).width( $orig.eq( i ).width() );
                } );
                return $h;
            },
            update: function () {
                var ids = self.$el.find( '.fedi-upcoming tr.fedi-row--sortable' ).map( function () {
                    return $( this ).data( 'id' );
                } ).get();
                request( self, 'reorder', { ids: ids } ).done( function ( res ) {
                    if ( res && res.success ) {
                        self.applyList( res.data );
                    }
                } );
            }
        } );
    };

    // ── Block editor: refresh after each save (publishing releases rows) ───

    function watchBlockEditor( box ) {
        if ( ! window.wp || ! wp.data || ! wp.data.select( 'core/editor' ) ) {
            return;
        }
        var wasSaving = false;
        wp.data.subscribe( function () {
            var ed = wp.data.select( 'core/editor' );
            var saving = ed.isSavingPost() && ! ed.isAutosavingPost();
            if ( wasSaving && ! saving ) {
                setTimeout( function () {
                    box.refresh();
                }, 1200 );
            }
            wasSaving = saving;
        } );
    }

    $( function () {
        $( '.fedi-box[data-context]' ).each( function () {
            var box = new Box( this );
            if ( box.context === 'editor' ) {
                watchBlockEditor( box );
            }
        } );
    } );
} )( jQuery );
