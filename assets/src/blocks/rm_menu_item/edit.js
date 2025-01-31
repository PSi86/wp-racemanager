import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, SelectControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

export default function Edit({ attributes, setAttributes }) {
    const { postOffset } = attributes;
    const blockProps = useBlockProps();

    const race = useSelect((select) => {
        const { getEntityRecords } = select('core');
        const offset = parseInt(postOffset.split('+')[1] || 0);
        const races = getEntityRecords('postType', 'race', {
            per_page: 1,
            offset: offset,
            orderby: 'date',
            order: 'desc',
        });
        return races && races.length ? races[0] : null;
    }, [postOffset]);

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Race Selection')}>
                    <SelectControl
                        label={__('Select Race')}
                        value={postOffset}
                        options={[
                            { label: 'Latest', value: 'latest' },
                            { label: 'Latest + 1', value: 'latest+1' },
                            { label: 'Latest + 2', value: 'latest+2' },
                            { label: 'Latest + 3', value: 'latest+3' },
                        ]}
                        onChange={(newOffset) => setAttributes({ postOffset: newOffset })}
                    />
                </PanelBody>
            </InspectorControls>
            <div {...blockProps}>
                {race ? (
                    <a href={race.link}>{race.title.rendered}</a>
                ) : (
                    <p>{__('No race found')}</p>
                )}
            </div>
        </>
    );
}
