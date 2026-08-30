<template>
    <div class="flc_page">
        <PageHeader
            :heading="$t('Settings')"
            :description="$t('Where FluentComments runs, and the rules every comment is held to. Notification emails are on the Emails tab.')"
            :saving="saving"
            :dirty="dirty"
            @save="updateSettings()"
        />

        <div class="flc_body">
            <main class="flc_main">
                <section class="flc_card flc_card_feature">
                    <header class="flc_card_head">
                        <h2>{{ $t('Where FluentComments runs') }}</h2>
                        <p>{{ $t('These post types use the FluentComments form instead of the WordPress one.') }}</p>
                    </header>

                    <div class="flc_post_types">
                        <label
                            v-for="type in appVars.comments_post_types"
                            :key="type.name"
                            class="flc_post_type"
                            :class="{ flc_post_type_on: settings.post_types.includes(type.name) }"
                        >
                            <el-checkbox v-model="settings.post_types" :value="type.name" :label="type.name">
                                <span class="flc_post_type_title">{{ type.title }}</span>
                            </el-checkbox>
                            <span class="flc_post_type_slug">{{ type.name }}</span>
                        </label>
                    </div>

                    <p v-if="!settings.post_types.length" class="flc_inline_note">
                        {{ $t('No post types selected, so FluentComments is doing nothing right now.') }}
                    </p>

                    <div class="flc_row">
                        <div class="flc_row_text">
                            <strong>{{ $t('Only accept comments from FluentComments') }}</strong>
                            <!-- translators: %s is <code>wp-comments-post.php</code>, the WordPress comment endpoint -->
                            <span v-html="$t('Recommended. Anything posted to the default WordPress form is rejected on the post types above, which is what stops bots hitting %s. Users who can moderate comments are never blocked.', '<code>wp-comments-post.php</code>')"></span>
                        </div>
                        <el-switch v-model="settings.reject_native_comments" active-value="yes" inactive-value="no" />
                    </div>

                    <p v-if="showPlacementWarning" class="flc_inline_warn" v-html="placementWarning"></p>
                </section>

                <section class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('Blocked and held words') }} <span class="flc_chip">{{ $t('Core') }}</span></h2>
                        <!-- translators: 1: <code>press</code>, 2: <code>WordPress</code> - an example of a partial word match -->
                        <p v-html="$t('One word, name, URL, or IP per line. Matched anywhere in the comment, its author, URL, email, or IP, and inside longer words too, so %1$s also catches %2$s.', '<code>press</code>', '<code>WordPress</code>')"></p>
                    </header>

                    <div class="flc_two_up">
                        <div class="flc_field">
                            <label>
                                <strong>{{ $t('Hold for review') }}</strong>
                                <span>{{ $t('Waits in the moderation queue for you.') }}</span>
                            </label>
                            <el-input v-model="discussion.moderation_keys" type="textarea" :rows="6" :placeholder="$t('One per line')" />
                        </div>

                        <div class="flc_field">
                            <label>
                                <strong>{{ $t('Send straight to trash') }}</strong>
                                <span>{{ $t('Never reaches the queue. Keep this one narrow.') }}</span>
                            </label>
                            <el-input v-model="discussion.disallowed_keys" type="textarea" :rows="6" :placeholder="$t('One per line')" />
                        </div>
                    </div>
                </section>

                <section class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('When to hold a comment') }} <span class="flc_chip">{{ $t('Core') }}</span></h2>
                        <p>{{ $t('WordPress applies these to every comment, ours included.') }}</p>
                    </header>

                    <div class="flc_row">
                        <div class="flc_row_text">
                            <strong>{{ $t('Hold every comment for review') }}</strong>
                            <span>{{ $t('Nothing appears until you approve it.') }}</span>
                        </div>
                        <el-switch v-model="discussion.comment_moderation" active-value="yes" inactive-value="no" />
                    </div>

                    <div class="flc_row">
                        <div class="flc_row_text">
                            <strong>{{ $t('Approve people who have been approved before') }}</strong>
                            <span>{{ $t('Their first comment waits, the rest go straight through.') }}</span>
                        </div>
                        <el-switch v-model="discussion.comment_previously_approved" active-value="yes" inactive-value="no" />
                    </div>

                    <div class="flc_row">
                        <div class="flc_row_text">
                            <strong>{{ $t('Hold comments with this many links') }}</strong>
                            <span>{{ $t('Link count is the single best spam signal. 2 is a sensible default.') }}</span>
                        </div>
                        <el-input-number v-model="discussion.comment_max_links" :min="0" :max="100" size="small" />
                    </div>
                </section>
            </main>

            <aside class="flc_sidebar">
                <section v-if="showPlacementPanel" class="flc_panel flc_panel_notice" :class="{ flc_panel_bad: showPlacementWarning }">
                    <div class="flc_panel_head">
                        <h2>{{ $t('Placement needed') }}</h2>
                        <button type="button" class="flc_link_button" :disabled="scanning" @click="scanTemplates()">
                            {{ scanning ? $t('Checking…') : $t('Recheck') }}
                        </button>
                    </div>

                    <!-- translators: 1: <strong>FluentComments</strong>, 2: <code>[fluent_comments]</code>, the shortcode -->
                    <p class="flc_panel_intro" v-html="$t('Block theme, so nothing is placed for you. These post types need the %1$s block or %2$s in the template they render through.', '<strong>FluentComments</strong>', '<code>[fluent_comments]</code>')"></p>

                    <ul class="flc_setup_list">
                        <li v-for="row in unplaced" :key="row.post_type" class="flc_setup_row">
                            <div class="flc_setup_row_head">
                                <span class="flc_dot" :class="'flc_dot_' + statusOf(row)"></span>
                                <strong>{{ row.label }}</strong>
                            </div>
                            <div>{{ statusLabel(row) }}</div>
                            <div v-if="row.template" class="flc_muted">
                                {{ $t('Template') }} <code>{{ row.template }}</code>
                            </div>
                            <a v-if="row.edit_url" :href="row.edit_url" target="_blank" rel="noopener">
                                {{ $t('Open in Site Editor') }}
                            </a>
                        </li>
                    </ul>

                    <p class="flc_panel_note">
                        {{ $t('Reads the template and everything it pulls in. A shortcode inside a single post\'s own content will not show up here.') }}
                    </p>
                </section>

                <section class="flc_panel">
                    <div class="flc_panel_head">
                        <h2>{{ $t('Who can comment') }} <span class="flc_chip">{{ $t('Core') }}</span></h2>
                    </div>

                    <div class="flc_side_row">
                        <span>{{ $t('Logged in users only') }}</span>
                        <el-switch v-model="discussion.comment_registration" active-value="yes" inactive-value="no" size="small" />
                    </div>

                    <div class="flc_side_row">
                        <span>{{ $t('Close comments on old posts') }}</span>
                        <el-switch v-model="discussion.close_comments_for_old_posts" active-value="yes" inactive-value="no" size="small" />
                    </div>

                    <div v-if="discussion.close_comments_for_old_posts === 'yes'" class="flc_side_row">
                        <span>{{ $t('Days before closing') }}</span>
                        <el-input-number v-model="discussion.close_comments_days_old" :min="0" :max="3650" size="small" controls-position="right" />
                    </div>

                    <div class="flc_side_row">
                        <span>{{ $t('Reply nesting depth') }}</span>
                        <el-input-number v-model="discussion.thread_comments_depth" :min="2" :max="10" size="small" controls-position="right" />
                    </div>
                </section>

                <!-- translators: 1: the "Core" chip, 2: a link reading "Settings > Discussion" -->
                <p class="flc_footnote" v-html="$t('%1$s settings are WordPress options. WordPress enforces them on every comment, and changing them here is the same as changing them under %2$s.', coreChip, discussionLink)"></p>
            </aside>
        </div>
    </div>
