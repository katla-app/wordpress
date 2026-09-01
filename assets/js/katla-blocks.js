/**
 * Katla Cookie Consent — block editor registration.
 *
 * Uses the wp.* globals directly so the plugin ships without a build step.
 */
(function (blocks, element, blockEditor, components, i18n) {
	'use strict';

	var el = element.createElement;
	var Fragment = element.Fragment;
	var __ = i18n.__;
	var useBlockProps = blockEditor.useBlockProps;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody = components.PanelBody;
	var SelectControl = components.SelectControl;
	var TextControl = components.TextControl;

	var config = window.katlaBlocks || {};

	function localeOptions() {
		var options = [{ value: '', label: __('Follow the site language', 'katla-consent') }];

		(config.locales || []).forEach(function (locale) {
			options.push({ value: locale, label: locale });
		});

		return options;
	}

	function categoryOptions() {
		var options = [{ value: '', label: __('All categories', 'katla-consent') }];

		(config.categories || []).forEach(function (category) {
			options.push(category);
		});

		return options;
	}

	function preview(title, description, showWarning) {
		var children = [
			el('p', { className: 'katla-block-preview__title', key: 'title' }, title),
			el('p', { className: 'katla-block-preview__description', key: 'description' }, description)
		];

		if (showWarning && !config.configured) {
			children.push(
				el(
					'p',
					{ className: 'katla-block-preview__warning', key: 'warning' },
					__('No Katla Site ID configured yet — nothing will render on the front end.', 'katla-consent')
				)
			);
		}

		return el('div', { className: 'katla-block-preview' }, children);
	}

	blocks.registerBlockType('katla/policy', {
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			var formats = {
				full: __('Full cookie and privacy policy', 'katla-consent'),
				cookie: __('Cookie policy', 'katla-consent'),
				table: __('Cookie tables only', 'katla-consent')
			};

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Policy', 'katla-consent') },
						el(SelectControl, {
							label: __('Content', 'katla-consent'),
							value: attributes.format,
							options: Object.keys(formats).map(function (key) {
								return { value: key, label: formats[key] };
							}),
							onChange: function (value) {
								setAttributes({ format: value });
							}
						}),
						el(SelectControl, {
							label: __('Language', 'katla-consent'),
							value: attributes.locale,
							options: localeOptions(),
							onChange: function (value) {
								setAttributes({ locale: value });
							}
						}),
						el(SelectControl, {
							label: __('Rendering', 'katla-consent'),
							value: attributes.render,
							options: [
								{ value: 'server', label: __('On the server (indexable)', 'katla-consent') },
								{ value: 'client', label: __('In the browser', 'katla-consent') }
							],
							help:
								'client' === attributes.render
									? __('Search engines will not see this content.', 'katla-consent')
									: __('The policy is rendered into the page HTML and cached.', 'katla-consent'),
							onChange: function (value) {
								setAttributes({ render: value });
							}
						})
					)
				),
				el('div', useBlockProps(), preview(__('Katla policy', 'katla-consent'), formats[attributes.format] || formats.full, true))
			);
		},
		save: function () {
			return null;
		}
	});

	blocks.registerBlockType('katla/cookie-table', {
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Cookie table', 'katla-consent') },
						el(SelectControl, {
							label: __('Category', 'katla-consent'),
							value: attributes.category,
							options: categoryOptions(),
							onChange: function (value) {
								setAttributes({ category: value });
							}
						}),
						el(SelectControl, {
							label: __('Language', 'katla-consent'),
							value: attributes.locale,
							options: localeOptions(),
							onChange: function (value) {
								setAttributes({ locale: value });
							}
						})
					)
				),
				el(
					'div',
					useBlockProps(),
					preview(
						__('Katla cookie table', 'katla-consent'),
						attributes.category
							? __('One category, listed from your latest Katla scan.', 'katla-consent')
							: __('Every scanned cookie, grouped by category.', 'katla-consent'),
						true
					)
				)
			);
		},
		save: function () {
			return null;
		}
	});

	blocks.registerBlockType('katla/settings-button', {
		edit: function (props) {
			var attributes = props.attributes;
			var setAttributes = props.setAttributes;

			return el(
				Fragment,
				null,
				el(
					InspectorControls,
					null,
					el(
						PanelBody,
						{ title: __('Cookie settings', 'katla-consent') },
						el(TextControl, {
							label: __('Label', 'katla-consent'),
							value: attributes.label,
							placeholder: __('Cookie settings', 'katla-consent'),
							onChange: function (value) {
								setAttributes({ label: value });
							}
						}),
						el(SelectControl, {
							label: __('Appearance', 'katla-consent'),
							value: attributes.style,
							options: [
								{ value: 'button', label: __('Button', 'katla-consent') },
								{ value: 'link', label: __('Text link', 'katla-consent') }
							],
							onChange: function (value) {
								setAttributes({ style: value });
							}
						})
					)
				),
				el(
					'div',
					useBlockProps(),
					el(
						'button',
						{ type: 'button', className: 'katla-settings-link', onClick: function (event) { event.preventDefault(); } },
						attributes.label || __('Cookie settings', 'katla-consent')
					)
				)
			);
		},
		save: function () {
			return null;
		}
	});
})(window.wp.blocks, window.wp.element, window.wp.blockEditor, window.wp.components, window.wp.i18n);
