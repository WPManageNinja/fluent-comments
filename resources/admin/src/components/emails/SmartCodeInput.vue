<template>
    <el-input
        ref="input"
        :size="size"
        :placeholder="placeholder"
        :model-value="modelValue"
        @update:model-value="$emit('update:modelValue', $event)"
    >
        <template #append>
            <SmartCodes :data="codes" :size="size" @insert="insert"/>
        </template>
    </el-input>
</template>

<script type="text/babel">
import SmartCodes from './SmartCodes.vue';

/**
 * A single line input with the placeholder picker on the end of it.
 *
 * Inserts at the cursor rather than appending, because a subject line is
 * usually being written around the code - "Re: {{post.title}} - thanks" is
 * a normal thing to want and appending makes it a two step job.
 */
export default {
    name: 'SmartCodeInput',
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
        placeholder: String,
        size: {
            type: String,
            default: 'default'
        }
    },
    methods: {
        insert(code) {
            const el = this.$refs.input ? this.$refs.input.$el.querySelector('input') : null;
            const value = this.modelValue || '';

            // No element to ask, so there is no cursor to insert at.
            if (!el) {
                this.$emit('update:modelValue', value + code);
                return;
            }

            const start = el.selectionStart === null ? value.length : el.selectionStart;
            const end = el.selectionEnd === null ? value.length : el.selectionEnd;

            this.$emit('update:modelValue', value.slice(0, start) + code + value.slice(end));

            this.$nextTick(() => {
                el.focus();

                try {
                    el.setSelectionRange(start + code.length, start + code.length);
                } catch (e) {
                    // Some input types refuse a selection range. Losing the
                    // cursor position is not worth an error.
                }
            });
        }
    }
}
</script>
