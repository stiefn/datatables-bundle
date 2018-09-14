/**
 * Symfony DataTables Bundle
 * (c) Omines Internetbureau B.V. - https://omines.nl/
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 *
 * @author Niels Keurentjes <niels.keurentjes@omines.com>
 */

(function($) {
    /**
     * Initializes the datatable dynamically.
     */
    $.fn.initDataTables = function(config, options, editorOptions) {
        var root = this,
            state = '';
        config = $.extend({}, $.fn.initDataTables.defaults, config);

        // Load page state if needed
        switch (config.state) {
            case 'fragment':
                state = window.location.hash;
                break;
            case 'query':
                state = window.location.search;
                break;
        }
        state = (state.length > 1 ? deparam(state.substr(1)) : {});
        var persistOptions = config.state === 'none' ? {} : {
            stateSave: true,
            stateLoadCallback: function(s, cb) {
                // Only need stateSave to expose state() function as loading lazily is not possible otherwise
                return null;
            }
        };

        return new Promise((fulfill, reject) => {
            // Perform initial load
            $.ajax(config.url, {
                method: config.method,
                data: {
                    _dt: config.name,
                    _init: true
                }
            }).done(function(data) {
                var baseState;

                function createMapRenderFunction(map) {
                    return function ( value, type, row, meta ) {
                        if(map[value]) {
                            return map[value];
                        }
                        return '';
                    }
                }
                function createSubstrRenderFunction(length) {
                    return function ( value, type, row, meta ) {
                        if(value.length > length) {
                            return value.substring(0, length) + '...';
                        }
                        return value;
                    }
                }
                for(var i = 0; i < data.options.columns.length; ++i) {
                    if(data.options.columns[i].render) {
                        if(Array.isArray(data.options.columns[i].render)) {
                            var map = data.options.columns[i].render;
                            data.options.columns[i].render = createMapRenderFunction(map);
                        } else if(Number.isInteger(data.options.columns[i].render)) {
                            var length = data.options.columns[i].render;
                            console.log(length);
                            data.options.columns[i].render = createSubstrRenderFunction(length);
                        }
                    }
                }
                // Merge all options from different sources together and add the Ajax loader
                var dtOpts = $.extend({}, data.options, config.options, options, persistOptions, {
                    ajax: function (request, drawCallback, settings) {
                        if (data) {
                            data.draw = request.draw;
                            drawCallback(data);
                            data = null;
                            var merged = $.extend(true, {}, dt.state(), state);
                            if (Object.keys(merged).length) {
                                dt
                                    .order(merged.order)
                                    .search(merged.search.search)
                                    .page.len(merged.length)
                                    .page(merged.start / merged.length)
                                    .draw(false);
                            }
                        } else {
                            request._dt = config.name;
                            $.ajax(config.url, {
                                method: config.method,
                                data: request
                            }).done(function(data) {
                                drawCallback(data);
                            })
                        }
                    }
                });

                root.html(data.template);
                var dt = $('table', root).DataTable(dtOpts);
                var editor = null;
                if(data.editorOptions) {
                    var editorOpts = $.extend({}, data.editorOptions, editorOptions);
                    editorOpts['table'] = '#' + config.name;
                    editorOpts['ajax'] = config.url;
                    editor = new $.fn.dataTable.Editor(editorOpts);
                }
                if (config.state !== 'none') {
                    dt.on('draw.dt', function(e) {
                        var data = $.param(dt.state()).split('&');

                        // First draw establishes state, subsequent draws run diff on the first
                        if (!baseState) {
                            baseState = data;
                        } else {
                            var diff = data.filter(el => { return baseState.indexOf(el) === -1 && el.indexOf('time=') !== 0; });
                            switch (config.state) {
                                case 'fragment':
                                    history.replaceState(null, null, window.location.origin + window.location.pathname + window.location.search
                                        + '#' + decodeURIComponent(diff.join('&')));
                                    break;
                                case 'query':
                                    history.replaceState(null, null, window.location.origin + window.location.pathname
                                        + '?' + decodeURIComponent(diff.join('&') + window.location.hash));
                                    break;
                            }
                        }
                    })
                }

                if(data.editorOptions) {
                    fulfill({
                        'dt': dt,
                        'editor': editor
                    })
                }
                fulfill({
                    'dt': dt
                });
            }).fail(function(xhr, cause, msg) {
                console.error('DataTables request failed: ' + msg);
                reject(cause);
            });
        });
    };

    /**
     * Provide global component defaults.
     */
    $.fn.initDataTables.defaults = {
        method: 'POST',
        state: 'fragment',
        url: window.location.origin + window.location.pathname
    };

    /**
     * Convert a querystring to a proper array - reverses $.param
     */
    function deparam(params, coerce) {
        var obj = {},
            coerce_types = {'true': !0, 'false': !1, 'null': null};
        $.each(params.replace(/\+/g, ' ').split('&'), function (j, v) {
            var param = v.split('='),
                key = decodeURIComponent(param[0]),
                val,
                cur = obj,
                i = 0,
                keys = key.split(']['),
                keys_last = keys.length - 1;

            if (/\[/.test(keys[0]) && /\]$/.test(keys[keys_last])) {
                keys[keys_last] = keys[keys_last].replace(/\]$/, '');
                keys = keys.shift().split('[').concat(keys);
                keys_last = keys.length - 1;
            } else {
                keys_last = 0;
            }

            if (param.length === 2) {
                val = decodeURIComponent(param[1]);

                if (coerce) {
                    val = val && !isNaN(val) ? +val              // number
                        : val === 'undefined' ? undefined         // undefined
                            : coerce_types[val] !== undefined ? coerce_types[val] // true, false, null
                                : val;                                                // string
                }

                if (keys_last) {
                    for (; i <= keys_last; i++) {
                        key = keys[i] === '' ? cur.length : keys[i];
                        cur = cur[key] = i < keys_last
                            ? cur[key] || (keys[i + 1] && isNaN(keys[i + 1]) ? {} : [])
                            : val;
                    }

                } else {
                    if ($.isArray(obj[key])) {
                        obj[key].push(val);
                    } else if (obj[key] !== undefined) {
                        obj[key] = [obj[key], val];
                    } else {
                        obj[key] = val;
                    }
                }

            } else if (key) {
                obj[key] = coerce
                    ? undefined
                    : '';
            }
        });

        return obj;
    }
}(jQuery));

