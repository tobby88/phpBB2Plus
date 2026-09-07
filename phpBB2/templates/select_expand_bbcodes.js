/*************************************************************
 * SXBB - Select Expand BBcodes MOD
 * Copyright (C) 2004, Markus (http://www.phpmix.com)
 * Preserved DOM-based implementation; released under GPL.
 *************************************************************/
(function (window, document) {
	'use strict';
	if (window.phpbbSxbb || !document.querySelectorAll || !document.addEventListener) {
		return;
	}

	var nextId = 0;
	var minimumHeight = 40;

	function parentBox(node) {
		while (node && node !== document) {
			if (node.nodeType === 1 && node.getAttribute('data-sxbb-box') === '1') {
				return node;
			}
			node = node.parentNode;
		}
		return null;
	}

	function refresh(box) {
		var state = box.phpbbSxbbState;
		if (!state) { return; }
		state.toggle.style.display = state.content.scrollHeight > minimumHeight ? '' : 'none';
		state.toggle.setAttribute('aria-expanded', state.expanded ? 'true' : 'false');
		state.toggle.textContent = state.toggle.getAttribute(state.expanded ? 'data-sxbb-contract' : 'data-sxbb-expand');
	}

	function setExpanded(box, expanded) {
		var state = box.phpbbSxbbState;
		state.expanded = expanded;
		state.content.style.maxHeight = expanded ? '' : minimumHeight + 'px';
		refresh(box);
	}

	function initializeBox(box) {
		if (box.phpbbSxbbState) { return; }
		var content = box.querySelector('[data-sxbb-content]');
		var controls = box.querySelector('[data-sxbb-controls]');
		var toggle = controls && controls.querySelector('[data-sxbb-action="toggle"]');
		var select = controls && controls.querySelector('[data-sxbb-action="select"]');
		if (!content || !toggle || !select || parentBox(content) !== box || parentBox(controls) !== box) {
			return;
		}
		var id;
		do { id = 'sxbb-content-' + (++nextId); } while (document.getElementById(id));
		content.id = id;
		toggle.setAttribute('aria-controls', id);
		select.setAttribute('aria-controls', id);
		select.style.display = document.createRange && window.getSelection ? '' : 'none';
		box.phpbbSxbbState = { content: content, toggle: toggle, expanded: false };
		controls.style.display = '';
		setExpanded(box, false);
	}

	function init(root) {
		if (!root || !root.querySelectorAll) { return; }
		if (root.nodeType === 1 && root.getAttribute('data-sxbb-box') === '1') {
			initializeBox(root);
		}
		var boxes = root.querySelectorAll('[data-sxbb-box]');
		for (var i = 0; i < boxes.length; i++) { initializeBox(boxes[i]); }
	}

	function refreshAll() {
		var boxes = document.querySelectorAll('[data-sxbb-box]');
		for (var i = 0; i < boxes.length; i++) { refresh(boxes[i]); }
	}

	document.addEventListener('click', function (event) {
		var button = event.target;
		while (button && button !== document && !(button.nodeType === 1 && button.getAttribute('data-sxbb-action'))) {
			button = button.parentNode;
		}
		if (!button || button === document) { return; }
		var box = parentBox(button);
		if (!box) { return; }
		initializeBox(box);
		var state = box.phpbbSxbbState;
		if (!state) { return; }
		event.preventDefault();
		if (button.getAttribute('data-sxbb-action') === 'select' && document.createRange && window.getSelection) {
			var range = document.createRange();
			range.selectNodeContents(state.content);
			var selection = window.getSelection();
			if (selection) {
				selection.removeAllRanges();
				selection.addRange(range);
			}
		} else if (button.getAttribute('data-sxbb-action') === 'toggle') {
			setExpanded(box, !state.expanded);
			// An expanded nested block must not remain clipped by its ancestors.
			if (state.expanded) {
				for (var outer = parentBox(box.parentNode); outer; outer = parentBox(outer.parentNode)) {
					initializeBox(outer);
					if (outer.phpbbSxbbState) { setExpanded(outer, true); }
				}
			}
		}
	}, false);

	window.phpbbSxbb = { init: init, refresh: refreshAll };
	function start() {
		init(document);
		// AJAX edits and previews insert normal HTML; no script execution needed.
		if (window.MutationObserver) {
			new window.MutationObserver(function (records) {
				for (var i = 0; i < records.length; i++) {
					for (var j = 0; j < records[i].addedNodes.length; j++) {
						init(records[i].addedNodes[j]);
					}
				}
			}).observe(document.body, { childList: true, subtree: true });
		}
	}
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', start, false);
	} else {
		start();
	}
	window.addEventListener('resize', refreshAll, false);
	// Late-loading images may make an initially short quote expandable.
	document.addEventListener('load', refreshAll, true);
})(window, document);
