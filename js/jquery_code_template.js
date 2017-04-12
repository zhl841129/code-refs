/**
 * @file
 *
 * This file contains some common javascript shared across the site.
 */

(function($) {
    var _this = function() {
        return window.defaultMaster;
    };

    window.defaultMaster = {
        /**
         * Default function will be trigger when page loads.
         */
        init: function init() {
        },
    }

    // Trigger init function.
    $(document).on('ready', _this().init);

})(jQuery);