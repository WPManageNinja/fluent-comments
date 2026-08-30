<template>
    <div class="flc_page flc_page_narrow">
        <PageHeader
            :heading="$t('Emails')"
            :description="$t('Every email FluentComments is responsible for: whether it is sent, and what it says.')"
            :show-save="false"
        >
            <template #actions>
                <el-button @click="$router.push({ name: 'email_template' })">{{ $t('Template design') }}</el-button>
            </template>
        </PageHeader>

        <div class="flc_stack" v-loading="loading">
            <section class="flc_card">
                <header class="flc_card_head">
                    <h2>{{ $t('To the people who comment') }}</h2>
                    <p>{{ $t('Sent by FluentComments, only for comments on the post types it runs on.') }}</p>
                </header>

                <ul class="flc_email_list">
                    <EmailRow
                        v-for="email in ourEmails"
                        :key="email.name"
                        :email="email"
                        :busy="busy === email.name"
                        @toggle="setEnabled(email, $event)"
                        @edit="edit(email)"
                    />
                </ul>
            </section>

            <section class="flc_card">
                <header class="flc_card_head">
                    <h2>{{ $t('To you') }} <span class="flc_chip">{{ $t('Core') }}</span></h2>
                    <p>{{ $t('WordPress sends these two itself, and these are its own switches. Leave the wording alone and they go out exactly as they do today; write your own and FluentComments rewrites them on the way out, on its post types only.') }}</p>
                </header>

                <ul class="flc_email_list">
                    <EmailRow
                        v-for="email in coreEmails"
                        :key="email.name"
                        :email="email"
                        :busy="busy === email.name"
                        @toggle="setEnabled(email, $event)"
                        @edit="edit(email)"
                    />
                </ul>
            </section>

            <!-- translators: 1: the "Core" chip, 2: a link reading "Settings > Discussion" -->
            <p class="flc_footnote" v-html="$t('The switches take effect as you set them, so there is nothing to save on this page. The two above marked %1$s are the same WordPress options as %2$s.', coreChip, discussionLink)"></p>
        </div>
    </div>
</template>

<script type="text/babel">
import PageHeader from '../PageHeader.vue';
import EmailRow from './EmailRow.vue';

/**
 * The list, and the only place an email is switched on or off.
 *
 * These five switches used to live on the Settings tab as well, which meant
 * two screens describing one value in two vocabularies - a switch called
 * "A comment landed on their post" over there, a row reading "Off" over
 * here, both writing the same option. The row is the setting now.
 *
 * Split by who receives it, which is the split that matters: one group is
 * what your readers see, the other only ever reaches you.
 */
export default {
    name: 'AllEmails',
    components: {PageHeader, EmailRow},
    data() {
        return {
            emails: [],
            loading: false,
            busy: ''
        }
    },
    computed: {
        coreChip() {
            return '<span class="flc_chip">' + this.$t('Core') + '</span>';
        },
        discussionLink() {
            return '<a href="' + this.appVars.discussion_url + '">' + this.$t('Settings &rsaquo; Discussion') + '</a>';
        },
        ourEmails() {
            return this.emails.filter(email => email.owner === 'plugin');
        },
        coreEmails() {
            return this.emails.filter(email => email.owner === 'core');
        }
    },
    methods: {
        edit(email) {
            this.$router.push({name: 'edit_email', params: {email_id: email.name}});
        },
        /**
         * Written straight away. There is no save button on this page, and
         * a switch that needs one is a switch people leave half set.
         */
        setEnabled(email, enabled) {
            this.busy = email.name;

            this.$post('toggle-email', {email_id: email.name, enabled: enabled ? 'yes' : 'no'})
                .then(response => {
                    // The status is composed server side, so it is read
                    // back rather than guessed: switching a customised
                    // email on returns it to 'active', not to 'system'.
                    email.status = response.status;
                    this.$syncToggles(response);
                    this.$notify.success(response.message);
                })
                .catch(error => {
                    this.$handleError(error);
                    this.fetchEmails();
                })
                .finally(() => {
                    this.busy = '';
                });
        },
        fetchEmails() {
            this.loading = true;

            this.$get('get-emails')
                .then(response => {
                    this.emails = response.emails || [];
                })
                .catch(error => this.$handleError(error))
                .finally(() => {
                    this.loading = false;
                });
        }
    },
    mounted() {
        this.fetchEmails();
    }
}
</script>
