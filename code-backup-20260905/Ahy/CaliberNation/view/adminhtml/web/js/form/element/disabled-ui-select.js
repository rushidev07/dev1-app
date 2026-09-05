/**
 * Generic ui-select extension that visually disables and blocks selection of
 * options marked with disabled:true. Used by Seller Participation and
 * Category Level Discount forms. Each form sets its own disabledMessage.
 */
define([
    'Magento_Ui/js/form/element/ui-select',
    'mage/translate',
    'jquery'
], function (UiSelect, $t, $) {
    'use strict';

    if (!$('#ahy-disabled-select-style').length) {
        $('<style id="ahy-disabled-select-style">').text(
            '.action-menu-item._unclickable {' +
            '  opacity: 0.45;' +
            '  cursor: not-allowed !important;' +
            '}' +
            '.action-menu-item._unclickable * { pointer-events: none; }'
        ).appendTo('head');
    }

    return UiSelect.extend({

        defaults: {
            disabledMessage: 'This option is already in use. Please edit the existing record instead.',
            disabledLabelSuffix: '— Already'
        },

        /**
         * Mark disabled options as label decorations so _unclickable CSS applies.
         */
        isLabelDecoration: function (data) {
            if (data && data.disabled) {
                return true;
            }
            return this._super(data);
        },

        /**
         * Block selection of disabled options and show an inline field error.
         */
        toggleOptionSelected: function (data, index, event) {
            if (data && data.disabled) {
                // Strip the "— Already …" suffix to show a clean name in the error.
                var name = (data.label || '').replace(/\s*—\s*Already\b.*$/, '');
                this.error($t(this.disabledMessage).replace('%1', name));
                return;
            }
            this.error(false);
            return this._super(data, index, event);
        }
    });
});
