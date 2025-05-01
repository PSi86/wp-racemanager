/**
 * blocks-src/race-gallery/index.js
 */

import { registerBlockType } from '@wordpress/blocks';
import { MediaPlaceholder, useBlockProps } from '@wordpress/block-editor';
import { Button, Spinner } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { useEntityRecords } from '@wordpress/core-data';

registerBlockType( 'wp-racemanager/race-gallery', {
  title:      __( 'Race Media Gallery', 'wp-racemanager' ),
  icon:       'images-alt2',
  category:   'media',
  attributes: {
    mediaItems: { type: 'array', default: [] },
  },

  edit( { attributes, setAttributes } ) {
    const { mediaItems } = attributes;
    const hasMedia       = mediaItems.length > 0;
    const blockProps     = useBlockProps();

    // Only store IDs
    const onConfirm = ( selection ) => {
      const models = Array.isArray( selection ) ? selection : selection.toJSON();
      setAttributes({
        mediaItems: models.map( ( m ) => m.id ),
      });
    };

    // Build the default WP gallery-edit frame selection
    const loadSelection = () => {
      if ( ! hasMedia ) {
        return false;
      }
      const shortcode   = new wp.shortcode({
        tag:   'gallery',
        attrs: { ids: mediaItems.join( ',' ) },
        type:  'single',
      });
      const attachments = wp.media.gallery.attachments( shortcode );
      const selection   = new wp.media.model.Selection(
        attachments.models,
        {
          props:    attachments.props.toJSON(),
          multiple: true,
        }
      );
      selection.gallery = attachments.gallery;
      selection.more().done( () => {
        selection.props.set( { query: false } );
        selection.unmirror();
        selection.props.unset( 'orderby' );
      } );
      return selection;
    };

    // Open the media frame
    const openGalleryFrame = () => {
      const frame = wp.media({
        frame:     'post',
        state:     hasMedia ? 'gallery-edit' : 'gallery',
        multiple:  true,
        editing:   true,
        library:   { type: [ 'image', 'video' ] },
        title:     hasMedia
          ? __( 'Edit Gallery or Add Media', 'wp-racemanager' )
          : __( 'Select or Upload Media',     'wp-racemanager' ),
        button:    { text: __( 'Confirm', 'wp-racemanager' ) },
        selection: loadSelection(),
      } );
      frame.on( 'update', onConfirm );
      frame.open();
    };

    // ——————————————————————————————————————————————
    // Fetch attachments (records + loading state)
    // ——————————————————————————————————————————————
    const query = { context: 'edit' };
    if ( hasMedia ) {
      query.include  = mediaItems;
      query.per_page = mediaItems.length;
    }
    const mediaQuery = useEntityRecords(
      'postType',
      'attachment',
      query
    ) || {};

    const mediaRecords = mediaQuery.records;
    const isResolving  = mediaQuery.isResolving;

    // ————————————————————————————————
    // While loading, show spinner
    // ————————————————————————————————
    if ( hasMedia && isResolving ) {
      return (
        <div { ...blockProps }>
          <Spinner />
        </div>
      );
    }

    // ————————————————————————————————
    // Sort into the original ID order
    // ————————————————————————————————
    const ordered = Array.isArray( mediaRecords )
      ? mediaRecords.slice().sort( ( a, b ) =>
          mediaItems.indexOf( a.id ) - mediaItems.indexOf( b.id )
        )
      : [];

    // ————————————————————————————————
    // Render placeholder or thumbnails
    // ————————————————————————————————
    return (
      <Fragment>
        <div { ...blockProps }>
          <h2 className="rm-gallery-headline">
            { __( 'Gallery', 'wp-racemanager' ) }
          </h2>

          { ! hasMedia ? (
            <MediaPlaceholder
              icon="images-alt2"
              labels={ {
                title:        __( 'Gallery', 'wp-racemanager' ),
                instructions: __(
                  'Drag and drop images, upload, or choose from your library.',
                  'wp-racemanager'
                ),
              } }
              onSelect={ onConfirm }
              allowedTypes={ [ 'image', 'video' ] }
              accept="image/*,video/*"
              multiple="add"
              gallery
            />
          ) : (
            <>
              <div className="rm-gallery-wrapper">
                { ordered.map( ( media ) => {
                  const thumb =
                    media.media_details?.sizes?.medium?.source_url ||
                    media.media_details?.sizes?.thumbnail?.source_url ||
                    media.source_url;
                  return (
                    <div key={ media.id } className="rm-gallery-thumb">
                      <img
                        src={ thumb }
                        alt={ media.alt_text || '' }
                      />
                    </div>
                  );
                } ) }
              </div>
              <div style={ { marginTop: 16 } }>
                <Button variant="primary" onClick={ openGalleryFrame }>
                  { __( 'Edit Gallery / Add Media', 'wp-racemanager' ) }
                </Button>
              </div>
            </>
          ) }

          <style>{ `
            .rm-gallery-wrapper {
              display: flex;
              flex-wrap: wrap;
              gap: 10px;
            }
            .rm-gallery-thumb img {
              width: 150px;
              height: 150px;
              object-fit: cover;
              border-radius: 3px;
            }
          ` }</style>
        </div>
      </Fragment>
    );
  },

  save() {
    return null;
  },
} );
