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
    $.fn.initDataTables = function(config, options, editorOptions, fileHandler) {
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
            var baseState;
            var childEditorInstances = [];

            function createChildEditor(name, editorOpts) {
                return (function() {
                    if(childEditorInstances[name] === undefined) {
                        childEditorInstances[name] = new $.fn.dataTable.Editor(editorOpts);
                    }
                    return childEditorInstances[name];
                });
            }

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
            function createImageRenderFunction(prefix) {
                return function ( value, type, row, meta ) {
                    value = prefix + value;
                    return '<img src="' + value + '" width="80" data-toggle="tooltip" data-html="true" data-placement="left" title="<img src=\'' + value + '\' style=\'max-width:400px\' />" />';
                }
            }
            for(var i = 0; i < initialConfig.options.columns.length; ++i) {
                if(initialConfig.options.columns[i].map) {
                    var map = initialConfig.options.columns[i].map;
                    initialConfig.options.columns[i].render = createMapRenderFunction(map);
                } else if(initialConfig.options.columns[i].renderedLength) {
                    var length = initialConfig.options.columns[i].renderedLength;
                    initialConfig.options.columns[i].render = createSubstrRenderFunction(length);
                } else if(initialConfig.options.columns[i].imageUrlPrefix) {
                    var prefix = initialConfig.options.columns[i].imageUrlPrefix;
                    initialConfig.options.columns[i].render = createImageRenderFunction(prefix);
                }
            }
            // Merge all options from different sources together and add the Ajax loader
            var dtOpts = $.extend({}, initialConfig.options, config.options, options, persistOptions, {
                ajax: function (request, drawCallback, settings) {
                    if (initialConfig) {
                        initialConfig.draw = request.draw;
                        drawCallback(initialConfig);
                        initialConfig = null;
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

            root.html(initialConfig.template);
            var dt = $('table', root).DataTable(dtOpts);
            var editor = null;
            var childEditors = null;
            if(initialConfig.editorOptions) {
                for(var i = 0; i < initialConfig.editorOptions.fields.length; ++i) {
                    if(initialConfig.editorOptions.fields[i].type
                        && (initialConfig.editorOptions.fields[i].type === 'upload'
                            || initialConfig.editorOptions.fields[i].type === 'uploadMany')) {
                        initialConfig.editorOptions.fields[i].display = function(id) {
                            return fileHandler(editor, id);
                        }
                    }
                }
                var editorOpts = $.extend({}, initialConfig.editorOptions, editorOptions);
                editorOpts['table'] = '#' + config.name;
                editorOpts['ajax'] = config.url;
                editor = new $.fn.dataTable.Editor(editorOpts);
            }
            if(initialConfig.childEditorOptions) {
                childEditors = [];
                $.each(initialConfig.childEditorOptions, function(name, options) {
                    for(var i = 0; i < options.fields.length; ++i) {
                        if(options.fields[i].type && (options.fields[i].type === 'upload'
                            || options.fields[i].type === 'uploadMany')) {
                            options.fields[i].display = function(id) {
                                return fileHandler(editor, id);
                            }
                        }
                    }
                    var editorOpts = $.extend({}, initialConfig.editorOptions, options);
                    editorOpts['table'] = '#' + config.name;
                    editorOpts['ajax'] = initialConfig.childEditorUrls[name];
                    childEditors[name] = createChildEditor(name, editorOpts);
                });
            }
            if (config.state !== 'none') {
                dt.on('draw.dt', function(e) {
                    var initialConfig = $.param(dt.state()).split('&');

                    // First draw establishes state, subsequent draws run diff on the first
                    if (!baseState) {
                        baseState = initialConfig;
                    } else {
                        var diff = initialConfig.filter(el => { return baseState.indexOf(el) === -1 && el.indexOf('time=') !== 0; });
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

            if(initialConfig.editorOptions) {
                let output = {
                    'dt': dt,
                    'editor': editor,
                    'childEditors': childEditors,
                    'editorButtons': initialConfig.editorButtons,
                    'initialOrder': initialConfig.options.order,
                    'reorderingEnabled': initialConfig.reorderingEnabled,
                    'groupingEnabled': initialConfig.groupingEnabled
                }
                if(initialConfig.reorderingEnabled) {
                    output = $.extend(output, {
                        'reorderingConstraintField': initialConfig.reorderingConstraintField
                    });
                }
                if(initialConfig.groupingEnabled) {
                    output = $.extend(output, {
                        'groupingColumn': initialConfig.groupingColumn,
                        'groupCreationField': initialConfig.groupCreationField,
                        'groupCreationIds': initialConfig.groupCreationIds,
                        'childRowColumns': initialConfig.childRowColumns,
                        'groupingConstraintField': initialConfig.groupingConstraintField
                    });
                }
                fulfill(output)
            }
            let output = {
                'dt': dt,
                'initialOrder': initialConfig.options.order,
                'reorderingEnabled': initialConfig.reorderingEnabled,
                'groupingEnabled': initialConfig.groupingEnabled
            }
            if(initialConfig.reorderingEnabled) {
                output = $.extend(output, {
                    'reorderingConstraintField': initialConfig.reorderingConstraintField
                });
            }
            if(initialConfig.groupingEnabled) {
                output = $.extend(output, {
                    'groupingColumn': initialConfig.groupingColumn,
                    'groupCreationField': initialConfig.groupCreationField,
                    'groupCreationIds': initialConfig.groupCreationIds,
                    'childRowColumns': initialConfig.childRowColumns,
                    'groupingConstraintField': initialConfig.groupingConstraintField
                });
            }
            fulfill(output);
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

            function createInitFunction(val) {
                return function ( editor ) {
                    if ( val ) {
                        editor.setContent( val );
                    }
                }
            }

            // Because tinyMCE uses an editable iframe, we need to destroy and
            // recreate it on every display of the input
            this
                .on( 'open.tinymceInit-'+conf._safeId, function () {
                    tinymce.init( $.extend( true, {
                        selector: '#'+conf._safeId,
                        height: '250',
                        language: 'de',
                        plugins: ['link']
                    }, conf.opts, {
                        init_instance_callback: createInitFunction(conf._initSetVal)
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