/**
 * Native <input type="color"> field for the Configurations grid's "Colour"
 * column. Just an abstract form element with a dedicated template - no
 * custom behavior needed, the browser's own color picker UI handles input,
 * and the base Element's `value` observable + change tracking (feeding
 * "was_changed" on save) work the same as any other grid column field.
 */
define([
    'Magento_Ui/js/form/element/abstract'
], function (Abstract) {
    'use strict';

    return Abstract.extend({
        defaults: {
            elementTmpl: 'Ahy_PDPRevamp/form/element/color-picker',
            value: ''
        }
    });
});
