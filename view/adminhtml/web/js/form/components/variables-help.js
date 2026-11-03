/**
 * @package     Defox_SEOSuite
 * @author      Marcin Lisiecki
 * @copyright   Copyright (c) 2025 deFox
 * @license     GNU General Public License v3.0
 */

/**
 * "Available variables" help block: click a variable to copy it.
 * Replaces the inline <script> that used to live in the form XML content (CSP strict mode safe).
 */
define([
    'jquery',
    'Magento_Ui/js/form/components/html'
], function ($, Html) {
    'use strict';

    return Html.extend({
        /**
         * @returns {Object}
         */
        initialize: function () {
            this._super();

            $(document).on('click', '#defox-variables-display code', function () {
                var text = $(this).text(),
                    $temp = $('<textarea>'),
                    $notification;

                $('body').append($temp);
                $temp.val(text).select();
                document.execCommand('copy');
                $temp.remove();

                $notification = $('<div class="message message-success" style="position: fixed; top: 50px; ' +
                    'right: 20px; z-index: 9999; padding: 10px; background: #4CAF50; color: white; ' +
                    'border-radius: 4px;"></div>').text('Variable copied: ' + text);
                $('body').append($notification);

                setTimeout(function () {
                    $notification.fadeOut(function () {
                        $(this).remove();
                    });
                }, 3000);
            });

            return this;
        }
    });
});
