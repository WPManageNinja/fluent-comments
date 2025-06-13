<template>
    <div>
        <div class="fbeta_dashboard">
            <div class="fluent_header">
                <h1 style="margin-bottom: 10px;">
                    FluentComments
                    <el-tag type="success">{{ appVars.version }}</el-tag>
                </h1>
                <p>Configure for which post types you want to use FluentComments as well as setup spam protection.</p>
            </div>
            <div class="fluent_content">
                <el-form v-model="settings" label-position="top">
                    <el-form-item label="Choose which post types you want to use FluentComments">
                        <el-checkbox-group v-model="settings.post_types" multiple placeholder="Select post types">
                            <el-checkbox v-for="(type, index) in appVars.comments_post_types" :key="index"
                                         :label="type.title" :value="type.name"></el-checkbox>
                        </el-checkbox-group>
                    </el-form-item>
                    <el-form-item label="Spam Protection">
                        <div style="display: block; width: 100%; margin-bottom: 0px;">
                            <el-checkbox true-value="yes" false-value="no" v-model="settings.reject_native_comments">
                                Enable spam protection for comments for the selected post types.
                            </el-checkbox>
                        </div>
                        <p>We highly recommend to enable this. If you enable, then all comments posted to WordPress default comment form will be rejected for the selected Post Types. This will block the spam comments by bot.</p>
                    </el-form-item>
                    <el-form-item>
                        <el-button :disabled="saving" :loading="saving" @click="updateSettings()" size="large" type="success">
                            Save Settings
                        </el-button>
                    </el-form-item>
                </el-form>
            </div>
        </div>
        <div v-if="appVars.using_block_theme == 'yes'"  class="fbeta_dashboard">
            <div class="fluent_header">
                <h1 style="margin-bottom: 10px;">
                    FSE Theme Compitability
                </h1>
            </div>
            <div class="fluent_content">
                <p>
                    As you are using a FSE theme, you can use the <strong>FluentComments</strong> shortcode to display comments and secure comment form in your posts.
                    <br />
                    Please replace your Comments block with the shortcode in target post types templates.
                </p>
                <p><b>FluentComments Shortcode:</b> [fluent_comments]</p>
            </div>
        </div>
    </div>
</template>

<script type="text/babel">

export default {
    name: 'Dashboard',
    data() {
        return {
            settings: this.appVars.settings || {},
            saving: false
        }
    },
    methods: {
        updateSettings() {
            this.saving = true;
            this.$post('save-settings', {settings: this.settings})
                .then(response => {
                    this.$notify.success(response.message);
                })
                .catch(error => {
                    this.$handleError(error);
                })
                .finally(() => {
                    this.saving = false;
                });
        }
    },
    mounted() {

    },
    created() {
        jQuery('.update-nag,.notice, #wpbody-content > .updated, #wpbody-content > .error').remove();
    }
}
</script>
