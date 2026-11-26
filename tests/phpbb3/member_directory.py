"""Exercise both historical lookups and the native editors separately on a disposable converted board."""
from html import unescape
import json
from pathlib import Path
import re
from urllib.parse import urlencode, urljoin

from delegation import authenticate
from http_board import FormFields, Page


def browse(base, board, target, php):
    fixture = Path(__file__).with_suffix(".php")

    def stage(name):
        return php(fixture, target, name, cwd=board, capture=True)

    ids = json.loads(stage("prepare"))
    mode = "&i=-phpbb2plus-community-acp-member_directory_module&mode="
    domain = "members.directory.invalid"

    def rows(page):
        table = re.search(r'<table [^>]*id="plus-directory-results".*?<tbody>(.*?)</tbody>', page, re.S)
        return re.findall(r"<tr[^>]*>(.*?)</tr>", table.group(1), re.S) if table else []

    def numbers(page):
        return [int(re.search(r'<td class="directory-id">(\d+)</td>', row).group(1)) for row in rows(page)]

    def search(browser, url, page, **fields):
        # Follow the real GET form, including its native ACP module and session fields.
        form = "plus-member-directory"
        action = Page(page).forms[form][0]
        values = FormFields(page, form).values
        values.update(fields)
        return browser.fetch(urljoin(url, action) + "?" + urlencode(values))

    def editor(page, label):
        found = re.search(r'<a href="([^"]+)">' + re.escape(label) + r'</a>', rows(page)[0])
        return unescape(found.group(1)) if found else None

    try:
        mail, mail_entry = authenticate(base, "EmailDirectoryJunior")
        mail_url = mail_entry + mode + "emails"
        mail_page = mail.fetch(mail_url)
        member, member_entry = authenticate(base, "MemberDirectoryJunior")
        member_url = member_entry + mode + "members"
        member_page = member.fetch(member_url)
        if "Module not accessible" not in mail.fetch(mail_entry + mode + "members", expected_status=503) or \
           "Module not accessible" not in member.fetch(member_entry + mode + "emails", expected_status=503):
            raise RuntimeError("The two independently delegated directory modes were not isolated")
        email = search(mail, mail_url, mail_page, q=domain, sort="user_email", order="DESC")
        addresses = [unescape(re.search(r'<a href="mailto:[^"]+">([^<]+)</a>', row).group(1)) for row in rows(email)]
        expected = [name.lower() + "+list@" + domain for name in ("Digit", "Beta", "Amp", "Alpha")]
        if addresses != expected or "mailto:digit%2Blist@" not in email or "DirFixtureAmp&amp;One" not in email:
            raise RuntimeError("The email directory lost an address, its local mail link, its order or its literal username")
        if any(x in email for x in ('class="directory-id"', 'class="directory-visit"', 'class="directory-actions"', 'value="user_posts"', 'Account ban', 'mode=overview&amp;u=')):
            raise RuntimeError("The email-only role saw account fields, controls or editor links")
        bogus_order = mail.fetch(mail_url + "&q=" + domain + "&sort=user_posts")
        if FormFields(bogus_order, "plus-member-directory").values["sort"] != "username":
            raise RuntimeError("The email directory accepted an account-activity sort")
        for text, count in (("%Fixture", 1), ("&One", 1), ("does-not-match-this-account", 0)):
            if len(rows(search(mail, mail_url, mail_page, q=text))) != count:
                raise RuntimeError("Literal wildcard, escaped name or empty email lookup failed")
        limited = search(member, member_url, member_page, q=domain, show=2, sort="user_posts", order="DESC")
        if numbers(limited) != [ids["Digit"], ids["Beta"]]:
            raise RuntimeError("Account activity sort lost its stable user ID tie-break")
        more = re.search(r'<a href="([^"]+)">Next</a>', limited)
        if not more or numbers(member.fetch(urljoin(member_url, unescape(more.group(1))))) != [ids["Alpha"], ids["Amp"]]:
            raise RuntimeError("The native directory pagination lost the filter, sort or page size")
        by_id = search(member, member_url, member_page, q=domain, sort="user_id", order="ASC", show=200)
        if numbers(by_id) != [ids[name] for name in ("Alpha", "Beta", "Digit", "Amp")]:
            raise RuntimeError("ID order changed the contents of the historical list")
        alpha, beta, digit, amp = rows(by_id)
        if 'style="color:#2167AC"' not in alpha or "Account ban" not in alpha or any("Account ban" in row for row in (beta, digit, amp)) or \
           '<td class="directory-visit">–</td>' not in beta or '<td class="directory-active">No</td>' not in beta or \
           '<td class="directory-active">Yes</td>' not in alpha or '<td class="directory-visit">–</td>' in alpha:
            raise RuntimeError("Account colour, active status, last visit or effective named ban did not follow the stored rows")
        for sort, expected_order in (("user_active", ("Beta", "Amp", "Alpha", "Digit")), ("user_lastvisit", ("Beta", "Alpha", "Digit", "Amp")),
                                     ("user_regdate", ("Alpha", "Beta", "Digit", "Amp"))):
            if numbers(search(member, member_url, member_page, q=domain, sort=sort, order="ASC")) != [ids[k] for k in expected_order]:
                raise RuntimeError("Unexpected historical directory order for " + sort)
        if numbers(search(member, member_url, member_page, q=domain, alpha="0")) != [ids["Digit"]] or \
           len(rows(search(member, member_url, member_page, q=domain, alpha="D"))) != 3:
            raise RuntimeError("The non-letter or letter filter diverged from the old list")
        for name in ("Anonymous", "Google [Bot]"):
            if rows(search(member, member_url, member_page, q=name)):
                raise RuntimeError("A technical identity appeared among member accounts")
        def single():
            return search(member, member_url, member_page, q="alpha+list@" + domain)
        page = single()
        if "directory-actions" in page or "bulk_action" in page:
            raise RuntimeError("A converted read-only user-list grant acquired editing or mass operations")
        stage("account-right")
        page = single()
        link = editor(page, "Edit")
        if not link or 'id="quicktools"' not in member.fetch(urljoin(member_url, link)) or editor(page, "User permissions") or editor(page, "User forum permissions"):
            raise RuntimeError("The separately granted account editor or its limits were incorrect")
        stage("global-right")
        page = single()
        link = editor(page, "User permissions")
        if not link or editor(page, "User forum permissions") or "DirFixtureAlpha" not in member.fetch(urljoin(member_url, link)):
            raise RuntimeError("The native global permission editor was not scoped to its selected user")
        stage("local-right")
        link = editor(single(), "User forum permissions")
        if not link or 'name="forum_id[]"' not in member.fetch(urljoin(member_url, link)):
            raise RuntimeError("The native per-forum editor did not open its own forum selection")
        admin, admin_entry = authenticate(base, "ModernMember")
        translated = admin.fetch(admin_entry + mode + "members" + "&q=ModernMember")
        if "Administrative Benutzerliste" not in translated or "Name oder E-Mail-Adresse enthält" not in translated or "ModernMember" not in rows(translated)[0]:
            raise RuntimeError("The German directory or the founder's account was missing")
        stage("revoke")
        denied = mail.fetch(mail_url, expected_status=403)
        if "You are not authorised to administer this board" not in denied or "Module not accessible" not in member.fetch(member_url, expected_status=503):
            raise RuntimeError("An open directory remained accessible after an explicit Never")
    finally:
        stage("clean")
