<template>
    <div class="flc_page">
        <PageHeader :heading="$t('About FluentComments')" :show-save="false"/>

        <div class="flc_body">
            <main class="flc_main">
                <section class="flc_card flc_card_feature">
                    <header class="flc_card_head">
                        <h2>FluentComments {{ appVars.version }}</h2>
                        <p>{{ $t('Comments that post without reloading the page, and a layered spam defense that holds what looks wrong instead of throwing it away. Built by the WPManageNinja team, the people behind FluentCRM, Fluent Forms and Fluent Support.') }}</p>
                    </header>

                    <div class="flc_about_actions">
                        <el-button type="primary" tag="a" :href="appVars.links.docs" target="_blank" rel="noopener">
                            {{ $t('Documentation') }}
                        </el-button>
                        <el-button tag="a" :href="appVars.links.support" target="_blank" rel="noopener">
                            {{ $t('Get support') }}
                        </el-button>
                        <el-button tag="a" :href="appVars.links.rate" target="_blank" rel="noopener">
                            ★ {{ $t('Leave a review') }}
                        </el-button>
                        <el-button tag="a" :href="appVars.links.github" target="_blank" rel="noopener">
                            {{ $t('Source on GitHub') }}
                        </el-button>
                    </div>
                </section>

                <section class="flc_card">
                    <header class="flc_card_head">
                        <h2>{{ $t('More from the Fluent family') }}</h2>
                        <p>{{ $t('Free plugins from the same team, all on wordpress.org.') }}</p>
                    </header>

                    <ul class="flc_plugin_list">
                        <li v-for="plugin in plugins" :key="plugin.slug">
                            <a :href="'https://wordpress.org/plugins/' + plugin.slug + '/'" target="_blank"
                               rel="noopener">
                                <strong>{{ plugin.name }}</strong>
                                <span>{{ plugin.description }}</span>
                            </a>
                        </li>
                    </ul>
                </section>
            </main>

            <aside class="flc_sidebar">
                <section class="flc_panel">
                    <div class="flc_panel_head">
                        <h2>{{ $t('Enjoying it?') }}</h2>
                    </div>
                    <p class="flc_panel_intro">
                        {{ $t('A review on wordpress.org is the single most useful thing you can do for a free plugin. It takes a minute and it is what decides whether the next person finds it.') }}
                    </p>
                    <p>
                        <a :href="appVars.links.rate" target="_blank" rel="noopener">{{ $t('Leave a review') }} &rarr;</a>
                    </p>
                </section>

                <section class="flc_panel">
                    <div class="flc_panel_head">
                        <h2>{{ $t('Something wrong?') }}</h2>
                    </div>
                    <p class="flc_panel_intro">
                        {{ $t('Support questions go to the wordpress.org forum. Bugs and pull requests go to GitHub, where they get read faster.') }}
                    </p>
                </section>

                <p class="flc_footnote">
                    {{ $t('FluentComments is free software under the GPL, version 2 or later.') }}
                </p>
            </aside>
        </div>
    </div>
</template>

<script type="text/babel">
import PageHeader from './PageHeader.vue';

export default {
    name: 'About',
    components: {PageHeader},
    data() {
        return {
            // Product names are not translated; the line under each one is.
            plugins: [
                {
                    slug: 'fluent-crm',
                    name: 'FluentCRM',
                    description: this.$t('Email marketing and contact management, self hosted.')
                },
                {
                    slug: 'fluentform',
                    name: 'Fluent Forms',
                    description: this.$t('Forms that stay fast on a page full of them.')
                },
                {
                    slug: 'fluent-support',
                    name: 'Fluent Support',
                    description: this.$t('A helpdesk that lives in your own admin.')
                },
                {
                    slug: 'fluent-smtp',
                    name: 'FluentSMTP',
                    description: this.$t('Make wp_mail() actually deliver.')
                },
                {
                    slug: 'fluent-security',
                    name: 'FluentAuth',
                    description: this.$t('Login security, two-factor and social login.')
                },
                {
                    slug: 'fluent-booking',
                    name: 'FluentBooking',
                    description: this.$t('Scheduling and appointments inside WordPress.')
                }
            ]
        }
    }
}
</script>
