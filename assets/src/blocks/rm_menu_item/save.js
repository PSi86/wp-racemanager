import { useBlockProps, RichText } from '@wordpress/block-editor';

const Save = ( props ) => {
    const { attributes } = props;
    const { content } = attributes;

    return (
        <div { ...useBlockProps.save() }>
            <RichText.Content tagName="p" value={ content } />
        </div>
    );
};

export default Save;
