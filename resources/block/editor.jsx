import { registerBlockType } from '@wordpress/blocks';
import {
    useBlockProps,
    InspectorControls,
    PanelColorSettings,
} from '@wordpress/block-editor';
import {
    PanelBody,
    ToggleControl,
    TextControl,
    RangeControl,
} from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import './editor.scss';

const BLOCK_NAME = 'fluent-comments/comments';

const CommentIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="24" height="24">
        <path d="M12 2C6.48 2 2 6.48 2 12c0 1.85.5 3.58 1.36 5.08L2 22l4.92-1.36C8.42 21.5 10.15 22 12 22c5.52 0 10-4.48 10-10S17.52 2 12 2zm0 18c-1.58 0-3.08-.4-4.39-1.12l-.31-.18-3.22.89.89-3.22-.18-.31C4.4 15.08 4 13.58 4 12c0-4.41 3.59-8 8-8s8 3.59 8 8-3.59 8-8 8z"/>
    </svg>
);

const ReplyIcon = () => (
    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" width="14" height="14">
        <path d="M10 9V5l-7 7 7 7v-4.1c5 0 8.5 1.6 11 5.1-1-5-4-10-11-11z"/>
    </svg>
);

const Edit = ({ attributes, setAttributes }) => {
    const {
        titleWithComments,
        titleNoComments,
        showTitle,
        showAvatars,
        primaryColor,
        textColor,
        cardBackgroundColor,
        borderRadius,
    } = attributes;

    const blockProps = useBlockProps({
        className: 'fluent-comments-block-preview',
        style: {
            '--fcom-text-link': primaryColor || undefined,
            '--fcom-primary-text': textColor || undefined,
            '--fcom-secondary-content-bg': cardBackgroundColor || undefined,
            '--fcom-radius': borderRadius ? `${borderRadius}px` : undefined,
        },
    });

    // The same string Frontend::getStrings() defaults to. Anything else
    // means the preview renders a title the front end never will, and the
    // .pot carries two msgids for one piece of copy.
    const defaultTitleWithComments = __('Latest comments ({count})', 'fluent-comments');
    const defaultTitleNoComments = __('Add your first comment to this post', 'fluent-comments');

    return (
        <>
            <InspectorControls>
                <PanelBody title={__('Display Settings', 'fluent-comments')} initialOpen={true}>
                    <ToggleControl
                        label={__('Show Title', 'fluent-comments')}
                        checked={showTitle}
                        onChange={(value) => setAttributes({ showTitle: value })}
                    />
                    {showTitle && (
                        <>
                            <TextControl
                                label={__('Title (with comments)', 'fluent-comments')}
                                value={titleWithComments}
                                onChange={(value) => setAttributes({ titleWithComments: value })}
                                placeholder={defaultTitleWithComments}
                                help={__('Use {count} to show comment count', 'fluent-comments')}
                            />
                            <TextControl
                                label={__('Title (no comments)', 'fluent-comments')}
                                value={titleNoComments}
                                onChange={(value) => setAttributes({ titleNoComments: value })}
                                placeholder={defaultTitleNoComments}
                            />
                        </>
                    )}
                    <ToggleControl
                        label={__('Show Avatars', 'fluent-comments')}
                        checked={showAvatars}
                        onChange={(value) => setAttributes({ showAvatars: value })}
                    />
                    <RangeControl
                        label={__('Border Radius', 'fluent-comments')}
                        value={borderRadius}
                        onChange={(value) => setAttributes({ borderRadius: value })}
                        min={0}
                        max={20}
                    />
                </PanelBody>

                <PanelColorSettings
                    title={__('Color Settings', 'fluent-comments')}
                    initialOpen={false}
                    colorSettings={[
                        {
                            value: primaryColor,
                            onChange: (value) => setAttributes({ primaryColor: value }),
                            label: __('Primary Color', 'fluent-comments'),
                        },
                        {
                            value: textColor,
                            onChange: (value) => setAttributes({ textColor: value }),
                            label: __('Text Color', 'fluent-comments'),
                        },
                        {
                            value: cardBackgroundColor,
                            onChange: (value) => setAttributes({ cardBackgroundColor: value }),
                            label: __('Card Background', 'fluent-comments'),
                        },
                    ]}
                />
            </InspectorControls>

            <div {...blockProps}>
                <div className="fluent-comments-editor-preview">
                    <div className="flc-preview-header">
                        <CommentIcon />
                        <span>FluentComments</span>
                    </div>
                    <div className="flc-preview-content">
                        {showTitle && (
                            <h2 className="flc-preview-title">
                                {(titleWithComments || defaultTitleWithComments).replace('{count}', '3')}
                            </h2>
                        )}

                        {/* Comment Form */}
                        <div className="flc-preview-form">
                            {showAvatars && <div className="flc-preview-avatar" />}
                            <div className="flc-preview-form-inner">
                                <div className="flc-preview-textarea">
                                    {__('Write your comment here…', 'fluent-comments')}
                                </div>
                                <button className="flc-preview-submit">
                                    {__('Submit Comment', 'fluent-comments')}
                                </button>
                            </div>
                        </div>

                        {/* Comments List */}
                        <div className="flc-preview-comments">
                            {/* First Comment */}
                            <div className="flc-preview-comment">
                                {showAvatars && <div className="flc-preview-avatar" />}
                                <div className="flc-preview-comment-body">
                                    <div className="flc-preview-comment-card">
                                        <div className="flc-preview-comment-meta">
                                            <strong>{__('Sample Commenter', 'fluent-comments')}</strong>
                                            <span className="flc-preview-time">{__('2 days ago', 'fluent-comments')}</span>
                                        </div>
                                        <p>{__('This is a preview. Comments left on your site appear here, in the colors and spacing you choose.', 'fluent-comments')}</p>
                                    </div>
                                    <div className="flc-preview-reply-link">
                                        <ReplyIcon />
                                        <span>{__('Reply', 'fluent-comments')}</span>
                                    </div>
                                </div>
                            </div>

                            {/* Second Comment */}
                            <div className="flc-preview-comment">
                                {showAvatars && <div className="flc-preview-avatar" />}
                                <div className="flc-preview-comment-body">
                                    <div className="flc-preview-comment-card">
                                        <div className="flc-preview-comment-meta">
                                            <strong>{__('Another Visitor', 'fluent-comments')}</strong>
                                            <span className="flc-preview-time">{__('5 hours ago', 'fluent-comments')}</span>
                                        </div>
                                        <p>{__('Threading is on, so a reply sits under the comment it answers.', 'fluent-comments')}</p>
                                    </div>
                                    <div className="flc-preview-reply-link">
                                        <ReplyIcon />
                                        <span>{__('Reply', 'fluent-comments')}</span>
                                    </div>

                                    {/* Nested Reply */}
                                    <div className="flc-preview-comment flc-preview-reply">
                                        {showAvatars && <div className="flc-preview-avatar small" />}
                                        <div className="flc-preview-comment-body">
                                            <div className="flc-preview-comment-card">
                                                <div className="flc-preview-comment-meta">
                                                    <strong>{__('Post Author', 'fluent-comments')}</strong>
                                                    <span className="flc-preview-time">{__('just now', 'fluent-comments')}</span>
                                                </div>
                                                <p>{__('And this is what that nested reply looks like.', 'fluent-comments')}</p>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
};

const Save = () => null;

registerBlockType(BLOCK_NAME, {
    icon: CommentIcon,
    edit: Edit,
    save: Save,
});
