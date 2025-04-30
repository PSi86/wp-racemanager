import { registerBlockType } from '@wordpress/blocks';
import { MediaPlaceholder, useBlockProps } from '@wordpress/block-editor';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';

registerBlockType('wp-racemanager/race-gallery', {
  title: __('Race Media Gallery', 'wp-racemanager'),
  icon: 'images-alt2',
  category: 'media',
  attributes: {
    mediaItems: { type: 'array', default: [] },
  },

  edit({ attributes, setAttributes }) {
    const { mediaItems } = attributes;
    const hasMedia = mediaItems.length > 0;
    const blockProps = useBlockProps();

    const onConfirm = (selection) => {
      const models = Array.isArray(selection) ? selection : selection.toJSON();
      setAttributes({
        mediaItems: models.map((m) => ({
          id:   m.id,
          url:  m.url,
          thumb: m.sizes?.medium?.url || m.sizes?.thumbnail?.url || m.url,
          alt:  m.alt,
        })),
      });
    };

    /**
     * Build a wp.media.model.Selection preloaded with your existing IDs
     * (powered by wp.media.gallery.attachments + one fetch under the hood)
     */
    const loadSelection = () => {
      const ids = mediaItems.map((item) => item.id);
      if (! ids.length) {
        return false;
      }

      // Create a fake [gallery ids="1,2,3"] shortcode object
      const sc = new wp.shortcode({
        tag:   'gallery',
        attrs: { ids: ids.join(',') },
        type:  'single',
      });

      // Let core build an attachments collection with the right "include" props
      const attachments = wp.media.gallery.attachments(sc);

      // Build a Selection from those attachments:
      const selection = new wp.media.model.Selection(
        attachments.models,
        {
          props:    attachments.props.toJSON(),
          multiple: true,
        }
      );

      // Keep the gallery metadata handy:
      selection.gallery = attachments.gallery;

      // Trigger one fetch for *all* IDs at once, then break mirroring/sorting ties:
      selection.more().done(() => {
        selection.props.set({ query: false });
        selection.unmirror();
        selection.props.unset('orderby');
      });

      return selection;
    };

    const openGalleryFrame = () => {
      const selection = loadSelection();
      const frameOptions = {
        frame:    'post',
        state:    selection ? 'gallery-edit' : 'gallery',
        multiple: true,
        editing:  true,
        library:  { type: ['image', 'video'] },
        title:    hasMedia
          ? __('Edit Gallery or Add Media', 'wp-racemanager')
          : __('Select or Upload Media',    'wp-racemanager'),
        button: { text: __('Confirm', 'wp-racemanager') },
      };

      if (selection) {
        frameOptions.selection = selection;
      }

      const frame = wp.media(frameOptions);
      frame.on('update', onConfirm);
      frame.open();
    };

    return (
      <Fragment>
        <div {...blockProps}>
          <h2 className="rm-gallery-headline">
            {__('Gallery', 'wp-racemanager')}
          </h2>

          {!hasMedia ? (
            <MediaPlaceholder
              icon="images-alt2"
              labels={{
                title:        __('Gallery', 'wp-racemanager'),
                instructions: __('Drag and drop images, upload, or choose from your library.', 'wp-racemanager'),
              }}
              onSelect={onConfirm}
              allowedTypes={['image', 'video']}
              accept="image/*,video/*"
              multiple="add"
              gallery
            />
          ) : (
            <>
              <div className="rm-gallery-wrapper">
                {mediaItems.map((media) => (
                  <div key={media.id} className="rm-gallery-thumb">
                    <img src={media.thumb} alt={media.alt || ''} />
                  </div>
                ))}
              </div>
              <div style={{ marginTop: 16 }}>
                <Button isPrimary onClick={openGalleryFrame}>
                  {__('Edit Gallery / Add Media', 'wp-racemanager')}
                </Button>
              </div>
            </>
          )}

          <style>{`
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
          `}</style>
        </div>
      </Fragment>
    );
  },

  save() {
    return null;
  },
});
