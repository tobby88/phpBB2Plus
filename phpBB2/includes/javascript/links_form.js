(function (root) {
    'use strict';
    function trim(value) { return String(value).replace(/^\s+|\s+$/g, ''); }
    function characters(value) { return String(value).match(/[\uD800-\uDBFF][\uDC00-\uDFFF]|[\s\S]/g) || []; }
    function validText(field) {
        var value = trim(field.value), max = Number(field.getAttribute('data-link-maxchars'));
        try { encodeURIComponent(value); } catch (error) { return false; }
        return value !== '' && characters(value).length <= max && !/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/.test(value);
    }
    function url(value, max) {
        value = trim(value);
        if (!/^https?:\/\//i.test(value) || /[\x00-\x20\x7f<>"'`\\]/.test(value)) { return false; }
        try {
            if (encodeURIComponent(value).replace(/%[0-9A-F]{2}/gi, 'x').length > max) { return false; }
            var parsed = new root.URL(value);
            if (!parsed.hostname || parsed.username || parsed.password || parsed.port === '0' || !/^https?:$/.test(parsed.protocol)) { return false; }
        } catch (error) { return false; }
        return value;
    }
    function invalid(form, field, preview) {
        field.setAttribute('aria-invalid', 'true');
        if (typeof field.focus === 'function') { field.focus(); }
        root.alert(form.getAttribute(preview ? 'data-preview-error' : 'data-link-error'));
        return false;
    }
    root.phpbbLinkForm = {
        limit: function (field) {
            var max = Number(field.getAttribute('data-link-maxchars')), points = characters(field.value);
            if (points.length > max) { field.value = points.slice(0, max).join(''); }
            field.removeAttribute('aria-invalid');
        },
        validate: function (form) {
            var fields = form.elements, logo = trim(fields.link_logo_src.value), names = ['link_title', 'link_desc', 'link_url', 'link_logo_src', 'link_category'], i;
            for (i = 0; i < names.length; i++) { fields[names[i]].removeAttribute('aria-invalid'); }
            if (!validText(fields.link_title)) { return invalid(form, fields.link_title, false); }
            if (!validText(fields.link_desc)) { return invalid(form, fields.link_desc, false); }
            if (url(fields.link_url.value, 100) === false) { return invalid(form, fields.link_url, false); }
            if ((logo === '' && fields.link_logo_src.required) || (logo !== '' && url(logo, 120) === false)) { return invalid(form, fields.link_logo_src, false); }
            if (!/^[1-9][0-9]{0,7}$/.test(fields.link_category.value) || Number(fields.link_category.value) > 16777215) { return invalid(form, fields.link_category, false); }
            return true;
        },
        preview: function (form) {
            var field = form.elements.link_logo_src, value = trim(field.value), panel = form.querySelector('[data-link-preview]'), image = panel.querySelector('img');
            panel.style.display = 'none'; image.removeAttribute('src');
            if (value !== '' && url(value, 120) === false) { return invalid(form, field, true); }
            field.removeAttribute('aria-invalid');
            image.setAttribute('referrerpolicy', 'no-referrer');
            image.src = value === '' ? 'images/links/no_logo88a.gif' : value;
            panel.style.display = '';
            return false;
        }
    };
}(window));
