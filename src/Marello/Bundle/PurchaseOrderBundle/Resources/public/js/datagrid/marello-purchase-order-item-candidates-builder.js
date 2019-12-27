define(function(require) {
    'use strict';

    const mediator = require('oroui/js/mediator');

    return {
        init: function(deferred, options) {

            options.gridPromise.done(function(grid) {

                var gridName = grid.name;

                mediator.trigger('datagrid:doRefresh:' + gridName);

                deferred.resolve();
            });
        }
    };
});