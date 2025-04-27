// blocks-src/race-gallery/index.js
import { registerBlockType } from '@wordpress/blocks';
import {
    MediaUpload,
    MediaUploadCheck,
    InspectorControls,
} from '@wordpress/block-editor';
import {
    Button,
    PanelBody,
    PanelRow,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { Fragment } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';

registerBlockType( 'wp-racemanager/race-gallery', {
    title: __( 'Race Media Gallery', 'wp-racemanager' ),
    icon: 'images-alt2',
    category: 'media',
    attributes: {
        mediaIds: {
            type: 'array',
            default: [],
        },
    },

    edit( { attributes, setAttributes } ) {
        const { mediaIds } = attributes;
        const postId = useSelect( select => select('core/editor').getCurrentPostId(), [] );
        const { editEntityRecord } = useDispatch( 'core' );

        const onSelect = ( medias ) => {
            const ids = medias.map( m => m.id );
            setAttributes( { mediaIds: ids } );
            ids.forEach( id =>
                editEntityRecord(
                    'postType',
                    'attachment',
                    id,
                    { parent: postId }
                )
            );
        };

        return (
            <Fragment>
                <InspectorControls>
                    <PanelBody title={ __( 'Gallery Settings', 'race' ) } initialOpen>
                        <PanelRow>
                            <MediaUploadCheck>
                                <MediaUpload
                                    onSelect={ onSelect }
                                    allowedTypes={ [ 'image', 'video' ] }
                                    gallery
                                    multiple
                                    value={ mediaIds }
                                    render={ ( { open } ) => (
                                        <Button isPrimary onClick={ open }>
                                            { __( 'Select or Upload Media', 'race' ) }
                                        </Button>
                                    ) }
                                />
                            </MediaUploadCheck>
                        </PanelRow>
                    </PanelBody>
                </InspectorControls>

                <div className="race-gallery-block">
                    { mediaIds.length
                        ? <p>{ mediaIds.length } media selected.</p>
                        : <p>{ __( 'No media selected.', 'race' ) }</p> }
                </div>
            </Fragment>
        );
    },

    save() {
        return null;
    },
} );
