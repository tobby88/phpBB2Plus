(function () {
	"use strict";
	var panel = document.getElementById("plus-portal-poll");
	if (!panel || !window.fetch || !window.DOMParser) { return; }
	var content = panel.querySelector(".plus-poll-content");
	var link = content.querySelector("a");
	var loading = false;
	panel.addEventListener("toggle", function () {
		if (!panel.open || loading || content.querySelector("form")) { return; }
		loading = true;
		fetch(link.href, {credentials: "same-origin"}).then(function (response) {
			if (!response.ok || new URL(response.url).origin !== location.origin) { return; }
			return response.text().then(function (html) {
				var source = new DOMParser().parseFromString(html, "text/html").querySelector("form.topic_poll");
				if (!source) { return; }
				var action = new URL(source.getAttribute("action"), response.url);
				if (action.origin !== location.origin) { return; }
				source.setAttribute("action", action.href);
				// Submit to phpBB's poll handler, with the original form key and session.
				source.removeAttribute("data-ajax");
				source.removeAttribute("data-refresh");
				source.querySelectorAll("[href], [src]").forEach(function (element) {
					var key = element.hasAttribute("href") ? "href" : "src";
					element.setAttribute(key, new URL(element.getAttribute(key), response.url).href);
				});
				content.insertBefore(document.importNode(source, true), link);
			});
		}).catch(function () {
			// The ordinary topic link is always available, including without JavaScript.
		}).then(function () { loading = false; });
	});
}());
