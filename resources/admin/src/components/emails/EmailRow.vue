<template>
    <li class="flc_email_row" :class="{ flc_email_row_off: !enabled }">
        <el-switch
            :model-value="enabled"
            :loading="busy"
            @update:model-value="$emit('toggle', $event)"
        />

        <div class="flc_email_text" @click="$emit('edit')">
            <strong>{{ email.title }}</strong>
            <span>{{ email.description }}</span>
        </div>

        <el-tag v-if="enabled" :type="email.status === 'active' ? 'success' : 'info'" disable-transitions>
            {{ email.status === 'active' ? $t('Customized') : defaultLabel }}
        </el-tag>

        <el-button size="small" @click="$emit('edit')">{{ $t('Edit') }}</el-button>
    </li>
</template>

<script type="text/babel">
/**
 * One email in the list.
 *
 * Two controls, because the underlying state really is two things: the
 * switch is whether it is sent, the tag is whose words are in it. That is
 * the same split the server stores - one in the switch this email always
 * had, one in the plugin's own option - so showing it as one three way
 * control would be hiding the shape of the thing.
 *
 * The tag is dropped entirely when the email is off. "Off, customised" is
 * true but it is not what anyone is scanning the list for.
 */
export default {
    name: 'EmailRow',
    emits: ['toggle', 'edit'],
    props: {
        email: {
            type: Object,
            required: true
        },
        busy: Boolean
    },
    computed: {
        enabled() {
            return this.email.status !== 'disabled';
        },
        defaultLabel() {
            return this.email.owner === 'core' ? this.$t('WordPress default') : this.$t('Default');
        }
    }
}
</script>
