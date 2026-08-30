<template>
    <div class="flc_page">
        <PageHeader
            :heading="$t('Template design')"
            :description="$t('The frame every FluentComments email is rendered into, and the addresses they come from.')"
            :saving="saving"
            :dirty="dirty"
            @save="save()"
        >
            <template #actions>
                <el-button @click="$router.push({ name: 'emails' })">{{ $t('Back to emails') }}</el-button>
            </template>
        </PageHeader>

        <div class="flc_design" v-loading="loading">
            <div class="flc_design_controls">
                <section class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('Header') }}</h2>
                    </header>

                    <div class="flc_field flc_field_stacked">
                        <label>
                            <strong>{{ $t('Logo') }}</strong>
                            <span>{{ $t('Shown above the message. Left off if you leave this empty.') }}</span>
                        </label>
                        <div class="flc_logo_field">
                            <el-input v-model="settings.logo" placeholder="https://…"/>
                            <el-button @click="pickLogo()">{{ $t('Choose') }}</el-button>
                            <el-button v-if="settings.logo" @click="settings.logo = ''">{{ $t('Clear') }}</el-button>
                        </div>
                    </div>
                </section>

                <section class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('Colours') }}</h2>
                        <p>{{ $t('The preview beside this repaints as you drag.') }}</p>
                    </header>

                    <div v-for="field in colorFields" :key="field.key" class="flc_side_row">
                        <span>{{ field.label }}</span>
                        <el-color-picker v-model="settings[field.key]" size="small"/>
                    </div>

                    <p class="flc_panel_note">
                        <button type="button" class="flc_link_button" @click="resetColors()">
                            {{ $t('Back to the defaults') }}
                        </button>
                    </p>
                </section>

                <section class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('Footer') }}</h2>
                        <p>{{ $t('Sits under the message. Leave it empty for your site name and address.') }}</p>
                    </header>

                    <el-input v-model="settings.footer_text" type="textarea" :rows="3"
                              :placeholder="$t('You are getting this because you commented on our site.')"/>
                </section>

                <section class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('Sender') }}</h2>
                        <p>{{ $t('Leave both empty and WordPress decides, which is what an SMTP plugin expects. These apply to the WordPress notices too, once you have customised them.') }}</p>
                    </header>

                    <div class="flc_two_up">
                        <div class="flc_field">
                            <label><strong>{{ $t('From name') }}</strong></label>
                            <el-input v-model="settings.from_name" :placeholder="$t('Your site')"/>
                        </div>
                        <div class="flc_field">
                            <label><strong>{{ $t('From email') }}</strong></label>
                            <el-input v-model="settings.from_email" placeholder="hello@example.com"/>
                        </div>
                        <div class="flc_field">
                            <label><strong>{{ $t('Reply-To name') }}</strong></label>
                            <el-input v-model="settings.reply_to_name" :placeholder="$t('Optional')"/>
                        </div>
                        <div class="flc_field">
                            <label><strong>{{ $t('Reply-To email') }}</strong></label>
                            <el-input v-model="settings.reply_to_email" :placeholder="$t('Optional')"/>
                        </div>
                    </div>
                </section>
            </div>

            <div class="flc_design_preview">
                <EmailFrame :content="sample" :style-config="settings"/>
                <p class="flc_muted">
                    {{ $t('Shown against a real comment from your site where there is one.') }}
                </p>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">
import PageHeader from '../PageHeader.vue';
import EmailFrame from './EmailFrame.vue';

/**
 * The one screen that changes all five emails at once.
 *
 * The preview is a real server-rendered email, fetched once, with the
 * colours reapplied in the browser as they change - so dragging a picker
 * costs nothing and still shows the real markup rather than an
 * approximation of it.
 */
export default {
    name: 'TemplateDesign',
    components: {PageHeader, EmailFrame},
    data() {
        return {
            settings: {},
            defaults: {},
            sample: '',
            loading: false,
            saving: false,
            dirty: false,
            colorFields: [
                {key: 'body_bg', label: this.$t('Page behind the email')},
                {key: 'content_bg', label: this.$t('Message background')},
                {key: 'content_color', label: this.$t('Message text')},
                {key: 'accent_color', label: this.$t('Links and buttons')},
                {key: 'highlight_bg', label: this.$t('Quoted comment background')},
                {key: 'highlight_color', label: this.$t('Quoted comment text')},
                {key: 'footer_content_color', label: this.$t('Footer text')}
            ]
        }
    },
    methods: {
        fetchTemplate() {
            this.loading = true;

            this.$get('get-email-template')
                .then(response => {
                    this.settings = response.settings || {};
                    this.defaults = response.defaults || {};
                    this.sample = response.default_content || '';

                    this.$nextTick(() => {
                        this.dirty = false;
                    });
                })
                .catch(error => this.$handleError(error))
                .finally(() => {
                    this.loading = false;
                });
        },
        save() {
            this.saving = true;

            this.$post('save-email-template', {settings: this.settings})
                .then(response => {
                    this.$notify.success(response.message);

                    // Read back rather than trusting what we sent: an
                    // unparseable colour is replaced with its default on
                    // the way in, and the picker should show that.
                    if (response.settings) {
                        this.settings = response.settings;
                    }

                    this.$nextTick(() => {
                        this.dirty = false;
                    });
                })
                .catch(error => this.$handleError(error))
                .finally(() => {
                    this.saving = false;
                });
        },
        resetColors() {
            this.colorFields.forEach(field => {
                this.settings[field.key] = this.defaults[field.key];
            });
        },
        /**
         * The media library, which wp_enqueue_media() put on the page for
         * exactly this.
         */
        pickLogo() {
            if (!window.wp || !window.wp.media) {
                return;
            }

            if (!this.mediaFrame) {
                this.mediaFrame = window.wp.media({
                    title: this.$t('Choose a logo'),
                    button: {text: this.$t('Use this image')},
                    library: {type: 'image'},
                    multiple: false
                });

                this.mediaFrame.on('select', () => {
                    const image = this.mediaFrame.state().get('selection').first().toJSON();

                    this.settings.logo = image.url;
                });
            }

            this.mediaFrame.open();
        }
    },
    watch: {
        settings: {
            deep: true,
            handler() {
                this.dirty = true;
            }
        }
    },
    created() {
        // Not reactive: it is a Backbone view, and proxying one breaks it.
        this.mediaFrame = null;
    },
    mounted() {
        this.fetchTemplate();
    }
}
</script>
