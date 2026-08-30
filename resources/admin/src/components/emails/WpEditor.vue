<template>
    <div class="flc_editor">
        <div class="flc_editor_bar">
            <SmartCodes :data="codes" @insert="insert"/>
        </div>

        <textarea v-if="hasWpEditor" :id="editorId" class="flc_editor_area">{{ modelValue }}</textarea>
        <textarea
            v-else
            ref="plain"
            class="flc_editor_area flc_editor_plain"
            :value="modelValue"
            @input="$emit('update:modelValue', $event.target.value)"
            @click="rememberCursor"
            @keyup="rememberCursor"
        ></textarea>
    </div>
</template>

<script type="text/babel">
import SmartCodes from './SmartCodes.vue';

/**
 * The WordPress editor, borrowed for the email body.
 *
 * wp.editor.initialize() wants a textarea that is already in the document,
 * so this mounts one and hands it over. The instance is torn down on
 * unmount: TinyMCE keeps a global registry keyed by element id, and leaving
 * a dead one behind means the next visit to this page initialises onto a
 * node that no longer exists and silently renders nothing.
 *
 * Content is read back on `change`, `keyup` and `SetContent`. `change`
 * alone is what WordPress binds, and it only fires when the editor loses
 * focus - so a body typed and saved without clicking away saved empty.
 */
export default {
    name: 'WpEditor',
    components: {SmartCodes},
    emits: ['update:modelValue'],
    props: {
        modelValue: {
            type: String,
            default: ''
        },
        codes: {
            type: Array,
            default: () => []
        },
        height: {
            type: Number,
            default: 420
        }
    },
    data() {
        return {
            editorId: 'flc_email_editor_' + Math.random().toString(36).slice(2, 10),
            hasWpEditor: !!(window.wp && ((window.wp.editor && window.wp.editor.autop) || window.wp.oldEditor)),
            cursor: 0
        }
    },
    computed: {
        api() {
            return (window.wp && (window.wp.oldEditor || window.wp.editor)) || null;
        }
    },
    methods: {
        initEditor() {
            if (!this.hasWpEditor || !this.api) {
                return;
            }

            const self = this;

            this.api.remove(this.editorId);
            this.api.initialize(this.editorId, {
                mediaButtons: true,
                quicktags: true,
                tinymce: {
                    height: this.height,
                    toolbar1: 'formatselect,bold,italic,bullist,numlist,link,blockquote,alignleft,aligncenter,alignright,underline,forecolor,removeformat,undo,redo',
                    setup(editor) {
                        ['change', 'keyup', 'SetContent', 'Undo', 'Redo'].forEach((event) => {
                            editor.on(event, () => self.pull());
                        });
                    },
                    content_style: 'body{font-family:ui-sans-serif,system-ui,sans-serif;font-size:15px}'
                        + 'blockquote{padding:12px 18px;margin:16px 0;background:#f9fafb;border-left:4px solid #2563eb;font-style:italic}'
                }
            });

            // The Text tab is a plain textarea; TinyMCE never sees it.
            jQuery('#' + this.editorId).on('input change', () => this.pull());
        },
        /** Reads whatever the active tab holds and pushes it up. */
        pull() {
            if (!this.api) {
                return;
            }

            this.$emit('update:modelValue', this.api.getContent(this.editorId));
        },
        insert(code) {
            if (this.hasWpEditor && window.tinymce && window.tinymce.activeEditor && !window.tinymce.activeEditor.isHidden()) {
                window.tinymce.activeEditor.insertContent(code);
                this.pull();
                return;
            }

            // Text tab, or no rich editor at all: place it at the cursor in
            // whichever textarea is on screen.
            const el = this.$refs.plain || document.getElementById(this.editorId);

            if (!el) {
                this.$emit('update:modelValue', (this.modelValue || '') + code);
                return;
            }

            const value = el.value || '';
            const start = el.selectionStart === null ? value.length : el.selectionStart;
            const end = el.selectionEnd === null ? value.length : el.selectionEnd;

            el.value = value.slice(0, start) + code + value.slice(end);
            this.$emit('update:modelValue', el.value);

            this.$nextTick(() => {
                el.focus();
                el.setSelectionRange(start + code.length, start + code.length);
            });
        },
        rememberCursor(event) {
            this.cursor = event.target.selectionStart;
        }
    },
    mounted() {
        this.initEditor();
    },
    beforeUnmount() {
        if (this.hasWpEditor && this.api) {
            jQuery('#' + this.editorId).off('input change');
            this.api.remove(this.editorId);
        }
    }
}
</script>
