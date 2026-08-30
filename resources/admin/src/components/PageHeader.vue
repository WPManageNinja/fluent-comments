<template>
    <header class="flc_page_head">
        <div class="flc_page_head_text">
            <h2>{{ heading }}</h2>
            <p v-if="description">{{ description }}</p>
        </div>

        <div class="flc_page_head_actions">
            <!--
                First, not wedged between the buttons: it is a status, and a
                status reads before the things you can do about it. On a
                narrow page this row wraps under the heading, which put it
                in the middle of a row of buttons.
            -->
            <span v-if="dirty" class="flc_unsaved">{{ $t('Unsaved changes') }}</span>
            <slot name="actions"/>
            <el-button v-if="showSave" type="primary" :loading="saving" :disabled="disabled" @click="$emit('save')">
                {{ saveText }}
            </el-button>
        </div>
    </header>
</template>

<script type="text/babel">
import {$t} from '../i18n';

/**
 * The bar at the top of every page: what you are looking at on the left,
 * what you can do about it on the right.
 *
 * The save button lives here rather than in the app bar because each page
 * saves its own thing. One global button would have to know which page it
 * was on, and would happily post an untouched page's state over the top of
 * whatever another tab had just written.
 */
export default {
    name: 'PageHeader',
    emits: ['save'],
    props: {
        heading: String,
        description: String,
        saving: Boolean,
        dirty: Boolean,
        disabled: Boolean,
        showSave: {
            type: Boolean,
            default: true
        },
        saveText: {
            // A prop default is evaluated before there is an instance to
            // reach $t through, hence the imported one.
            type: String,
            default: () => $t('Save changes')
        }
    }
}
</script>
