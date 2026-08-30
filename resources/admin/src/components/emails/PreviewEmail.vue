<template>
    <el-skeleton v-if="loading" :animated="true" :rows="8"/>

    <template v-else-if="rendered">
        <p class="flc_preview_subject"><strong>{{ $t('Subject:') }}</strong> {{ rendered.subject }}</p>
        <EmailFrame :content="rendered.body"/>
    </template>

    <el-empty v-else :description="$t('This preview could not be built.')"/>
</template>

<script type="text/babel">
import EmailFrame from './EmailFrame.vue';

/**
 * Renders an email against the most recent real comment on the site - a
 * made up one only when there are none yet. Real text is the point: an
 * email that looks fine against "Lorem ipsum" often does not against a
 * four paragraph comment.
 *
 * With `emailData` it renders what is in the editor, unsaved. Without it,
 * the built-in default.
 */
export default {
    name: 'PreviewEmail',
    components: {EmailFrame},
    props: {
        emailId: String,
        /**
         * What is in the editor right now. Left off to preview the default
         * instead, which is what the server falls back to when it is
         * handed nothing.
         */
        emailData: {
            type: Object,
            default: null
        }
    },
    data() {
        return {
            rendered: null,
            loading: true
        }
    },
    methods: {
        fetchPreview() {
            this.loading = true;

            const payload = {email_id: this.emailId};

            if (this.emailData) {
                payload.email_data = this.emailData;
            }

            this.$post('preview-email', payload)
                .then(response => {
                    this.rendered = response.rendered_email;
                })
                .catch(error => this.$handleError(error))
                .finally(() => {
                    this.loading = false;
                });
        }
    },
    mounted() {
        this.fetchPreview();
    }
}
</script>
