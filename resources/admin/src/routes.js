import Settings from './components/Settings.vue';
import AllEmails from './components/emails/AllEmails.vue';
import EditEmail from './components/emails/EditEmail.vue';
import TemplateDesign from './components/emails/TemplateDesign.vue';
import About from './components/About.vue';
import {$t} from './i18n';

/*
 * Three tabs, and everything under Emails keeps the Emails tab lit - hence
 * meta.active rather than matching the route name, which would drop the
 * highlight the moment you opened one email to edit it.
 */
export const routes = [
    {
        path: '/',
        name: 'settings',
        component: Settings,
        meta: {title: $t('Settings'), active: 'settings'}
    },
    {
        path: '/emails',
        name: 'emails',
        component: AllEmails,
        meta: {title: $t('Emails'), active: 'emails'}
    },
    {
        path: '/emails/template',
        name: 'email_template',
        component: TemplateDesign,
        meta: {title: $t('Email Template'), active: 'emails'}
    },
    {
        path: '/emails/:email_id',
        name: 'edit_email',
        component: EditEmail,
        props: true,
        meta: {title: $t('Edit Email'), active: 'emails'}
    },
    {
        path: '/about',
        name: 'about',
        component: About,
        meta: {title: $t('About'), active: 'about'}
    },
    {
        path: '/:pathMatch(.*)*',
        redirect: {name: 'settings'}
    }
];
