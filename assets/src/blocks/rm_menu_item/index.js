import { registerBlockType } from '@wordpress/blocks';
import { __ } from '@wordpress/i18n';
import edit from './edit';
import save from './save';
import './style.scss';

registerBlockType( 'wp-racemanager/rm_menu_item', {
    title: __( 'RaceManager Menu Item', 'wp-racemanager' ),
    icon: 'menu',
    category: 'widgets',
    edit,
    save,
} );
