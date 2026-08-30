<template>
    <div class="flc_page flc_page_narrow">
        <PageHeader
            :heading="email ? email.title : $t('Edit email')"
            :description="email ? email.description : ''"
            :saving="saving"
            :dirty="dirty"
            :disabled="!settings"
            @save="save()"
        >
            <template #actions>
                <el-button v-if="settings && settings.content_status === 'active'" @click="showPreview = true">
                    {{ $t('Preview') }}
                </el-button>
                <el-button @click="$router.push({ name: 'emails' })">{{ $t('Back to emails') }}</el-button>
            </template>
        </PageHeader>

        <div class="flc_stack" v-loading="loading">
            <el-skeleton v-if="!email || !settings" :animated="true" :rows="6"/>

            <template v-else>
                <section class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('This email') }}</h2>
                    </header>

                    <div class="flc_row">
                        <div class="flc_row_text">
                            <strong>{{ $t('Send it') }}</strong>
                            <span>{{ email.toggle_note || sendNote }}</span>
                        </div>
                        <el-switch v-model="settings.enabled" active-value="yes" inactive-value="no"/>
                    </div>

                    <div class="flc_row">
                        <div class="flc_row_text">
                            <strong>{{ $t('Wording') }}</strong>
                            <span>{{ wordingNote }}</span>
                        </div>
                        <el-radio-group v-model="settings.content_status">
                            <el-radio-button value="system" :label="defaultLabel"/>
                            <el-radio-button value="active" :label="$t('Your own')"/>
                        </el-radio-group>
                    </div>
                </section>

                <section v-if="settings.content_status === 'system'" class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('What gets sent') }}</h2>
                        <!-- translators: %s is a link reading "template design" -->
                        <p v-if="!isCore" v-html="$t('Rendered against the most recent comment on your site, through the %s every email shares.', templateLink)"></p>
                    </header>

                    <div class="flc_card_preview">
                        <!--
                            Only shown where we are the ones sending it. At this setting a core
                            email is built by WordPress, in plain text, from strings we never see -
                            so there is nothing of ours to render, and rendering our own default
                            here would be showing something that is not going out.
                        -->
                        <PreviewEmail v-if="!isCore" :email-id="email_id"/>

                        <template v-else>
                            <p class="flc_muted">
                                {{ $t('WordPress builds this one itself, as plain text, and FluentComments leaves it alone. There is nothing here to preview.') }}
                            </p>
                            <p>
                                <button type="button" class="flc_link_button"
                                        @click="settings.content_status = 'active'">
                                    {{ $t('Write your own instead') }}
                                </button>
                                {{ $t('- it starts from a FluentComments version you can preview and edit.') }}
                            </p>
                        </template>
                    </div>
                </section>

                <section v-if="settings.content_status === 'active'" class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('Content') }}</h2>
                        <p>
                            {{ $t('Anything in braces is filled in when the email is sent.') }}
                            <template v-if="email.owner === 'core'">
                                {{ $t('This one goes to several people at once, so there is no single recipient to greet.') }}
                            </template>
                        </p>
                    </header>

                    <div class="flc_field flc_field_stacked">
                        <label>
                            <strong>{{ $t('Subject') }}</strong>
                        </label>
                        <SmartCodeInput v-model="settings.email.subject" :codes="smartcodes" size="large"
                                        :placeholder="$t('Subject line')"/>
                    </div>

                    <div class="flc_field flc_field_stacked">
                        <label>
                            <strong>{{ $t('Body') }}</strong>
                            <span>
                                {{ $t('Reset it with') }}
                                <button type="button" class="flc_link_button" @click="useDefault()">
                                    {{ $t('start from the default') }}
                                </button>.
                            </span>
                        </label>
                        <WpEditor v-if="!reloadingEditor" v-model="settings.email.body" :codes="smartcodes"/>
                    </div>
                </section>
            </template>
        </div>

        <el-dialog v-model="showPreview" :title="$t('Preview')" width="820px" :close-on-click-modal="true">
            <PreviewEmail
                v-if="showPreview"
                :email-id="email_id"
                :email-data="{ subject: settings.email.subject, body: settings.email.body }"
            />
            <template #footer>
                <el-button type="primary" @click="showPreview = false">{{ $t('Close') }}</el-button>
            </template>
        </el-dialog>
    </div>