(function( factory ){
    if ( typeof define === 'function' && define.amd ) {
        // AMD
        define( ['jquery', 'datatables', 'datatables-editor'], factory );
    }
    else if ( typeof exports === 'object' ) {
        // Node / CommonJS
        module.exports = function ($, dt) {
            if ( ! $ ) { $ = require('jquery'); }
            factory( $, dt || $.fn.dataTable || require('datatables') );
        };
    }
    else if ( jQuery ) {
        // Browser standard
        factory( jQuery, jQuery.fn.dataTable );
    }
}(function( $, DataTable ) {
    'use strict';


    if ( ! DataTable.ext.editorFields ) {
        DataTable.ext.editorFields = {};
    }

    var _fieldTypes = DataTable.Editor ?
        DataTable.Editor.fieldTypes :
        DataTable.ext.editorFields;


    _fieldTypes.tinymce = {
        create: function ( conf ) {
            var that = this;
            conf._safeId = DataTable.Editor.safeId( conf.id );

            conf._input = $('<div><textarea id="'+conf._safeId+'""></textarea></div>');

            // Because tinyMCE uses an editable iframe, we need to destroy and
            // recreate it on every display of the input
            this
                .on( 'open.tinymceInit-'+conf._safeId, function () {
                    tinymce.init( $.extend( true, {
                        selector: '#'+conf._safeId,
                        height: '250',
                        language: 'de'
                    }, conf.opts, {
                        init_instance_callback: function ( editor ) {
                            if ( conf._initSetVal ) {
                                editor.setContent( conf._initSetVal );
                                conf._initSetVal = null;
                            }
                        }
                    } ) );

                    var editor = tinymce.get( conf._safeId );

                    if ( editor && conf._initSetVal ) {
                        editor.setContent( conf._initSetVal );
                        conf._initSetVal = null;
                    }
                } )
                .on( 'close.tinymceInit-'+conf._safeId, function () {
                    var editor = tinymce.get( conf._safeId );


                    if ( editor ) {
                        editor.destroy();
                    }

                    conf._initSetVal = null;
                    conf._input.find('textarea').val('');
                } );

            return conf._input;
        },

        get: function ( conf ) {
            var editor = tinymce.get( conf._safeId );
            if ( ! editor ) {
                return conf._initSetVal;
            }

            return editor.getContent();
        },

        set: function ( conf, val ) {
            var editor = tinymce.get( conf._safeId );

            // If not ready, then store the value to use when the `open` event fires
            conf._initSetVal = val;
            if ( ! editor ) {
                return;
            }
            editor.setContent( val );
        },

        enable: function ( conf ) {}, // not supported in TinyMCE

        disable: function ( conf ) {}, // not supported in TinyMCE

        destroy: function (conf) {
            var id = DataTable.Editor.safeId(conf.id);

            this.off( 'open.tinymceInit-'+id );
            this.off( 'close.tinymceInit-'+id );
        },

        // Get the TinyMCE instance - note that this is only available after the
        // first onOpen event occurs
        tinymce: function ( conf ) {
            return tinymce.get( conf._safeId );
        }
    };


}));