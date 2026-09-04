/**
 * Section toggle for the category form's Display Settings.
 *
 * Exposes `effectiveChecked` = master switch AND this toggle. Dependent fields bind
 * their visibility to that instead of `checked`, which gives the AND that a single
 * <link name="visible"> cannot express.
 *
 * Why not nested fieldsets: Magento\Catalog\Model\Category\DataProvider::getFieldsMap()
 * collects grandchildren and prepareFieldsMeta() writes them back flat, so nesting
 * renders every field twice — once from this XML, once from the raw EAV attribute.
 *
 * Fails open: effectiveChecked starts true, so if this file fails to load the fields
 * stay visible (today's behaviour) rather than disappearing.
 */
define([
    'Magento_Ui/js/form/element/single-checkbox'
], function (Checkbox) {
    'use strict';

    return Checkbox.extend({
        defaults: {
            masterChecked: true,
            effectiveChecked: true,
            imports: {
                masterChecked: '${ $.parentName }.ahy_use_subcategory_layout:checked'
            },
            listens: {
                checked: 'updateEffectiveChecked',
                masterChecked: 'updateEffectiveChecked'
            }
        },

        initObservable: function () {
            this._super().observe(['masterChecked', 'effectiveChecked']);
            this.updateEffectiveChecked();

            return this;
        },

        updateEffectiveChecked: function () {
            this.effectiveChecked(Boolean(this.masterChecked()) && Boolean(this.checked()));
        }
    });
});