</template>

<script type="text/babel">
import PageHeader from '../PageHeader.vue';
import WpEditor from './WpEditor.vue';
import SmartCodeInput from './SmartCodeInput.vue';
import PreviewEmail from './PreviewEmail.vue';

/**
 * One email, as the two things it really is.
 *
 * "Send it" writes the switch this email already had - the same one the
 * list shows, and for the two core ones the same one Settings > Discussion
 * writes. "Wording" writes the plugin's own option. Keeping them apart is
 * what lets you write an email before switching it on, and what stops a
 * customised body disappearing behind an off switch.
 *
 * "The default" means something different on either side of the list: for
 * our own emails it is content we ship, for WordPress's two it is us
 * keeping our hands off. `defaultLabel` and `wordingNote` say that out
 * loud, once, rather than in a panel per choice.
 *
 * The draft is loaded whatever the state, so picking "Your own" starts
 * from what is being sent right now rather than an empty box, and
 * switching away and back does not lose what was typed.
 */
export default {
    name: 'EditEmail',
    components: {PageHeader, WpEditor, SmartCodeInput, PreviewEmail},
    props: {
        email_id: {
            type: String,
            required: true
        }
    },
    data() {
        return {
            email: null,
            settings: null,
            smartcodes: [],
            defaultContent: null,
            loading: false,
            saving: false,
            dirty: false,
            showPreview: false,
            reloadingEditor: false
        }
    },
    computed: {
        isCore() {
            return this.email && this.email.owner === 'core';
        },
        /**
         * A plain anchor rather than a <router-link>, because the sentence
         * around it is one translatable string and a component cannot be
         * spliced into the middle of one. The router is on hash history, so
         * this navigates exactly as the component would.
         */
        templateLink() {
            return '<a href="#/emails/template">' + this.$t('template design') + '</a>';
        },
        defaultLabel() {
            return this.isCore ? this.$t('WordPress default') : this.$t('FluentComments default');
        },
        sendNote() {
            return this.settings && this.settings.enabled === 'yes'
                ? this.$t('Off turns it off everywhere. This is the same switch the list shows.')
                : this.$t('Nobody is told when this happens.');
        },
        /**
         * Said once here rather than in a panel per choice. The two
         * defaults are not the same kind of thing: ours is content we ship,
         * WordPress's is us keeping our hands off.
         */
        wordingNote() {
            if (!this.settings) {
                return '';
            }

            if (this.settings.content_status === 'active') {
                return this.$t('Your subject and body below are sent instead of the default.');
            }

            return this.isCore
                ? this.$t('WordPress sends its own notice, untouched. Nothing here changes it.')
                : this.$t('The wording FluentComments ships with is sent.');
        }
    },
    methods: {
        fetchEmail() {
            this.loading = true;

            this.$get('get-email', {email_id: this.email_id})
                .then(response => {
                    this.email = response.email;
                    this.settings = response.settings;
                    this.smartcodes = response.smartcodes || [];
                    this.defaultContent = response.default_content;

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
            if (!this.settings) {
                return;
            }

            this.saving = true;

            this.$post('save-email', {email_id: this.email_id, settings: this.settings})
                .then(response => {
                    this.$notify.success(response.message);
                    this.$syncToggles(response);

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
        /**
         * TinyMCE owns its own copy of the body, so setting the model is
         * not enough - the editor has to be built again around the new
         * value.
         */
        useDefault() {
            if (!this.defaultContent) {
                return;
            }

            this.settings.email.subject = this.defaultContent.subject;
            this.settings.email.body = this.defaultContent.body;

            this.reloadingEditor = true;

            this.$nextTick(() => {
                this.reloadingEditor = false;
            });
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
    mounted() {
        this.fetchEmail();
    }
}
</script>