</template>

<script type="text/babel">

import PageHeader from './PageHeader.vue';

export default {
    name: 'SettingsPage',
    components: {PageHeader},
    data() {
        const settings = Object.assign({}, this.appVars.settings || {});

        if (!Array.isArray(settings.post_types)) {
            settings.post_types = [];
        }

        return {
            settings,
            discussion: Object.assign({}, this.appVars.discussion || {}),
            saving: false,
            scanning: false,
            scan: [],
            dirty: false
        }
    },
    computed: {
        isBlockTheme() {
            return this.appVars.using_block_theme === 'yes';
        },
        /**
         * Post types whose template has neither the block nor the shortcode.
         * Empty until the first scan lands, so this never flashes a warning
         * at somebody who has done nothing wrong.
         */
        unplaced() {
            return this.scan.filter(row => !row.has_block && !row.has_shortcode);
        },
        unplacedLabels() {
            return this.unplaced.map(row => row.label).join(', ');
        },
        /**
         * Two whole sentences rather than a spliced "has"/"have", which is
         * a plural rule English happens to make cheap and most languages
         * do not.
         */
        placementWarning() {
            /* translators: %s is a comma separated list of post type names */
            const singular = this.$t('<strong>%s</strong> has neither the FluentComments block nor the shortcode in the template. With this on, those posts cannot take comments at all.', this.unplacedLabels);
            /* translators: %s is a comma separated list of post type names */
            const plural = this.$t('<strong>%s</strong> have neither the FluentComments block nor the shortcode in the template. With this on, those posts cannot take comments at all.', this.unplacedLabels);

            return this.unplaced.length > 1 ? plural : singular;
        },
        coreChip() {
            return '<span class="flc_chip">' + this.$t('Core') + '</span>';
        },
        discussionLink() {
            return '<a href="' + this.appVars.discussion_url + '">' + this.$t('Settings &rsaquo; Discussion') + '</a>';
        },
        showPlacementWarning() {
            return this.isBlockTheme
                && this.settings.reject_native_comments === 'yes'
                && this.unplaced.length > 0;
        },
        /**
         * Only when there is something to do about it. Everything placed,
         * or a classic theme, and the panel is noise.
         */
        showPlacementPanel() {
            return this.isBlockTheme && this.unplaced.length > 0;
        }
    },
    methods: {
        statusOf(row) {
            if (row.has_block || row.has_shortcode) {
                return 'ok';
            }

            return row.has_core_comments ? 'bad' : 'warn';
        },
        statusLabel(row) {
            if (row.has_block) {
                return this.$t('FluentComments block found');
            }

            if (row.has_shortcode) {
                return this.$t('[fluent_comments] shortcode found');
            }

            if (!row.template) {
                return this.$t('No matching template');
            }

            return row.has_core_comments
                ? this.$t('Still using the default Comments block')
                : this.$t('Nothing found yet');
        },
        updateSettings() {
            this.saving = true;
            this.$post('save-settings', {settings: this.settings, discussion: this.discussion})
                .then(response => {
                    this.$notify.success(response.message);

                    // Read core's options back rather than trusting what we
                    // sent: sanitize_option() trims and dedupes the word
                    // lists, so what is stored is not always what was typed.
                    if (response.discussion) {
                        this.discussion = Object.assign({}, response.discussion);
                    }

                    // And fold the result back into the page-load snapshot.
                    // There is no keep-alive on the router view, so leaving
                    // for Emails and coming back rebuilds this page from
                    // window.fluentCommentsVars - which, without this, still
                    // holds what the page was rendered with. The switch the
                    // owner just turned off comes back on, and the next save
                    // writes it over the server's value. $syncToggles solves
                    // the same problem in the Emails -> Settings direction.
                    this.$syncSettings(this.settings, this.discussion);

                    // The deep watchers fire on the assignment above, so the
                    // flag has to be cleared after they have run.
                    this.$nextTick(() => {
                        this.dirty = false;
                    });

                    // The post type list is an input to the scan.
                    this.scanTemplates();
                })
                .catch(error => {
                    this.$handleError(error);
                })
                .finally(() => {
                    this.saving = false;
                });
        },
        scanTemplates() {
            if (!this.isBlockTheme) {
                return;
            }

            this.scanning = true;
            this.$get('scan-templates')
                .then(response => {
                    this.scan = response.templates || [];
                })
                .catch(error => {
                    this.$handleError(error);
                })
                .finally(() => {
                    this.scanning = false;
                });
        }
    },
    watch: {
        settings: {
            deep: true,
            handler() {
                this.dirty = true;
            }
        },
        discussion: {
            deep: true,
            handler() {
                this.dirty = true;
            }
        }
    },
    mounted() {
        this.scanTemplates();
    }
}
</script>
