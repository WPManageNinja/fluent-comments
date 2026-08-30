import {createApp} from 'vue'
import {createRouter, createWebHashHistory} from 'vue-router'
import App from './App.vue'
import {routes} from './routes'
import {applyStoredTheme} from './theme'
import {$t, $_n} from './i18n'

import {ElNotification, ElMessageBox, ElLoading} from "element-plus";

import './style.scss';

// Before the app mounts, not in a component: the bar, the ground and the
// scrollbars are all painted off the body class, and doing it a tick later
// is a white flash on every page load for anybody on dark.
applyStoredTheme();

const app = createApp(App);

app.config.globalProperties.$notify = ElNotification;
app.config.globalProperties.$confirm = ElMessageBox.confirm;
app.use(ElLoading);

const request = function (method, action, data = {}) {
    data.query_timestamp = Date.now();
    data.action = 'fluent-comments-admin-' + action;
    data.__nonce = window.fluentCommentsVars.nonce;

    return new Promise((resolve, reject) => {
        window.jQuery.ajax({
            url: window.fluentCommentsVars.ajax_url,
            type: method,
            data: data
        })
            .then(response => resolve(response))
            .fail(errors => reject(errors.responseJSON));
    });
}

function convertToText(obj) {
    const string = [];
    if (typeof (obj) === 'object' && (obj.join === undefined)) {
        for (const prop in obj) {
            string.push(convertToText(obj[prop]));
        }
    } else if (typeof (obj) === 'object' && !(obj.join === undefined)) {
        for (const prop in obj) {
            string.push(convertToText(obj[prop]));
        }
    } else if (typeof (obj) === 'function') {

    } else if (typeof (obj) === 'string') {
        string.push(obj)
    }

    return string.join('<br />')
}

app.config.globalProperties.appVars = window.fluentCommentsVars;

app.mixin({
    methods: {
        $get(action, data = {}) {
            return request('GET', action, data);
        },
        $post(action, data = {}) {
            return request('POST', action, data);
        },
        $handleError(response) {
            let errorMessage = '';
            if (typeof response === 'string') {
                errorMessage = response;
            } else if (response && response.message) {
                errorMessage = response.message;
            } else {
                errorMessage = convertToText(response);
            }
            if (!errorMessage) {
                errorMessage = $t('Something is wrong!');
            }
            this.$notify({
                type: 'error',
                title: $t('Error'),
                message: errorMessage,
                dangerouslyUseHTMLString: true
            });
        },
        /**
         * Folds a response's fresh switch values back into the page-load
         * snapshot the Settings tab reads.
         *
         * Both tabs write the same three settings and the same two
         * WordPress options. Without this, turning an email off under
         * Emails and then saving Settings - which still held what the page
         * was rendered with - would put it straight back on.
         */
        $syncToggles(response) {
            if (!response || !response.toggles) {
                return;
            }

            Object.assign(window.fluentCommentsVars.settings, response.toggles.settings || {});
            Object.assign(window.fluentCommentsVars.discussion, response.toggles.discussion || {});
        },
        convertToText,
        $t,
        $_n,
    }
});

const router = createRouter({
    routes,
    history: createWebHashHistory()
});

// The page is one wp-admin screen with three tabs, so the browser title has
// to follow the tab - wp-admin only ever set it once, on page load.
router.afterEach((to) => {
    document.title = (to.meta.title || $t('Settings')) + ' | FluentComments';
    window.scrollTo({top: 0});
});

app.use(router).mount(
    '#fluent_comment_app'
);
