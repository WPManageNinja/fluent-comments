<template>
    <div class="flc_app">
        <header class="flc_topbar">
            <div class="flc_topbar_inner">
                <div class="flc_brand">
                    <span class="flc_mark" aria-hidden="true">
                        <svg viewBox="0 0 45 45" fill="none" xmlns="http://www.w3.org/2000/svg">
                        <path d="M45 9.23617V35.7638C45 40.8656 40.7077 45 35.4096 45H9.59042C4.29513 45 0 40.8656 0 35.7638V9.23617C0 4.13579 4.29513 0 9.59042 0H35.4096C40.7063 0 45 4.13579 45 9.23617Z" fill="url(#flc_logo_gradient)"/>
                        <path d="M31 9H13C11.4087 9 9.88258 9.63214 8.75736 10.7574C7.63214 11.8826 7 13.4087 7 15V25C7 26.5913 7.63214 28.1174 8.75736 29.2426C9.88258 30.3679 11.4087 31 13 31H21.68L31.42 37.82C31.5825 37.9348 31.7756 37.9985 31.9745 38.003C32.1734 38.0074 32.3691 37.9525 32.5366 37.8451C32.7041 37.7377 32.8358 37.5828 32.9147 37.4002C32.9937 37.2176 33.0164 37.0156 32.98 36.82L32.13 30.82C33.4881 30.5597 34.7146 29.8382 35.602 28.7777C36.4894 27.7172 36.9832 26.3827 37 25V15C37 13.4087 36.3679 11.8826 35.2426 10.7574C34.1174 9.63214 32.5913 9 31 9ZM35 25C35 26.0609 34.5786 27.0783 33.8284 27.8284C33.0783 28.5786 32.0609 29 31 29C30.856 28.9986 30.7134 29.0283 30.582 29.087C30.4505 29.1458 30.3333 29.2323 30.2384 29.3405C30.1434 29.4488 30.073 29.5762 30.0318 29.7142C29.9907 29.8522 29.9798 29.9974 30 30.14L30.67 34.86L22.56 29.18C22.3956 29.0652 22.2005 29.0025 22 29H13C11.9391 29 10.9217 28.5786 10.1716 27.8284C9.42143 27.0783 9 26.0609 9 25V15C9 13.9391 9.42143 12.9217 10.1716 12.1716C10.9217 11.4214 11.9391 11 13 11H31C32.0609 11 33.0783 11.4214 33.8284 12.1716C34.5786 12.9217 35 13.9391 35 15V25Z" fill="white"/>
                        <path d="M31 14H18C17.7348 14 17.4804 14.1054 17.2929 14.2929C17.1054 14.4804 17 14.7348 17 15C17 15.2652 17.1054 15.5196 17.2929 15.7071C17.4804 15.8946 17.7348 16 18 16H31C31.2652 16 31.5196 15.8946 31.7071 15.7071C31.8946 15.5196 32 15.2652 32 15C32 14.7348 31.8946 14.4804 31.7071 14.2929C31.5196 14.1054 31.2652 14 31 14ZM31 19H13C12.7348 19 12.4804 19.1054 12.2929 19.2929C12.1054 19.4804 12 19.7348 12 20C12 20.2652 12.1054 20.5196 12.2929 20.7071C12.4804 20.8946 12.7348 21 13 21H31C31.2652 21 31.5196 20.8946 31.7071 20.7071C31.8946 20.5196 32 20.2652 32 20C32 19.7348 31.8946 19.4804 31.7071 19.2929C31.5196 19.1054 31.2652 19 31 19ZM31 24H13C12.7348 24 12.4804 24.1054 12.2929 24.2929C12.1054 24.4804 12 24.7348 12 25C12 25.2652 12.1054 25.5196 12.2929 25.7071C12.4804 25.8946 12.7348 26 13 26H31C31.2652 26 31.5196 25.8946 31.7071 25.7071C31.8946 25.5196 32 25.2652 32 25C32 24.7348 31.8946 24.4804 31.7071 24.2929C31.5196 24.1054 31.2652 24 31 24Z" fill="white"/>
                        <defs>
                        <linearGradient id="flc_logo_gradient" x1="11.4615" y1="11.4615" x2="42.6821" y2="42.6821" gradientUnits="userSpaceOnUse">
                        <stop stop-color="#0069B6"/>
                        <stop offset="1" stop-color="#5465FF"/>
                        </linearGradient>
                        </defs>
                        </svg>
                    </span>
                    <h1>FluentComments</h1>
                    <span class="flc_version">{{ appVars.version }}</span>
                </div>

                <nav class="flc_nav">
                    <router-link
                        v-for="item in menuItems"
                        :key="item.route"
                        :to="{ name: item.route }"
                        :class="{ flc_nav_on: isActive(item) }"
                    >{{ item.title }}</router-link>
                </nav>

                <div class="flc_topbar_actions">
                    <a
                        class="flc_icon_button"
                        :href="appVars.links.docs"
                        target="_blank"
                        rel="noopener"
                        :title="$t('Documentation')"
                    >
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                            <circle cx="12" cy="12" r="9"/>
                            <path d="M9.6 9.2a2.5 2.5 0 1 1 3.3 2.4c-.6.2-.9.8-.9 1.4v.5"/>
                            <path d="M12 17h.01"/>
                        </svg>
                        <span class="screen-reader-text">{{ $t('Documentation') }}</span>
                    </a>

                    <el-dropdown
                        trigger="click"
                        popper-class="flc_theme_menu"
                        @command="chooseMode"
                    >
                        <button
                            type="button"
                            class="flc_icon_button"
                            :title="$t('Appearance: %s', modeLabel)"
                        >
                            <svg v-if="mode === 'light'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <circle cx="12" cy="12" r="4"/>
                                <path d="M12 2v2M12 20v2M4.9 4.9l1.5 1.5M17.6 17.6l1.5 1.5M2 12h2M20 12h2M4.9 19.1l1.5-1.5M17.6 6.4l1.5-1.5"/>
                            </svg>
                            <svg v-else-if="mode === 'dark'" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M20 14.2A8.2 8.2 0 0 1 9.8 4 8.4 8.4 0 1 0 20 14.2Z"/>
                            </svg>
                            <svg v-else viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <rect x="2.5" y="4" width="19" height="13" rx="2"/>
                                <path d="M8.5 20.5h7M12 17.5v3"/>
                            </svg>
                            <!-- translators: %s is the current appearance mode: Light, Dark or System -->
                            <span class="screen-reader-text">{{ $t('Appearance: %s', modeLabel) }}</span>
                        </button>

                        <template #dropdown>
                            <el-dropdown-menu>
                                <el-dropdown-item
                                    v-for="option in modeOptions"
                                    :key="option.value"
                                    :command="option.value"
                                    :class="{ flc_theme_on: mode === option.value }"
                                >{{ option.label }}</el-dropdown-item>
                            </el-dropdown-menu>
                        </template>
                    </el-dropdown>
                </div>
            </div>
        </header>

        <router-view/>
    </div>
