/**
 * Katla Cookie Consent — settings screen behaviour.
 *
 * Adds and removes rows in the handle/URL to category repeaters.
 */
(function () {
	'use strict';

	var config = window.katlaAdmin || {};

	function nextIndex(container) {
		var rows = container.querySelectorAll('.katla-map__row');
		var highest = -1;

		Array.prototype.forEach.call(rows, function (row) {
			var input = row.querySelector('input[type="text"]');

			if (!input) {
				return;
			}

			var match = input.name.match(/\[(\d+)\]\[key\]$/);

			if (match) {
				highest = Math.max(highest, parseInt(match[1], 10));
			}
		});

		return highest + 1;
	}

	function buildRow(container) {
		var name = container.getAttribute('data-katla-map');
		var list = container.getAttribute('data-katla-list');
		var index = nextIndex(container);

		var row = document.createElement('div');
		row.className = 'katla-map__row';

		var input = document.createElement('input');
		input.type = 'text';
		input.className = 'regular-text';
		input.name = name + '[' + index + '][key]';
		if (list) {
			input.setAttribute('list', list);
		}
		row.appendChild(input);

		var select = document.createElement('select');
		select.name = name + '[' + index + '][category]';
		(config.categories || []).forEach(function (category) {
			var option = document.createElement('option');
			option.value = category.value;
			option.textContent = category.label;
			select.appendChild(option);
		});
		row.appendChild(select);

		var remove = document.createElement('button');
		remove.type = 'button';
		remove.className = 'button-link katla-map__remove';
		remove.textContent = (config.i18n && config.i18n.remove) || 'Remove';
		row.appendChild(remove);

		return { row: row, input: input };
	}

	document.addEventListener('click', function (event) {
		var target = event.target;

		if (target.classList.contains('katla-map__add')) {
			event.preventDefault();

			var container = target.closest('.katla-map');
			var built = buildRow(container);

			container.insertBefore(built.row, container.querySelector('.katla-map__rows-end'));
			built.input.focus();

			return;
		}

		if (target.classList.contains('katla-map__remove')) {
			event.preventDefault();

			var row = target.closest('.katla-map__row');

			if (row && row.parentNode) {
				row.parentNode.removeChild(row);
			}
		}
	});
})();
