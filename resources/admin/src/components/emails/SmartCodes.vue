<template>
    <el-popover
        ref="pop"
        placement="bottom-end"
        trigger="click"
        :width="440"
        popper-class="flc_codes_popover"
    >
        <div class="flc_codes">
            <ul class="flc_codes_groups">
                <li
                    v-for="(group, index) in data"
                    :key="group.key || index"
                    :class="{ flc_codes_group_on: activeIndex === index }"
                    @click="activeIndex = index"
                >{{ group.title }}</li>
            </ul>

            <ul class="flc_codes_list">
                <li v-for="(label, code) in activeCodes" :key="code" @click="pick(code)">
                    {{ label }}<span>{{ code }}</span>
                </li>
            </ul>
        </div>

        <template #reference>
            <el-button :size="size">{{ buttonText }}</el-button>
        </template>
    </el-popover>
</template>

<script type="text/babel">
import {$t} from '../../i18n';

/**
 * The placeholder picker.
 *
 * Groups on the left, codes on the right, and a click emits the code for
 * whoever asked to put it wherever the cursor is. The popover is left on
 * its own `trigger="click"` rather than a bound `visible` so that clicking
 * away closes it; driving it by hand meant the panel stayed open until you
 * hit the button again.
 */
export default {
    name: 'SmartCodes',
    emits: ['insert'],
    props: {
        data: {
            type: Array,
            default: () => []
        },
        buttonText: {
            // A prop default is evaluated before there is an instance to
            // reach $t through, hence the imported one.
            type: String,
            default: () => $t('+ Placeholder')
        },
        size: {
            type: String,
            default: 'small'
        }
    },
    data() {
        return {
            activeIndex: 0
        }
    },
    computed: {
        activeCodes() {
            const group = this.data[this.activeIndex];

            return group ? group.shortcodes : {};
        }
    },
    watch: {
        // The group list is fetched, so the index has to survive it
        // arriving - and arriving shorter than it was.
        data() {
            if (this.activeIndex >= this.data.length) {
                this.activeIndex = 0;
            }
        }
    },
    methods: {
        pick(code) {
            this.$emit('insert', code);

            if (this.$refs.pop) {
                this.$refs.pop.hide();
            }
        }
    }
}
</script>