</template>

<script type="text/babel">
import {getMode, setMode, watchSystem} from './theme';

export default {
    name: 'FluentCommentsApp',
    data() {
        return {
            mode: getMode(),
            modeOptions: [
                {value: 'light', label: this.$t('Light')},
                {value: 'dark', label: this.$t('Dark')},
                {value: 'system', label: this.$t('System')}
            ],
            menuItems: [
                {route: 'settings', title: this.$t('Settings'), match: 'settings'},
                {route: 'emails', title: this.$t('Emails'), match: 'emails'},
                {route: 'about', title: this.$t('About'), match: 'about'}
            ]
        }
    },
    computed: {
        modeLabel() {
            const option = this.modeOptions.find(item => item.value === this.mode);
            return option ? option.label : this.$t('Light');
        }
    },
    methods: {
        chooseMode(mode) {
            setMode(mode);
            this.mode = getMode();
        },

        /**
         * Lit by meta.active, not by route name: /emails/template and
         * /emails/:id are still the Emails tab.
         */
        isActive(item) {
            return (this.$route.meta ? this.$route.meta.active : '') === item.match;
        }
    },
    created() {
        jQuery('.update-nag,.notice, #wpbody-content > .updated, #wpbody-content > .error').remove();

        // Only fires while the mode is `system`; theme.js does the paint and
        // the cache, this is here to keep the icon honest.
        watchSystem(() => {
            this.mode = getMode();
        });
    }
}
</script>
