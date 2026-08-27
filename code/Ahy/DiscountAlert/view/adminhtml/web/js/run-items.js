/**
 * Progressive enhancement for the discount alert review grid.
 *
 * The page is fully functional without this file: both disable buttons are real submit
 * buttons and the pager has a Go button. This adds the confirmation dialogs, the live
 * selection count, select-all, and instant pager submits.
 *
 * Logs each step under the [DiscountAlert] prefix so a browser console shows whether the
 * component loaded and what a click actually did.
 */
define([
    'jquery',
    'Magento_Ui/js/modal/confirm',
    'Magento_Ui/js/modal/alert'
], function ($, confirm, alert) {
    'use strict';

    var LOG = '[DiscountAlert] ';

    function log(message, payload) {
        if (window.console && window.console.log) {
            if (payload === undefined) {
                window.console.log(LOG + message);
            } else {
                window.console.log(LOG + message, payload);
            }
        }
    }

    log('run-items.js loaded');

    return function (config, element) {
        var $form = $(element),
            settings = $.extend({
                confirmTitle: 'Disable products',
                confirmSingle: 'Disable this product?',
                confirmMultiple: 'Disable the selected products?',
                emptyMessage: 'Select at least one product first.',
                selectedText: 'selected',
                revertTitle: 'Revert to MSRP',
                revertSingle: 'Show MSRP for this product?',
                revertMultiple: 'Show MSRP for the selected products?',
                restoreTitle: 'Restore discounted price',
                restoreSingle: 'Put the discounted price back on this product?',
                restoreMultiple: 'Put the discounted prices back on the selected products?'
            }, config || {}),
            rowSelector = 'input[data-role="row-checkbox"]',
            submitting = false;

        log('component initialised', {
            form: $form.attr('id') || '(no id)',
            action: $form.attr('action'),
            rows: $form.find(rowSelector).length
        });

        if (!$form.length) {
            log('ERROR: no form element, enhancement skipped');

            return;
        }

        function rows() {
            return $form.find(rowSelector);
        }

        function selected() {
            return rows().filter(':checked');
        }

        function updateCount() {
            var total = rows().length,
                count = selected().length;

            $form.find('[data-role="select-all"]').prop('checked', total > 0 && count === total);
            $form.find('[data-role="selection-count"]')
                .text(count ? count + ' ' + settings.selectedText : '');
        }

        /**
         * Re-submit the clicked button natively, so its name and value reach the server
         * exactly as an unenhanced click would have sent them.
         */
        function send($button) {
            var name = $button.attr('name'),
                value = $button.attr('value'),
                formAction = $button.attr('formaction');

            submitting = true;

            /*
             * form.submit() ignores the clicked button's formaction, so it has to be
             * copied onto the form by hand. Without this every enhanced click would post
             * to the form's own action - the disable endpoint - and a Show MSRP click
             * would silently disable products instead of repricing them. The unenhanced
             * path is unaffected: a real click honours formaction natively.
             */
            if (formAction) {
                $form.attr('action', formAction);
                log('routing submit to ' + formAction);
            }

            if (name) {
                $('<input>', {type: 'hidden', name: name, value: value}).appendTo($form);
                log('submitting with ' + name + '=' + value);
            } else {
                log('submitting selection', selected().length);
            }

            $form.get(0).submit();
        }

        function ask(title, message, $button) {
            confirm({
                title: title,
                content: message,
                actions: {
                    confirm: function () {
                        send($button);
                    },
                    cancel: function () {
                        log('cancelled by user');
                    }
                }
            });
        }

        /**
         * Wires one row button: confirm, then submit that button natively.
         */
        function onRowAction(role, title, message) {
            $form.on('click', '[data-role="' + role + '"]', function (event) {
                var $button = $(this);

                log(role + ' clicked', $button.attr('value'));

                if (submitting) {
                    return true;
                }

                event.preventDefault();
                ask(title, message, $button);

                return false;
            });
        }

        /**
         * Wires one mass action: refuse an empty selection, confirm, then submit.
         */
        function onMassAction(role, title, message) {
            $form.on('click', '[data-role="' + role + '"]', function (event) {
                var $button = $(this),
                    count = selected().length;

                log(role + ' clicked', count);

                if (submitting) {
                    return true;
                }

                event.preventDefault();

                if (!count) {
                    alert({content: settings.emptyMessage});

                    return false;
                }

                ask(title, message, $button);

                return false;
            });
        }

        $form.on('change', '[data-role="select-all"]', function () {
            var checked = $(this).prop('checked');

            rows().prop('checked', checked);
            log('select-all toggled', checked);
            updateCount();
        });

        $form.on('change', rowSelector, updateCount);

        onRowAction('disable-single', settings.confirmTitle, settings.confirmSingle);
        onMassAction('disable-selected', settings.confirmTitle, settings.confirmMultiple);

        onRowAction('revert-single', settings.revertTitle, settings.revertSingle);
        onMassAction('revert-selected', settings.revertTitle, settings.revertMultiple);

        onRowAction('restore-single', settings.restoreTitle, settings.restoreSingle);
        onMassAction('restore-selected', settings.restoreTitle, settings.restoreMultiple);

        // Pager: submit as soon as the page size changes, and hide the Go button now that
        // it is no longer needed.
        $('[data-role="page-size"]').each(function () {
            var select = this,
                pagerForm = select.form;

            if (!pagerForm) {
                log('WARNING: page size control has no associated form');

                return;
            }

            $('[data-role="pager-go"]').addClass('_js-hidden');
            $(select).on('change', function () {
                log('page size changed', select.value);
                pagerForm.submit();
            });
        });

        updateCount();
    };
});
