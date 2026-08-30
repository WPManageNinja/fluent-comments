<template>
    <div class="flc_email_frame">
        <iframe ref="frame" frameborder="0" :title="$t('Email preview')"></iframe>
    </div>
</template>

<script type="text/babel">
/**
 * Renders an email inside an iframe, so its styles cannot reach the admin
 * page and the admin page's cannot reach it.
 *
 * Content and colours are painted in one pass rather than by a watcher
 * each. Writing the content replaces the whole document, which throws away
 * anything the colour pass had just written - so whichever ran last used to
 * win, and dragging a colour picker after a content change showed the old
 * colours until you touched the body again.
 */
export default {
    name: 'EmailFrame',
    props: {
        content: String,
        styleConfig: Object
    },
    created() {
        // Deliberately not in data(): a DOM node has no business being
        // reactive, and Vue proxying one is a good way to lose an hour.
        this.styleNode = null;
        this.defaultFooter = null;
        this.lastContent = null;
    },
    methods: {
        doc() {
            const frame = this.$refs.frame;

            if (!frame) {
                return null;
            }

            return frame.contentDocument || frame.contentWindow.document;
        },
        paint() {
            const doc = this.doc();

            if (!doc) {
                return;
            }

            const config = this.styleConfig;

            if (this.lastContent !== this.content) {
                doc.open();
                doc.write(this.content || '<html><body></body></html>');
                doc.close();

                this.lastContent = this.content;
                this.styleNode = null;

                // Captured before anything is written over it, so clearing
                // the footer field can put the real default back rather
                // than leaving a blank strip.
                const footer = doc.querySelector('.footer_text');
                this.defaultFooter = footer ? footer.innerHTML : null;
            }

            if (!config) {
                return;
            }

            /*
             * One stylesheet, rewritten in place. Appending a fresh <style>
             * per change means one per animation frame while a colour
             * picker is being dragged, and a head with hundreds of them.
             */
            if (!this.styleNode || !this.styleNode.isConnected) {
                this.styleNode = doc.createElement('style');
                this.styleNode.type = 'text/css';
                doc.head.appendChild(this.styleNode);
            }

            this.styleNode.textContent = [
                `body, .body_wrap { background-color: ${config.body_bg} !important; }`,
                `.content_wrap { background-color: ${config.content_bg} !important; color: ${config.content_color} !important; }`,
                `a { color: ${config.accent_color} !important; }`,
                `.fcom_btn { background-color: ${config.accent_color} !important; color: #ffffff !important; }`,
                `blockquote { background-color: ${config.highlight_bg} !important; color: ${config.highlight_color} !important; border-left-color: ${config.accent_color} !important; }`,
                `blockquote p { color: ${config.highlight_color} !important; }`,
                `.footer_table, .footer_text, .footer_text a { color: ${config.footer_content_color} !important; }`
            ].join('\n');

            const footer = doc.querySelector('.footer_text');

            if (footer) {
                footer.innerHTML = config.footer_text || this.defaultFooter || '';
            }

            const logoWrap = doc.querySelector('.flc_preview_logo');

            if (logoWrap) {
                logoWrap.innerHTML = config.logo
                    ? `<img src="${config.logo}" alt="" style="max-width:180px;height:auto;display:inline-block;border:0;margin-bottom:20px"/>`
                    : '';
            }
        },
        schedulePaint() {
            this.$nextTick(this.paint);
        }
    },
    watch: {
        content: {
            immediate: true,
            handler: 'schedulePaint'
        },
        styleConfig: {
            deep: true,
            handler: 'schedulePaint'
        }
    },
    mounted() {
        this.paint();
    }
}
</script>
