define([
    'backbone'
], function(Backbone) {
    'use strict';

    /**
     * @export  oroform/js/multiple-entity/model
     * @class   oroform.MultipleEntity.Model
     * @extends Backbone.Model
     */
    const EntityModel = Backbone.Model.extend({
        defaults: {
            id: null,
            isDefault: false,
            sku: null,
            productName: null,
            manufacturingCode: null,
            status: null,
            categories: null,
            image: null,
            createdAt: null
        },

        /**
         * @inheritDoc
         */
        constructor: function EntityModel(attrs, options) {
            EntityModel.__super__.constructor.call(this, attrs, options);
        }
    });

    return EntityModel;
});
