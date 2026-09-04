/**
 * Keeps the "Thumbnail Caption" field in the admin Image Detail modal in sync
 * with the hidden input that actually gets submitted.
 */
define(['jquery'], function ($) {
    'use strict';
    var FIELD = 'ahy_thumb_caption',
        PANEL_SELECTOR = '[data-role="image-thumb-caption"]',
        // The gallery container this template renders (see its wrapping
        // div.gallery); the submitted hidden inputs live inside it.
        GALLERY_SELECTOR = '.gallery';
    return function () {
        // Delegated from document: the panel is created and destroyed on the fly,
        // so binding directly to the input would only ever catch the first one.
        $(document).on('change keyup', PANEL_SELECTOR, function () {
            var $input = $(this),
                value = $input.val(),
                name = $input.attr('name'),
                $gallery,
                $dialog,
                imageData;
            if (!name) {
                return;
            }
            // The hidden twin lives in the gallery's own markup, which is inside
            // the product form and therefore actually submitted.
            $gallery = $(GALLERY_SELECTOR);
            if (!$gallery.length) {
                $gallery = $input.closest('form').length ? $input.closest('form') : $(document);
            }
            $gallery.find('input[type="hidden"][name="' + name + '"]').val(value);
            // Mirror into the modal's cached image data so a reopen shows the
            // current value; without this the panel repopulates from the stale
            // object and looks like the edit was lost.
            $dialog = $input.closest('[data-role="dialog"]');
            imageData = $dialog.length ? $dialog.data('imageData') : null;
            if (imageData) {
                imageData[FIELD] = value;
            }
        });
    };
});
